<?php
// /aktivnosti/functions.php

/**
 * Создать таблицу, если её нет
 */
function ensureTable(): void {
    global $DB;

    // создаём таблицу, если она не существует
    $DB->Query("
        CREATE TABLE IF NOT EXISTS b_aktivnosti_metrics (
          ID INT AUTO_INCREMENT PRIMARY KEY,
          USER_ID INT NOT NULL,
          METRIC_DATE DATE NOT NULL,
          TOUCHES INT DEFAULT 0,
          MEETINGS_SCHEDULED INT DEFAULT 0,
          MEETINGS_HELD INT DEFAULT 0,
          PROPOSALS_NEW INT DEFAULT 0,
          PAYMENTS INT DEFAULT 0,
          UNIQUE KEY ux_uid_date (USER_ID, METRIC_DATE)
        ) ENGINE=InnoDB CHARSET=utf8
    ", true);

    // удаляем дублирующиеся записи, оставляя самую позднюю
    $DB->Query("
        DELETE t1
        FROM b_aktivnosti_metrics t1
        JOIN b_aktivnosti_metrics t2
          ON t1.USER_ID = t2.USER_ID
         AND t1.METRIC_DATE = t2.METRIC_DATE
         AND t1.ID < t2.ID
    ", true);

    // таблица планов метрик по сотрудникам
    $DB->Query("
        CREATE TABLE IF NOT EXISTS b_aktivnosti_metric_plan (
          ID INT AUTO_INCREMENT PRIMARY KEY,
          USER_ID INT NOT NULL,
          METRIC_CODE VARCHAR(50) NOT NULL,
          PLAN INT DEFAULT 0,
          PERIOD_FROM DATE DEFAULT NULL,
          PERIOD_TO DATE DEFAULT NULL,
          UNIQUE KEY ux_user_metric_period (USER_ID, METRIC_CODE, PERIOD_FROM, PERIOD_TO)
        ) ENGINE=InnoDB CHARSET=utf8
    ", true);

    // обновление структуры таблицы, если она была создана ранее без периодов
    $DB->Query("ALTER TABLE b_aktivnosti_metric_plan ADD COLUMN PERIOD_FROM DATE DEFAULT NULL", true);
    $DB->Query("ALTER TABLE b_aktivnosti_metric_plan ADD COLUMN PERIOD_TO DATE DEFAULT NULL", true);
    $DB->Query("ALTER TABLE b_aktivnosti_metric_plan DROP INDEX ux_user_metric", true);
    $DB->Query("ALTER TABLE b_aktivnosti_metric_plan ADD UNIQUE KEY ux_user_metric_period (USER_ID, METRIC_CODE, PERIOD_FROM, PERIOD_TO)", true);

    // таблица прав на страницу настроек
    $DB->Query("
        CREATE TABLE IF NOT EXISTS b_aktivnosti_settings_users (
          USER_ID INT PRIMARY KEY
        ) ENGINE=InnoDB CHARSET=utf8
    ", true);

    // таблица типов (кастомных) метрик
    $DB->Query("
        CREATE TABLE IF NOT EXISTS b_aktivnosti_metric_types (
          CODE VARCHAR(50) PRIMARY KEY,
          LABEL VARCHAR(255) NOT NULL,
          BUILTIN TINYINT(1) DEFAULT 0,
          ACTIVE TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB CHARSET=utf8
    ", true);

    // записываем системные метрики в справочник (если их нет)
    $builtin = [
        ['code' => 'touches',            'label' => 'Новые касания'],
        ['code' => 'meetings_scheduled', 'label' => 'Назначено встреч'],
        ['code' => 'meetings_held',      'label' => 'Проведено встреч'],
        ['code' => 'proposals_new',      'label' => 'Новые КП'],
        ['code' => 'payments',           'label' => 'Оплаты'],
    ];
    foreach ($builtin as $b) {
        $c = $DB->ForSql($b['code']);
        $l = $DB->ForSql($b['label']);
        $DB->Query("INSERT IGNORE INTO b_aktivnosti_metric_types (CODE, LABEL, BUILTIN, ACTIVE) VALUES ('$c', '$l', 1, 1)", true);
    }

    // значения кастомных метрик (key-value)
    $DB->Query("
        CREATE TABLE IF NOT EXISTS b_aktivnosti_metric_values (
          ID INT AUTO_INCREMENT PRIMARY KEY,
          USER_ID INT NOT NULL,
          METRIC_DATE DATE NOT NULL,
          METRIC_CODE VARCHAR(50) NOT NULL,
          VALUE INT DEFAULT 0,
          UNIQUE KEY ux_uid_date_code (USER_ID, METRIC_DATE, METRIC_CODE)
        ) ENGINE=InnoDB CHARSET=utf8
    ", true);
    // назначение кастомных метрик сотрудникам
    $DB->Query("
        CREATE TABLE IF NOT EXISTS b_aktivnosti_metric_user_types (
          USER_ID INT NOT NULL,
          METRIC_CODE VARCHAR(50) NOT NULL,
          PRIMARY KEY (USER_ID, METRIC_CODE)
        ) ENGINE=InnoDB CHARSET=utf8
    ", true);
}

/**
 * Получить список действительно активных пользователей Bitrix24.
 * @return array ID => Ф.И.О.
 */
function getActiveUsers(): array {
    $filter = [
        'ACTIVE' => 'Y',            // системно активен
        '!=DATE_DEACTIVATE' => false // не уволен / не заблокирован
    ];
    $select = ['FIELDS' => ['ID', 'NAME', 'LAST_NAME']];
    $rs = CUser::GetList('last_name', 'asc', $filter, $select);
    $out = [];
    while ($u = $rs->Fetch()) {
        $out[$u['ID']] = trim("{$u['NAME']} {$u['LAST_NAME']}");
    }
    return $out;
}

// совместимость со старым кодом
function getUsers(): array {
    return getActiveUsers();
}

/**
 * Вернуть имя текущего пользователя для отображения
 */
function getCurrentUserName(): string {
    global $USER;
    $name = trim($USER->GetFirstName() . ' ' . $USER->GetLastName());
    return $name ?: $USER->GetLogin();
}

/**
 * Сохранить метрики из POST-формы
 */
function saveMetrics(): void {
    global $DB, $USER;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['date'])) {
        return;
    }

    $uid = (int)$_POST['user_id'];
    $dt = $DB->ForSql($_POST['date']);
    $map = metricMap();
    $userCustomCodes = getUserAssignedCustomMetrics($uid);
    $builtinCodes = [];
    $customValues = [];
    foreach ($map as $code => $info) {
        $val = (int)($_POST[$code] ?? 0);
        // если у метрики указан столбец — это встроенная, идёт в основную таблицу
        if (!empty($info['column'])) {
            $builtinCodes[$code] = $val;
        } else {
            $customValues[$code] = $val;
        }
    }

    // сохраняем встроенные метрики в основную таблицу, только если у пользователя нет кастомных назначений
    if (empty($userCustomCodes)) {
        $t   = $builtinCodes['touches']            ?? 0;
        $ms  = $builtinCodes['meetings_scheduled'] ?? 0;
        $mh  = $builtinCodes['meetings_held']      ?? 0;
        $pn  = $builtinCodes['proposals_new']      ?? 0;
        $pay = $builtinCodes['payments']           ?? 0;

        $DB->Query(
            "INSERT INTO b_aktivnosti_metrics 
            (USER_ID, METRIC_DATE, TOUCHES, MEETINGS_SCHEDULED, MEETINGS_HELD, PROPOSALS_NEW, PAYMENTS)
            VALUES ($uid, '$dt', $t, $ms, $mh, $pn, $pay)
            ON DUPLICATE KEY UPDATE 
              TOUCHES=VALUES(TOUCHES),
              MEETINGS_SCHEDULED=VALUES(MEETINGS_SCHEDULED),
              MEETINGS_HELD=VALUES(MEETINGS_HELD),
              PROPOSALS_NEW=VALUES(PROPOSALS_NEW),
              PAYMENTS=VALUES(PAYMENTS)"
        );
    }

    // сохраняем кастомные метрики в KV-таблицу
    foreach ($customValues as $code => $val) {
        $codeSql = $DB->ForSql($code);
        $v = (int)$val;
        $DB->Query(
            "INSERT INTO b_aktivnosti_metric_values (USER_ID, METRIC_DATE, METRIC_CODE, VALUE)
             VALUES ($uid, '$dt', '$codeSql', $v)
             ON DUPLICATE KEY UPDATE VALUE=VALUES(VALUE)"
        );
    }

    echo '<div class="ui-alert ui-alert-success">Данные сохранены</div>';
}

/**
 * Вернуть последние 6 периодов по сотрудникам
 */
function fetchMetrics(array $userIds, string $period): array {
    global $DB;
    $ids = array_map('intval', $userIds);
    if (empty($ids)) {
        return [];
    }
    $idList = implode(',', $ids);

    switch ($period) {
        case 'week':
            $grp = "YEARWEEK(METRIC_DATE, 1)";
            $lbl = "DATE_FORMAT(DATE_SUB(METRIC_DATE, INTERVAL WEEKDAY(METRIC_DATE) DAY), '%Y-%m-%d')";
            break;
        case 'month':
            $grp = "DATE_FORMAT(METRIC_DATE, '%Y-%m')";
            $lbl = $grp;
            break;
        default: // day
            $grp = "DATE_FORMAT(METRIC_DATE, '%Y-%m-%d')";
            $lbl = $grp;
    }

    // Определяем последние 6 периодов по данным из обеих таблиц
    $periods = [];
    $sqlPeriods = "
      SELECT period, label FROM (
        SELECT $grp AS period, $lbl AS label
        FROM b_aktivnosti_metrics
        WHERE USER_ID IN ($idList)
        GROUP BY period, label
        UNION
        SELECT $grp AS period, $lbl AS label
        FROM b_aktivnosti_metric_values
        WHERE USER_ID IN ($idList)
        GROUP BY period, label
      ) t
      ORDER BY period DESC
      LIMIT 6
    ";
    $resP = $DB->Query($sqlPeriods);
    while ($r = $resP->Fetch()) {
        $periods[(string)$r['period']] = $r['label'];
    }
    if (!$periods) {
        return [];
    }

    // Загружаем суммы встроенных метрик по периодам
    $sqlBuiltin = "
        SELECT $grp AS period,
               SUM(TOUCHES) AS touches,
               SUM(MEETINGS_SCHEDULED) AS meetings_scheduled,
               SUM(MEETINGS_HELD) AS meetings_held,
               SUM(PROPOSALS_NEW) AS proposals_new,
               SUM(PAYMENTS) AS payments
        FROM b_aktivnosti_metrics
        WHERE USER_ID IN ($idList)
        GROUP BY period
    ";
    $builtin = [];
    $resB = $DB->Query($sqlBuiltin);
    while ($row = $resB->Fetch()) {
        $builtin[(string)$row['period']] = $row;
    }

    // Загружаем суммы кастомных метрик по периодам и кодам
    $sqlCustom = "
        SELECT $grp AS period, METRIC_CODE, SUM(VALUE) AS val
        FROM b_aktivnosti_metric_values
        WHERE USER_ID IN ($idList)
        GROUP BY period, METRIC_CODE
    ";
    $custom = [];
    $resC = $DB->Query($sqlCustom);
    while ($row = $resC->Fetch()) {
        $p = (string)$row['period'];
        $code = $row['METRIC_CODE'];
        $custom[$p][$code] = (int)$row['val'];
    }

    // Формируем итоговый массив строк по выбранным периодам (в обратном порядке затем перевернём)
    $out = [];
    foreach (array_keys($periods) as $periodKey) {
        $label = $periods[$periodKey];
        $row = ['period' => $periodKey, 'label' => $label];
        if (isset($builtin[$periodKey])) {
            $row += [
                'touches' => (int)($builtin[$periodKey]['touches'] ?? 0),
                'meetings_scheduled' => (int)($builtin[$periodKey]['meetings_scheduled'] ?? 0),
                'meetings_held' => (int)($builtin[$periodKey]['meetings_held'] ?? 0),
                'proposals_new' => (int)($builtin[$periodKey]['proposals_new'] ?? 0),
                'payments' => (int)($builtin[$periodKey]['payments'] ?? 0),
            ];
        }
        if (isset($custom[$periodKey])) {
            foreach ($custom[$periodKey] as $code => $val) {
                $row[$code] = (int)$val;
            }
        }
        $out[] = $row;
    }

    // отсортируем так же: последние 6 периодов уже выбраны по убыванию, вернём по возрастанию
    return array_reverse($out);
}

/**
 * Справочник доступных метрик
 */
function metricMap(): array {
    global $DB;
    // встроенные метрики (хранятся в основной таблице)
    $map = [
        'touches'            => ['label' => 'Новые касания',   'column' => 'TOUCHES'],
        'meetings_scheduled' => ['label' => 'Назначено встреч', 'column' => 'MEETINGS_SCHEDULED'],
        'meetings_held'      => ['label' => 'Проведено встреч', 'column' => 'MEETINGS_HELD'],
        'proposals_new'      => ['label' => 'Новые КП',         'column' => 'PROPOSALS_NEW'],
        'payments'           => ['label' => 'Оплаты',           'column' => 'PAYMENTS'],
    ];

    // кастомные типы из справочника
    $res = $DB->Query("SELECT CODE, LABEL FROM b_aktivnosti_metric_types WHERE ACTIVE=1 AND BUILTIN=0 ORDER BY CODE");
    while ($row = $res->Fetch()) {
        $code = $row['CODE'];
        if (!isset($map[$code])) {
            $map[$code] = ['label' => $row['LABEL']]; // без 'column' => кастомная
        }
    }
    return $map;
}

/**
 * Создать/удалить кастомные метрики (настройки)
 */
function saveCustomMetricTypes(): void {
    global $DB;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['form'] ?? '') !== 'types') {
        return;
    }

    // удаление выбранных метрик
    $toDelete = array_map('strval', $_POST['remove'] ?? []);
    if ($toDelete) {
        $codes = array_map(fn($c)=>"'".$DB->ForSql($c)."'", $toDelete);
        $DB->Query("DELETE FROM b_aktivnosti_metric_types WHERE CODE IN (".implode(',', $codes).") AND BUILTIN=0");
        // по желанию можно удалить и значения
        $DB->Query("DELETE FROM b_aktivnosti_metric_values WHERE METRIC_CODE IN (".implode(',', $codes).")");
    }

    // добавление новой метрики по названию
    $newLabel = trim((string)($_POST['new_label'] ?? ''));
    if ($newLabel !== '') {
        $codeBase = strtolower(preg_replace('~[^a-z0-9]+~i', '_', translitToAscii($newLabel)));
        $codeBase = trim($codeBase, '_');
        if ($codeBase === '') { $codeBase = 'metric'; }
        $code = $codeBase;
        // обеспечиваем уникальность кода
        for ($i=2; ; $i++) {
            $exists = $DB->Query("SELECT 1 FROM b_aktivnosti_metric_types WHERE CODE='".$DB->ForSql($code)."' LIMIT 1")->Fetch();
            if (!$exists) break;
            $code = $codeBase.'_'.$i;
        }
        $DB->Query("INSERT INTO b_aktivnosti_metric_types (CODE, LABEL, BUILTIN, ACTIVE) VALUES ('".$DB->ForSql($code)."', '".$DB->ForSql($newLabel)."', 0, 1)");
    }

    echo '<div class="ui-alert ui-alert-success">Кастомные метрики обновлены</div>';
}

/**
 * Простейшая транслитерация в ASCII для генерации кода
 */
function translitToAscii(string $s): string {
    // если есть iconv — используем
    if (function_exists('iconv')) {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) return $t;
    }
    // запасной вариант — убираем диакритику и кириллицу
    $map = [
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'C','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch','Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    return strtr($s, $map);
}

/**
 * Вернуть коды кастомных метрик, назначенных сотруднику
 */
function getUserAssignedCustomMetrics(int $uid): array {
    global $DB;
    $uid = (int)$uid;
    $out = [];
    $res = $DB->Query("SELECT METRIC_CODE FROM b_aktivnosti_metric_user_types WHERE USER_ID=$uid");
    while ($row = $res->Fetch()) { $out[] = $row['METRIC_CODE']; }
    return $out;
}

/**
 * Получить уникальные коды кастомных метрик для группы пользователей
 */
function getAssignedCustomMetricsForUsers(array $uids): array {
    global $DB;
    $ids = array_filter(array_map('intval', $uids));
    if (!$ids) return [];
    $idList = implode(',', $ids);
    $codes = [];
    $res = $DB->Query("SELECT DISTINCT METRIC_CODE FROM b_aktivnosti_metric_user_types WHERE USER_ID IN ($idList)");
    while ($row = $res->Fetch()) { $codes[] = $row['METRIC_CODE']; }
    return $codes;
}

/**
 * Назначить набор кастомных метрик нескольким сотрудникам (перезапись для каждого)
 */
function assignCustomMetricsToUsers(array $uids, array $codes): void {
    global $DB;
    $uids = array_unique(array_map('intval', $uids));
    // Оставляем только существующие и активные коды
    $existing = array_keys(metricMap());
    $codes = array_values(array_unique(array_intersect(array_map('strval', $codes), $existing)));
    if (!$uids) return;

    foreach ($uids as $uid) {
        $DB->Query("DELETE FROM b_aktivnosti_metric_user_types WHERE USER_ID=$uid");
        foreach ($codes as $code) {
            $DB->Query("INSERT INTO b_aktivnosti_metric_user_types (USER_ID, METRIC_CODE) VALUES ($uid, '".$DB->ForSql($code)."')");
        }
    }
}

/**
 * Обработчик формы назначения кастомных метрик
 */
function saveCustomMetricAssignments(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['form'] ?? '') !== 'assign_custom') {
        return;
    }
    $userIds = array_map('intval', $_POST['users'] ?? []);
    $codes   = array_map('strval', $_POST['codes'] ?? []);
    assignCustomMetricsToUsers($userIds, $codes);
    echo '<div class="ui-alert ui-alert-success">Назначения кастомных метрик сохранены</div>';
}

/**
 * Получить список пользователей, имеющих доступ к настройкам
 */
function getSettingsAdmins(): array {
    global $DB;
    $ids = [502]; // базовый администратор
    $res = $DB->Query('SELECT USER_ID FROM b_aktivnosti_settings_users');
    while ($row = $res->Fetch()) {
        $ids[] = (int)$row['USER_ID'];
    }
    return array_unique($ids);
}

/**
 * Получить всех назначенных сотрудников по кастомным метрикам
 * @return array code => int[] userIds
 */
function getAssignedUsersByMetric(): array {
    global $DB;
    $map = [];
    $res = $DB->Query('SELECT METRIC_CODE, USER_ID FROM b_aktivnosti_metric_user_types ORDER BY METRIC_CODE, USER_ID');
    while ($row = $res->Fetch()) {
        $code = (string)$row['METRIC_CODE'];
        $map[$code][] = (int)$row['USER_ID'];
    }
    return $map;
}

/**
 * Добавление/удаление сотрудников у конкретной кастомной метрики
 */
function saveMetricUsers(): void {
    global $DB;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['form'] ?? '') !== 'metric_users') {
        return;
    }
    $code = $DB->ForSql((string)($_POST['metric_code'] ?? ''));
    if ($code === '') return;

    if (!empty($_POST['add_user_id'])) {
        $uid = (int)$_POST['add_user_id'];
        if ($uid > 0) {
            $DB->Query("INSERT IGNORE INTO b_aktivnosti_metric_user_types (USER_ID, METRIC_CODE) VALUES ($uid, '$code')");
        }
    }
    if (!empty($_POST['remove_user_id'])) {
        $uid = (int)$_POST['remove_user_id'];
        $DB->Query("DELETE FROM b_aktivnosti_metric_user_types WHERE USER_ID=$uid AND METRIC_CODE='$code'");
    }
    echo '<div class="ui-alert ui-alert-success">Список сотрудников обновлён</div>';
}

/**
 * Проверить права на настройки
 */
function isSettingsAdmin(int $uid): bool {
    return in_array($uid, getSettingsAdmins(), true);
}

/**
 * Сохранить .список администраторов
 */
function saveSettingsRights(): void {
    global $DB;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['form'] ?? '') !== 'rights') {
        return;
    }
    $ids = array_map('intval', $_POST['admin_ids'] ?? []);
    if (!empty($_POST['new_admin_id'])) {
        $ids[] = (int)$_POST['new_admin_id'];
    }
    if (!empty($_POST['remove_admin'])) {
        $ids = array_diff($ids, [(int)$_POST['remove_admin']]);
    }
    $ids = array_unique($ids);

    $DB->Query('TRUNCATE TABLE b_aktivnosti_settings_users');
    foreach ($ids as $id) {
        if ($id == 502) {
            continue; // базовый админ
        }
        $DB->Query("INSERT INTO b_aktivnosti_settings_users (USER_ID) VALUES ($id)");
    }

    echo '<div class="ui-alert ui-alert-success">Права сохранены</div>';
}

/**
 * Получить планы метрик для сотрудника
 */
function getUserMetricPlans(int $uid, ?string $from = null, ?string $to = null): array {
    global $DB;
    $uid = (int)$uid;
    $where = "USER_ID=$uid";

    if ($from !== null && $to !== null) {
        $fromSql = $DB->ForSql($from);
        $toSql   = $DB->ForSql($to);
        $where .= " AND ((PERIOD_FROM IS NULL OR PERIOD_FROM <= '$toSql')
                     AND (PERIOD_TO IS NULL OR PERIOD_TO >= '$fromSql'))";
    }

    $res = $DB->Query("SELECT METRIC_CODE, PLAN FROM b_aktivnosti_metric_plan WHERE $where");
    $out = [];
    while ($row = $res->Fetch()) {
        $out[$row['METRIC_CODE']] = (int)$row['PLAN'];
    }
    return $out;
}

/**
 * Сохранить планы метрик
 */
function saveUserMetricPlans(): void {
    global $DB;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['form'] ?? '') !== 'metrics') {
        return;
    }

    // поддержка множественного сохранения
    $employeeIds = [];
    if (!empty($_POST['employees']) && is_array($_POST['employees'])) {
        $employeeIds = array_map('intval', $_POST['employees']);
    } else {
        $employeeIds = [(int)($_POST['employee'] ?? 0)];
    }

    $from = $DB->ForSql($_POST['period_from'] ?? '');
    $to   = $DB->ForSql($_POST['period_to'] ?? '');
    $fromVal = $from ? "'$from'" : 'NULL';
    $toVal   = $to ? "'$to'"   : 'NULL';

    $map = metricMap();
    foreach ($employeeIds as $uid) {
        $uid = (int)$uid;
        foreach ($map as $code => $info) {
            $plan   = (int)($_POST['plan'][$code] ?? 0);
            $codeSql = $DB->ForSql($code);

            // корректируем существующие планы, пересекающиеся с выбранным периодом
            if ($from !== '' && $to !== '') {
                $res = $DB->Query("SELECT ID, PERIOD_FROM, PERIOD_TO, PLAN FROM b_aktivnosti_metric_plan WHERE USER_ID=$uid AND METRIC_CODE='$codeSql' AND (PERIOD_TO IS NULL OR PERIOD_TO >= '$from') AND (PERIOD_FROM IS NULL OR PERIOD_FROM <= '$to')");
                while ($row = $res->Fetch()) {
                    $rowFrom = $row['PERIOD_FROM'];
                    $rowTo   = $row['PERIOD_TO'];

                    if (($rowFrom === null || $rowFrom < $from) && ($rowTo === null || $rowTo > $to)) {
                        // план перекрывает новый период полностью — делим на две части
                        $beforeTo  = date('Y-m-d', strtotime($from . ' -1 day'));
                        $afterFrom = date('Y-m-d', strtotime($to . ' +1 day'));
                        $rowToVal  = $rowTo ? "'".$DB->ForSql($rowTo)."'" : 'NULL';
                        $DB->Query("UPDATE b_aktivnosti_metric_plan SET PERIOD_TO='$beforeTo' WHERE ID={$row['ID']}");
                        $DB->Query("INSERT INTO b_aktivnosti_metric_plan (USER_ID, METRIC_CODE, PLAN, PERIOD_FROM, PERIOD_TO) VALUES ($uid, '$codeSql', {$row['PLAN']}, '$afterFrom', $rowToVal)");
                    } elseif ($rowFrom === null || $rowFrom < $from) {
                        // пересечение с началом нового периода — сокращаем конец
                        $beforeTo = date('Y-m-d', strtotime($from . ' -1 day'));
                        $DB->Query("UPDATE b_aktivnosti_metric_plan SET PERIOD_TO='$beforeTo' WHERE ID={$row['ID']}");
                    } elseif ($rowTo === null || $rowTo > $to) {
                        // пересечение с концом нового периода — сдвигаем начало
                        $afterFrom = date('Y-m-d', strtotime($to . ' +1 day'));
                        $DB->Query("UPDATE b_aktivnosti_metric_plan SET PERIOD_FROM='$afterFrom' WHERE ID={$row['ID']}");
                    } else {
                        // план полностью внутри нового периода — удаляем
                        $DB->Query("DELETE FROM b_aktivnosti_metric_plan WHERE ID={$row['ID']}");
                    }
                }
            } else {
                // прежнее поведение для открытых периодов
                $where = "USER_ID=$uid AND METRIC_CODE='$codeSql'";
                if ($from !== '') {
                    $where .= " AND (PERIOD_TO IS NULL OR PERIOD_TO >= '$from')";
                }
                if ($to !== '') {
                    $where .= " AND (PERIOD_FROM IS NULL OR PERIOD_FROM <= '$to')";
                }
                $DB->Query("DELETE FROM b_aktivnosti_metric_plan WHERE $where");
            }

            if ($plan > 0) {
                $DB->Query(
                    "INSERT INTO b_aktivnosti_metric_plan
                      (USER_ID, METRIC_CODE, PLAN, PERIOD_FROM, PERIOD_TO)
                    VALUES ($uid, '$codeSql', $plan, $fromVal, $toVal)
                    ON DUPLICATE KEY UPDATE PLAN=VALUES(PLAN)"
                );
            }
        }
    }

    echo '<div class="ui-alert ui-alert-success">Настройки сохранены</div>';
}

/**
 * Удалить все планы метрик для сотрудника
 */
function deleteUserMetricPlans(): void {
    global $DB;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['form'] ?? '') !== 'clear_plans') {
        return;
    }
    $uid = (int)$_POST['employee'];
    $DB->Query("DELETE FROM b_aktivnosti_metric_plan WHERE USER_ID=$uid");
    echo '<div class="ui-alert ui-alert-success">Все планы удалены</div>';
}

/**
 * Получить коды метрик для сотрудника
 */
function getUserMetrics(int $uid, ?string $date = null): array {
    // Если есть назначенные кастомные метрики — используем только их
    $custom = getUserAssignedCustomMetrics($uid);
    if ($custom) {
        $existing = array_keys(metricMap());
        return array_values(array_intersect($custom, $existing));
    }
    // иначе показываем встроенные
    return array_keys(array_filter(metricMap(), fn($v)=>!empty($v['column'])));
}

/**
 * Получить суммарный план по группе сотрудников
 * за указанный период.
 */
function getPlansForUsersRange(array $uids, string $from, string $to): array {
    global $DB;
    $ids = array_map('intval', $uids);
    if (empty($ids)) {
        return [];
    }
    $idList  = implode(',', $ids);
    $fromSql = $DB->ForSql($from);
    $toSql   = $DB->ForSql($to);

    $res = $DB->Query("
        SELECT METRIC_CODE,
               SUM(PLAN * (
                   DATEDIFF(
                       LEAST(IFNULL(PERIOD_TO, '$toSql'), '$toSql'),
                       GREATEST(IFNULL(PERIOD_FROM, '$fromSql'), '$fromSql')
                   ) + 1
               )) AS P
        FROM b_aktivnosti_metric_plan
        WHERE USER_ID IN ($idList)
          AND (PERIOD_FROM IS NULL OR PERIOD_FROM <= '$toSql')
          AND (PERIOD_TO IS NULL OR PERIOD_TO >= '$fromSql')
        GROUP BY METRIC_CODE
    ");

    $out = [];
    while ($row = $res->Fetch()) {
        $out[$row['METRIC_CODE']] = (int)$row['P'];
    }
    return $out;
}

/**
 * Получить суммарный план по группе сотрудников на выбранную дату
 * (совместимость со старым кодом).
 */
function getPlansForUsers(array $uids, ?string $date = null): array {
    $d = $date ?? date('Y-m-d');
    return getPlansForUsersRange($uids, $d, $d);
}
