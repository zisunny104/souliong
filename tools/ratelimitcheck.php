<?php
/** Fault-injected limiter storage checks; never uses projects/ or state/. */
if (($argv[1] ?? '') === '--child') {
    require_once dirname(__DIR__) . '/api/security.php';
    class RateFaultStream {
        public $context;
        private string $failure;
        public function stream_open($path, $mode, $options, &$opened): bool { $this->failure = $GLOBALS['argv'][2]; return $this->failure !== 'open'; }
        public function url_stat($path, $flags): array { return ['mode' => 0040777]; }
        public function stream_lock($operation): bool { return $operation !== LOCK_EX || $this->failure !== 'lock'; }
        public function stream_read($length): string { return ''; }
        public function stream_eof(): bool { return true; }
        public function stream_stat(): array { return ['size' => 0]; }
        public function stream_seek($offset, $whence = SEEK_SET): bool { return true; }
        public function stream_tell(): int { return 0; }
        public function stream_truncate($size): bool { return $this->failure !== 'truncate'; }
        public function stream_write($data): int { return $this->failure === 'write' ? 0 : strlen($data); }
        public function stream_flush(): bool { return $this->failure !== 'flush'; }
    }
    stream_wrapper_register('ratefault', RateFaultStream::class);
    register_shutdown_function(fn() => print("\nSTATUS:" . (http_response_code() ?: 200) . "\n"));
    rate_limit(['state_dir' => 'ratefault://isolated', 'rate_max' => $argv[2] === 'limit' ? 0 : 2]);
    echo 'allowed';
    exit;
}
foreach (['open', 'lock', 'truncate', 'write', 'flush', 'limit', 'ok'] as $case) {
    $p = proc_open([PHP_BINARY, __FILE__, '--child', $case], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($p);
    $expected = $case === 'ok' ? 200 : ($case === 'limit' ? 429 : 503);
    if ($code !== 0 || !str_contains($out, 'STATUS:' . $expected) || ($expected === 503 && (!str_contains($err, 'souliong rate-limit:') || str_contains($out, 'ratefault:') || str_contains($out, 'storage')))) {
        throw new RuntimeException("$case: $out\n$err");
    }
}
echo "限速儲存：開檔、鎖定、截斷、寫入及 flush 失敗回 503 並記錄；正常與 429 通過\n";
