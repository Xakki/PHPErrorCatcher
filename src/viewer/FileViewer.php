<?php

namespace xakki\phperrorcatcher\viewer;

use xakki\phperrorcatcher\LogData;
use xakki\phperrorcatcher\HttpData;
use xakki\phperrorcatcher\PHPErrorCatcher;
use xakki\phperrorcatcher\storage\FileStorage;

class FileViewer extends BaseViewer {

    const VERSION = '0.1';
    /**
     * Error list
     * @var array
     */
    private $_errorListView = [
        PHPErrorCatcher::LEVEL_CRITICAL => [
            'type' => '[Critical]',
            'color' => 'red',
            'debug' => 1
        ],
        PHPErrorCatcher::LEVEL_ERROR => [
            'type' => '[Error]',
            'color' => 'red',
            'debug' => 1
        ],
        PHPErrorCatcher::LEVEL_WARNING => [
            'type' => '[Warning]',
            'color' => '#F18890',
            'debug' => 1
        ],
        PHPErrorCatcher::LEVEL_NOTICE => [
            'type' => '[Notice]',
            'color' => '#858585',
            'debug' => 0
        ],
        PHPErrorCatcher::LEVEL_INFO => [
            'type' => '[INFO]',
            'color' => '#858585',
            'debug' => 0
        ],

        PHPErrorCatcher::LEVEL_TIME => [ // 8192
            'type' => '[TIME]',
            'color' => 'brown',
            'debug' => 0
        ],
        PHPErrorCatcher::LEVEL_DEBUG => [ // 16384
            'type' => '[DEBUG]',
            'color' => '#c6c644',
            'debug' => 0
        ],
    ];

    /* @var FileStorage */
    protected $_fileStorage;

    function __construct(PHPErrorCatcher $owner, $config = []) {
        parent::__construct($owner, $config);
        if (empty($this->initGetKey)) return;
        $this->_fileStorage = $owner->getStorage('FileStorage');
        if (!$this->_fileStorage) {
            echo '<p>need FileStorage for FileViewer</p>';
            exit();
        }
        ini_set("memory_limit", "128M");

        ob_start();
        $html = $this->renderView();
        $html .= ob_get_contents();
        ob_end_clean();
        echo $html;
        exit();
    }

    /**
     * Просмотр логов
     */
    public function renderView() {

        $url = str_replace([
            '\\',
            '\/\/',
            '\.\/',
            '\.\.'
        ], '', $_GET[$this->initGetKey]);

        $file = trim($url, '/');
        $tabs = [
            'BD' => '',
            'PROF' => '',
            'PHPINFO' => '',
        ];

        if (!empty($_GET['download'])) {
            header('Content-Type: application/octet-stream');
        } else {
            header('Content-type: text/html; charset=UTF-8');
        }

        if (!isset($_GET['only']) && empty($_GET['backup'])) {
            $this->renderViewHead($file);

            $home = $this->getHomeUrl('');


            echo '<ul class="nav nav-tabs">';
            echo '<li class="nav-item"><a class="nav-link' . (!isset($tabs[$file]) ? ' active' : '') . '" href="' . $home . '/">Логи</a></li>';

            if (file_exists($this->_owner->getRawLogFile())) {
                echo '<li class="nav-item"><a class="text-danger nav-link' . ($file=='rawlog' ? ' active' : '') . '" href="' . $home . 'rawlog">Errors</a></li>';
            }
            //($this->owner->getPdo() ? '<li class="nav-item"><a class="nav-link' . ($file == 'BD' ? ' active' : '') . '" href="' . $home . 'BD">BD</a></li>' : '') .
            //($this->owner->get('_profilerStatus') ? '<li class="nav-item"><a class="nav-link' . ($file == 'PROF' ? ' active' : '') . '" href="' . $home . 'PROF&source=' . $this->owner->get('profiler_namespace') . '&run=">Профаилер</a></li>' : '') .
            echo '<li class="nav-item"><a class="nav-link' . ($file == 'PHPINFO' ? ' active' : '') . '" href="' . $home . 'PHPINFO">PHPINFO</a></li>';
            echo '<li class="nav-item"><a class="nav-link" href="?" target="_blank">HOME</a></li>';
            echo '<li class="nav-item"><a class="nav-link">Core ver: '.PHPErrorCatcher::VERSION.'; Viewer ver: '.self::VERSION.'</a></li>';
            echo  '</ul>';
        }


//        if ($file == 'BD') {
//            echo $this->viewRenderBD();
//        }
//        elseif ($file == 'PROF') {
//            echo $this->viewRenderPROF();
//        }
//        else
        if ($file == 'rawlog') {
            if (file_exists($this->_owner->getRawLogFile())) {
                if (!empty($_GET['del'])) {
                    unlink($this->_owner->getRawLogFile());
                    echo '--empty--';
                } else {
                    echo '<p><a href="' . $home . 'rawlog&del=1" class="linkDel">Удалить</a></p>';
                    echo $this->renderJsonLogs($this->_owner->getRawLogFile());
                }
            } else {
                echo '--empty--';
            }
        }
        elseif ($file == 'PHPINFO') {
            phpinfo();
        }
        else {
            $file = $this->_fileStorage->getLogPath() . '/' . $file;

            if ($file) {
                if (!file_exists($file)) {
                    header('Location: ' . $this->getPreviosUrl());
                    return '';
                }
                elseif (is_dir($file)) {
                    if (isset($_GET['backup'])) {
                        $this->viewCreateBackUpDir($file);
                        return '';
                    }
                    echo $this->renderViewBreadCrumb($url);
                    echo $this->renderViewDirList(static::viewGetDirList($url));
                }
                else {
                    if (isset($_GET['backup'])) {
                        $this->viewCreateBackUp($file);
                        return '';
                    }

                    if (!isset($_GET['only'])) {
                        echo $this->renderViewBreadCrumb($url);

                        if (!$this->checkIsBackUp($file)) {
                            echo ' [<a href="' . $_SERVER['REQUEST_URI'] . '&only=1&download=1" class="linkSource">Download</a> <a href="' . $_SERVER['REQUEST_URI'] . '&only=1" class="linkSource">Source</a> <a href="' . $_SERVER['REQUEST_URI'] . '&backup=do">Бекап</a> <a href="' . $_SERVER['REQUEST_URI'] . '&backup=del">Удалить</a>]';
                        }
                        echo '</h3>';
                    }
                    //chmod($file, 0777);
                    echo $this->getFileContent($file);
                }
            } else {
                echo '<h3>Logs</h3>';
                echo $this->renderViewDirList(static::viewGetDirList());
            }
        }

        if (!isset($_GET['only'])) {
            echo '</body></html>';
        }
    }

    /**
     * рендер заголовка HTML
     * @param $file
     * @return string
     */
    public function renderViewHead($file) {
        include __DIR__.'/file/tpl.php';
    }

    /**
     * Просмотр Директории логов
     * @param string $path
     * @return array
     */
    public function viewGetDirList($path = '') {
        $dirList1 = $dirList2 = [];
        $path = trim($path, '/.');
        $fullPath = $this->_fileStorage->getLogPath() . ($path ? '/' . $path : '');

        if (!file_exists($fullPath)) {
            $oldUmask = umask(0);
            if (!mkdir($fullPath, 0775, true)) {
                echo ' Cant create dir ' . $fullPath;
                return [];
            }
            umask($oldUmask);
        }
        $isBackUpDir = $this->checkIsBackUp($fullPath);
        $dir = dir($fullPath);
        $homeUrl = $this->getHomeUrl();

        while (false !== ($entry = $dir->read())) {
            if ($entry != '.' && $entry != '..') {
                $fileUrl = $homeUrl . $path . '/' . $entry;
                $filePath = $fullPath . '/' . $entry;


                if (is_dir($filePath)) {
                    $size = $create = '';
                    $createTime = 0;
                    if (!is_readable($filePath)) {
                        $dirList1[$entry] = [
                            $path . '/' . $entry,
                            '',
                            '',
                            '',
                            ''
                        ];
                        continue;
                    }
                } else {
                    if (!is_readable($filePath)) {
                        $dirList2[$entry] = [
                            $path . '/' . $entry,
                            '',
                            '',
                            '',
                            ''
                        ];
                        continue;
                    }
                    //                    trigger_error($this->_fileStorage->getLogPath() . ' * ' . $path . ' * ' . $entry. '=> '.$filePath, E_USER_DEPRECATED);
                    $size = filesize($filePath);
                    $createTime = filemtime($filePath);
                    $create = date("Y-m-d H:i:s", $createTime);
                    $size = number_format($size, 0, '', ' ') . ' б.';
                }

                $tmp = [
                    '<a href="' . $fileUrl . '" style="' . (is_dir($filePath) ? 'font-weight:bold;' : '') . '">' . $path . '/' . $entry . '</a> ',
                    $size,
                    $create,
                    ($size ? ' <a href="' . $fileUrl . '&only=1" class="linkSource">Source</a>' : '') . ((!$isBackUpDir && ($path || !$this->checkIsBackUp($filePath))) ? ' <a href="' . $fileUrl . '&backup=do">Бекап</a> <a href="' . $fileUrl . '&backup=del" class="linkDel">Удалить</a>' : ''),
                    $createTime
                ];
                // glyphicon glyphicon-hdd
                // glyphicon glyphicon-trash
                if ($size === '') {
                    $dirList1[$entry] = $tmp;
                } else {
                    $dirList2[$entry] = $tmp;
                }
            }
        }
        krsort($dirList1);
        krsort($dirList2);
        $dirList = $dirList1 + $dirList2;
        return $dirList;
    }

    /**
     * Рендер директории логов
     * @param $dirList
     * @return string
     */
    public function renderViewDirList($dirList) {
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
        foreach ($dirList as $row) {
            $html .= '<tr><td>' . $row[0] . '<td>' . $row[1] . '<td>' . $row[2] . '<td>' . $row[3] . '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Делаем бекап фаила и ссылку на него
     * @param $file
     */
    private function viewCreateBackUp($file) {

        if (!file_exists($file)) {
            header('Location: ' . $this->getPreviosUrl());
        }
        elseif (is_dir($file)) {
            echo 'Is Dir';
        }
        elseif ($this->checkIsBackUp($file)) {
            echo 'Is BackUp Dir: is protect  dir';
        }

        if (defined('ERROR_NO_BACKUP')) {
            unlink($file);
            header('Location: ' . $this->getPreviosUrl());
        }
        else {
            $backUpFile = str_replace($this->_fileStorage->getLogPath(), $this->_fileStorage->getLogPath() . $this->_fileStorage->getBackUpDir(), $file);
            $backUpFileDir = dirname($backUpFile);
            if (!file_exists($backUpFileDir)) {
                $oldUmask = umask(0);
                mkdir($backUpFileDir, 0775, true);
                umask($oldUmask);
            }

            if (file_exists($backUpFile)) {
                $i = pathinfo($backUpFile);
                $backUpFile = $i['dirname'] . '/' . $i['filename'] . '.' . time() . '.' . $i['extension'];
            }
            if (file_exists($backUpFile)) {
                echo 'Error!';
                return;
            }
            $fileInfo = pathinfo($file);
            if (rename($file, $backUpFile)) {
                $loc = str_replace([
                    '&backup=do',
                    '&backup=del'
                ], '', $_SERVER['REQUEST_URI']);
                $backUpFileUrl = $this->getFileUrl($backUpFile);

                // add info
                file_put_contents($backUpFile, PHP_EOL . '<hr/><a href="' . $loc . '">This backup file in ' . date('Y-m-d H:i:s') . ' from origin</a>', FILE_APPEND);
                if ($_GET['backup'] == 'del' and strpos($file, $this->_fileStorage->getLogPath() . $this->_fileStorage->getBackUpDir()) === false) {
                    //
                }
                else {
                    // add info
                    file_put_contents($file, '... <a href="' . $backUpFileUrl . '">This file was backup ' . date('Y-m-d H:i:s') . '</a><hr/>' . PHP_EOL);
                }
                header('Location: ' . $this->getPreviosUrl());
            }
            else {
                echo "не удалось переместить $file...\n";
            }
        }
    }

    /**
     * Бекапи логи
     * @param $dir
     */
    private function viewCreateBackUpDir($dir) {
        if (!is_dir($dir)) {
            echo 'Is not Dir';
        }
        if (defined('ERROR_NO_BACKUP')) {
            static::delTree($dir);
        } else {
            $backUpFileDir = str_replace($this->_fileStorage->getLogPath(), $this->_fileStorage->getLogPath() . $this->_fileStorage->getBackUpDir(), $dir);
            if (file_exists($backUpFileDir)) {
                $backUpFileDir = rtrim($backUpFileDir, '/') . '_' . time();
            } else {
                $parentDir = dirname($backUpFileDir);
                if (!file_exists($parentDir)) {
                    $oldUmask = umask(0);
                    mkdir($parentDir, 0775, true);
                    umask($oldUmask);
                }
            }
            rename($dir, $backUpFileDir);
        }
        if (!headers_sent()) {
            header('Location: ' . $_SERVER['HTTP_REFERER']);
        }
    }

    /**
     * Хлебные крошки
     * @param $url
     * @return string
     */
    private function renderViewBreadCrumb($url) {
        $temp = preg_split('/\//', $url, -1, PREG_SPLIT_NO_EMPTY);
        $ctr = '';
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        $basePath = $fullPath = $this->getHomeUrl('');
        foreach ($temp as $r) {
            $fullPath .= '/' . $r;
            $ctr .= '<li class="breadcrumb-item"><a href="' . $fullPath . '">' . $r . '</a>';
        }
        return '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="' . $basePath . '">Home</a>' . $ctr . '</ol></nav>';
    }

    private function getPreviosUrl() {
        $temp = preg_split('/\//', $_GET[$this->initGetKey], -1, PREG_SPLIT_NO_EMPTY);
        array_pop($temp);
        return '?'.$this->initGetKey.'=/'.implode('/', $temp);
    }


    /*********************************/


    private function checkIsBackUp($file) {
        return (strpos($file, $this->_fileStorage->getLogPath() . $this->_fileStorage->getBackUpDir()) !== false);
    }


    /**
     * Рекурсивно удаляем директорию
     * @param $dir
     * @return bool
     */
    public static function delTree($dir) {
        $files = array_diff(scandir($dir), [
            '.',
            '..'
        ]);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? static::delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    public function getFileContent($file) {
        // if ()
        // mime_content_type
        $pathinfo = pathinfo($file);
        $is_img = @getimagesize($file);
        if ($is_img) {
            return '<br/><img src="' . $this->_owner->getRelativeFilePath($file) . '" alt="' . $pathinfo['basename'] . '" style="max-width:100%;"/>';
        }
        elseif ($pathinfo['extension'] == 'html' || isset($_GET['only'])) {
            return file_get_contents($file);
        }
        elseif ($pathinfo['extension'] == FileStorage::FILE_EXT) {
            return $this->renderJsonLogs($file);
        }
        else {
            return '<pre>'.file_get_contents($file).'</pre>';
        }
    }

    public function getFileUrl($realFilePath) {
        $realFilePath = str_replace($this->_fileStorage->getLogPath(), '', $realFilePath);
        return $this->getHomeUrl() . ltrim($realFilePath, '/');
    }


    /*******************************************************/
    /*******************************************************/
    /*******************************************************/
    /*******************************************************/

    protected function renderJsonLogs($file) {
        $html = '';
        if (!file_exists($file)) return 'No file';
        $file_handle = fopen($file, "r");
        while (!feof($file_handle)) {
            $line = fgets($file_handle);
            if (!$line) continue;
            $data = json_decode($line);
            if (!empty($data->http) && !empty($data->logs)) {
                $html .= $this->renderAllLogs($data->http, $data->logs);
            } else {
                $html .= '<pre>'.$line.'</pre>';
            }
            if (PHPErrorCatcher::isMemoryOver()) {
                $html = '<h3>isMemoryOver</h3>'.$html;
                break;
            }
        }
        fclose($file_handle);
        return $html;
    }

    /**
     * Render line
     * @param HttpData $httpData
     * @param LogData[] $logDatas
     * @return string
     */
    public function renderAllLogs($httpData, $logDatas) {
//        $this->_owner->setSafeParams();
//        $profilerUrlTag = '';
//        if ($this->_profilerUrl) {
//            $profilerUrlTag = '<div class="bugs_prof">XHPROF: <a href="' . $this->_profilerUrl . '">' . $this->_profilerId . '</a></div>';
//        }
        $html = '<div class="bugs">';
        if (!empty($httpData->host))
            $html .= '<span class="bugs_host">' . $httpData->host . '</span> ';
        if (!empty($httpData->url))
            $html .= '<span class="bugs_uri">'. $httpData->method.' ' . $httpData->url . '</span> ';
        if (!empty($httpData->ip_addr))
            $html .= '<span class="bugs_ip">' . $httpData->ip_addr . '</span> ';
        if (!empty($httpData->referrer))
            $html .= '<span class="bugs_ref">' . $httpData->referrer . '</span> ';
        if (!empty($httpData->overMemory))
            $html .= '<span class="bugs_alert">Over memory limit</span> ';

        foreach ($logDatas as $logData) {
            $html .= $this->renderItemLog($logData);
        }

        return $html . '</div>';
    }

    /**
     * @param LogData $logData
     * @return string
     */
    public static function renderItemLog($logData) {
        $res = '<div class="bug_item bug_level_' . $logData->level . '">'
            . '<span class="bug_time">' . date('H:i:s', $logData->timestamp) . '</span>'
            . '<span class="bug_type">' . $logData->type . ' : ' . $logData->level . ($logData->count > 1 ? '['.$logData->count.']' : '') . '</span>';
        //(isset($logData->fields[PHPErrorCatcher::FIELD_ERR_CODE]) ? $logData->fields[PHPErrorCatcher::FIELD_ERR_CODE] : E_UNRECONIZE)
        if ($logData->tags) {
            $res .= '<span class="bug_tags">[' . implode(', ',$logData->tags) . ']</span>';
        }
        if ($logData->fields) {
            $res .= '<span class="bug_fields">' . PHPErrorCatcher::renderVars((array) $logData->fields) . '</span>';
        }
        if ($logData->file) {
            //        $debug .= '<div class="bug_file"> File <a href="file:/' . $errfile . ':' . $errline . '">' . $errfile . ':' . $errline . '</a></div>';
            $fl = explode(':', $logData->file);
            $res .= '<span class="bug_file"> File <a href="idea://open?url=file://' . $fl[0] . '&line=' . $fl[1] . '">' . $logData->file . '</a></span>';
        }
        $res .= '<div class="bug_str">' . PHPErrorCatcher::_e($logData->message) . '</div>';

        if ($logData->trace) {
            $res .= '<div class="trace xsp"><div class="xsp-head" onclick="bugSp(this)">Trace</div><div class="xsp-body pre">'
                . $logData->trace
                . '</div></div>';
        }

        $res .= '</div>';
        return $res;
    }

}