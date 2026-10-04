<?php
require_once __DIR__ . '/features.php';
function contrib_timestamp($value): int|false {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
    try {
        $date = new DateTimeImmutable($value); $errors = DateTimeImmutable::getLastErrors();
        return $errors && ($errors['warning_count'] || $errors['error_count']) ? false : $date->getTimestamp();
    } catch (Throwable $e) { return false; }
}
/** Codes and code-free access share enabled/start/end semantics. Null end means long-term. */
function contrib_window_state(array $policy, ?int $now = null): array {
    $now ??= time();
    $startRaw = $policy['starts_at'] ?? null; $endRaw = $policy['expires_at'] ?? null;
    $start = $startRaw === null ? null : contrib_timestamp($startRaw);
    $end = $endRaw === null ? null : contrib_timestamp($endRaw);
    $valid = $start !== false && $end !== false && ($start === null || $end === null || $end > $start);
    $state = ($policy['enabled'] ?? false) !== true || !$valid ? 'disabled'
        : ($start !== null && $now < $start ? 'scheduled' : ($end !== null && $now >= $end ? 'ended' : 'open'));
    return ['open' => $state === 'open', 'state' => $state,
        'starts_at' => $valid && $start !== null ? gmdate('c', $start) : null,
        'expires_at' => $valid && $end !== null ? gmdate('c', $end) : null, 'serverTime' => gmdate('c', $now)];
}
function contrib_free_policy(?array $meta): array {
    return is_array($meta['contributionAccess'] ?? null) ? $meta['contributionAccess'] : [];
}
function contrib_free_state(?array $meta, ?int $now = null): array {
    $policy = contrib_free_policy($meta);
    if (!souliong_module_on($meta, 'upload')) $policy['enabled'] = false;
    return contrib_window_state($policy, $now);
}
function contrib_free_open(array $cfg, string $project): bool {
    if (!preg_match('/^[a-z0-9_-]{1,40}$/D', $project)) return false;
    $path = $cfg['projects_dir'] . '/' . $project . '/meta.json';
    $meta = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    return contrib_free_state(is_array($meta) ? $meta : null)['open'];
}
function contrib_code_active(array $code, ?int $now = null): bool {
    $state = contrib_window_state(['enabled' => $code['enabled'] ?? true,
        'starts_at' => $code['starts_at'] ?? null, 'expires_at' => $code['expires_at'] ?? null], $now);
    $max = $code['max_uses'] ?? null;
    return $state['open'] && ($max === null || (int)($code['used_count'] ?? 0) < (int)$max);
}
function contrib_local_time(string $input): ?string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input, new DateTimeZone('Asia/Taipei'));
    if (!$date || $date->format('Y-m-d\TH:i') !== $input) return null;
    return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
}

/** Public status contains no code values or contributor secrets. */
function contrib_access_state(array $cfg, string $project, ?array $meta): array {
    $state = contrib_free_state($meta);
    $enabled = souliong_module_on($meta, 'upload');
    $contrib = souliong_contrib_cfg($meta);
    $boundaries = [];
    foreach (array_merge([contrib_free_policy($meta)], codes_load($cfg, $project)) as $policy) {
        if (!is_array($policy) || ($policy['enabled'] ?? true) !== true) continue;
        foreach (['starts_at', 'expires_at'] as $field) {
            $time = contrib_timestamp($policy[$field] ?? null);
            if ($time !== false && $time > time()) $boundaries[] = $time;
        }
    }
    return $state + ['codesAvailable' => $enabled && codes_active($cfg, $project) !== [],
        'next_change_at' => $boundaries ? gmdate('c', min($boundaries)) : null,
        'kinds' => $enabled ? $contrib['kinds'] : [], 'newSpot' => $enabled ? $contrib['newSpot'] : 'off'];
}
