<?php

use xakki\phperrorcatcher\PHPErrorCatcher;

require_once __DIR__ . '/../vendor/autoload.php';

// Можем подключить профайлер xhprof (Да , реализована только поддержка xhprof)
// Для рисования графиков нужна либа graphviz (sudo apt-get install graphviz)
// естественно чтоб была подключен модуль пхпшный (sudo apt-get install php5-xhprof)
// если хотите подключить свой "БлэкДжек с девицами" , то вам нужно переопределить метод initProfiler() и endProfiler()

// pdo - array|function можете использовать свое, уже созданное подключение
// mailer - function - Для получения писем "счастья" подключаем почту
// можно использовать свою либу, но чтоб в ней   были атрибуты Body и Subject, и метод Send()
// ну или можете переопределить метод sendErrorMail()
// Шаблон темы задан по умолчанию в параметре \PHPErrorCatcher::$mailerSubjectPrefix

PHPErrorCatcher::init([
    'logFields' => ['project' => defined('IS_DEV') ? 'unidoski.dev' : 'unidoski'],
    'debugMode' => isset($_COOKIE['changeMe']) || isset($_GET['changeMe']),
    'logCookieKey' => 'changeMe2',
    'dirRoot' => rtrim(dirname(__DIR__), '/'),
    'saveLogIfHasError' => !defined('IS_DEV'),
    'ignoreRules' => [['level' => PHPErrorCatcher::LEVEL_NOTICE, 'type' => PHPErrorCatcher::TYPE_TRIGGER]],
    'storage' => [
        'ElasticStorage' => [
            'url' => 'http://192.168.0.1:9200',
            'auth' => 'elastic:changeMe3',
        ],
        'FileStorage' => [
            'logPath' => __DIR__ . '/runtime/log',
        ],
    ],
    'plugin' => [
        'JsLogPlugin' => [
            'initGetKey' => 'changeMe4',
        ],
    ],
    'viewer' => [
        'class' => 'FileViewer',
        'initGetKey' => 'changeMe5',
    ],
]);

// Выплняем код с ошибкой для теста
$r = 1 / 0;
// Далее выполняем рабочий код
