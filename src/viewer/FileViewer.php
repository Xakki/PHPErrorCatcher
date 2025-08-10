<?php

namespace Xakki\PhpErrorCatcher\viewer;

use Xakki\PhpErrorCatcher\dto\LogData;
use Xakki\PhpErrorCatcher\dto\HttpData;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\storage\BaseStorage;
use Xakki\PhpErrorCatcher\storage\FileStorage;

class FileViewer extends BaseViewer
{
    const VERSION = '0.1';
    /**
     * @var array<string, array<string, mixed>>
     */
    private $errorListView = [
        PhpErrorCatcher::LEVEL_CRITICAL => [
            'type' => '[Critical]',
            'color' => 'red',
            'debug' => 1
        ],
        PhpErrorCatcher::LEVEL_ERROR => [
            'type' => '[Error]',
            'color' => 'red',
            'debug' => 1
        ],
        PhpErrorCatcher::LEVEL_WARNING => [
            'type' => '[Warning]',
            'color' => '#F18890',
            'debug' => 1
        ],
        PhpErrorCatcher::LEVEL_NOTICE => [
            'type' => '[Notice]',
            'color' => '#858585',
            'debug' => 0
        ],
        PhpErrorCatcher::LEVEL_INFO => [
            'type' => '[INFO]',
            'color' => '#858585',
            'debug' => 0
        ],

        PhpErrorCatcher::LEVEL_TIME => [ // 8192
            'type' => '[TIME]',
            'color' => 'brown',
            'debug' => 0
        ],
        PhpErrorCatcher::LEVEL_DEBUG => [ // 16384
            'type' => '[DEBUG]',
            'color' => '#c6c644',
            'debug' => 0
        ],
    ];

    /** @var FileStorage */
    protected $_fileStorage;

    /** @var string */
    protected $idePath;

    /** @var string[] */
    public $extraLinks = ['HOME' => '?'];

    /**
     * @param PhpErrorCatcher $owner
     * @param array<string, mixed> $config
     */
    public function __construct(PhpErrorCatcher $owner, $config = [])
    {
        parent::__construct($owner, $config);
        if (empty($this->initGetKey)) {
            echo '<p>need initGetKey for FileViewer</p>';
            exit();
        }
        $this->_fileStorage = $owner->getStorage('FileStorage');
        if (!$this->_fileStorage) {
            echo '<p>need FileStorage for FileViewer</p>';
            exit();
        }
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function renderView()
    {

        $url = str_replace([
            '\\',
            '\/\/',
            '\.\/',
            '\.\.'
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
        } elseif ($file == 'Memcached') {
            print_r('<pre>');
            if ($this->owner->memcache() instanceof \Memcached) {
                print_r($this->owner->memcache()->getStats());
            } else {
                echo 'No Memcached';
            }
            print_r('</pre>');
        } elseif ($file == 'storage') {
            list($st, $fn) = explode('/', $_GET['fname']);
            echo '<p>';
            /** @var BaseStorage $storageClass */
            $storageClass = $this->owner->getStorage($st);
            if ($storageClass && method_exists($storageClass, 'action' . $fn)) {
                echo call_user_func([$storageClass, 'action' . $fn]);
            } else {
                echo 'No action call';
            }
            echo '</p>';
        } else {
            $file = rtrim($this->_fileStorage->getLogPath(), '/') . '/' . $file;

            if ($file) {
                if (!file_exists($file)) {
                    header('Location: ' . $this->getPreviosUrl());
                    return '';
                } elseif (is_dir($file)) {
                    if (isset($_GET['backup'])) {
                        $this->viewCreateBackUpDir($file);
                        return '';
                    }
                    echo $this->renderViewBreadCrumb($url);
                    echo $this->renderViewDirList(static::viewGetDirList($url));
                } else {
                    if (isset($_GET['backup'])) {
                        $this->viewCreateBackUp($file);
                        return '';
                    }

                    if (isset($_GET['only'])) {
                        $size = filesize($file);
                        $handle = fopen($file, 'rb');

                        ini_set('max_execution_time', 0);
                        // Set headers to force download
                        header('Content-Type: application/octet-stream');
                        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
                        header('Content-Length: ' . $size);
                        // Clean the output buffer
                        ob_clean();
                        flush();

                        // Read and output the file in chunks
                        while (!feof($handle)) {
                            echo fread($handle, 65536); // 64KB chunks
                            ob_flush();  // Flush the output buffer
                            flush();     // Ensure that data is sent to the browser
                        }

                        // Close the file handle
                        fclose($handle);
                        exit;
                    }

                    echo $this->renderViewBreadCrumb($url);

                    if (!$this->checkIsBackUp($file)) {
                        echo ' [<a href="' . $_SERVER['REQUEST_URI'] . '&only=1&download=1" class="linkSource">Download</a> <a href="' . $_SERVER['REQUEST_URI'] . '&only=1" class="linkSource">Source</a> <a href="' . $_SERVER['REQUEST_URI'] . '&backup=do">Бекап</a> <a href="' . $_SERVER['REQUEST_URI'] . '&backup=del">Удалить</a>]';
                    }
                    echo '</h3>';
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
        return '';
    }

    /**
     * @param string $file
     * @return void
     */
    public function renderViewHead($file)
    {
        $view = $this;
        include __DIR__ . '/file/tpl.php';
    }

    /**
     * @param string $path
     * @return array<string, array<int, mixed>>
     */
    public function viewGetDirList($path = '')
    {
        $dirList1 = $dirList2 = [];
        $path = trim($path, '/.');
        if ($path) {
            $path = '/' . $path;
        }
        $fullPath = $this->_fileStorage->getLogPath() . $path;

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
                    ($size ? ' <a href="' . $fileUrl . '&only=1" class="linkSource">Download</a>' : '') . ((!$isBackUpDir && ($path || !$this->checkIsBackUp($filePath))) ? ' <a href="' . $fileUrl . '&backup=do">Бекап</a> <a href="' . $fileUrl . '&backup=del" class="linkDel">Удалить</a>' : ''),
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
     * @param array<string, array<int, mixed>> $dirList
     * @return string
     */
    public function renderViewDirList($dirList)
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
     * @param string $file
     * @return void
     */
    private function viewCreateBackUp($file)
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
            $backUpFile = str_replace($this->_fileStorage->getLogPath(), $this->_fileStorage->getLogPath() . $this->_fileStorage->getBackUpDir(), $file);
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
            $prem = fileperms($file);
            if (rename($file, $backUpFile)) {
                $loc = str_replace([
                    '&backup=do',
                    '&backup=del'
                ], '', $_SERVER['REQUEST_URI']);
                $backUpFileUrl = $this->getFileUrl($backUpFile);

                // add info
                file_put_contents($backUpFile, PHP_EOL . '<hr/><a href="' . $loc . '">This backup file in ' . date('Y-m-d H:i:s') . ' from origin</a>', FILE_APPEND);
                if ($_GET['backup'] == 'del' && strpos($file, $this->_fileStorage->getLogPath() . $this->_fileStorage->getBackUpDir()) === false) {
                    $loc = '';
                } else {
                    // add info
                    file_put_contents($file, '... <a href="' . $backUpFileUrl . '">This file was backup ' . date('Y-m-d H:i:s') . '</a><hr/>' . PHP_EOL);
                    chmod($file, $prem);
                }
                header('Location: ' . $this->getPreviosUrl());
            } else {
                echo "не удалось переместить $file...\n";
            }
        }
    }

    /**
     * @param string $dir
     * @return void
     */
    private function viewCreateBackUpDir($dir)
    {
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
     * @param string $url
     * @return string
     */
    private function renderViewBreadCrumb($url)
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

    /**
     * @return string
     */
    private function getPreviosUrl()
    {
        $temp = preg_split('/\//', $_GET[$this->initGetKey], -1, PREG_SPLIT_NO_EMPTY);
        array_pop($temp);
        return '?' . $this->initGetKey . '=/' . implode('/', $temp);
    }


    /*********************************/


    /**
     * @param string $file
     * @return bool
     */
    private function checkIsBackUp($file)
    {
        return (strpos($file, $this->_fileStorage->getLogPath() . $this->_fileStorage->getBackUpDir()) !== false);
    }


    /**
     * @param string $dir
     * @return bool
     */
    public static function delTree($dir)
    {
        $files = array_diff(scandir($dir), [
            '.',
            '..'
        ]);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? static::delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    /**
     * @param string $file
     * @return string
     */
    public function getFileContent($file)
    {
        // if ()
        // mime_content_type
        $pathinfo = pathinfo($file);
        $is_img = @getimagesize($file);
        $pathinfo['extension'] = !empty($pathinfo['extension']) ? $pathinfo['extension'] : '';
        if ($is_img) {
            return '<br/><img src="' . $this->owner->getRelativeFilePath($file) . '" alt="' . $pathinfo['basename'] . '" style="max-width:100%;"/>';
        } elseif ($pathinfo['extension'] == 'html' || isset($_GET['only'])) {
            return file_get_contents($file);
        } elseif ($pathinfo['extension'] == FileStorage::FILE_EXT) {
            return $this->renderJsonLogs($file);
        } else {
            return '<pre>' . htmlspecialchars(file_get_contents($file), ENT_QUOTES) . '</pre>';
        }
    }

    /**
     * @param string $realFilePath
     * @return string
     */
    public function getFileUrl($realFilePath)
    {
        $realFilePath = str_replace($this->_fileStorage->getLogPath(), '', $realFilePath);
        return $this->getHomeUrl() . ltrim($realFilePath, '/');
    }


    /*******************************************************/
    /*******************************************************/
    /*******************************************************/
    /*******************************************************/

    /**
     * @param string $file
     * @return string
     * @throws \Exception
     */
    protected function renderJsonLogs($file)
    {
        $html = '';
        if (!file_exists($file)) {
            return 'No file';
        }
        $file_handle = fopen($file, "r");
        while (!feof($file_handle)) {
            $line = fgets($file_handle);
            if (!$line) {
                continue;
            }
            $data = json_decode($line);
            if (!empty($data->http) && !empty($data->logs)) {
                $html .= $this->renderAllLogs(HttpData::init((array)$data->http), $data->logs);
            } else {
                $html .= '<pre>' . $line . '</pre>';
            }
            if (PhpErrorCatcher::isMemoryOver()) {
                $html = '<h3>isMemoryOver</h3>' . $html;
                break;
            }
        }
        fclose($file_handle);
        return $html;
    }

    /**
     * Render line
     * @param HttpData $httpData
     * @param LogData[] $logs
     * @return string
     * @throws \Exception
     */
    public function renderAllLogs($httpData, $logs)
    {
        //        $this->_owner->setSafeParams();
        //        $profilerUrlTag = '';
        //        if ($this->_profilerUrl) {
        //            $profilerUrlTag = '<div class="bugs_prof">XHPROF: <a href="' . $this->_profilerUrl . '">' . $this->_profilerId . '</a></div>';
        //        }
        $html = '<div class="bugs">';
        if (!empty($httpData->shell)) {
            $html .= '<span class="bugs_uri">console: ' . $httpData->shell . '</span> ';
        } else {
            if (!empty($httpData->method)) {
                $html .= '<span class="bugs_uri">' . $httpData->method . '</span> ';
            }
            if (!empty($httpData->url)) {
                $html .= '<a class="bugs_uri" target="_blank" href="//' . $httpData->host . $httpData->url . '">' . $httpData->host . $httpData->url . '</a> ';
            } elseif (!empty($httpData->host)) {
                $html .= '<div class="bugs_uri">' . $httpData->host . '</div> ';
            }
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

        foreach ($logs as $logData) {
            $html .= $this->renderItemLog(LogData::init((array)$logData));
        }

        return $html . '</div>';
    }

    /**
     * @param LogData $logData
     * @return string
     * @throws \Exception
     */
    public function renderItemLog($logData)
    {
        $view = $this;
        require __DIR__ . '/file/head.php';
        if (!is_object($logData)) {
            return '+';
        }
        $dt = explode('.', (string) $logData->microtime);
        $res = '<div class="bug_item bug_level_' . $logData->level . '">'
            . '<span class="bug_time">' . date('H:i:s', (int) $dt[0]) . '.' . (isset($dt[1]) ? $dt[1] : '') . '</span>'
            . '<span class="bug_type">' . $logData->type . ' : ' . $logData->level . ($logData->count > 1 ? '[' . $logData->count . ']' : '') . '</span>';
        //(isset($logData->fields[PhpErrorCatcher::FIELD_ERR_CODE]) ? $logData->fields[PhpErrorCatcher::FIELD_ERR_CODE] : E_UNRECONIZE)
        if ($logData->fields) {
            $logData->fields = (array) $logData->fields;
            array_walk($logData->fields, function (&$v) {
                if (!is_string($v)) {
                    $v = PhpErrorCatcher::dumpAsString($v);
                }
                if (mb_strlen($v) > 512) {
                    $v = mb_substr($v, 0, 512) . '...';
                }
            });
            $res .= '<span class="bug_fields">' . json_encode($logData->fields) . '</span>';
        }
        if ($logData->file) {
            //        $debug .= '<div class="bug_file"> File <a href="file:/' . $errfile . ':' . $errline . '">' . $errfile . ':' . $errline . '</a></div>';
            $fl = explode(':', $logData->file);
            $res .= '<span class="bug_file"> File <a href="idea://open?url=file://' . $this->idePath . $fl[0] . '&line=' . (isset($fl[1]) ? $fl[1] : '') . '">' . $logData->file . '</a></span>';
        }
        $res .= '<div class="bug_str">' . PhpErrorCatcher::esc($logData->message) . '</div>';

        if ($logData->trace) {
            $res .= '<div class="trace xsp"><div class="xsp-head" onclick="bugSp(this)">Trace</div><div class="xsp-body pre">'
                . PhpErrorCatcher::esc($logData->trace)
                . '</div></div>';
        }

        $res .= '</div>';
        return $res;
    }

    /**
     * @return array[]
     */
    public function getErrorListView()
    {
        return $this->errorListView;
    }
}
