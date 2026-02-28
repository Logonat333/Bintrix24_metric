

config.php — всё, что связано с окружением и ресурсами;

functions.php — логика БД;

view.php — HTML/CSS/JS;

Данные по каждому сотруднику и дню хранятся в единственной строке.

Начиная с версии 2024.05 графики строят дополнительную линию тренда,
если за выбранный период найдено шесть и более точек.

Metric.php — контроллер;

index.php — точка входа в Bitrix.



SQL 
b_aktivnosti_metrics
(
  ID                  INT AUTO_INCREMENT PRIMARY KEY,
  USER_ID             INT NOT NULL,
  METRIC_DATE         DATE NOT NULL,
  TOUCHES             INT,
  MEETINGS_SCHEDULED  INT,
  MEETINGS_HELD       INT,
  PROPOSALS_NEW       INT,

  PAYMENTS            INT
  UNIQUE KEY ux_uid_date (USER_ID, METRIC_DATE)
)

При инициализации таблицы дубли за прошлые периоды удаляются, а затем
создаётся уникальный индекс `ux_uid_date`. При повторном вводе метрики за
тот же день данные перезаписываются.
Если в фильтре статистики выбрано несколько сотрудников,
все показатели суммируются между ними, и графики отображают
суммарные значения за каждый период.
