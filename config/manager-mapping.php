<?php

/*
 * Отдельная структура персональных таблиц менеджеров.
 * Порядок ключей определяет порядок видимых столбцов.
 * Пользовательское поле можно задать одним из способов:
 * array('type' => 'custom', 'title' => 'Название поля')
 * array('type' => 'custom', 'field' => 'UF_CRM_123456789')
 * Если field заполнен, он имеет приоритет над title/titles.
 */
return array(
    'columns' => array(
        '№' => array(
            'type' => 'custom',
            'title' => 'Номер заказа',
            'field' => 'UF_CRM_1779779635693'),

        // Дата формирования бланка: используется дата входа в стадию «Счет оплачен».
        'Дата' => array(
            'type' => 'computed',
            'name' => 'invoice_date',
            'field' => ''),
        'Имя' => array('type' => 'computed', 'name' => 'client_name'),
        'Сумма' => array(
            'type' => 'field',
            'field' => 'opportunity',
            'format' => 'number'
            ),
        'Оплата' => array(
            'type' => 'custom',
            'title' => 'Оплата',
            'field' => 'UF_CRM_1779972174388'
            ),
        'Себес' => array(
            'type' => 'custom',
            'titles' => array('Себестоимость производства', 'Себестоимость'),
            'format' => 'number',
            'field' => 'UF_CRM_1779982029'
        ),
        'Материал' => array(
            'type' => 'custom',
            'title' => 'Материал заказа',
            'field' => 'UF_CRM_1790676521'),
        'Квадратура' => array(
            'type' => 'custom',
            'title' => 'Площадь работы, м2',
            'format' => 'number',
            'field' => 'UF_CRM_1779972345790'),

        // В Bitrix24 хранится исходная сумма. В Sheets с русской локалью записывается формула =исходная_сумма*1,5.
        'Доп траты' => array(
            'type' => 'custom',
            'titles' => array('Доп траты'),
            'format' => 'number',
            'field' => 'UF_CRM_1794947309',
        ),
        'Доп траты 1,5х' => array(
            'type' => 'custom',
            'titles' => array('Доп траты'),
            'format' => 'number',
            'field' => 'UF_CRM_1794947309',
            'formula_multiplier' => 1.5,
        ),
        'Кому' => array(
            'type' => 'custom',
            'titles' => array('Кому', 'Куда'),
            'field' => 'UF_CRM_1794925303632'
        ),
        'Дельта' => array(
            'type' => 'row_formula',
            'formula' => '={Сумма}-{Себес}-{Доп траты}',
        ),
        // Последние три поля всегда идут в этом порядке.
        'Ссылка на контакт сделки' => array('type' => 'computed', 'name' => 'contact_url'),
        'Ссылка на сделку' => array('type' => 'computed', 'name' => 'deal_url'),
        'Комментарий' => array('type' => 'field', 'field' => 'comments'),
    ),
);
