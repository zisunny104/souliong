<?php
if (!defined('SOULIONG_MANAGER_CONTEXT')) { http_response_code(404); exit; }
$accessPolicy = contrib_free_policy($meta);
$accessState = contrib_free_state($meta);
$accessTime = static function ($value) {
    try { return $value ? (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d\TH:i') : ''; }
    catch (Throwable $e) { return ''; }
};
$accessMode = !empty($accessPolicy['starts_at']) && strtotime($accessPolicy['starts_at']) > time() ? 'scheduled' : (!empty($accessPolicy['expires_at']) || !empty($accessPolicy['starts_at']) ? 'period' : 'long');
$newSpotPolicy = souliong_contrib_cfg($meta)['newSpot'];
?>
<form method="post" class="contrib-access-form" data-contribution-access>
  <input type="hidden" name="action" value="contribaccess">
  <input type="hidden" name="project" value="<?= $esc($p) ?>">
  <input type="hidden" name="csrf" value="<?= $esc_csrf ?>">
  <input type="hidden" name="contrib_newspot_submitted" value="1">
  <label><input type="checkbox" name="contrib_free_enabled" <?= !empty($accessPolicy['enabled']) ? 'checked' : '' ?>> <?= $t('contribution_free_switch') ?></label>
  <p class="hint"><?= $t('contribution_current_state') ?><?= $t('contribution_period_' . $accessState['state']) ?><?= !souliong_module_on($meta, 'upload') ? ' · ' . $t('contribution_upload_disabled') : '' ?></p>
  <div class="contrib-access-body" <?= empty($accessPolicy['enabled']) ? 'hidden' : '' ?>>
    <p class="hint"><?= $t('contribution_free_hint') ?></p>
    <label><?= $t('contribution_period_mode') ?>
      <select name="contrib_free_mode">
      <?php foreach (['long', 'period', 'scheduled'] as $mode): ?><option value="<?= $mode ?>" <?= $accessMode === $mode ? 'selected' : '' ?>><?= $t('contribution_mode_' . $mode) ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="contrib-access-dates" <?= $accessMode === 'long' ? 'hidden' : '' ?>>
      <label><?= $t('contribution_start_label') ?><input type="datetime-local" name="contrib_free_start" value="<?= $esc($accessTime($accessPolicy['starts_at'] ?? null)) ?>"></label>
      <label><?= $t('contribution_end_label') ?><input type="datetime-local" name="contrib_free_end" value="<?= $esc($accessTime($accessPolicy['expires_at'] ?? null)) ?>"></label>
    </div>
    <label><input type="checkbox" name="contrib_allow_newspot" <?= $newSpotPolicy === 'contributor' ? 'checked' : '' ?>> <?= $t('contribution_allow_newspot') ?></label>
    <p class="hint"><?= $t('contribution_newspot_shared_hint') ?></p>
    <?php if ($newSpotPolicy === 'admin'): ?><p class="hint"><?= $t('contribution_admin_newspot_kept') ?></p><?php endif; ?>
  </div>
  <div class="contrib-access-actions"><button class="btn" type="submit"><?= $t('contribution_save') ?></button><a class="btn" target="_blank" rel="noopener" href="<?= $esc(Route::map($p) . '?embed=1&ui=submit') ?>"><?= $t('contribution_embed_preview') ?></a></div>
</form>
<script>
(function () {
  var forms = document.querySelectorAll('[data-contribution-access]');
  forms.forEach(function (form) {
    if (form.dataset.ready) return; form.dataset.ready = '1';
    var toggle = form.elements.contrib_free_enabled, mode = form.elements.contrib_free_mode;
    var dates = form.querySelector('.contrib-access-dates'), body = form.querySelector('.contrib-access-body');
    function updateDates() {
      dates.hidden = mode.value === 'long';
      form.elements.contrib_free_start.required = toggle.checked && mode.value === 'scheduled';
      form.elements.contrib_free_end.required = toggle.checked && mode.value === 'period';
    }
    function update() {
      body.hidden = !toggle.checked; updateDates();
      var codes = form.closest('.contribution-settings').querySelector('.contribution-codes');
      if (toggle.checked && codes) codes.open = false;
    }
    toggle.addEventListener('change', update);
    mode.addEventListener('change', updateDates);
    updateDates();
    form.addEventListener('submit', function () {
      if (mode.value === 'long' && toggle.checked) { form.elements.contrib_free_start.value = ''; form.elements.contrib_free_end.value = ''; }
    });
  });
})();
</script>
