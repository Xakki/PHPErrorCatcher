<?php

use xakki\phperrorcatcher\PHPErrorCatcher;
use xakki\phperrorcatcher\viewer\FileViewer;
/**
 * @var string $file
 * @var \xakki\phperrorcatcher\viewer\FileViewer $this
 */

$home = $this->getHomeUrl('');
$tabs = [
    'BD' => '',
    'PROF' => '',
    'PHPINFO' => '',
];

?>
<html>
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
            white-space: pre-wrap;
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
</head>

<body>

<ul class="nav nav-tabs">
    <li class="nav-item"><a class="nav-link<?=(!isset($tabs[$file]) ? ' active' : '')?>" href="<?=$home?>/">Логи</a></li>

    <?php if (file_exists($this->owner->getRawLogFile())): ?>
        <li class="nav-item"><a class="text-danger nav-link<?=($file=='rawlog' ? ' active' : '')?>" href="<?=$home?>rawlog">Errors</a></li>
    <?php endif; ?>

    <?php foreach ($this->owner->getStorages() as $stClass => $st): ?>
        <?php foreach ($st->getViewMenu() as $fName => $menuName): ?>
            <li class="nav-item"><a class="text-danger nav-link<?=(($file=='storage' && $_GET['fname'] == $stClass.'/'.$fName)? ' active' : '')?>" href="<?=$home?>storage&fname=<?=$stClass.'/'.$fName?>"><?=$menuName?></a></li>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <li class="nav-item"><a class="nav-link<?=($file == 'PHPINFO' ? ' active' : '')?>" href="<?=$home?>PHPINFO">PHPINFO</a></li>
    <li class="nav-item"><a class="nav-link" href="?" target="_blank">HOME</a></li>
    <li class="nav-item"><a class="nav-link">Core ver: <?=PHPErrorCatcher::VERSION?>. Viewer ver: <?=FileViewer::VERSION?></a></li>
</ul>