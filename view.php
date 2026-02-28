<?php
// тут выводим форму и графики; рассчитываем JSON-массивы из $rows

$rows      = fetchMetrics($selUsers, $periode);
$labelsArr = array_column($rows, 'label');
$labels    = json_encode($labelsArr);

// определяем набор метрик для раздела "Статистика по периодам"
$builtinCodes = array_keys(array_filter($metricTypes, function($v){ return !empty($v['column']); }));
// 1) по назначениям
$customAssigned = getAssignedCustomMetricsForUsers($selUsers);
// 2) по фактическим данным в выборке
$customFromData = [];
foreach ($rows as $row) {
    foreach ($row as $k => $v) {
        if ($k === 'period' || $k === 'label') continue;
        if (!isset($metricTypes[$k])) continue;
        if (in_array($k, $builtinCodes, true)) continue;
        if ((int)$v !== 0) { $customFromData[] = $k; }
    }
}
$customSet = array_values(array_unique(array_merge($customAssigned, $customFromData)));
if ($customSet) {
    // есть кастомные — показываем только их
    $chartMetricTypes = array_intersect_key($metricTypes, array_flip($customSet));
} else {
    // иначе только базовые
    $chartMetricTypes = array_intersect_key($metricTypes, array_flip($builtinCodes));
}

$datasets = [];
foreach ($chartMetricTypes as $code => $info) {
    // важный момент: здесь НЕ кодируем в JSON
    $datasets[$code] = array_column($rows, $code);
}

// получаем планы для отображаемых периодов
$planSeries = [];
foreach ($chartMetricTypes as $code => $info) {
    $planSeries[$code] = array_fill(0, count($labelsArr), null);
}
foreach ($labelsArr as $i => $label) {
    if ($periode === 'week') {
        $from = $label;
        $to   = date('Y-m-d', strtotime($label . ' +6 days'));
    } elseif ($periode === 'month') {
        $from = $label . '-01';
        $to   = date('Y-m-t', strtotime($from));
    } else {
        $from = $label;
        $to   = $label;
    }
    $plans = getPlansForUsersRange($selUsers, $from, $to);
    foreach ($plans as $code => $plan) {
        $planSeries[$code][$i] = (int)$plan;
    }
}

// определяем, какие метрики использовать в графиках
$usedMetrics = [];
foreach ($chartMetricTypes as $code => $info) {
    $hasPlan = false;
    foreach ($planSeries[$code] as $v) {
        if ($v !== null && $v > 0) { $hasPlan = true; break; }
    }
    if ($hasPlan) {
        $usedMetrics[] = $code;
        continue;
    }
    foreach ($rows as $r) {
        if ((int)($r[$code] ?? 0) !== 0) {
            $usedMetrics[] = $code;
            break;
        }
    }
}
if (!$usedMetrics) {
    $usedMetrics = array_keys($chartMetricTypes);
}

$usedMetricsJson = json_encode($usedMetrics);
$planSeriesJson  = json_encode($planSeries);
$datasetsJson    = json_encode($datasets);
$metricTypesJson = json_encode($chartMetricTypes);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Активности (Метрики)</title>
  <link rel="stylesheet" href="/aktivnosti/css/metrics.css">
  <script src="/aktivnosti/js/Chart.min.js"></script>
  <!-- Плагин для вывода числовых меток -->
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

  <style>
    .page-entity-wrap {
      padding: 20px;
      margin: 0;
      width: 100%;
      box-sizing: border-box;
    }
    .ui-entity-section {  
      background:#fff; border:1px solid #e5e5e5; border-radius:6px;
      padding:20px; margin-bottom:30px;
      box-shadow:0 2px 4px rgba(0,0,0,0.04);
    }
    .ui-entity-section-title { 
      font-size:18px; font-weight:600; color:#333;
      border-bottom:1px solid #eee; padding-bottom:10px; margin-bottom:20px;
    }
    .ui-ctl-group.grid-2 {
      display:grid;
      grid-template-columns: repeat(auto-fit,minmax(240px,1fr));
      gap:16px;
      align-items:end;
    }
    .ui-ctl {
      display:flex; flex-direction:column;
    }
    .ui-ctl-label-text { margin-bottom:6px; color:#555; font-size:14px; }
    .user-multi-select { display:flex; flex-wrap:wrap; gap:4px; padding:4px; border:1px solid #ccc; border-radius:4px; min-height:38px; }
    .user-multi-select input { border:none; outline:none; flex:1; min-width:120px; }
    .user-token { background:#eef; border-radius:3px; padding:2px 4px; display:flex; align-items:center; }
    .user-token .remove { margin-left:4px; cursor:pointer; }
    .charts-wrapper {
      display:flex;
      flex-wrap:wrap;
      gap:24px;
    }
    .chart-container {
      flex:1 1 300px;
      min-width:300px;
      position:relative;
      height:450px;
      background:#fafafa;
      border:1px solid #eee;
      border-radius:4px;
      padding:10px;
    }
    .chart-container canvas {
      position:absolute !important;
      top:0; left:0;
      width:100% !important;
      height:100% !important;
    }
  </style>
</head>
<body>
  <div class="page-entity-wrap">

    <div class="ui-entity-section" id="input-form">
      <div class="ui-entity-section-title">Ввод метрик</div>
      <form method="post" class="ui-ctl-group grid-2">
        <div class="ui-ctl">
          <div class="ui-ctl-label-text">Сотрудник</div>
          <input type="text" id="input-user" class="ui-ctl-element" list="users-list">
          <input type="hidden" name="user_id" id="input-user-id" value="<?=htmlspecialchars($currentInputUser)?>">
          <datalist id="users-list">
            <?php foreach($users as $id => $n): ?>
              <option data-id="<?=htmlspecialchars($id)?>" value="<?=htmlspecialchars($n)?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="ui-ctl">
          <div class="ui-ctl-label-text">Дата</div>
          <input type="date" name="date" class="ui-ctl-element" required>
        </div>
        <?php
        $inputMetrics = $inputMetrics ?: array_keys($metricTypes);
        foreach ($metricTypes as $code => $info):
          if (!in_array($code, $inputMetrics, true)) continue;
        ?>
        <div class="ui-ctl">
          <div class="ui-ctl-label-text"><?=htmlspecialchars($info['label'])?></div>
          <input type="number" name="<?=htmlspecialchars($code)?>" class="ui-ctl-element" required>
        </div>
        <?php endforeach; ?>
        <button class="ui-btn ui-btn-success" type="submit">Заполнить</button>
      </form>
    </div>

    <div class="ui-entity-section">
      <div class="ui-entity-section-title">Статистика по периодам</div>
      <form method="get" class="ui-ctl-group grid-2">
        <div class="ui-ctl">
          <div class="ui-ctl-label-text">Сотрудник</div>
          <div id="filter-users" class="user-multi-select"></div>
        </div>
        <div class="ui-ctl">
          <div class="ui-ctl-label-text">Период</div>
          <select name="periode" class="ui-ctl-element">
            <option value="day"   <?=($periode==='day'?'selected':'')?>>День</option>
            <option value="week"  <?=($periode==='week'?'selected':'')?>>Неделя</option>
            <option value="month" <?=($periode==='month'?'selected':'')?>>Месяц</option>
          </select>
        </div>
        <button class="ui-btn" type="submit">Показать</button>
      </form>

      <div class="charts-wrapper">
        <?php foreach ($usedMetrics as $code): ?>
          <div class="chart-container"><canvas id="chart-<?=htmlspecialchars($code)?>"></canvas></div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($isSettingsAdmin): ?>
      <div class="ui-entity-section">
        <div class="ui-entity-section-title">
          <a href="#" id="settings-toggle" style="text-decoration:none;">Настройки</a>
        </div>
        <div id="settings-content" style="display:none;">

          <div class="ui-entity-section" id="settings-assign" style="box-shadow:none;border:none;padding:0;">
            <div class="ui-entity-section-title">Закрепление кастомных метрик за сотрудником</div>
            <form method="get" class="ui-ctl-group" style="margin-bottom:12px;">
              <input type="text" id="assign-user-input" class="ui-ctl-element" list="users-list" value="<?=htmlspecialchars($users[$assignUser] ?? '')?>" placeholder="Выберите сотрудника">
              <input type="hidden" name="assign_user" id="assign-user-id" value="<?=htmlspecialchars($assignUser)?>">
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

          <div class="ui-entity-section" style="box-shadow:none;border:none;padding:0;">
            <div class="ui-entity-section-title">Кастомные метрики</div>
            <form method="post" class="ui-ctl-group" style="margin-bottom:20px;">
              <input type="hidden" name="form" value="types">
              <input type="text" name="new_label" class="ui-ctl-element" placeholder="Название новой метрики">
              <button class="ui-btn ui-btn-success" type="submit">Добавить</button>
            </form>
            <?php if (!empty($customTypes)): ?>
              <form method="post" class="ui-ctl-group" style="align-items:center;">
                <input type="hidden" name="form" value="types">
                <?php foreach ($customTypes as $t): ?>
                  <div class="settings-admin-item" style="gap:10px;">
                    <span style="min-width:160px;">Код: <b><?=htmlspecialchars($t['CODE'])?></b></span>
                    <span><?=htmlspecialchars($t['LABEL'])?></span>
                    <button class="remove-btn" style="display:inline;" name="remove[]" value="<?=htmlspecialchars($t['CODE'])?>" onclick="return confirm('Удалить метрику <?=htmlspecialchars($t['LABEL'])?>?');">Удалить</button>
                  </div>
                <?php endforeach; ?>
                <button class="ui-btn ui-btn-danger" type="submit">Сохранить удаления</button>
              </form>
            <?php endif; ?>
          </div>

          <div class="ui-entity-section" style="box-shadow:none;border:none;padding:0;">
            <div class="ui-entity-section-title">Права на настройки</div>
            <form method="post" class="ui-ctl-group">
              <input type="hidden" name="form" value="rights">
              <input type="text" id="new-admin" class="ui-ctl-element" list="users-list" placeholder="Выберите пользователя">
              <input type="hidden" name="new_admin_id" id="new-admin-id">
              <button class="ui-btn ui-btn-success" type="submit">Добавить</button>
              <div class="settings-admin-list">
                <?php foreach($settingsAdmins as $id): if ($id == 502) continue; ?>
                  <div class="settings-admin-item">
                    <?=htmlspecialchars($users[$id] ?? $id)?>
                    <button class="remove-btn" type="submit" name="remove_admin" value="<?=htmlspecialchars($id)?>">&times;</button>
                    <input type="hidden" name="admin_ids[]" value="<?=htmlspecialchars($id)?>">
                  </div>
                <?php endforeach; ?>
              </div>
            </form>
          </div>

          <div class="ui-entity-section" id="settings-plans" style="box-shadow:none;border:none;padding:0;">
            <div class="ui-entity-section-title">План метрик сотрудников</div>
            <div id="settings-filter-form" class="ui-ctl-group" style="margin-bottom:20px;">
              <div class="ui-ctl" style="min-width:320px;">
                <div class="ui-ctl-label-text">Сотрудники</div>
                <div id="settings-users" class="user-multi-select"></div>
                <input type="hidden" id="settings-user" value="<?=htmlspecialchars(implode(',', $settingsUsers ?? []))?>">
              </div>
              <input type="date" id="settings-period-from" class="ui-ctl-element" value="<?=htmlspecialchars($settingsPeriodFrom)?>">
              <input type="date" id="settings-period-to" class="ui-ctl-element" value="<?=htmlspecialchars($settingsPeriodTo)?>">
              <button class="ui-btn" id="settings-apply-btn" type="button">Выбрать</button>
            </div>

            <form method="post" class="metrics-plan-form">
              <input type="hidden" name="form" value="metrics">
              <?php foreach(($settingsUsers ?? []) as $sid): ?>
                <input type="hidden" name="employees[]" value="<?=htmlspecialchars((string)$sid)?>">
              <?php endforeach; ?>
              <input type="hidden" name="period_from" id="plan-period-from" value="<?=htmlspecialchars($settingsPeriodFrom)?>">
              <input type="hidden" name="period_to" id="plan-period-to" value="<?=htmlspecialchars($settingsPeriodTo)?>">
              <?php foreach(($planMetricTypes ?? $metricTypes) as $code => $info): ?>
                <label class="metric-item">
                  <?=htmlspecialchars($info['label'])?>
                  <input type="number" name="plan[<?=htmlspecialchars($code)?>]" class="ui-ctl-element" style="width:120px;" value="<?=isset($settingsPlans[$code])?htmlspecialchars($settingsPlans[$code]):''?>" placeholder="План">
                </label>
              <?php endforeach; ?>
                <button class="ui-btn ui-btn-success" type="submit">Сохранить</button>
              </form>
              <?php if (!empty($settingsUsers) && count($settingsUsers)===1): ?>
                <form method="post" class="ui-ctl-group" style="margin-top:10px;">
                  <input type="hidden" name="form" value="clear_plans">
                  <input type="hidden" name="employee" value="<?=htmlspecialchars((string)$settingsUsers[0])?>">
                  <button class="ui-btn ui-btn-danger" type="submit" onclick="return confirm('Удалить все планы сотрудника?');">Удалить все планы сотрудника</button>
                </form>
              <?php endif; ?>
            </div>

        </div>
      </div>
    <?php endif; ?>

  </div>

  <script>
document.addEventListener('DOMContentLoaded', () => {
  // регистрируем плагин datalabels
  Chart.register(ChartDataLabels);

  const userMap = <?=json_encode($users)?>;
  const currentFilterIds = <?=json_encode($currentFilterUsers)?>;
  const labels = <?=$labels?>;
  const datasets = <?=$datasetsJson?>;
  const planSeries = <?=$planSeriesJson?>;
  const metricTypes = <?=$metricTypesJson?>;
  const usedMetrics = <?=$usedMetricsJson?>;

  // toggle настроек (если блок есть)
  const settingsToggle = document.getElementById('settings-toggle');
  if (settingsToggle) {
    settingsToggle.addEventListener('click', (e) => {
      e.preventDefault();
      const block = document.getElementById('settings-content');
      if (block) {
        block.style.display = block.style.display === 'none' ? 'block' : 'none';
      }
    });
  }
  // Открываем блок настроек, если пришли с якорем или параметром
  const ensureSettingsOpen = () => {
    const hashes = ['#settings-plans', '#settings-assign', '#settings'];
    const needOpen = hashes.includes(location.hash) || new URLSearchParams(location.search).get('open_settings') === '1';
    if (needOpen) {
      const block = document.getElementById('settings-content');
      if (block) block.style.display = 'block';
    }
  };
  ensureSettingsOpen();

  const bindUserPicker = (textId, hiddenId) => {
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
  bindUserPicker('input-user', 'input-user-id');
  bindUserPicker('new-admin', 'new-admin-id');
  // settings users multi-select with reload
  bindUserPicker('assign-user-input', 'assign-user-id');

  // При выборе сотрудника во вводе — перезагрузка с input_user
  const iu = document.getElementById('input-user');
  const iuId = document.getElementById('input-user-id');
  if (iu && iuId) {
    iu.addEventListener('change', () => {
      if (!iuId.value) return; // только если выбран из списка
      const params = new URLSearchParams(window.location.search);
      params.set('input_user', iuId.value);
      window.location.href = window.location.pathname + '?' + params.toString() + '#input-form';
    });
  }

  // При выборе сотрудника в "Закрепление кастомных метрик" — перезагрузка с assign_user
  const au = document.getElementById('assign-user-input');
  const auId = document.getElementById('assign-user-id');
  if (au && auId) {
    au.addEventListener('change', () => {
      if (!auId.value) return;
      const params = new URLSearchParams(window.location.search);
      params.set('assign_user', auId.value);
      window.location.href = window.location.pathname + '?' + params.toString() + '#settings-assign';
    });
  }

  const syncPlanFields = () => {
    const userHidden   = document.getElementById('settings-user-id');
    const periodFromEl = document.getElementById('settings-period-from');
    const periodToEl   = document.getElementById('settings-period-to');
    const planUser     = document.getElementById('plan-employee');
    const planFrom     = document.getElementById('plan-period-from');
    const planTo       = document.getElementById('plan-period-to');
    if (planUser && userHidden)   planUser.value = userHidden.value;
    if (planFrom && periodFromEl) planFrom.value = periodFromEl.value;
    if (planTo && periodToEl)     planTo.value   = periodToEl.value;
  };

  ['settings-user-input', 'settings-period-from', 'settings-period-to']
    .forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener('change', syncPlanFields);
      }
    });

  syncPlanFields();

  // Build multi-select for settings users; reload on change
  const settingsUsersWrap = document.getElementById('settings-users');
  const settingsUsersHidden = document.getElementById('settings-user');
  const settingsApplyBtn = document.getElementById('settings-apply-btn');
  if (settingsUsersWrap && settingsUsersHidden) {
    const selectedIds = (settingsUsersHidden.value || '').split(',').filter(Boolean);
    const input = document.createElement('input');
    input.setAttribute('list', 'users-list');
    input.className = 'ui-ctl-element';
    settingsUsersWrap.appendChild(input);

    const addToken = (id) => {
      if (!id || settingsUsersWrap.querySelector('input[type="hidden"][value="'+id+'"]')) return;
      const span = document.createElement('span');
      span.className = 'user-token';
      span.textContent = userMap[id] || id;
      const rm = document.createElement('span');
      rm.textContent = '×';
      rm.className = 'remove';
      rm.addEventListener('click', () => { span.remove(); onChange(); });
      span.appendChild(rm);
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.value = id;
      span.appendChild(hidden);
      settingsUsersWrap.insertBefore(span, input);
    };

    const collectIds = () => Array.from(settingsUsersWrap.querySelectorAll('input[type="hidden"]')).map(i => i.value);

    const apply = () => {
      const ids = collectIds();
      const params = new URLSearchParams(window.location.search);
      params.set('settings_user', ids.join(','));
      const pf = document.getElementById('settings-period-from');
      const pt = document.getElementById('settings-period-to');
      if (pf) params.set('period_from', pf.value || '');
      if (pt) params.set('period_to', pt.value || '');
      window.location.href = window.location.pathname + '?' + params.toString() + '#settings-plans';
    };

    input.addEventListener('change', () => {
      const val = input.value;
      const opt = Array.from(document.querySelectorAll('#users-list option')).find(o => o.value === val);
      if (opt) {
        addToken(opt.dataset.id);
        input.value = '';
      }
    });

    if (settingsApplyBtn) {
      settingsApplyBtn.addEventListener('click', apply);
    }

    // удаление токена не применяет сразу — ждём нажатие "Выбрать"

    selectedIds.forEach(id => addToken(id));
  }

  // ------------------------ multi user select -------------------------
  const filterWrap = document.getElementById('filter-users');
  if (filterWrap) {
    const addToken = (id) => {
      if (!id || filterWrap.querySelector('input[value="'+id+'"]')) return;
      const span = document.createElement('span');
      span.className = 'user-token';
      span.textContent = userMap[id] || id;
      const rm = document.createElement('span');
      rm.textContent = '×';
      rm.className = 'remove';
      rm.addEventListener('click', () => span.remove());
      span.appendChild(rm);
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'user[]';
      hidden.value = id;
      span.appendChild(hidden);
      filterWrap.insertBefore(span, filterInput);
    };

    const filterInput = document.createElement('input');
    filterInput.setAttribute('list', 'users-list');
    filterInput.className = 'ui-ctl-element';
    filterWrap.appendChild(filterInput);

    filterInput.addEventListener('change', () => {
      const val = filterInput.value;
      const opt = Array.from(document.querySelectorAll('#users-list option'))
                       .find(o => o.value === val);
      if (opt) {
        addToken(opt.dataset.id);
        filterInput.value = '';
      }
    });

    currentFilterIds.forEach(id => addToken(String(id)));
  }

  const colors = ['#36a2eb', '#ff6384', '#4bc0c0', '#ff9f40', '#9966ff'];

  const calcTrend = (arr) => {
    const n = arr.length;
    if (n < 6) return null;
    let sumX=0, sumY=0, sumXY=0, sumXX=0;
    for (let i=0;i<n;i++) {
      const x=i+1;
      const y=Number(arr[i]);
      sumX+=x; sumY+=y; sumXY+=x*y; sumXX+=x*x;
    }
    const k=(n*sumXY-sumX*sumY)/(n*sumXX-sumX*sumX) || 0;
    const b=(sumY-k*sumX)/n;
    return arr.map((_,i)=>k*(i+1)+b);
  };

  usedMetrics.forEach((code, idx) => {
    const data = (datasets[code] || []).map(Number);
    const trend = calcTrend(data);
    const planData = (planSeries[code] || []).map(v => v === null ? null : Number(v));

    const ds = [{
      label:       metricTypes[code].label,
      data:        data,
      fill:        false,
      tension:     0.3,
      pointRadius: 5,
      borderWidth: 2,
      borderColor: colors[idx % colors.length],
      backgroundColor: colors[idx % colors.length]
    }];

    if (planData.some(v => v !== null)) {
      ds.push({
        label:        'План',
        data:         planData,
        borderColor:  '#000',
        borderWidth:  2,
        pointRadius:  0,
        fill:         false,
        tension:      0,
        stepped:      true,
        spanGaps:     false,
        datalabels:   { display:false }
      });
    }

    if (trend) {
      ds.push({
        label:        'Тренд',
        data:         trend,
        borderColor:  '#888',
        borderWidth:  2,
        borderDash:   [6,4],
        pointRadius:  0,
        fill:         false,
        tension:      0,
        datalabels:   { display:false }
      });
    }

    new Chart(
      document.querySelector('#chart-' + code).getContext('2d'),
      {
        type: 'line',
        data: { labels, datasets: ds },
        options: {
          responsive:          true,
          maintainAspectRatio: false,
          scales: { y: { beginAtZero: true } },
          plugins: {
            legend: { display: true },
            datalabels: {
              color:    '#333',
              anchor:   'end',
              align:    'top',
              font:     { weight:'bold', size:12 },
              formatter:value=>value
            },
            tooltip: { enabled: true }
          }
        }
      }
    );
  });
});
</script>

</body>
</html>
