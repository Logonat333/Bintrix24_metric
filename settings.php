<?php
\Bitrix\Main\Page\Asset::getInstance()->addCss('/aktivnosti/css/metrics.css');
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');
$APPLICATION->SetTitle('Настройки метрик');

include __DIR__ . '/config.php';
include __DIR__ . '/functions.php';

ensureTable();

if (!isSettingsAdmin((int)$USER->GetID())) {
    echo 'Доступ запрещен';
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    return;
}

saveSettingsRights();
deleteUserMetricPlans();
saveUserMetricPlans();
saveCustomMetricTypes();
saveCustomMetricAssignments();
saveMetricUsers();

$users       = getActiveUsers();
$admins      = getSettingsAdmins();
$metricTypes = metricMap();
// список кастомных метрик (для управления)
global $DB;
$customTypes = [];
$res = $DB->Query("SELECT CODE, LABEL FROM b_aktivnosti_metric_types WHERE BUILTIN=0 ORDER BY CODE");
while ($row = $res->Fetch()) { $customTypes[] = $row; }
$assignedByCode = getAssignedUsersByMetric();

$selUser    = isset($_GET['user']) ? (int)$_GET['user'] : (int)array_key_first($users);
$periodFrom = $_GET['period_from'] ?? date('Y-m-01');
$periodTo   = $_GET['period_to'] ?? date('Y-m-t');
$plans      = getUserMetricPlans($selUser, $periodFrom, $periodTo);
$assignUser = isset($_GET['assign_user']) ? (int)$_GET['assign_user'] : (int)array_key_first($users);
$assignCodes = getUserAssignedCustomMetrics($assignUser);
?>
<div class="page-entity-wrap">
  <datalist id="users-list">
    <?php foreach($users as $id => $name): ?>
      <option data-id="<?=htmlspecialchars($id)?>" value="<?=htmlspecialchars($name)?>"></option>
    <?php endforeach; ?>
  </datalist>

  <div class="ui-entity-section">
    <div class="ui-entity-section-title">Права на настройки</div>
    <form method="post" class="ui-ctl-group">
      <input type="hidden" name="form" value="rights">
      <input type="text" id="new-admin" class="ui-ctl-element" list="users-list" placeholder="Выберите пользователя">
      <input type="hidden" name="new_admin_id" id="new-admin-id">
      <button class="ui-btn ui-btn-success" type="submit">Добавить</button>
      <div class="settings-admin-list">
        <?php foreach($admins as $id): if ($id == 502) continue; ?>
          <div class="settings-admin-item">
            <?=htmlspecialchars($users[$id] ?? $id)?>
            <button class="remove-btn" type="submit" name="remove_admin" value="<?=htmlspecialchars($id)?>">&times;</button>
            <input type="hidden" name="admin_ids[]" value="<?=htmlspecialchars($id)?>">
          </div>
        <?php endforeach; ?>
      </div>
    </form>
  </div>

  <div class="ui-entity-section">
    <div class="ui-entity-section-title">Закрепление кастомных метрик за сотрудником</div>
    <form method="get" class="ui-ctl-group" style="margin-bottom:12px;">
      <input type="text" id="assign-user-input" class="ui-ctl-element" list="users-list" value="<?=htmlspecialchars($users[$assignUser] ?? '')?>" placeholder="Выберите сотрудника">
      <input type="hidden" name="assign_user" id="assign-user-id" value="<?=htmlspecialchars($assignUser)?>">
      <button class="ui-btn" type="submit">Выбрать</button>
    </form>
    <form method="post" class="ui-ctl-group" style="align-items:flex-start;">
      <input type="hidden" name="form" value="assign_custom">
      <input type="hidden" name="users[]" value="<?=htmlspecialchars($assignUser)?>">
      <div class="ui-ctl-label-text">Выберите кастомные метрики для сотрудника</div>
      <div>
        <?php foreach ($customTypes as $t): $c=$t['CODE']; ?>
          <label style="display:inline-flex;align-items:center;gap:6px;margin:4px 12px 4px 0;">
            <input type="checkbox" name="codes[]" value="<?=htmlspecialchars($c)?>" <?=in_array($c, $assignCodes, true)?'checked':''?>>
            <?=htmlspecialchars($t['LABEL'])?>
            <span style="color:#888;">(<?=htmlspecialchars($c)?>)</span>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="align-self:end;">
        <button class="ui-btn ui-btn-success" type="submit">Сохранить</button>
      </div>
    </form>
  </div>

  <div class="ui-entity-section">
    <div class="ui-entity-section-title">Кастомные метрики</div>
    <form method="post" class="ui-ctl-group" style="margin-bottom:20px;">
      <input type="hidden" name="form" value="types">
      <input type="text" name="new_label" class="ui-ctl-element" placeholder="Название новой метрики">
      <button class="ui-btn ui-btn-success" type="submit">Добавить</button>
    </form>

    <?php if ($customTypes): ?>
      <form method="post" class="ui-ctl-group" style="align-items:center;">
        <input type="hidden" name="form" value="types">
        <?php foreach ($customTypes as $t): $code=$t['CODE']; $codeEsc=htmlspecialchars($code); ?>
          <div class="settings-admin-item" style="gap:10px; flex-wrap:wrap; align-items:flex-start;">
            <div style="min-width:240px;">
              <div><span style="min-width:160px;">Код: <b><?=htmlspecialchars($code)?></b></span></div>
              <div><?=htmlspecialchars($t['LABEL'])?></div>
              <button class="remove-btn" style="display:inline;" name="remove[]" value="<?=htmlspecialchars($code)?>" onclick="return confirm('Удалить метрику <?=htmlspecialchars($t['LABEL'])?>?');">Удалить метрику</button>
            </div>

            <div style="flex:1; min-width:300px;">
              <div class="ui-ctl-label-text">Назначенные сотрудники</div>
              <div style="display:flex; flex-wrap:wrap; gap:6px;">
                <?php foreach (($assignedByCode[$code] ?? []) as $uid): ?>
                  <form method="post" style="display:inline-flex; gap:6px; align-items:center; background:#eef; border-radius:3px; padding:2px 4px;">
                    <span><?=htmlspecialchars($users[$uid] ?? $uid)?></span>
                    <input type="hidden" name="form" value="metric_users">
                    <input type="hidden" name="metric_code" value="<?=$codeEsc?>">
                    <input type="hidden" name="remove_user_id" value="<?=htmlspecialchars((string)$uid)?>">
                    <button class="remove-btn" style="display:inline;" onclick="return confirm('Убрать сотрудника?');">&times;</button>
                  </form>
                <?php endforeach; ?>
              </div>

              <form method="post" class="ui-ctl-group" style="margin-top:8px; align-items:end;">
                <input type="hidden" name="form" value="metric_users">
                <input type="hidden" name="metric_code" value="<?=$codeEsc?>">
                <div class="ui-ctl">
                  <div class="ui-ctl-label-text">Добавить сотрудника</div>
                  <input type="text" id="mu-<?=$codeEsc?>" class="ui-ctl-element" list="users-list" placeholder="ФИО">
                  <input type="hidden" name="add_user_id" id="mu-id-<?=$codeEsc?>">
                </div>
                <button class="ui-btn ui-btn-success" type="submit">Добавить</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
        <button class="ui-btn ui-btn-danger" type="submit">Сохранить удаления</button>
      </form>
    <?php endif; ?>
  </div>

  

  <div class="ui-entity-section">
    <div class="ui-entity-section-title">План метрик сотрудников</div>
    <form method="get" class="ui-ctl-group" style="margin-bottom:20px;">
      <input type="text" id="settings-user-input" class="ui-ctl-element" list="users-list" value="<?=htmlspecialchars($users[$selUser] ?? '')?>" placeholder="Выберите пользователя">
      <input type="hidden" name="user" id="settings-user-id" value="<?=htmlspecialchars($selUser)?>">
      <input type="date" name="period_from" class="ui-ctl-element" value="<?=htmlspecialchars($periodFrom)?>">
      <input type="date" name="period_to" class="ui-ctl-element" value="<?=htmlspecialchars($periodTo)?>">
      <button class="ui-btn" type="submit">Выбрать</button>
    </form>

    <form method="post" class="metrics-plan-form">
      <input type="hidden" name="form" value="metrics">
      <input type="hidden" name="employee" value="<?=htmlspecialchars($selUser)?>">
      <input type="hidden" name="period_from" value="<?=htmlspecialchars($periodFrom)?>">
      <input type="hidden" name="period_to" value="<?=htmlspecialchars($periodTo)?>">
      <?php foreach($metricTypes as $code => $info): ?>
        <label class="metric-item">
          <?=htmlspecialchars($info['label'])?>
          <input type="number" name="plan[<?=htmlspecialchars($code)?>]" class="ui-ctl-element" style="width:120px;" value="<?=isset($plans[$code])?htmlspecialchars($plans[$code]):''?>" placeholder="План">
        </label>
      <?php endforeach; ?>
      <button class="ui-btn ui-btn-success" type="submit">Сохранить</button>
    </form>
    <form method="post" class="ui-ctl-group" style="margin-top:10px;">
      <input type="hidden" name="form" value="clear_plans">
      <input type="hidden" name="employee" value="<?=htmlspecialchars($selUser)?>">
      <button class="ui-btn ui-btn-danger" type="submit" onclick="return confirm('Удалить все планы сотрудника?');">Удалить все планы сотрудника</button>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const userMap = <?=json_encode($users)?>;
  const bindPicker = (textId, hiddenId) => {
    const input = document.getElementById(textId);
    const hidden = document.getElementById(hiddenId);
    if (!input || !hidden) return;
    input.value = userMap[hidden.value] || input.value;
    input.addEventListener('input', () => {
      const val = input.value;
      const opt = Array.from(document.querySelectorAll('#users-list option'))
                       .find(o => o.value === val);
      hidden.value = opt ? opt.dataset.id : '';
    });
  };
  bindPicker('new-admin', 'new-admin-id');
  bindPicker('settings-user-input', 'settings-user-id');
  bindPicker('assign-user-input', 'assign-user-id');
  // binders for per-metric add user inputs
  <?php foreach ($customTypes as $t): $code=htmlspecialchars($t['CODE']); ?>
  bindPicker('mu-<?=$code?>', 'mu-id-<?=$code?>');
  <?php endforeach; ?>
  // per-metric add user binders already set above
});
</script>
<?php
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
