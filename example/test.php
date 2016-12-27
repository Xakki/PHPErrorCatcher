<?
    use xakki\phperrorcatcher\PHPErrorCatcher;

    /**
     * Подключение для Yii
     */
    defined('ERROR_VIEW_PATH') || define('ERROR_VIEW_PATH', __DIR__ . "/tmp");  // absolute path for view dir
    defined('ERROR_VIEW_GET') || define('ERROR_VIEW_GET', 'showLogs');      // url view logs http://exaple.com/?showMeLogs=1
    defined('ERROR_LOG_DIR') || define("ERROR_LOG_DIR", "/logsError");
    defined('ERROR_BACKUP_DIR') || define('ERROR_BACKUP_DIR', '/_backUp'); // Backup relative dir / Only inside ERROR_VIEW_PATH
    if (isset($_COOKIE['debug']) || isset($_GET['debug'])) {
        defined('ERROR_DEBUG_MODE') || define('ERROR_DEBUG_MODE', true); // будет отображатся плашка
    }
    $PHPErrorCatcherLib = __DIR__ . '/../src/PHPErrorCatcher.php';
    if (!file_exists($PHPErrorCatcherLib)) return;

    require_once $PHPErrorCatcherLib;

    require_once __DIR__.'/../vendor/autoload.php';

    // Можем подключить профайлер xhprof (Да , реализована только поддержка xhprof)
    // Для рисования графиков нужна либа graphviz (sudo apt-get install graphviz)
    // естественно чтоб была подключен модуль пхпшный (sudo apt-get install php5-xhprof)
    // если хотите подключить свой "БлэкДжек с девицами" , то вам нужно переопределить метод initProfiler() и endProfiler()
    // путь к расположению библиотек профаилера
    PHPErrorCatcher::$xhprofDir = __DIR__ . '/../vendor/lox/xhprof';
    // профилируем только медленные скрипты, работающие больше 6сек
    PHPErrorCatcher::$minTimeProfiled = 6000;
    // Можно профилировать все запросы с переданными параметрами, к примеру
    if (isset($_COOKIE['prof']) || isset($_GET['prof'])) {
        PHPErrorCatcher::$xhprofEnable = true;
    }
    // dont log session data
    PHPErrorCatcher::$enableSessionLog = false;

    PHPErrorCatcher::init();

    // Можно передать параметр после инициализации
    // можете использовать свое, уже созданное подключение
    PHPErrorCatcher::$pdo = function () {
        return new PDO("mysql:host=localhost;port=3106;dbname=test", 'testUser', 'testPass');
    };

    // Для получения писем "счастья" подключаем почту
    // можно использовать свою либу, но чтоб в ней   были атрибуты Body и Subject, и метод Send()
    // ну или можете переопределить метод sendErrorMail()
    // Шаблон темы задан по умолчанию в параметре \PHPErrorCatcher::$mailerSubjectPrefix

    PHPErrorCatcher::$mailer = function () {
        global $config;
        $transport = $config['components']['mailer']['transport'];
        $cmsmailer = new \PHPMailer\PHPMailer\PHPMailer();
        $cmsmailer->Host = $transport['host'];
        $cmsmailer->Port = $transport['port'];
        $cmsmailer->SMTPAuth = true;
        $cmsmailer->SMTPDebug = 3;
        $cmsmailer->Username = $transport['username'];
        $cmsmailer->Password = $transport['password'];
        $cmsmailer->Sender = $transport['username'];
        $cmsmailer->FromName = 'PHPErrorCatcher';
        $cmsmailer->Mailer = 'smtp';
        $cmsmailer->ContentType = 'text/html';
        $cmsmailer->CharSet = 'utf-8';
        $cmsmailer->AddAddress('test@example.ru');
        return $cmsmailer;
    };


    // Метод инициализации просмотра логов
    // этот метод вызываем в конце  передачи всех необходимых параметров
    PHPErrorCatcher::initLogView();


    // Выплняем код с ошибкой для теста
    $r = 1 / 0;
    // Далее выполняем рабочий код
