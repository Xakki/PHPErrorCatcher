<?php
/*
    define('ERROR_VIEW_GET', '')      // url view logs http://exaple.com/?showMeLogs=1
    define('ERROR_VIEW_PATH', '')  // absolute path for view dir
    define('ERROR_BACKUP_DIR', '') // Backup relative dir / Only inside ERROR_VIEW_PATH
    define('ERROR_LOG_DIR', '')    // Error log relative dir / Only inside ERROR_VIEW_PATH
 */
if (
    !defined('ERROR_VIEW_GET')      // url view logs http://exaple.com/?showMeLogs=1
    || !defined('ERROR_VIEW_PATH')  // absolute path for view dir
    || !defined('ERROR_BACKUP_DIR') // Backup relative dir / Only inside ERROR_VIEW_PATH
    || !defined('ERROR_LOG_DIR')    // Error log relative dir / Only inside ERROR_VIEW_PATH
) {
    exit('Not defined constants for PHPErrorCatcher!!!');
}

if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'console';
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '*';
if (!isset($_SERVER['REMOTE_ADDR'])) $_SERVER['REMOTE_ADDR'] = 'localhost';

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('E_EXCEPTION_ERROR', 2); // custom error

/**
 * User: xakki
 * Date: 27.06.15
 * Time: 12:00
 * PHPErrorCatcher::model()->handleException($e);
 * trigger_error($message, E_USER_WARNING);
 */
class PHPErrorCatcher
{
    /**
     * Debug notice , if has error
     * Attention - slow function
     * @var bool
     */
    private $showNotice = false;

    /**
     * Path for profiler xhprof
     * @var null|string
     */
    private $xhprofPath = null;

    /**
     * URL location for profiler xhprof
     * @var null|string
     */
    private $xhprofUrl = null;

    /**
     * profiler namespace (xhprof)
     * @var string
     */
    public $profiler_namespace = 'slow';

    /**
     * Error list
     * @var array
     */
    static private $errorList = array(
        0 => array(
            'type' => '[@]',
            'color' => 'black',
            'debug' => 0
        ),
        E_ERROR => array( //1
            'type' => '[Fatal Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_PARSE => array( //4
            'type' => '[Parse Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_CORE_ERROR => array( //16
            'type' => '[Fatal Core Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_COMPILE_ERROR => array( //64
            'type' => '[Compilation Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_USER_ERROR => array( //256
            'type' => '[Triggered Error]',
            'color' => 'red',
            'debug' => 1
        ),
        E_RECOVERABLE_ERROR => array( //4096
            'type' => '[Catchable Fatal Error]',
            'color' => 'red',
            'debug' => 1
        ),

        E_WARNING => array( //2
            'type' => '[Warning]',
            'color' => '#F18890',
            'debug' => 1
        ),
        E_CORE_WARNING => array( //32
            'type' => '[Core Warning]',
            'color' => '#F18890',
            'debug' => 1
        ),
        E_COMPILE_WARNING => array( //128
            'type' => '[Compilation Warning]',
            'color' => '#F18890',
            'debug' => 1
        ),
        E_USER_WARNING => array( //512
            'type' => '[Triggered Warning]',
            'color' => '#F18890',
            'debug' => 1
        ),

        E_STRICT => array( //2048
            'type' => '[Deprecation Notice]',
            'color' => 'brown',
            'debug' => 0
        ),

        E_NOTICE => array( //8
            'type' => '[Notice]',
            'color' => '#858585',
            'debug' => 0
        ),
        E_USER_NOTICE => array( //1024
            'type' => '[Triggered Notice]',
            'color' => '#858585',
            'debug' => 0
        ),

        E_DEPRECATED => array( // 8192
            'type' => '[DEPRECATED Error]',
            'color' => 'brown',
            'debug' => 1
        ),
        E_USER_DEPRECATED => array( // 16384
            'type' => '[DEPRECATED User Error]',
            'color' => 'brown',
            'debug' => 1
        ),

        E_EXCEPTION_ERROR => array( // 2
            'type' => '[Exception]',
            'color' => 'red',
            'debug' => 1
        ),
    );

    /**
     * If true, all data log into file
     * @var bool
     */
    public $hasError = false;

    /**
     * Print all logs for output
     * @var string
     */
    private $allLogs = '';

    /**
     * singleton object
     * @var
     */
    protected static $obj;

    /**
     * @param bool $showNotice
     * @param null $xhprofPath
     * @param null $xhprofUrl
     * @return PHPErrorCatcher
     */
    public static function model($showNotice = false, $xhprofPath = null, $xhprofUrl = null) {
        if (!self::$obj) {
            self::$obj = new self($showNotice, $xhprofPath, $xhprofUrl);
        }
        return self::$obj;
    }

    public function __construct($showNotice = false, $xhprofPath = null, $xhprofUrl = null)
    {
        $this->showNotice = $showNotice;
        $this->xhprofPath = $xhprofPath;
        $this->xhprofUrl = $xhprofUrl;
        $this->startProfiler();
        register_shutdown_function(array($this, 'shutdown'));
        set_error_handler(array($this, 'handleError'));
        set_exception_handler(array($this, 'handleException'));

    }

    public function shutdown()
    {
        $error = error_get_last();
        if ($error) {
            $this->handleError($error['type'], $error['message'], $error['file'], $error['line'], 0);
        }

        if (isset($_GET['whereExit'])) {
            $this->allLogs .= self::debugPrint(0);
            $this->hasError = 1;
        }

        $profilerId = $this->endProfiler();

        if ($this->allLogs && $this->hasError) {
            $path = ERROR_VIEW_PATH.ERROR_LOG_DIR . '/' . date('Y.m.d');
            if (!file_exists($path)) {
                mkdir($path, 0777, true);
            }

            $fileName = $path . '/' . date('H') . '.html';

            if (!file_exists($fileName)) $flagNew = true;
            else $flagNew = false;

            if ($profilerId && $this->xhprofUrl) {
                $profilerId = '<div><a href="'.$this->xhprofUrl.'/index.php?run='.$profilerId.'&source='.$this->profiler_namespace.'">xhprof '.$profilerId.'</div>';
            }
            elseif (!extension_loaded('xhprof')) {
                $profilerId = '<div>No load xhprof extension</div>';
            }

            file_put_contents($fileName, '<div style="border-top:3px gray solid;">' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . ' ; ' . $_SERVER['REMOTE_ADDR']. ' ; Ref '.$_SERVER['HTTP_REFERER'] .'; ' . (count($_POST) ? PHP_EOL.'POST : '.json_encode($_POST) : '') . $profilerId . $this->allLogs . '</div>', FILE_APPEND);
            if ($flagNew) chmod($fileName, 0777);
        }

    }

    public function handleError($errno, $errstr, $errfile, $errline, $vars = null, $tace = null)
    {
        if (!$this->showNotice && ($errno==E_NOTICE || $errno==E_USER_NOTICE)) {
            return;
        }
        $micro_date = microtime();
        $micro_date = explode(" ",$micro_date);
        $debug = '<hr/> ' . date('Y-m-d H:i:s', $micro_date[1]) . ':'.$micro_date[0].' <span style="color:' . self::$errorList[$errno]['color'] . ';">' .
            self::$errorList[$errno]['type'] . '</span> <span>' .
            $errstr . '</span><div>' .
            'File <a href="file:/' . $errfile . ':' . $errline . '">' . $errfile . ':' . $errline . '</a></div>';

        if (self::$errorList[$errno]['debug']) { // для нотисов подробности ни к чему
            $debug .= self::appendAdditionalInfo($errstr);
            $debug .= self::debugPrint(1, $tace);
            $this->hasError = true;
        }

        $this->allLogs .= $debug;
    }

    /**
     * @param $e Exception
     * @param string $mess
     */
    public function handleException($e, $mess = '') {
        $this->handleError(E_EXCEPTION_ERROR, $e->getMessage().($mess ? '<br/>'.$mess : ''), $e->getFile(), $e->getLine(), $e->getCode(), $e->getTrace());
    }

    public function startProfiler() {

        if ($this->xhprofPath && extension_loaded('xhprof')) {
            include_once $this->xhprofPath.'/xhprof_lib/utils/xhprof_lib.php';
            include_once $this->xhprofPath.'/xhprof_lib/utils/xhprof_runs.php';
            xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
        }
    }

    public function endProfiler() {
        if ($this->xhprofPath && extension_loaded('xhprof')) {
            $xhprof_data = xhprof_disable();
            $xhprof_runs = new XHProfRuns_Default();
            return $xhprof_runs->save_run($xhprof_data, $this->profiler_namespace);
        }
        return null;
    }
    /**
     * Ф. трасировки ошибок
     * @param int $slice
     * @param array $traceArr
     * @return string
     */
    static public function debugPrint($slice = 1, $traceArr = null)
    {
        $MAXSTRLEN = 1024;
        $s = '<div class="xdebug" style="background-color: #e1e1e1; margin: 5px 0;">';
        if (!$traceArr) {
            $traceArr = debug_backtrace();
            $traceArr = array_slice($traceArr, $slice);
        }
        $i = 0;
        foreach ($traceArr as $arr) {
            $s .= '<div class="xdebug-item" style="border-top: solid 1px #a1a1a1;margin-left:' . (10 * $i) . 'px;"><span>';
            if (isset($arr['line']) and $arr['file']) $s .= ' in <a href="file:/' . $arr['file'] . '">' . $arr['file'] . ':' . $arr['line'] . '</a> , ';
            if (isset($arr['class'])) $s .= '#class <b>' . $arr['class'] . '-></b>';
            $s .= '</span>';
            //$s .= '<br/>';
            $args = array();
            if (isset($arr['args']))
                foreach ($arr['args'] as $v) {
                    if (is_null($v)) $args[] = '<b>NULL</b>';
                    else if (is_array($v)) $args[] = '<b>Array[' . sizeof($v) . ']</b>';
                    else if (is_object($v)) $args[] = '<b>Object:' . get_class($v) . '</b>';
                    else if (is_bool($v)) $args[] = '<b>' . ($v ? 'true' : 'false') . '</b>';
                    else {
                        $v = (string)@$v;
                        $str = self::_e(substr($v, 0, $MAXSTRLEN));
                        if (strlen($v) > $MAXSTRLEN) $str .= '...';
                        $args[] = $str;
                    }
                }
            if (isset($arr['function'])) {
                $s .= '<b>' . $arr['function'] . '</b>(' . implode(',', $args) . ')';
            }
            $s .= '</div>';
            $i++;
        }
        $s .= '</div>';
        return $s;
    }

    static public function appendAdditionalInfo($errstr) {
        $flag = false;
        if (strpos($errstr, 'The session id is too long or contains illegal characters')!==false) {
            $flag = true;
        }
        if ($flag) {
            return '<br>COOKIE:'.htmlspecialchars(json_encode($_COOKIE)).'<br/>'.
            'SESSION: '.htmlspecialchars(json_encode($_SESSION)).'<br/>'.
            'SERVER: '.htmlspecialchars(json_encode($_SERVER)).'<br/>';
        }
        return '';
    }

    static public function _e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_IGNORE, 'utf-8');
    }

    static public function showDirList($path = '')
    {
        $dirList = array();
        if (!file_exists(ERROR_VIEW_PATH . '/' . $path)) {
            if(!mkdir(ERROR_VIEW_PATH . '/' . trim($path, '/'), 0777, true)) {
                exit(' Cant create dir '.ERROR_VIEW_PATH . '/' . trim($path, '/'));
            }
        }
        $dir = dir(ERROR_VIEW_PATH . '/' . $path);
        $path = $path . '/';
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        $pathUrl = rtrim($url['path'], '/');
        while (false !== ($entry = $dir->read())) {
            if ($entry != '.' && $entry != '..') {
                $fileUrl = $pathUrl.'/?' . ERROR_VIEW_GET . '=' . $path . $entry;
                $filePath = ERROR_VIEW_PATH . '/' . $path . '/'. $entry;
                if (is_dir($filePath)) {
                    $size = $create = '';
                }
                else {
                    $size = filesize($filePath);
                    $create = date("Y-m-d H:i:s", filemtime($filePath));
                    $size = number_format($size, 0, '', ' ').' б.';
                }
                $dirList[$entry] = array('<a href="'.$fileUrl.'" style="'.(is_dir($filePath) ? 'font-weight:bold;' : '').'">' . $path . $entry . '</a> ', $size, $create,
                    '<a href="'.$fileUrl.'&backup=do">Бекап</a> <a href="'.$fileUrl.'&backup=del">Удалить</a>');
            }
        }
        krsort($dirList);
        return $dirList;
    }

    static public function renderDirList($dirList) {
        $html = '<table class="table table-striped" style="width: auto;">';
        $html .= '<thead>
            <tr>
              <th>name</th>
              <th>size</th>
              <th>Modify time</th>
              <th></th>
            </tr>
          </thead>
          <tbody>';
        foreach($dirList as $row) {
            $html .= '<tr><td>'.$row[0].'<td>'.$row[1].'<td>'.$row[2].'<td>'.$row[3].'</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    static public function renderHead() {
        return '<html>
            <head>
                <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/css/bootstrap.min.css">
                <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/css/bootstrap-theme.min.css">
                <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/js/bootstrap.min.js"></script>
            </head>';
    }
    /**
     * Просмотр логов
     */
    static public function logView()
    {
        echo self::renderHead().'<body>';
        $url = str_replace(array('\\', '\/\/', '\.\/', '\.\.'), '', $_GET[ERROR_VIEW_GET]);

        $file = ERROR_VIEW_PATH . '/' . trim($url, '/');
        if (file_exists($file)) {
            chmod($file, 0777);
            if (is_dir($file)) {
                if (isset($_GET['backup'])) {
                    self::doBackUpDir($file);
                    exit();
                }
                echo '<h3>Logs ' . self::getPath($url) . '</h3>';
                echo self::renderDirList(self::showDirList($url));
            } else {
                echo '<h3>Logs file ' . self::getPath($url);
                if (strpos($file, ERROR_VIEW_PATH.ERROR_BACKUP_DIR)===false) {
                    echo ' <a href="'.$_SERVER['REQUEST_URI'].'&backup=do">Бекап</a> <a href="'.$_SERVER['REQUEST_URI'].'&backup=del">Удалить</a>';
                }
                echo '</h3>';
                if (isset($_GET['backup'])) {
                    self::doBackUp($file);
                    exit();
                }
                $pathinfo = pathinfo($file);
                if ($pathinfo['extension']=='html') {
                    echo file_get_contents($file);
                }
                else {
                    echo '<pre>'.file_get_contents($file).'</pre>';
                }
            }
        } else {
            echo '<h3>Logs</h3>';
            echo self::renderDirList(self::showDirList());
        }
        echo '</body>';
    }

    /**
     * Делаем бекап фаила и ссылку на него
     * @param $file
     */
    static function doBackUp($file) {

        if (is_dir($file)) {
            echo 'Is Dir';
        }

        $backUpFile = str_replace(ERROR_VIEW_PATH, ERROR_VIEW_PATH.ERROR_BACKUP_DIR, $file);
        $backUpFileDir = dirname($backUpFile);
        if (!file_exists($backUpFileDir)) {
            mkdir($backUpFileDir, 0777, true);
        }

        if (file_exists($backUpFile)) {
            $i = pathinfo($backUpFile);
            $backUpFile = $i['dirname'].'/'.$i['filename'].'.'.time().'.'.$i['extension'];
        }
        if (file_exists($backUpFile)) {
            exit('Error!');
        }

        if (copy($file, $backUpFile)) {
            $loc = str_replace(array('&backup=do', '&backup=del'), '', $_SERVER['REQUEST_URI']);
            $backUpFileUrl = str_replace($_GET['showErrLogs'], str_replace(ERROR_VIEW_PATH, '', $backUpFile), $loc);

            // add info
            file_put_contents($backUpFile, '<a href="'.$loc.'">This backup file in '.date('Y-m-d H:i:s').' from origin</a><hr/>'.PHP_EOL.file_get_contents($backUpFile));
            if ($_GET['backup']=='del' and strpos($file, ERROR_VIEW_PATH.ERROR_BACKUP_DIR)===false) {
                unlink($file);
            }
            else {
                // add info
                file_put_contents($file, '... <a href="'.$backUpFileUrl.'">This file was backup '.date('Y-m-d H:i:s').'</a><hr/>'.PHP_EOL);
            }
            header('Location: '.$_SERVER['HTTP_REFERER']);
        }
        else {
            echo "не удалось скопировать $file...\n";
        }
    }

    static function doBackUpDir($dir) {
        if (!is_dir($dir)) {
            echo 'Is not Dir';
        }
        $backUpFileDir = str_replace(ERROR_VIEW_PATH, ERROR_VIEW_PATH.ERROR_BACKUP_DIR, $dir);
        if (file_exists($backUpFileDir)) {
            $backUpFileDir = rtrim($backUpFileDir, '/').'_'.time();
        }
//        var_dump($dir, $backUpFileDir); exit();
        rename($dir, $backUpFileDir);
        header('Location: '.$_SERVER['HTTP_REFERER']);
    }

    /**
     * Хлебные крошки
     * @param $url
     * @return string
     */
    static function getPath($url)
    {
        $temp = preg_split('/\//', $url, -1, PREG_SPLIT_NO_EMPTY);
        $ctr = '';
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        $basePath = $fullPath = rtrim($url['path'], '/'). '/?showErrLogs=';
        foreach ($temp as $r) {
            $fullPath .= '/' . $r;
            $ctr .= '/<a href="' . $fullPath . '">' . $r . '</a>';
        }
        return '<a href="'.$basePath.'">Home</a>' . $ctr;
    }

    /**
     * @return string
     */
    public function getAllLogs() {
        return $this->allLogs;
    }

    /**
     * Рекурсивно удаляем директорию
     * @param $dir
     * @return bool
     */
    public static function delTree($dir) {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
}

// print logs function
if (isset($_GET[ERROR_VIEW_GET])) {
    PHPErrorCatcher::logView();
    exit();
}
