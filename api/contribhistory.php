<?php
require_once __DIR__ . '/contribaccess.php';

/** Display-only period snapshots; they never grant submission access. */
function contrib_history_archive_access(array &$meta, array $next, ?int $now = null): void {
    $old = contrib_free_policy($meta);
    if ($old === $next) return;
    $recordedStart = $meta['contributionAccessOpenedAt'] ?? null;
    if (!empty($next['enabled']) && empty($next['starts_at'])) $meta['contributionAccessOpenedAt'] = gmdate('c', $now ?? time());
    else unset($meta['contributionAccessOpenedAt']);
    if (($old['enabled'] ?? false) !== true) return;
    $period = ['starts_at' => $old['starts_at'] ?? null, 'expires_at' => $old['expires_at'] ?? null,
        'recorded_start' => $recordedStart, 'changed_at' => gmdate('c', $now ?? time()), 'reason' => $next['enabled'] ? 'replaced' : 'closed'];
    $history = is_array($meta['contributionAccessHistory'] ?? null) ? $meta['contributionAccessHistory'] : [];
    $history[] = $period;
    $meta['contributionAccessHistory'] = array_slice($history, -100);
}

function contrib_history_archive_code(array $cfg, string $project, array $code, string $reason): void {
    $file = project_dir($cfg, $project) . '/meta.json';
    $meta = json_decode((string)@file_get_contents($file), true);
    if (!is_array($meta)) error_page(500, '儲存失敗', '無法保留投稿碼期間，請稍後再試。');
    $history = is_array($meta['contributionCodeHistory'] ?? null) ? $meta['contributionCodeHistory'] : [];
    $history[] = ['created' => $code['created'] ?? null, 'starts_at' => $code['starts_at'] ?? null,
        'expires_at' => $code['expires_at'] ?? null, 'changed_at' => gmdate('c'),
        'reason' => $reason, 'was_enabled' => ($code['enabled'] ?? true) === true,
        'label' => (string)($code['label'] ?? ''),
        'reference' => substr(hash('sha256', $project . ':' . ($code['created'] ?? '') . ':' . ($code['code'] ?? '')), 0, 12)];
    $meta['contributionCodeHistory'] = array_slice($history, -100);
    if (file_put_contents($file, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX) === false) {
        error_page(500, '儲存失敗', '無法保留投稿碼期間，請稍後再試。');
    }
}

function contrib_history_period_state(array $period, bool $historical = false, ?int $now = null): string {
    $now ??= time();
    $start = contrib_timestamp($period['starts_at'] ?? $period['recorded_start'] ?? $period['created'] ?? null);
    $end = contrib_timestamp($period['expires_at'] ?? null);
    $changed = contrib_timestamp($period['changed_at'] ?? null);
    if ($historical) return $start !== false && $changed !== false && $start > $changed ? 'cancelled' : 'ended';
    if (($period['enabled'] ?? true) !== true) return $start !== false && $start > $now ? 'cancelled' : 'disabled';
    if ($start !== false && $start > $now) return 'scheduled';
    return $end !== false && $end <= $now ? 'ended' : 'open';
}
