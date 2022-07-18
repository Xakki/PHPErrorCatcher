<?php

namespace Xakki\PhpErrorCatcher\viewer;

use Xakki\PhpErrorCatcher\HttpData;
use Xakki\PhpErrorCatcher\LogData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\storage\FileStorage;

class FileViewer extends BaseViewer
{
    public const VERSION = '0.1';

    public array $errorListView = [
        PhpErrorCatcher::LEVEL_CRITICAL => [
            'type' => '[Critical]',
            'color' => 'red',
            'debug' => 1,
        ],
        PhpErrorCatcher::LEVEL_ERROR => [
            'type' => '[Error]',
            'color' => 'red',
            'debug' => 1,
        ],
        PhpErrorCatcher::LEVEL_WARNING => [
            'type' => '[Warning]',
            'color' => '#F18890',
            'debug' => 1,
        ],
        PhpErrorCatcher::LEVEL_NOTICE => [
            'type' => '[Notice]',
            'color' => '#858585',
            'debug' => 0,
        ],
        PhpErrorCatcher::LEVEL_INFO => [
            'type' => '[INFO]',
            'color' => '#858585',
            'debug' => 0,
        ],
        PhpErrorCatcher::LEVEL_TIME => [ // 8192
            'type' => '[TIME]',
            'color' => 'brown',
            'debug' => 0,
        ],
        PhpErrorCatcher::LEVEL_DEBUG => [ // 16384
            'type' => '[DEBUG]',
            'color' => '#c6c644',
            'debug' => 0,
        ],
    ];

    protected FileStorage $fileStorage;

    public function __construct(PhpErrorCatcher $owner, array $config = [])
    {
        parent::__construct($owner, $config);
        if (empty($this->initGetKey)) {
            echo '<p>need initGetKey for FileViewer</p>';
            exit();
        }
        // @phpstan-ignore-next-line
        $this->fileStorage = $owner->getStorage('FileStorage');
        if (!$this->fileStorage) {
            echo '<p>need FileStorage for FileViewer</p>';
            exit();
        }
    }

    /**
     * Просмотр логов
     */
    public function renderView(): void
    {
        $url = str_replace([
            '\\',
            '\/\/',
            '\.\/',
            '\.\.',
        ], '', $_GET[$this->initGetKey]);

        $file = trim($url, '/');

        if (!empty($_GET['download'])) {
            header('Content-Type: application/octet-stream');
        } else {
            header('Content-type: text/html; charset=UTF-8');
        }

        if (!isset($_GET['only']) && empty($_GET['backup'])) {
            $this->renderViewHead($file);
        }

        //        if ($file == 'BD') {
        //            echo $this->viewRenderBD();
        //        }
        //        elseif ($file == 'PROF') {
        //            echo $this->viewRenderPROF();
        //        }
        //        else
        if ($file == 'rawlog') {
            if (file_exists($this->owner->getRawLogFile())) {
                if (!empty($_GET['del'])) {
                    unlink($this->owner->getRawLogFile());
                    echo '--empty--';
                } else {
                    echo '<p><a href="' . $this->getHomeUrl('') . 'rawlog&del=1" class="linkDel">Удалить</a></p>';
                    echo $this->renderJsonLogs($this->owner->getRawLogFile());
                }
            } else {
                echo '--empty--';
            }
        } elseif ($file == 'PHPINFO') {
            phpinfo();
        } elseif ($file == 'storage') {
            [$st, $fn] = explode('/', $_GET['fname']);
            echo '<p>';
            if ($this->owner->getStorage($st) && method_exists($this->owner->getStorage($st), 'action' . $fn)) {
                echo call_user_func([$this->owner->getStorage($st), 'action' . $fn]);
            } else {
                echo 'No action call';
            }
            echo '</p>';
        } else {
            $file = rtrim($this->fileStorage->getLogPath(), '/') . '/' . $file;

            if ($file) {
                if (!file_exists($file)) {
                    header('Location: ' . $this->getPreviosUrl());
                    return;
                } elseif (is_dir($file)) {
                    if (isset($_GET['backup'])) {
                        $this->viewCreateBackUpDir($file);
                        return;
                    }
                    echo $this->renderViewBreadCrumb($url);
                    echo $this->renderViewDirList(static::viewGetDirList($url));
                } else {
                    if (isset($_GET['backup'])) {
                        $this->viewCreateBackUp($file);
                        return;
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
     */
    public function renderViewHead(string $file): void
    {
        include __DIR__ . '/file/tpl.php';
    }

    /**
     * Просмотр Директории логов
     */
    public function viewGetDirList(string $path = ''): array
    {
        $dirList1 = $dirList2 = [];
        $path = trim($path, '/.');
        if ($path) {
            $path = '/' . $path;
        }
        $fullPath = $this->fileStorage->getLogPath() . $path;

        if (!file_exists($fullPath)) {
            if (!$this->mkdir($fullPath)) {
                echo ' Cant create dir ' . $fullPath;
                return [];
            }
        }
        $isBackUpDir = $this->checkIsBackUp($fullPath);
        $dir = dir($fullPath);
        $homeUrl = $this->getHomeUrl();

        while (false !== ($entry = $dir->read())) {
            if ($entry != '.' && $entry != '..') {
                $fileUrl = $homeUrl . urlencode($path . '/' . $entry);

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
                            '',
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
                            '',
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
                    ($size ? ' <a href="' . $fileUrl . '&only=1" class="linkSource">Source</a>' : '') . (!$isBackUpDir && ($path || !$this->checkIsBackUp($filePath)) ? ' <a href="' . $fileUrl . '&backup=do">Бекап</a> <a href="' . $fileUrl . '&backup=del" class="linkDel">Удалить</a>' : ''),
                    $createTime,
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
        return $dirList1 + $dirList2;
    }

    /**
     * Рендер директории логов
     */
    public function renderViewDirList(array $dirList): string
    {
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
     */
    private function viewCreateBackUp(string $file): void
    {
        if (!file_exists($file)) {
            header('Location: ' . $this->getPreviosUrl());
        } elseif (is_dir($file)) {
            echo 'Is Dir';
        } elseif ($this->checkIsBackUp($file)) {
            echo 'Is BackUp Dir: is protect  dir';
        }

        if (defined('ERROR_NO_BACKUP')) {
            unlink($file);
            header('Location: ' . $this->getPreviosUrl());
        } else {
            $backUpFile = str_replace($this->fileStorage->getLogPath(), $this->fileStorage->getLogPath() . $this->fileStorage->getBackUpDir(), $file);
            $backUpFileDir = dirname($backUpFile);
            if (!file_exists($backUpFileDir)) {
                $this->mkdir($backUpFileDir);
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
                    '&backup=del',
                ], '', $_SERVER['REQUEST_URI']);
                $backUpFileUrl = $this->getFileUrl($backUpFile);

                // add info
                file_put_contents($backUpFile, PHP_EOL . '<hr/><a href="' . $loc . '">This backup file in ' . date('Y-m-d H:i:s') . ' from origin</a>', FILE_APPEND);
                if ($_GET['backup'] == 'del' && strpos($file, $this->fileStorage->getLogPath() . $this->fileStorage->getBackUpDir()) === false) {
                    echo '';
                } else {
                    // add info
                    file_put_contents($file, '... <a href="' . $backUpFileUrl . '">This file was backup ' . date('Y-m-d H:i:s') . '</a><hr/>' . PHP_EOL);
                }
                header('Location: ' . $this->getPreviosUrl());
            } else {
                echo "не удалось переместить $file...\n";
            }
        }
    }

    /**
     * Бекапи логи
     */
    private function viewCreateBackUpDir(string $dir): void
    {
        if (!is_dir($dir)) {
            echo 'Is not Dir';
        }
        if (defined('ERROR_NO_BACKUP')) {
            static::delTree($dir);
        } else {
            $backUpFileDir = str_replace($this->fileStorage->getLogPath(), $this->fileStorage->getLogPath() . $this->fileStorage->getBackUpDir(), $dir);
            if (file_exists($backUpFileDir)) {
                $backUpFileDir = rtrim($backUpFileDir, '/') . '_' . time();
            } else {
                $parentDir = dirname($backUpFileDir);
                if (!file_exists($parentDir)) {
                    $this->mkdir($parentDir);
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
     */
    private function renderViewBreadCrumb(string $url): string
    {
        $temp = preg_split('/\//', $url, -1, PREG_SPLIT_NO_EMPTY);
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        $basePath = $fullPath = $this->getHomeUrl('');
        $ctr = '<li class="breadcrumb-item"><a href="' . $basePath . '">Home</a></li>';
        foreach ($temp as $r) {
            $fullPath .= '/' . $r;
            $ctr .= '<li class="breadcrumb-item"><a href="' . $fullPath . '">' . $r . '</a></li>';
        }

        return '<nav aria-label="breadcrumb"><ol class="breadcrumb">' . $ctr . '</ol></nav>';
    }

    private function getPreviosUrl()
    {
        $temp = preg_split('/\//', $_GET[$this->initGetKey], -1, PREG_SPLIT_NO_EMPTY);
        array_pop($temp);
        return '?' . $this->initGetKey . '=/' . implode('/', $temp);
    }

    /*********************************/

    private function checkIsBackUp(string $file): bool
    {
        return strpos($file, $this->fileStorage->getLogPath() . $this->fileStorage->getBackUpDir()) !== false;
    }

    /**
     * Рекурсивно удаляем директорию
     */
    public static function delTree(string $dir): bool
    {
        $files = array_diff(scandir($dir), [
            '.',
            '..',
        ]);
        foreach ($files as $file) {
            is_dir("$dir/$file") ? static::delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    public function getFileContent(string $file): string
    {
        // if ()
        // mime_content_type
        $pathinfo = pathinfo($file);
        $isImg = getimagesize($file);
        $pathinfo['extension'] = $pathinfo['extension'] ?? '';
        if ($isImg) {
            return '<br/><img src="' . $this->owner->getRelativeFilePath($file) . '" alt="' . $pathinfo['basename'] . '" style="max-width:100%;"/>';
        } elseif ($pathinfo['extension'] == 'html' || isset($_GET['only'])) {
            return file_get_contents($file);
        } elseif ($pathinfo['extension'] == FileStorage::FILE_EXT) {
            return $this->renderJsonLogs($file);
        } else {
            return '<pre>' . file_get_contents($file) . '</pre>';
        }
    }

    public function getFileUrl($realFilePath)
    {
        $realFilePath = str_replace($this->fileStorage->getLogPath(), '', $realFilePath);
        return $this->getHomeUrl() . ltrim($realFilePath, '/');
    }

    /*******************************************************/
    /*******************************************************/
    /*******************************************************/
    /*******************************************************/

    protected function renderJsonLogs(string $file): string
    {
        $html = '';
        if (!file_exists($file)) {
            return 'No file';
        }
        $fileHandle = fopen($file, "r");
        while (!feof($fileHandle)) {
            $line = fgets($fileHandle);
            if (!$line) {
                continue;
            }
            $data = json_decode($line);
            if (!empty($data->http) && !empty($data->logs)) {
                $html .= self::renderAllLogs($data->http, $data->logs);
            } else {
                $html .= '<pre>' . $line . '</pre>';
            }
            if (PhpErrorCatcher::isMemoryOver()) {
                $html = '<h3>isMemoryOver</h3>' . $html;
                break;
            }
        }
        fclose($fileHandle);
        return $html;
    }

    /**
     * Render line
     *
     * @param HttpData $httpData
     * @param LogData[] $logDatas
     * @return string
     */
    public static function renderAllLogs(HttpData $httpData, array $logDatas): string
    {
        //        $this->_owner->setSafeParams();
        //        $profilerUrlTag = '';
        //        if ($this->_profilerUrl) {
        //            $profilerUrlTag = '<div class="bugs_prof">XHPROF: <a href="' . $this->_profilerUrl . '">' . $this->_profilerId . '</a></div>';
        //        }
        $html = '<div class="bugs">';
        if (!empty($httpData->method)) {
            $html .= '<span class="bugs_uri">' . $httpData->method . '</span> ';
        }
        if (!empty($httpData->host)) {
            $html .= '<a class="bugs_uri" target="_blank" href="//' . $httpData->host . $httpData->url . '">' . $httpData->host . $httpData->url . '</a> ';
        }

        if (!empty($httpData->ipAddr)) {
            $html .= '<span class="bugs_ip">' . $httpData->ipAddr . '</span> ';
        }
        if (!empty($httpData->referrer)) {
            $html .= '<span class="bugs_ref">' . $httpData->referrer . '</span> ';
        }
        if (!empty($httpData->overMemory)) {
            $html .= '<span class="bugs_alert">Over memory limit</span> ';
        }

        foreach ($logDatas as $logData) {
            $html .= static::renderItemLog($logData);
        }

        return $html . '</div>';
    }

    public static function renderItemLog(LogData $logData): string
    {
        $dt = explode('.', (string) $logData->timestamp);
        $res = '<div class="bug_item bug_level_' . $logData->level . '">'
            . '<span class="bug_time">' . date('H:i:s', $dt[0]) . '.' . $dt[1] . '</span>'
            . '<span class="bug_type">' . $logData->type . ' : ' . $logData->level . ($logData->count > 1 ? '[' . $logData->count . ']' : '') . '</span>';
        //(isset($logData->fields[PhpErrorCatcher::FIELD_ERR_CODE]) ? $logData->fields[PhpErrorCatcher::FIELD_ERR_CODE] : E_UNRECONIZE)
        if ($logData->tags) {
             array_walk($logData->tags, function (&$v) {
                if (!is_string($v)) {
                    $v = PhpErrorCatcher::dumpAsString($v);
                }
                if (mb_strlen($v) > 32) {
                    $v = mb_substr($v, 32) . '...';
                }
                 $v = PhpErrorCatcher::esc($v);
             });
            $res .= '<span class="bug_tags">[' . implode(', ', $logData->tags) . ']</span>';
        }
        if ($logData->fields) {
            $logData->fields = (array) $logData->fields;
             array_walk($logData->fields, function (&$v) {
                if (!is_string($v)) {
                    $v = PhpErrorCatcher::dumpAsString($v);
                }
                if (mb_strlen($v) > 512) {
                    $v = mb_substr($v, 512) . '...';
                }
             });
            $res .= '<span class="bug_fields">' . json_encode($logData->fields) . '</span>';
        }
        if ($logData->file) {
            //        $debug .= '<div class="bug_file"> File <a href="file:/' . $errfile . ':' . $errline . '">' . $errfile . ':' . $errline . '</a></div>';
            $fl = explode(':', $logData->file);
            $res .= '<span class="bug_file"> File <a href="' . static::getFileIdea($fl[0], $fl[1]) . '">' . $logData->file . '</a></span>';
        }
        $res .= '<div class="bug_str">' . PhpErrorCatcher::esc($logData->message) . '</div>';

        if ($logData->trace) {
            $res .= '<div class="trace xsp"><div class="xsp-head" onclick="bugSp(this)">Trace</div><div class="xsp-body">';
            foreach (explode(PHP_EOL, $logData->trace) as $tr) {
                $r = explode('|', $tr);
                if (isset($r[2])) {
                    [$tFile, $tLine] = explode(':', trim($r[1]));
                    $res .= '<div>' . $r[0] . ' <a href="' . static::getFileIdea($tFile, $tLine) . '">' . $r[2] . '</a></div>';
                } else {
                    $res .= '<div>' . PhpErrorCatcher::esc($tr) . '</div>';
                }
            }
            $res .= '</div></div>';
        }

        $res .= '</div>';
        return $res;
    }

    public static function getFileIdea(string $file, int $line): string
    {
        return 'idea://open?url=file://' . PhpErrorCatcher::$realPath . $file . '&line=' . $line;
    }
}
