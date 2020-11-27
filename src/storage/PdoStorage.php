<?php

namespace xakki\phperrorcatcher\storage;

use PDO;

class PdoStorage extends BaseStorage
{

    /**
     * @var null|array|callback|PDO
     */
    protected $pdo = null;
    protected $pdoTableName = '_myprof';


    /**
     * Получаем PDO соединение с БД
     * @return PDO|null
     */
    public function getPdo()
    {
        if ($this->pdo && is_array($this->pdo)) {
            $this->pdo = array_merge([
                'engine' => 'mysql',
                'host' => 'localhost',
                'port' => 3106,
                'dbname' => 'test',
                'username' => 'test',
                'passwd' => 'test',
                'options' => [],
            ], $this->pdo);
            $this->pdo = new PDO($this->pdo['engine'] . ':host=' . $this->pdo['host'] . ';port=' . $this->pdo['port'] . ';dbname=' . $this->pdo['dbname'], $this->pdo['username'], $this->pdo['passwd'], $this->pdo['options']);
        } elseif ($this->pdo && is_callable($this->pdo)) {
            $this->pdo = call_user_func_array($this->pdo, []);
        }
        return ($this->pdo instanceof PDO ? $this->pdo : null);
    }


    public function viewRenderBD($flag = false)
    {
        $fields = [
            'id' => [
                'ID',
                'filter' => 1,
                'sort' => 1
            ],
            'host' => [
                'Host',
                'filter' => 1,
                'sort' => 1
            ],
            'name' => [
                'Name',
                'filter' => 1,
                'sort' => 1,
                'url' => true
            ],
            'script' => [
                'Script',
                'filter' => 1,
                'sort' => 1
            ],
            'time_cr' => [
                'Create',
                'filter' => 1,
                'sort' => 1,
                'type' => 'date'
            ],
            'time_run' => [
                'Time(ms)',
                'filter' => 1,
                'sort' => 1
            ],
            'profiler_id' => [
                'Prof',
                'url' => $this->owner->getXhprofUrl()
            ],
            'ref' => [
                'Ref',
                'filter' => 1,
                'url' => true
            ],
            'info' => [
                'Info',
                'filter' => 1,
                'spoiler' => 1
            ],
            'json_post' => [
                'Post',
                'filter' => 1,
                'spoiler' => 1
            ],
            'json_cookies' => [
                'Cookies',
                'filter' => 1,
                'spoiler' => 1
            ],
            'json_session' => [
                'Session',
                'filter' => 1,
                'spoiler' => 1
            ],
            'is_secure' => [
                'HTTPS',
                'filter' => 1,
                'sort' => 1,
                'spoiler' => 1,
                'type' => 'bool'
            ],
        ];

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
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);
        $counts = $counts['cnt'];
        // значение максимальной страницы
        $max_page = ceil($counts / $itemsOnPage);
        $paginator = range(1, $max_page ? $max_page : 1);

        $page = (($_GET['page'] && $_GET['page'] <= $max_page) ? $_GET['page'] : 1);

        $query = 'SELECT * FROM ' . $this->owner->get('pdoTableName');

        $where = [];
        $param = [];

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
        $dataList = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                    $tmp .= '<th class="filter-date"><input type="text" name="fltr[' . $k . ']" value="' . $val . '" class="form-control">' . '<input type="text" name="fltr[' . $k . '_2]" value="' . (!empty($_GET['fltr'][$k . '_2']) ? $_GET['fltr'][$k . '_2'] : '') . '" class="form-control">';
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


    private function createDB()
    {
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

}