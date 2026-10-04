<?php
require_once __DIR__ . '/features.php';

/** Require an absolute ISO timestamp; hosting timezone must never affect access. */
function public_photo_timestamp($value): int|false
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
    try {
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        return $errors && ($errors['warning_count'] || $errors['error_count']) ? false : $date->getTimestamp();
    } catch (Throwable $e) { return false; }
}

/** Public photo access is an explicit, finite interval; malformed settings fail closed. */
function public_photo_state(?array $meta, ?int $now = null): array
{
    $policy = is_array($meta['publicPhoto'] ?? null) ? $meta['publicPhoto'] : [];
    $start = public_photo_timestamp($policy['startsAt'] ?? null);
    $end = public_photo_timestamp($policy['endsAt'] ?? null);
    $enabled = ($policy['enabled'] ?? false) === true && $start !== false && $end !== false && $end > $start
        && souliong_module_on($meta, 'upload') && in_array('photo', souliong_contrib_cfg($meta)['kinds'], true);
    $now ??= time();
    $state = !$enabled ? 'disabled' : ($now < $start ? 'scheduled' : ($now >= $end ? 'ended' : 'open'));
    return ['open' => $state === 'open', 'state' => $state,
        'startsAt' => $enabled ? gmdate('c', $start) : null, 'endsAt' => $enabled ? gmdate('c', $end) : null,
        'serverTime' => gmdate('c', $now)];
}

function public_photo_open(array $cfg, string $project): bool
{
    if (!preg_match('/^[a-z0-9_-]{1,40}$/D', $project)) return false;
    $path = $cfg['projects_dir'] . '/' . $project . '/meta.json';
    $meta = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    return public_photo_state(is_array($meta) ? $meta : null)['open'];
}

/** datetime-local is interpreted in Taipei, independent of the hosting server timezone. */
function public_photo_local_time(string $input): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input, new DateTimeZone('Asia/Taipei'));
    if (!$date || $date->format('Y-m-d\TH:i') !== $input) return null;
    return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
}
