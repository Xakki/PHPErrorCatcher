<?php

namespace xakki\phperrorcatcher;


class PHPErrorViewer
{
    /**
     * @var PHPErrorCatcher
     */
    private $owner;

    public function __construct($owner) {
        $this->owner = $owner;
    }

    /**
     * Просмотр логов
     */
    public function renderView() {
        ini_set("memory_limit", "128M");
        $url = str_replace(array('\\', '\/\/', '\.\/', '\.\.'), '', $_GET[$this->owner->get('viewKey')]);

        $file = trim($url, '/');
        $tabs = array(
            'BD' => '',
            'PROF' => '',
            'PHPINFO' => '',
        );

        if (!empty($_GET['download'])) {
            header('Content-Type: application/octet-stream');
        } else {
            header('Content-type: text/html; charset=UTF-8');
        }

        if (!isset($_GET['only']) && empty($_GET['backup'])) {
            echo '<html>';
            echo $this->renderViewHead($file);
            echo '<body>';

            echo '<ul class="nav nav-tabs">' .
                '<li class="nav-item"><a class="nav-link' . (!isset($tabs[$file]) ? ' active' : '') . '" href="?' . $this->owner->get('viewKey') . '=/">Логи</a></li>' .
                ($this->owner->getPdo() ? '<li class="nav-item"><a class="nav-link' . ($file == 'BD' ? ' active' : '') . '" href="?' . $this->owner->get('viewKey') . '=BD">BD</a></li>' : '') .
                ($this->owner->get('_profilerStatus') ? '<li class="nav-item"><a class="nav-link' . ($file == 'PROF' ? ' active' : '') . '" href="?' . $this->owner->get('viewKey') . '=PROF&source=' . $this->owner->get('profiler_namespace') . '&run=">Профаилер</a></li>' : '') .
                '<li class="nav-item"><a class="nav-link' . ($file == 'PHPINFO' ? ' active' : '') . '" href="?' . $this->owner->get('viewKey') . '=PHPINFO">PHPINFO</a></li>' .
                '<li class="nav-item"><a class="nav-link" href="?" target="_blank">HOME</a></li>' .
                '</ul>';
        }


        if ($file == 'BD') {
            echo $this->viewRenderBD();
        } elseif ($file == 'PROF') {
            echo $this->viewRenderPROF();
        } elseif ($file == 'PHPINFO') {
            ob_start();
            phpinfo();
            $html = ob_get_contents();
            // flush the output buffer
            ob_end_clean();
            echo $html;
        } else {
            $file = $this->owner->get('logPath') . '/' . $file;

            if (file_exists($file)) {

                if (is_dir($file)) {
                    if (isset($_GET['backup'])) {
                        $this->viewCreateBackUpDir($file);
                        exit();
                    }
                    echo $this->renderViewBreadCrumb($url);
                    echo $this->renderViewDirList(static::viewGetDirList($url));
                } else {
                    if (isset($_GET['backup'])) {
                        $this->viewCreateBackUp($file);
                        exit();
                    }

                    if (!isset($_GET['only'])) {
                        echo $this->renderViewBreadCrumb($url);

                        if (!$this->checkIsBackUp($file)) {
                            echo ' [<a href="' . $_SERVER['REQUEST_URI'] . '&only=1&download=1" class="linkSource">Download</a> <a href="' . $_SERVER['REQUEST_URI'] . '&only=1" class="linkSource">Source</a> <a href="' . $_SERVER['REQUEST_URI'] . '&backup=do">Бекап</a> <a href="' . $_SERVER['REQUEST_URI'] . '&backup=del">Удалить</a>]';
                        }
                        echo '</h3>';
                    }
                    //chmod($file, 0777);
                    echo static::getFileContent($file);
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
            <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css"
                  integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
            <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo"
                    crossorigin="anonymous"></script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js"
                    integrity="sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49" crossorigin="anonymous"></script>
            <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js"
                    integrity="sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy" crossorigin="anonymous"></script>
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
            <?= $this->renderViewScript($this->owner) ?>
        </head>
        <?php
    }

    public static function renderViewScript($owner) {
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

            .bug_str {
            }

            .bug_vars .xsp-body {
                white-space: pre-wrap;
            }

            .bug_file {
            }

            <?php  foreach ($owner->get('_errorListView') as $errno => $error): ?>
            .bug_<?=$errno?> .bug_type {
                color: <?=$error['color']?>;
            }

            ';
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
        $dirList1 = $dirList2 = array();
        $path = trim($path, '/.');
        $fullPath = $this->owner->get('logPath') . ($path ? '/' . $path : '');

        if (!file_exists($fullPath)) {
            if (!mkdir($fullPath, 0775, true)) {
                exit(' Cant create dir ' . $fullPath);
            }
        }
        $isBackUpDir = $this->checkIsBackUp($fullPath);
        $dir = dir($fullPath);
        $url = $_SERVER['REQUEST_URI'];
        $url = parse_url($url);
        $pathUrl = rtrim($url['path'], '/');
        while (false !== ($entry = $dir->read())) {
            if ($entry != '.' && $entry != '..') {
                $fileUrl = $pathUrl . '/?' . $this->owner->get('viewKey') . '=' . $path . '/' . $entry;
                $filePath = $fullPath . '/' . $entry;


                if (is_dir($filePath)) {
                    $size = $create = '';
                    $createTime = 0;
                    if (!is_readable($filePath)) {
                        $dirList1[$entry] = array($path . '/' . $entry, '', '', '', '');
                        continue;
                    }
                } else {
                    if (!is_readable($filePath)) {
                        $dirList2[$entry] = array($path . '/' . $entry, '', '', '', '');
                        continue;
                    }
                    //                    trigger_error($this->owner->get('logPath') . ' * ' . $path . ' * ' . $entry. '=> '.$filePath, E_USER_DEPRECATED);
                    $size = filesize($filePath);
                    $createTime = filemtime($filePath);
                    $create = date("Y-m-d H:i:s", $createTime);
                    $size = number_format($size, 0, '', ' ') . ' б.';
                }

                $tmp = array(
                    '<a href="' . $fileUrl . '" style="' . (is_dir($filePath) ? 'font-weight:bold;' : '') . '">' . $path . '/' . $entry . '</a> ',
                    $size,
                    $create,
                    ($size ? ' <a href="' . $fileUrl . '&only=1" class="linkSource">Source</a>' : '') .
                    ((!$isBackUpDir && ($path || !$this->checkIsBackUp($filePath))) ? ' <a href="' . $fileUrl . '&backup=do">Бекап</a> <a href="' . $fileUrl . '&backup=del" class="linkDel">Удалить</a>' : ''),
                    $createTime
                );
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

        if (is_dir($file)) {
            echo 'Is Dir';
        } elseif ($this->checkIsBackUp($file)) {
            echo 'Is BackUp Dir: is protect  dir';
        }
        if (defined('ERROR_NO_BACKUP')) {
            unlink($file);
            header('Location: ' . $_SERVER['HTTP_REFERER']);
        } else {

            $backUpFile = str_replace($this->owner->get('logPath'), $this->owner->get('logPath') . $this->owner->get('backUpDir'), $file);
            $backUpFileDir = dirname($backUpFile);
            if (!file_exists($backUpFileDir)) {
                mkdir($backUpFileDir, 0775, true);
            }

            if (file_exists($backUpFile)) {
                $i = pathinfo($backUpFile);
                $backUpFile = $i['dirname'] . '/' . $i['filename'] . '.' . time() . '.' . $i['extension'];
            }
            if (file_exists($backUpFile)) {
                exit('Error!');
            }

            if (copy($file, $backUpFile)) {
                $loc = str_replace(array('&backup=do', '&backup=del'), '', $_SERVER['REQUEST_URI']);
                $backUpFileUrl = str_replace($_GET[$this->owner->get('viewKey')], str_replace($this->owner->get('logPath'), '', $backUpFile), $loc);

                // add info
                file_put_contents($backUpFile, '<a href="' . $loc . '">This backup file in ' . date('Y-m-d H:i:s') . ' from origin</a><hr/>' . PHP_EOL . file_get_contents($backUpFile));
                if ($_GET['backup'] == 'del' and strpos($file, $this->owner->get('logPath') . $this->owner->get('backUpDir')) === false) {
                    unlink($file);
                    $i = pathinfo($file);
                    header('Location: ' . str_replace('/' . $i['filename'] . '.' . $i['extension'], '', $_SERVER['HTTP_REFERER']));
                } else {
                    // add info
                    file_put_contents($file, '... <a href="' . $backUpFileUrl . '">This file was backup ' . date('Y-m-d H:i:s') . '</a><hr/>' . PHP_EOL);
                    header('Location: ' . $_SERVER['HTTP_REFERER']);
                }

            } else {
                echo "не удалось скопировать $file...\n";
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
            $backUpFileDir = str_replace($this->owner->get('logPath'), $this->owner->get('logPath') . $this->owner->get('backUpDir'), $dir);
            if (file_exists($backUpFileDir)) {
                $backUpFileDir = rtrim($backUpFileDir, '/') . '_' . time();
            } else {
                $parentDir = dirname($backUpFileDir);
                if (!file_exists($parentDir)) {
                    mkdir($parentDir, 0774, true);
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
        $basePath = $fullPath = rtrim($url['path'], '/') . '/?' . $this->owner->get('viewKey') . '=/';
        foreach ($temp as $r) {
            $fullPath .= '/' . $r;
            $ctr .= '<li class="breadcrumb-item"><a href="' . $fullPath . '">' . $r . '</a>';
        }
        return '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="' . $basePath . '">Home</a>' . $ctr . '</ol></nav>';
    }


    /**
     * Печатаем то что выдает профаилер
     * @return mixed|string
     */
    public function viewRenderPROF() {
        $allowInc = array(
            'callgraph' => 1,
            'typeahead' => 1,
        );

        // xhprof настолько древний, что до сих пор общается с глобальными переменными (
        foreach ($_GET as $k => $r) {
            global $$k;
            $$k = $r;
            //                $GLOBALS[$k] = $r;
        }

        if (isset($_GET['viewSrc'])) {
            $file = $this->owner->get('xhprofDir') . "/xhprof_html/" . trim($_GET['viewSrc'], '/\\.');
            if (file_exists($file)) {
                $ext = substr($_GET['viewSrc'], -3);
                if ($ext == 'css') header('Content-Type: text/css');
                elseif ($ext == '.js') header('Content-Type: application/javascript');
                exit(file_get_contents($file));
            }
            exit('File not found');
        }
        ini_set("xhprof.output_dir", $this->owner->get('logPath') . '/xhprof');
        ob_start();
        $xfile = $this->owner->get('xhprofDir') . '/xhprof_html/' . ((isset($_GET['viewInc']) && isset($allowInc[$_GET['viewInc']])) ? $_GET['viewInc'] : 'index') . '.php';
        if (file_exists($xfile)) include $xfile;
        $html = ob_get_contents();
        if ($_GET['viewInc'] == 'callgraph') {
            exit($html);
        } else {
            $html = str_replace(array(
                'link href=\'/',
                'script src=\'/',
                'a href="' . htmlentities($_SERVER['SCRIPT_NAME']) . '?',
                'a href="/?',
                'a href="/callgraph.php?',
                'a href="/typeahead.php?'
            ), array(
                'link href=\'?' . $this->owner->get('viewKey') . '=PROF&only=1&viewSrc=',
                'script src=\'?' . $this->owner->get('viewKey') . '=PROF&only=1&viewSrc=',
                'a href="?' . $this->owner->get('viewKey') . '=PROF&',
                'a href="?' . $this->owner->get('viewKey') . '=PROF&',
                'a href="?' . $this->owner->get('viewKey') . '=PROF&only=1&viewInc=callgraph&',
                'a href="?' . $this->owner->get('viewKey') . '=PROF&&viewInc=typeahead&'
            ), $html);
        }
        ob_end_clean();
        return $html;
    }

    /*********************************/


    public function viewRenderBD($flag = false) {
        $fields = array(
            'id' => array('ID', 'filter' => 1, 'sort' => 1),
            'host' => array('Host', 'filter' => 1, 'sort' => 1),
            'name' => array('Name', 'filter' => 1, 'sort' => 1, 'url' => true),
            'script' => array('Script', 'filter' => 1, 'sort' => 1),
            'time_cr' => array('Create', 'filter' => 1, 'sort' => 1, 'type' => 'date'),
            'time_run' => array('Time(ms)', 'filter' => 1, 'sort' => 1),
            'profiler_id' => array('Prof', 'url' => $this->owner->getXhprofUrl()),
            'ref' => array('Ref', 'filter' => 1, 'url' => true),
            'info' => array('Info', 'filter' => 1, 'spoiler' => 1),
            'json_post' => array('Post', 'filter' => 1, 'spoiler' => 1),
            'json_cookies' => array('Cookies', 'filter' => 1, 'spoiler' => 1),
            'json_session' => array('Session', 'filter' => 1, 'spoiler' => 1),
            'is_secure' => array('HTTPS', 'filter' => 1, 'sort' => 1, 'spoiler' => 1, 'type' => 'bool'),
        );

        $itemsOnPage = 40;
        $stmt = $this->owner->getPdo()->prepare('SELECT count(*) as cnt FROM ' . $this->owner->get('pdoTableName'));
        $stmt->execute();
        $err = $stmt->errorInfo();
        if ($err[1]) {
            if ($err[1] == 1146) {
                if (!$flag) {
                    $this->createDB();
                    return $this->viewRenderBD(true);
                }
            }
            return '<p class="alert alert-danger">' . $err[1] . ': ' . $err[2] . '</p>';
        }
        $counts = $stmt->fetch(\PDO::FETCH_ASSOC);
        $counts = $counts['cnt'];
        // значение максимальной страницы
        $max_page = ceil($counts / $itemsOnPage);
        $paginator = range(1, $max_page ? $max_page : 1);

        $page = (($_GET['page'] && $_GET['page'] <= $max_page) ? $_GET['page'] : 1);

        $query = 'SELECT * FROM ' . $this->owner->get('pdoTableName');

        $where = array();
        $param = array();

        if (!empty($_GET['fltr'])) {
            foreach ($_GET['fltr'] as $k => $r) {

                if (substr($k, -2) == '_2') {
                    $pair = true;
                    $k = substr($k, 0, -2);
                } else {
                    $pair = false;
                }

                if (isset($fields[$k]) && $fields[$k]['filter']) {
                    if (isset($fields[$k]['type']) && $fields[$k]['type'] == 'date') {
                        if ($pair) {
                            $where[] = $k . ' <= ?';
                        } else {
                            $where[] = $k . ' >= ?';
                        }
                        $param[] = strtotime($r);
                    } elseif (isset($fields[$k]['type']) && $fields[$k]['type'] == 'bool') {
                        if ($r !== '') {
                            $where[] = $k . ' = ?';
                            $param[] = $r;
                        }
                    } else {
                        $where[] = $k . ' LIKE ? ';
                        $param[] = $r;
                    }

                }
            }

        }
        if (!empty($_GET['sort'])) {
            $sort = $_GET['sort'];
            $ord = '';
            if (substr($sort, 0, 1) == '-') {
                $ord = ' DESC';
                $sort = substr($sort, 1);
            }

            if (isset($fields[$sort]) && $fields[$sort]['sort']) {
                $query .= ' ORDER BY ' . $sort . $ord;
            }
        } else {
            $query .= ' ORDER BY id DESC';
        }
        $query .= ' LIMIT ' . (($page - 1) * $itemsOnPage) . ',' . $itemsOnPage;

        $stmt = $this->owner->getPdo()->prepare($query);
        $stmt->execute();
        $dataList = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $html = '';

        $html .= '<div style="float:left;">Кол-во:' . $counts . '</div>';
        if (count($paginator) > 1):
            $html .= '<ul class="pagination pagination-sm" style="margin:0 0 0 10px;">';
            $getParam = $_GET;
            foreach ($paginator as $i):
                $getParam['page'] = $i;
                $html .= '<li' . ($i == $page ? ' class="active"' : '') . '><a href="?' . http_build_query($getParam) . '">' . $i . '</a>';
            endforeach;
            $html .= '</ul>';
        endif;

        $html .= '<table class="table table-striped" style="width: auto;"><thead><tr class="thead">';
        $tmp = '</tr> <tr class="filter">';

        foreach ($fields as $k => $r) {
            if (!empty($r['sort']) && $r['sort']) {
                $html .= '<th data-field="' . $k . '" class="sort"><i>+</i>' . $r[0] . '<i>-</i>';
            } else {
                $html .= '<th data-field="' . $k . '">' . $r[0] . '';
            }
            if (!empty($r['filter']) && $r['filter']) {
                $val = (!empty($_GET['fltr'][$k]) ? $_GET['fltr'][$k] : '');
                if (!empty($r['type']) && $r['type'] == 'date') {
                    $tmp .= '<th class="filter-date"><input type="text" name="fltr[' . $k . ']" value="' . $val . '" class="form-control">' .
                        '<input type="text" name="fltr[' . $k . '_2]" value="' . (!empty($_GET['fltr'][$k . '_2']) ? $_GET['fltr'][$k . '_2'] : '') . '" class="form-control">';
                } elseif (!empty($r['type']) && $r['type'] == 'bool') {
                    $sel1 = $sel2 = '';
                    if ($val === '1') {
                        $sel1 = ' selected="selected"';
                    } elseif ($val === '0') {
                        $sel2 = ' selected="selected"';
                    }
                    $tmp .= '<th class="filter-bool"><select name="filter[' . $k . ']" class="form-control"><option value="" name=" - "><option value="1" name="YES"' . $sel1 . '><option value="0" name="no"' . $sel2 . '></select>';
                } else {
                    $tmp .= '<th><input type="text" name="filter[' . $k . ']" value="' . $val . '" class="form-control">';
                }
            } else {
                $tmp .= '<th>';
            }

        }

        $html .= $tmp . '</tr></thead><tbody>';

        foreach ($dataList as $row) {
            $html .= '<tr>';
            foreach ($fields as $k => $r) {
                if (!empty($r['type']) && $r['type'] == 'date') {
                    $row[$k] = date('Y-m-d H:i:s', $row[$k]);
                } elseif (!empty($r['type']) && $r['type'] == 'bool') {
                    $row[$k] = ($row[$k] ? '+' : '');
                } elseif (!empty($r['url']) && $row[$k] && $row[$k] != '*') {
                    if ($r['url'] === true) {
                        $row[$k] = ($row[$k] ? '<a href="' . (strpos($r['host'], '://') !== false ? '' : $r['host']) . $row[$k] . '" target="_blank">' . substr($row[$k], 0, 15) . '...</a>' : '');
                    } else {
                        $row[$k] = ($row[$k] ? '<a href="' . $r['url'] . $row[$k] . '" target="_blank">' . $row[$k] . '</a>' : '');
                    }
                }

                if (!empty($r['spoiler'])) {
                    $html .= '<td' . (strlen($row[$k]) > 10 ? ' class="spoiler"><i>+</i>' : '>') . '<span>' . $row[$k] . '</span>';
                } else {
                    $html .= '<td>' . $row[$k];
                }
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<style>
            table td {
                max-width: 250px;
                overflow: auto;
            }
            th.sort {
            }
            th.sort i {
                cursor: pointer;
                color: blue;
                margin: 0 4px;
            }
            th.sort i:hover {
                color: red;
            }
            /*******/
            .spoiler span {
                display: none;
            }
            .spoiler i {
                color: blue;
                cursor: pointer;
            }
            .filter-date input {
                width: 49%;
                display: inline-block;
            }
            .bugs_post {
                word-wrap: break-word;
            }
        </style>
        <script>

        $(document).ready(function() {
            $(document).on("click", ".spoiler i", function() {
                $(this).hide().next().show();
            });
            $(document).on("click", "th.sort i", function() {
                locationSearch("sort", ($(this).text()=="-" ? "-" : "") + $(this).parent().attr("data-field"));
            });

            $(document).on("change", "tr.filter input", function() {
                var val = $(this).val();
                if ($(this).attr("type") == "checkbox" && !$(this).is(":checked")) {
                    val = 0;
                }
                locationSearch($(this).attr("name"), val);
            });
        });

        function locationSearch(name, val) {
            var gets = window.location.search.replace(/&amp;/g, "&").substring(1).split("&");
            var newAdditionalURL = "";
            var temp = "?";
            for (var i=0; i<gets.length; i++)
            {
                if(gets[i].split("=")[0] != name)
                {
                    newAdditionalURL += temp + gets[i];
                    temp = "&";
                }
            }
            window.location.search = newAdditionalURL + "&" + name + "=" + encodeURIComponent(val);
        }
        </script>';
        return $html;
    }

    /******************************************************************************************/


    private function createDB() {
        $sql = 'CREATE TABLE `' . $this->owner->get('pdoTableName') . '` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `time_cr` int(11) NOT NULL,
  `time_run` int(9) NOT NULL,
  `info` text,
  `json_post` text,
  `json_cookies` text,
  `json_session` text,
  `is_secure` tinyint(1) NOT NULL DEFAULT \'0\',
  `ref` varchar(255) DEFAULT NULL,
  `profiler_id` varchar(255) DEFAULT NULL,
  `script` varchar(255) DEFAULT NULL,
  `host` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;';
        $stmt = $this->owner->getPdo()->prepare($sql);
        $stmt->execute();
    }


    private function checkIsBackUp($file) {
        return (strpos($file, $this->owner->get('logPath') . $this->owner->get('backUpDir')) !== false);
    }


    /**
     * Рекурсивно удаляем директорию
     * @param $dir
     * @return bool
     */
    public static function delTree($dir) {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? static::delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    public static function getFileContent($file) {
        // if ()
        // mime_content_type
        $pathinfo = pathinfo($file);
        $is_img = @getimagesize($file);
        if ($is_img) {
            return '<img src="' . str_replace($_SERVER['DOCUMENT_ROOT'], '', $file) . '" alt="' . $pathinfo['basename'] . '"/>';
        } elseif ($pathinfo['extension'] == 'html' || isset($_GET['only'])) {
            return file_get_contents($file);
        } else {
            return '<pre>' . file_get_contents($file) . '</pre>';
        }
    }
}