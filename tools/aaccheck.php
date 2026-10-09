<?php
// 產生真實 ADTS AAC，確認 MIME、無損換容器與沒有 FFmpeg 的回退。
require dirname(__DIR__) . '/api/uploadlib.php';
function ac_run(array $args): string {
    $p = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('process failed');
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) fclose($pipe);
    if (proc_close($p) !== 0) throw new RuntimeException($err);
    return $out;
}
function ac_check(bool $ok, string $msg): void { if (!$ok) throw new RuntimeException($msg); }
$src = tempnam(sys_get_temp_dir(), 'aac-test-'); $packed = null;
try {
    ac_run(['ffmpeg','-y','-hide_banner','-loglevel','error','-f','lavfi','-i','sine=frequency=440:duration=1','-c:a','aac','-f','adts',$src]);
    $allowed = souliong_kinds()['audio']['mimes']; $mime = detect_mime($src);
    ac_check(($allowed[$mime] ?? null) === 'aac', 'actual AAC MIME accepted');
    $packed = uploadlib_compress_media(['compress_media'=>false], $src, 'audio', $allowed, $mime);
    ac_check($packed !== null && $packed['ext'] === 'm4a' && $packed['mime'] === 'audio/mp4', 'small AAC remuxes with compression off');
    $before = ac_run(['ffmpeg','-v','error','-i',$src,'-map','0:a:0','-f','hash','-hash','sha256','-']);
    $after = ac_run(['ffmpeg','-v','error','-i',$packed['tmp'],'-map','0:a:0','-f','hash','-hash','sha256','-']);
    ac_check($before === $after, 'decoded audio unchanged');
    $script = 'require '.var_export(dirname(__DIR__).'/api/uploadlib.php',true).'; $p=uploadlib_compress_media(["ffmpeg_bin"=>"/nonexistent/souliong-ffmpeg"],'.var_export($src,true).',"audio",souliong_kinds()["audio"]["mimes"]); exit($p===null?0:1);';
    ac_run([PHP_BINARY,'-r',$script]);
    echo "PASS: 真實 AAC MIME、小檔及壓縮關閉仍換 M4A、音訊內容不變、缺少 FFmpeg 回退原檔\n";
} finally { @unlink($src); if ($packed) @unlink($packed['tmp']); }
