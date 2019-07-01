<?php

namespace xakki\phperrorcatcher\plugin;

class XhprofPlugin extends BasePlugin {

    /**
     * Enable xhprof profiler
     * @var bool
     */
    protected $xhprofEnable = false;

    /**
     * If xhprofEnable=true then save only if time more this property
     * @var int
     */
    protected $minTimeProfiled = 4000999999;

    /**
     * location source profiler xhprof
     * @var null|string
     */
    protected $xhprofDir = null;

    /**
     * profiler namespace (xhprof)
     * @var string
     */
    protected $profiler_namespace = 'slow';

    protected $_profilerId = null;
    protected $_profilerUrl = null;
    protected $_profilerStatus = false;

    /**
     * Запуск профайлера
     */
    public function initProfiler() {
        if (!$this->xhprofDir || $this->_profilerStatus) return;

        $lib1 = $this->xhprofDir . '/xhprof_lib/utils/xhprof_lib.php';
        $lib2 = $this->xhprofDir . '/xhprof_lib/utils/xhprof_runs.php';
        $tmpDir = $this->logPath . '/xhprof';
        if (extension_loaded('xhprof') && file_exists($lib1) && file_exists($lib2)) {
            if (!file_exists($tmpDir)) {
                if (!mkdir($tmpDir, 0775, true)) {
                    $this->setViewAlert('Cant create dir ' . $tmpDir);
                    return;
                }
            }
            ini_set("xhprof.output_dir", $tmpDir);
            include_once $lib1;
            include_once $lib2;
            xhprof_enable(XHPROF_FLAGS_NO_BUILTINS | XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
            $this->_profilerStatus = true;
        } else {
            $this->setViewAlert('Cant INIT profiler :' . (!extension_loaded('xhprof') ? 'Not load xhprof php modul' : '') . (!file_exists($lib1) || !file_exists($lib2) ? 'Cant load xhprof libs' : ''));
        }
    }

    /**
     * Завершение и сохранение профайлера
     * @return null|string
     */
    public function endProfiler() {
        if ($this->_profilerStatus) {
            $xhprof_data = xhprof_disable();
            if ($this->_time_end > $this->minTimeProfiled || $this->xhprofEnable) {
                $xhprof_runs = new \XHProfRuns_Default();
                $this->_profilerId = $xhprof_runs->save_run($xhprof_data, $this->profiler_namespace);
                $this->_profilerUrl = null;
                if ($this->_profilerId) {
                    $this->_profilerUrl = $this->getXhprofUrl($this->_profilerId);
                }
                return $this->_profilerId;
            }
        }
        return null;
    }

    public function getXhprofUrl($id = '') {
        return '?' . $this->viewKey . '=PROF&source=' . $this->profiler_namespace . '&run=' . $id;
    }


    /**
     * Сохранить статистику профалера в БД и ссылку на него
     * @param null $script
     * @param null $info
     * @param bool|false $simple
     * @return bool
     */
    public function saveStatsProfiler($script = null, $info = null, $simple = false) {
        $this->setSafeParams();
        if (is_null($script)) {
            $script = $_SERVER['SCRIPT_NAME'];
        }
        $data = [
            //            'name' => ($_SERVER['REDIRECT_URL'] ? $_SERVER['REDIRECT_URL'] : ($_SERVER['DOCUMENT_URI'] ? $_SERVER['DOCUMENT_URI'] : $_SERVER['SCRIPT_NAME'])),
            'name' => $_SERVER['REQUEST_URI'],
            'time_cr' => $this->_time_start,
            'time_run' => $this->_time_end,
            'info' => $info,
            'json_post' => (!empty($this->_postData) ? self::_e($this->_postData) : null),
            'json_cookies' => ((!empty($this->_cookieData) && !$simple) ? self::_e($this->_cookieData) : null),
            'json_session' => ((!empty($this->_sessionData) && !$simple) ? self::_e($this->_sessionData) : null),
            'is_secure' => ((isset($_SERVER['HTTPS']) and $_SERVER['HTTPS'] !== 'off') ? true : false),
            'ref' => (!$simple ? $_SERVER['HTTP_REFERER'] : null),
            'script' => $script,
            'host' => $_SERVER['HTTP_HOST'],
        ];
        if ($this->_profilerId) {
            $data['profiler_id'] = $this->_profilerId;
        }

        $stmt = $this->getPdo()->prepare('INSERT INTO ' . $this->pdoTableName . ' (' . implode(',', array_keys($data)) . ') VALUES(' . str_repeat('?,', (count($data) - 1)) . '?)');
        $res = $stmt->execute(array_values($data));
        $err = $stmt->errorInfo();
        if ($err[1]) {
            $this->_allLogs .= '<p class="alert alert-danger">INSERT BD: ' . $err[1] . ', ' . $err[2] . '</p>';
            $this->_hasError = true;
        }
        return $res;
    }

    /**
     * Получить ссылку на профалер текщего скрипта
     * @return null
     */
    public static function getProfilerUrl() {
        return self::$_obj->_profilerUrl;
    }


    public function shutdown() {
        try {
            $prof = $this->endProfiler();
            if (!$prof) {
                if ($this->_time_end > $this->minTimeProfiled && $this->getPdo()) {
                    $this->saveStatsProfiler();
                }
            }
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Печатаем то что выдает профаилер
     * @return mixed|string
     */
    public function viewRenderPROF() {
        $allowInc = [
            'callgraph' => 1,
            'typeahead' => 1,
        ];

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
                if ($ext == 'css') header('Content-Type: text/css'); elseif ($ext == '.js') header('Content-Type: application/javascript');
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
            $home = $this->owner->getHomeUrl('PROF');
            $html = str_replace([
                'link href=\'/',
                'script src=\'/',
                'a href="' . htmlentities($_SERVER['SCRIPT_NAME']) . '?',
                'a href="/?',
                'a href="/callgraph.php?',
                'a href="/typeahead.php?'
            ], [
                'link href=\'?' . $home . '&only=1&viewSrc=',
                'script src=\'?' . $home . '&only=1&viewSrc=',
                'a href="' . $home . '&',
                'a href="' . $home . '&',
                'a href="' . $home . '&only=1&viewInc=callgraph&',
                'a href="' . $home . '&viewInc=typeahead&'
            ], $html);
        }
        ob_end_clean();
        return $html;
    }
}