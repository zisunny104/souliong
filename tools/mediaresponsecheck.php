<?php
/** Isolated HTTP checks: never reads the checkout's api/config.php or project data. */
$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/souliong-media-check-' . bin2hex(random_bytes(6));
$server = null; $checks = 0; $skips = 0;
function mr_assert(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function mr_remove(string $path): void {
    if (is_dir($path)) {
        foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') mr_remove($path . '/' . $name);
        rmdir($path);
    } else { chmod($path, 0600); unlink($path); }
}
function mr_request(string $url, string $method = 'GET', string $range = ''): array {
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5, 'header' => $range !== '' ? 'Range: ' . $range : '']]);
    $body = file_get_contents($url, false, $ctx);
    $lines = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $lines[0] ?? '', $m);
    $headers = [];
    foreach ($lines as $line) if (str_contains($line, ':')) {
        [$key, $value] = explode(':', $line, 2); $headers[strtolower($key)] = trim($value);
    }
    return [(int)($m[1] ?? 0), $body, $headers];
}
try {
    mkdir($dir . '/api', 0775, true); mkdir($dir . '/projects/test/media', 0775, true); mkdir($dir . '/projects/test/photos', 0775, true);
    foreach (['media.php', 'photo.php', 'store.php', 'imaging.php'] as $file) copy($root . '/api/' . $file, $dir . '/api/' . $file);
    $cfg = require $root . '/api/config.example.php';
    $cfg['projects_dir'] = $dir . '/projects'; $cfg['state_dir'] = $dir . '/state';
    file_put_contents($dir . '/api/config.php', '<?php return ' . var_export($cfg, true) . ';');
    file_put_contents($dir . '/router.php', '<?php $api=$_GET["api"]??""; if(!in_array($api,["photo","media"],true)){http_response_code(404);return;} require __DIR__."/api/".$api.".php";');
    file_put_contents($dir . '/projects/test/media/test.m4a', '0123456789');
    file_put_contents($dir . '/projects/test/media/empty.m4a', '');
    file_put_contents($dir . '/projects/test/photos/test.jpg', 'photo-fixture');
    $socket = stream_socket_server('tcp://127.0.0.1:0'); $address = stream_socket_get_name($socket, false); fclose($socket);
    $port = (int)substr(strrchr($address, ':'), 1);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir, $dir . '/router.php'], [0 => ['pipe','r'], 1 => ['file',$dir . '/server.log','a'], 2 => ['file',$dir . '/server.log','a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Cannot launch PHP test server');
    fclose($pipes[0]);
    for ($i=0; $i<50; $i++) { $ready=@fsockopen('127.0.0.1',$port); if ($ready) {fclose($ready);break;} usleep(20000); }
    $base = 'http://127.0.0.1:' . $port . '/?api=';
    foreach (['media','photo'] as $api) {
        [$code] = mr_request($base . $api . '&f[]=bad'); mr_assert($code === 400, "$api array input must return 400");
        [$code] = mr_request($base . $api . '&f=../secret'); mr_assert($code === 400, "$api traversal must return 400");
        [$code] = mr_request($base . $api . '&f=test/missing'); mr_assert($code === 404, "$api missing file must return 404");
    }
    $media = $base . 'media&f=test/test.m4a';
    [$code,$body,$headers] = mr_request($media); mr_assert($code === 200 && $body === '0123456789' && $headers['content-length'] === '10' && $headers['accept-ranges'] === 'bytes', 'Full media response');
    [$code,$body,$headers] = mr_request($media, 'HEAD'); mr_assert($code === 200 && $body === '' && $headers['content-length'] === '10', 'Media HEAD parity');
    foreach (['bytes=2-5' => ['2345','bytes 2-5/10'], 'bytes=-3' => ['789','bytes 7-9/10'], 'bytes=7-' => ['789','bytes 7-9/10']] as $range => [$wantBody,$wantRange]) {
        [$code,$body,$headers] = mr_request($media, 'GET', $range);
        mr_assert($code === 206 && $body === $wantBody && $headers['content-range'] === $wantRange && (int)$headers['content-length'] === strlen($wantBody), 'Range ' . $range);
    }
    [$code,$body,$headers] = mr_request($media, 'GET', 'bytes=20-30'); mr_assert($code === 416 && $body === '' && $headers['content-length'] === '0' && $headers['content-range'] === 'bytes */10', 'Unsatisfiable range has empty body');
    [$code,$body] = mr_request($media, 'GET', 'bytes=0-1,4-5'); mr_assert($code === 200 && $body === '0123456789', 'Multi-range keeps existing full-response fallback');
    [$code,$body,$headers] = mr_request($base . 'media&f=test/empty.m4a'); mr_assert($code === 200 && $body === '' && $headers['content-length'] === '0', 'Empty media response');
    [$code,$body,$headers] = mr_request($base . 'photo&f=test/test.jpg'); mr_assert($code === 200 && $body === 'photo-fixture' && $headers['content-length'] === '13', 'Photo GET');
    [$code,$body,$headers] = mr_request($base . 'photo&f=test/test.jpg', 'HEAD'); mr_assert($code === 200 && $body === '' && $headers['content-length'] === '13', 'Photo HEAD parity');
    foreach (['media' => 'media/test.m4a', 'photo' => 'photos/test.jpg'] as $api => $relative) {
        $path = $dir . '/projects/test/' . $relative; chmod($path, 0000); clearstatcache(true,$path);
        if (is_readable($path)) { $skips++; chmod($path,0600); continue; } // root may bypass mode bits.
        foreach (['GET','HEAD'] as $method) {
            [$code,$body,$headers] = mr_request($base . $api . '&f=test/' . basename($path), $method);
            mr_assert($code === 500 && $body === '' && $headers['content-length'] === '0' && $headers['cache-control'] === 'no-store' && !isset($headers['content-range']), "$api unreadable $method fails consistently before success headers");
        }
        chmod($path,0600);
    }
    echo "mediaresponsecheck: $checks checks passed; $skips permission cases skipped\n";
} finally {
    if (is_resource($server)) {proc_terminate($server);proc_close($server);}
    if (is_dir($dir)) mr_remove($dir);
}
