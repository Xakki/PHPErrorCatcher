<?php

namespace xakki\phperrorcatcher\plugin;

class ToolbarPlugin extends BasePlugin {


    public function renderToolbar() {
        if (!$this->_isViewMode && $this->debugMode) {
            if (empty($this->_viewAlert) && !$this->_hasError && (!$this->_profilerStatus || !static::init()->_profilerId)) return;
            echo PHPErrorViewer::renderViewScript($this);
            ?>
            <div class="pecToolbar xsp">
                <button class="btn <?= ($this->_hasError ? 'btn-danger' : 'btn-primary') ?> xsp-head"
                        onclick="bugSp(this)">Expand
                    Logs: <?= static::getErrCount() ?></button>
                <a class="btn btn-default" href="?<?= $this->viewKey ?>=/">View All Logs</a>
                <div class="xsp-body">
                    <?php if ($this->_profilerStatus && static::init()->_profilerId): ?>
                        <p class="alert-info"><a
                                    href="<?= self::getProfilerUrl() ?>">Profiler <?= $this->_profilerId ?></a></p>
                    <?php endif; ?>
                    <?php if ($this->_hasError): ?>
                        <div class="alert-danger"><?= $this->_allLogs ?></div>
                    <?php endif; ?>
                    <?php if (count($this->_viewAlert)): ?>
                        <div class="alert-warning">
                            <?php foreach ($this->_viewAlert as $r): ?>
                                <p><?= $r ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
    }


    public function shutdown() {
        $this->renderToolbar();
    }
}