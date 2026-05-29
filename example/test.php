<?php

declare(strict_types=1);

use Xakki\PhpErrorCatcher\PhpErrorCatcher;

require_once __DIR__ . '/../vendor/autoload.php';

// Minimal bootstrap: register the handlers and configure storages, the JS
// plugin and the web log viewer. `storage` is required; everything else is
// optional. See PhpErrorCatcher static properties for the full option list.
//
// NOTE: PhpErrorCatcher::init([...]) is deprecated; use new PhpErrorCatcher() directly.

new PhpErrorCatcher(
    storage: [
//        'ElasticStorage' => [
//            'url' => 'http://192.168.0.1:9200',
//            'auth' => 'elastic:changeMe3',
//        ],
        'FileStorage' => [
            'logPath' => __DIR__ . '/runtime/log',
        ],
    ],
    plugin: [
        'JsLogPlugin' => [
            // GET-parameter trigger that accepts incoming logs. With dynamic
            // delivery of /catcher.js the server injects it into the script
            // (jsLogKey), so any value works. For a static
            // <script src="src/catcher.js"> it must match the client default —
            // 'catcherLogName'.
            'initGetKey' => 'catcherLogName',
            // Path that serves catcher.js dynamically (GET). '' disables it.
            // 'scriptUrl' => '/catcher.js',
            // Optional secret: enables a stateless token (HMAC signature).
            // Empty/unset — access is gated by initGetKey only.
            // 'secret' => 'changeMe6',
        ],
    ],
    viewer: [
        'class' => 'FileViewer',
        'initGetKey' => 'changeMe5',
    ],
    logFields: ['project' => defined('IS_DEV') ? 'unidoski.dev' : 'unidoski'],
    debugMode: isset($_COOKIE['changeMe']) || isset($_GET['changeMe']),
    logCookieKey: 'changeMe2',
    dirRoot: rtrim(dirname(__DIR__), '/'),
    saveLogIfHasError: !defined('IS_DEV'),
    ignoreRules: [['level' => PhpErrorCatcher::LEVEL_NOTICE, 'type' => PhpErrorCatcher::TYPE_TRIGGER]],
);

// Trigger an error on purpose to test the catcher
$r = 1 / 0;
// ...the rest of your application code runs here
