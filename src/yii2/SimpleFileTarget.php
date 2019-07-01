<?php

namespace xakki\phperrorcatcher\yii2;

use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;
use yii\log\FileTarget;
use yii\log\Logger;

class SimpleFileTarget extends FileTarget {
    public $showTraces = 3;

    public function formatMessage($message) {
        list($text, $level, $category, $timestamp) = $message;
        $level = Logger::getLevelName($level);
        if (!is_string($text)) {
            // exceptions may not be serializable if in the call stack somewhere is a Closure
            if ($text instanceof \Throwable || $text instanceof \Exception) {
                $text = (string)$text;
            } else {
                $text = VarDumper::export($text);
            }
        }
        $t = PHP_EOL . '    ';
        if ($this->showTraces) {
            $traces = [];
            if (isset($message[4])) {
                $c = 0;
                foreach ($message[4] as $trace) {
                    $traces[] = "in {$trace['file']}:{$trace['line']}";
                    $c++;
                    if ($this->showTraces <= $c) break;
                }
            }
            $traces = $t . implode($t, $traces);
        } else {
            $traces = '';
        }
        if (count($this->logVars)) {
            $context = ArrayHelper::filter($GLOBALS, $this->logVars);
            $logVars = [];
            foreach ($context as $key => $value) {
                $logVars[] = "\${$key} = " . VarDumper::dumpAsString($value);
            }
            $logVars = $t . implode($t, $logVars);
        } else {
            $logVars = '';
        }

        $prefix = $this->getMessagePrefix($message);
        return $this->getTime($timestamp) . " {$prefix}[$level][$category] $text" . $traces . $logVars;
    }

    protected function getContextMessage() {
        return '';
    }
}