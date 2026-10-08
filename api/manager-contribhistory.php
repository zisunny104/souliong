<?php
if (!defined('SOULIONG_MANAGER_CONTEXT')) { http_response_code(404); exit; }
$periodGroups = ['open' => [], 'scheduled' => [], 'ended' => []];
$currentAccess = contrib_free_policy($meta);
if ($currentAccess) $currentAccess['recorded_start'] = $meta['contributionAccessOpenedAt'] ?? null;
$addPeriod = static function ($row, $kind, $historical) use (&$periodGroups, $meta) {
    if (!is_array($row)) return;
    $state = contrib_history_period_state($row, $historical);
    if (!$historical && $state === 'open' && !souliong_module_on($meta, 'upload')) $state = 'disabled';
    $bucket = in_array($state, ['open', 'scheduled'], true) ? $state : 'ended';
    $periodGroups[$bucket][] = ['period' => $row, 'kind' => $kind, 'state' => $state, 'historical' => $historical];
};
if ($currentAccess) $addPeriod($currentAccess, 'free', false);
foreach (array_reverse(is_array($meta['contributionAccessHistory'] ?? null) ? $meta['contributionAccessHistory'] : []) as $period) $addPeriod($period, 'free', true);
foreach ($codesList as $period) $addPeriod($period, 'code', false);
foreach (array_reverse(is_array($meta['contributionCodeHistory'] ?? null) ? $meta['contributionCodeHistory'] : []) as $period) $addPeriod($period, 'code', true);
$periodDate = static function ($value) use ($t) {
    if (!$value || contrib_timestamp($value) === false) return $t('contribution_time_unknown');
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y/m/d H:i');
};
?>
<details class="contribution-history">
  <summary><?= $t('contribution_history_heading') ?></summary>
  <p class="hint"><?= $t('contribution_history_hint') ?></p>
  <div class="contribution-history-list">
  <?php foreach ($periodGroups as $group => $periods): if (!$periods) continue; ?>
    <h4><?= $t('contribution_history_' . $group) ?></h4>
    <?php foreach ($periods as $item): $period = $item['period'];
      $end = $period['expires_at'] ?? null;
      if ($item['historical'] && $item['state'] !== 'cancelled' && !empty($period['changed_at']) && (!$end || strtotime($period['changed_at']) < strtotime($end))) $end = $period['changed_at'];
      $start = $period['starts_at'] ?? $period['recorded_start'] ?? ($item['kind'] === 'code' ? ($period['created'] ?? null) : null);
    ?>
    <div class="contribution-period">
      <strong><?= $t($item['kind'] === 'free' ? 'contribution_free_switch' : 'contrib_code') ?></strong>
      <?php if ($item['kind'] === 'code' && !empty($period['label'])): ?> · <?= $esc($period['label']) ?><?php endif; ?>
      <span class="hint"> · <?= $t('contribution_period_' . $item['state']) ?></span>
      <div class="hint"><?= $esc($periodDate($start)) ?> — <?= $esc($end ? $periodDate($end) : $t('contribution_no_end')) ?></div>
      <?php if ($item['state'] === 'cancelled'): ?><div class="hint"><?= $t('contribution_cancelled_hint') ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </div>
</details>
