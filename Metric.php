<?php
// /aktivnosti/Metric.php.  — главный контроллер

include __DIR__ . '/config.php';
include __DIR__ . '/functions.php';

ensureTable();                   // создаём таблицу, если нужно
$users = getActiveUsers();       // берём только активных сотрудников
saveMetrics();                   // сохраняем данные метрик

$isSettingsAdmin = isSettingsAdmin((int)$USER->GetID());
if ($isSettingsAdmin) {
    saveSettingsRights();        // сохраняем права на настройки
    deleteUserMetricPlans();     // удаляем планы метрик при необходимости
    saveUserMetricPlans();       // сохраняем планы метрик
    saveCustomMetricTypes();       // управляем справочником кастомных метрик
    saveCustomMetricAssignments(); // назначаем кастомные метрики сотрудникам
    saveMetricUsers();             // добавление/удаление сотрудника у конкретной метрики
    $settingsAdmins = getSettingsAdmins();
    // несколько сотрудников для планов
    if (isset($_GET['settings_user'])) {
        $val = $_GET['settings_user'];
        if (is_array($val)) {
            $settingsUsers = array_map('intval', $val);
        } else {
            $settingsUsers = array_map('intval', explode(',', (string)$val));
        }
        $settingsUsers = array_values(array_filter($settingsUsers));
    } else {
        $settingsUsers = [];
    }
    $settingsPeriodFrom = $_GET['period_from'] ?? date('Y-m-01');
    $settingsPeriodTo   = $_GET['period_to'] ?? date('Y-m-t');
    // метрики для планов: объединение метрик выбранных пользователей
    if ($settingsUsers) {
        $allCodes = [];
        foreach ($settingsUsers as $suid) {
            $allCodes = array_merge($allCodes, getUserMetrics((int)$suid, null));
        }
        $allCodes = array_values(array_unique($allCodes));
        $planMetricTypes = array_intersect_key(metricMap(), array_flip($allCodes));
    } else {
        $planMetricTypes = [];
    }
    $settingsPlans  = [];
    // список кастомных метрик
    global $DB;
    $customTypes = [];
    $res = $DB->Query("SELECT CODE, LABEL FROM b_aktivnosti_metric_types WHERE BUILTIN=0 ORDER BY CODE");
    while ($row = $res->Fetch()) { $customTypes[] = $row; }
    // для раздела закрепления метрик за сотрудником (встроенный блок настроек)
    // по умолчанию не выбран
    $assignUser = isset($_GET['assign_user']) ? (int)$_GET['assign_user'] : 0;
    $assignCodes = getUserAssignedCustomMetrics($assignUser);
} else {
    $settingsAdmins = [];
    $settingsUsers  = [];
    $settingsPlans  = [];
    $settingsPeriodFrom = date('Y-m-01');
    $settingsPeriodTo   = date('Y-m-t');
    $customTypes = [];
    $assignUser = 0;
    $assignCodes = [];
    $planMetricTypes = metricMap();
}

// -------------------------------------------------------------
// определяем пользователя и период для отображения графиков
// -------------------------------------------------------------
$selUsers = [];
if (isset($_GET['user'])) {
    $selUsers = is_array($_GET['user']) ? $_GET['user'] : explode(',', $_GET['user']);
    $selUsers = array_map('intval', $selUsers);
} else {
    $selUsers = [$USER->GetID()];
}
$periode = $_GET['periode']     ?? 'day';

// переменные, используемые в шаблоне view.php
$currentFilterUsers = $selUsers;                               // кто отображается
// при выборе сотрудника в форме ввода — перезагружаем страницу с ?input_user=
if (isset($_GET['input_user'])) {
    $currentInputUser = (int)$_GET['input_user'];
} else if ($_SERVER['REQUEST_METHOD']==='POST') {
    $currentInputUser = (int)($_POST['user_id'] ?? $USER->GetID());
} else {
    $currentInputUser = $USER->GetID();
}
$currentUserName   = getCurrentUserName();

$metricTypes  = metricMap();
$inputMetrics = getUserMetrics($currentInputUser, date('Y-m-d'));

// подключаем представление
include __DIR__ . '/view.php';
