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
            echo '<html>';
            echo $this->renderViewHead($file);
            echo '<body>';
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
        ?>
        <head>
            <title>LogView<?= ($file ? ':' . $file : '') ?></title>
            <meta http-equiv="Cache-Control" content="no-cache">
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
            <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
            <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
            <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
            <script>
				selectText = function (e) {
					var r, s;
					if (window.getSelection) {
						s = window.getSelection();
						if (s.setBaseAndExtent) {
							s.setBaseAndExtent(e, 0, e, e.innerText.length - 1);
						} else {
							r = document.createRange();
							r.selectNodeContents(e);
							s.removeAllRanges();
							s.addRange(r);
						}
					} else if (document.getSelection) {
						s = document.getSelection();
						r = document.createRange();
						r.selectNodeContents(e);
						s.removeAllRanges();
						s.addRange(r);
					} else if (document.selection) {
						r = document.body.createTextRange();
						r.moveToElementText(e);
						r.select();
					}
				}

				$(document).ready(function () {
					$('.linkDel').on('click', function () {
						if (confirm('Удалить?'))
							return true;
						return false;
					});
					$('.xdebug-item-file a, .bug_file a').on('click', function () {
						selectText(this);
						return false;
					});
				});
            </script>
            <?= $this->renderViewScript() ?>
        </head>
        <?php
    }

    public function renderViewScript() {
        ?>
        <style>
            .xsp.unfolded > .xsp-head::before {
                content: " - ";
            }

            .xsp > .xsp-head::before {
                content: " + ";
            }

            .xsp > .xsp-body {
                display: none;
            }

            .xsp.unfolded > .xsp-body {
                display: block;
            }

            .xsp > .xsp-head {
                color: #797979;
                cursor: pointer;
            }

            .xsp > .xsp-head:hover {
                color: black;
            }

            .pecToolbar {
                position: fixed;
                z-index: 9999;
                top: 10px;
                right: 10px;
                min-width: 300px;
                max-width: 40%;
                padding: 10px;
                background: gray;
                text-align: right;
            }

            .pecToolbar > .xsp-body {
                margin-top: 10px;
                text-align: left;
            }

            .xdebug > .xsp-body {
                padding: 0;
                padding: 0 0 0 1em;
                border-bottom: 1px dashed #C3CBD1;
                color: black;
                font-size: 10px;
            }
            .trace > .xsp-body {
                white-space: pre-wrap;
            }

            .bugs {
                border-top: 3px gray solid;
                margin-top: 10px;
                padding-top: 5px;
            }

            .bug_item {
                padding: 10px 5px;
            }

            .bug_item span {
                padding: 0 5px 0 0;
            }

            .bug_time {
            }

            .bug_mctime {
                font-style: italic;
                font-size: 0.8em;
                margin: 0 3px;
            }

            .bug_type {
                font-weight: bold;
            }

            .bugs_post {
                dispaly: inline-flex;
            }

            .bug_str {
            }

            .bug_vars .xsp-body,
            .bug_vars .small,
            .bugs_post .xsp-body,
            .bugs_post .small {
                white-space: pre-wrap;
            }

            .bug_file {
            }

            <?php  foreach ($this->_errorListView as $errno => $error): ?>
            .bug_level_<?=$errno?> .bug_type {
                color: <?=$error['color']?>;
            }
            .pre {
                white-space: pre;
            }
            <?php endforeach; ?>
        </style>
        <script>
			function bugSp(obj) {
				var obj = obj.parentNode;
				if (obj.className.indexOf('unfolded') >= 0) obj.className = obj.className.replace('unfolded', ''); else obj.className = obj.className + ' unfolded';
			}
        </script>
        <?php
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
        $html = '<div class="bugs">' .
            '<span class="bugs_host">' . $httpData->host . '</span> ';
        if (!empty($httpData->url))
            $html .= '<span class="bugs_uri">'. $httpData->method.' ' . $httpData->url . '</span> ';
        if (!empty($httpData->ip_addr))
            $html .= '<span class="bugs_ip">' . $httpData->ip_addr . '</span> ';
        if (!empty($httpData->referrer))
            $html .= '<span class="bugs_ref">' . $httpData->referrer . '</span> ';
        if (!empty($httpData->overMemory))
            $html .= '<span class="bugs_alert">Over memory limit</span> ';

//        if (!empty($this->_owner->get('_postData'))) {
//            $res .= PHP_EOL . '<div class="bugs_post xsp"><div class="xsp-head" onclick="bugSp(this)">POST</div><div class="xsp-body pre">'
//                . $this->_owner->renderVars($this->_owner->get('_postData')) . '</div></div>';
////            $res .= PHP_EOL . '<div class="bugs_post">' . self::renderVars($this->_postData) . '</div>';
//        }
//        if (!empty($this->_owner->get('_cookieData'))) {
//            $res .= PHP_EOL . '<div class="bugs_post xsp"><div class="xsp-head" onclick="bugSp(this)">COOKIE</div><div class="xsp-body pre">'
//                . $this->_owner->renderVars($this->_owner->get('_cookieData')) . '</div></div>';
////            $res .= PHP_EOL . '<div class="bugs_post">' . self::renderVars($this->_postData) . '</div>';
//        }
//        if (!empty($this->_owner->get('_sessionData'))) {
//            $res .= PHP_EOL . '<div class="bugs_post xsp"><div class="xsp-head" onclick="bugSp(this)">SESSION</div><div class="xsp-body pre">'
//                . $this->_owner->renderVars($this->_owner->get('_sessionData')) . '</div></div>';
////            $res .= PHP_EOL . '<div class="bugs_post">' . self::renderVars($this->_postData) . '</div>';
//        }

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
//        if ($vars) {
//            $vars = self::renderVars($vars);
//            if (mb_strlen($vars) > $this->limitString) {
//                $debug .= '<div class="bug_vars xsp"><div class="xsp-head" onclick="bugSp(this)">Vars</div><div class="xsp-body">' . $vars . '</div></div>';
//            } else {
//                $debug .= '<div class="bug_vars small">' . $vars . '</div>';
//            }
//        }
        if ($logData->trace) {
            $res .= '<div class="trace xsp"><div class="xsp-head" onclick="bugSp(this)">Trace</div><div class="xsp-body pre">'
                . $logData->trace
                . '</div></div>';
        }

        $res .= '</div>';
        return $res;
    }



//    /**
//     * Функция трасировки ошибок
//     * @param int $slice
//     * @param array $traceArr
//     * @return string
//     */
//    public static function renderBackTrace($slice = 1, $traceLimit = 5, $traceArr = null) {
//        $s = '<div class="xdebug xsp"><div class="xsp-head" onclick="bugSp(this)">Trace</div><div class="xsp-body">';
//        if (!$traceArr || !is_array($traceArr)) {
//            $traceArr = debug_backtrace();
//        }
//        $traceArr = array_slice($traceArr, $slice, $traceLimit);
//        $i = 0;
//        foreach ($traceArr as $arr) {
//            $s .= '<div class="xdebug-item" style="margin-left:' . (10 * $i) . 'px;">' . '<span class="xdebug-item-file">';
//            if (isset($arr['line']) and $arr['file']) {
//                //                $s .= ' in <a href="file:/' . $arr['file'] . '">' . $arr['file'] . ':' . $arr['line'] . '</a> , ';
//                $s .= ' in <a href="idea://open?url=file://' . $arr['file'] . '&line=' . $arr['line'] . '">' . $arr['file'] . ':' . $arr['line'] . '</a> , ';
//            }
//            if (isset($arr['class'])) $s .= '#class <b>' . $arr['class'] . '-></b>';
//            $s .= '</span>';
//            //$s .= '<br/>';
//            $args = [];
//            if (isset($arr['args'])) {
//                foreach ($arr['args'] as $v) {
//                    if (is_null($v)) $args[] = '<b class="xdebug-item-arg-null">NULL</b>'; else if (is_array($v)) $args[] = '<b class="xdebug-item-arg-arr">Array[' . sizeof($v) . ']</b>'; else if (is_object($v)) $args[] = '<b class="xdebug-item-arg-obj">Object:' . get_class($v) . '</b>'; else if (is_bool($v)) $args[] = '<b class="xdebug-item-arg-bool">' . ($v ? 'true' : 'false') . '</b>'; else {
//                        $v = (string)@$v;
//                        $str = static::_e(substr($v, 0, 128));
//                        if (strlen($v) > 128) $str .= '...';
//                        $args[] = '<b class="xdebug-item-arg">' . $str . '</b>';
//                    }
//                }
//            }
//            if (isset($arr['function'])) {
//                $s .= '<span class="xdebug-item-func"><b>' . $arr['function'] . '</b>(' . implode(',', $args) . ')</span>';
//            }
//            $s .= '</div>';
//            $i++;
//        }
//        $s .= '</div></div>';
//        return $s;
//    }
}