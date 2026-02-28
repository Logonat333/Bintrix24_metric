<?php
// подтягиваем Bitrix prolog, если файл вызван напрямую
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
}

// ошибки для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// автосоздание папок для JS/CSS
$base = __DIR__;
foreach (['js','css'] as $d) {
    if (!is_dir("$base/$d")) mkdir("$base/$d", 0755, true);
}

// Chart.js из CDN, если ещё нет
$chart = "$base/js/Chart.min.js";
if (!file_exists($chart)) {
    $txt = @file_get_contents('https://cdn.jsdelivr.net/npm/chart.js');
    file_put_contents($chart, $txt ?: "// failed to fetch Chart.js\n");
}

// стили сеток
$css = "$base/css/metrics.css";
if (!file_exists($css)) {
    file_put_contents($css, <<<CSS
.page-entity-wrap { padding:20px; background:#fff }
.ui-entity-section { margin-bottom:30px }
.ui-entity-section-title { font-size:18px; font-weight:600; margin-bottom:15px }
/* двухколоночная форма */
.ui-ctl-group.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px }
.ui-ctl-group.grid-2 .ui-btn { grid-column:span 2 }
/* грид графиков */
.charts-wrapper { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:20px; margin-top:20px }
.charts-wrapper canvas { width:100% !important; height:250px !important }
CSS
    );
}

// подключаем модуль Bitrix
CModule::IncludeModule('main');
global $DB, $USER;
