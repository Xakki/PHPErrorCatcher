
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