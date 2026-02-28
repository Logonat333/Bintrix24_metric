<?php
// просто точка входа: загружаем страницу метрик
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');
$APPLICATION->SetTitle('Активности (Метрики)');

include __DIR__.'/Metric.php';  // основной контроллер

require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
