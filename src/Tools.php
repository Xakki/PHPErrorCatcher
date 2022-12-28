<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher;

use Exception;

class Tools
{
    public const COLOR_GREEN = '0;32',
        COLOR_GRAY = '0;37',
        COLOR_GRAY2 = '1;37',
        COLOR_YELLOW = '1;33',
        COLOR_RED = '0;31',
        COLOR_WHITE = '1;37',
        COLOR_LIGHT_BLUE = '1;34',
        COLOR_BLUE = '0;34',
        COLOR_BLUE2 = '1;36';

    public static function cliColor(string $text, string $colorId): string
    {
        if (!isset($_SERVER['TERM'])) {
            return $text;
        }
        return "\033[" . $colorId . "m" . $text . "\033[0m";
    }

    public static function utf8ize($mixed)
    {
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[$key] = self::utf8ize($value);
            }
        } elseif (is_string($mixed)) {
            $mixed = mb_convert_encoding($mixed, 'UTF-8');
        }
        return $mixed;
    }

    public static function safeJsonEncode($value, $options = 0, $depth = 512): string
    {
        $encoded = json_encode($value, $options, $depth);
        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                return $encoded ?? '';
            case JSON_ERROR_DEPTH:
                throw new Exception('json_encode: Maximum stack depth exceeded');
            case JSON_ERROR_STATE_MISMATCH:
                throw new Exception('json_encode: Underflow or the modes mismatch');
            case JSON_ERROR_CTRL_CHAR:
                throw new Exception('json_encode: Unexpected control character found');
            case JSON_ERROR_SYNTAX:
                throw new Exception('json_encode: Syntax error, malformed JSON');
            case JSON_ERROR_UTF8:
                return self::safeJsonEncode(Tools::utf8ize($value), $options, $depth);
            default:
                throw new Exception('json_encode: Unknown error');
        }
    }

    public static function convertMemoryToByte($limitMemory): int
    {
        if ($limitMemory > 0) {
            if (strpos($limitMemory, 'G')) $m = 1024 * 1024 * 1024;
            elseif (strpos($limitMemory, 'M')) $m = 1024 * 1024;
            elseif (strpos($limitMemory, 'K')) $m = 1024;
            else $m = 1;
            $limitMemory = (int)$limitMemory * $m;
        }
        return $limitMemory;
    }

    public static function isMemoryOver(): bool
    {
        $limitMemory = Tools::convertMemoryToByte(ini_get('memory_limit'));
        if ($limitMemory > 0) {
            return memory_get_usage() >= ($limitMemory * 0.9);
        }
        return false;
    }

    private static array $objects = [];

    public static function dumpAsString(mixed $var, $limitString, int $depth = 3, bool $highlight = false): string
    {
        $output = Tools::dumpInternal($var, $depth, $limitString);
        if ($highlight) {
            $result = highlight_string("<?php\n" . $output, true);
            $output = preg_replace('/&lt;\\?php<br \\/>/', '', $result, 1);
        }
        self::$objects = [];
        return $output;
    }

    public static function dumpInternal(mixed $var, int $depth, int $limitString = 1024, int $level = 0): string
    {
        $output = '';
        switch (gettype($var)) {
            case 'boolean':
                $output .= $var ? 'T' : 'F';
                break;
            case 'integer':
            case 'double':
                $output .= (string)$var;
                break;
            case 'string':
                $size = mb_strlen($var);
                if ($size > $limitString) {
                    $output = '"' . static::esc(mb_substr($var, 0, $limitString)) . '...(' . $size . 'b)"';
                } else {
                    $output = '"' . static::esc($var) . '"';
                }
                break;
            case 'resource':
                $output .= '{resource}';
                break;
            case 'NULL':
                $output .= 'null';
                break;
            case 'unknown type':
                $output .= '{unknown}';
                break;
            case 'array':
                if ($depth <= $level) {
                    $output .= '[...]';
                } elseif (empty($var)) {
                    $output .= '[]';
                } else {
                    $spaces = str_repeat(' ', $level * 4);
                    $output .= '[';
                    foreach ($var as $key => $val) {
                        $output .= "\n" . $spaces . '    ';
                        $output .= self::dumpInternal($key, $depth);
                        $output .= ' => ';
                        $output .= self::dumpInternal($val, $depth, $limitString, $level + 1);
                    }
                    $output .= "\n" . $spaces . ']';
                }
                break;
            case 'object':
                $id = array_search($var, self::$objects, true);
                if ($id !== false) {
                    $output .= get_class($var) . '#' . ($id + 1) . '(...)';
                } elseif ($depth <= $level) {
                    $output .= get_class($var) . '(...)';
                } else {
                    $id = array_push(self::$objects, $var);
                    $className = get_class($var);
                    $spaces = str_repeat(' ', $level * 4);
                    $output .= "$className#$id\n" . $spaces . '(';
                    if ('__PHP_Incomplete_Class' !== get_class($var) && method_exists($var, '__debugInfo')) {
                        $dumpValues = $var->__debugInfo();
                        if (!is_array($dumpValues)) {
                            throw new Exception('__debuginfo() must return an array');
                        }
                    } else {
                        $dumpValues = (array)$var;
                    }
                    foreach ($dumpValues as $key => $value) {
                        $keyDisplay = strtr(trim($key), "\0", ':');
                        $output .= "\n" . $spaces . "    [$keyDisplay] => ";
                        $output .= self::dumpInternal($value, $depth, $limitString, $level + 1);
                    }
                    $output .= "\n" . $spaces . ')';
                }
                break;
        }
        return $output;
    }

    public static function renderDebugArray(mixed $arr, $arrLen = 6, $strLen = 256): mixed
    {
        if (!is_array($arr)) {
            return $arr;
        }
        $i = $arrLen;
        $args = [];

        foreach ($arr as $k => $v) {
            if ($i == 0) {
                $args[] = '...' . (count($arr) - 6) . ']';
                break;
            }
            $prf = '';
            if (!is_numeric($k)) {
                $prf = $k . ':';
            }
            if (is_null($v)) {
                $args[] = $prf . 'NULL';
            } elseif (is_array($v)) {
                $args[] = $prf . '[' . implode(', ', self::renderDebugArray($v, $arrLen, $strLen)) . ']';
            } elseif (is_object($v)) {
                $args[] = $prf . get_class($v);
            } elseif (is_bool($v)) {
                $args[] = $prf . ($v ? 'T' : 'F');
            } elseif (is_resource($v)) {
                $args[] = $prf . 'RESOURCE';
            } elseif (is_numeric($v)) {
                $args[] = $prf . $v;
            } elseif (is_string($v)) {
                $l = mb_strlen($v);
                if ($l > $strLen) {
                    $v = mb_substr($v, 0, $strLen - 30) . '...(' . $l . ')...' . mb_substr($v, $l - 20);
                }
                $args[] = $prf . '"' . preg_replace(["/\n+/u", "/\r+/u", "/\s+/u"], ['', '', ' '], $v) . '"';
            } else {
                $args[] = $prf . 'OVER';
            }
            $i--;
        }
        return $args;
    }

    public static function containExclude(string $str, array $exclude): bool
    {
        foreach ($exclude as $item) {
            if (str_contains($str, $item)) {
                return true;
            }
        }
        return false;
    }

    public static function isTraceHasExclude(array $traceItem, array $exclude): bool
    {
        $exclude[] = 'phperrorcatcher';
        return self::containExclude($traceItem['file'] ?? $traceItem['class'], $exclude);
    }

    public static function getFileLineByTrace(array $trace, array $lineExclude = []): string
    {
        foreach ($trace as $item) {
            if (Tools::isTraceHasExclude($item, $lineExclude)) {
                continue;
            }
            if (!empty($item['file'])) {
                return $item['file'] . ':' . $item['line'];
            }
//            return (!empty($item['class']) ? $item['class'] . '::' : '')
//                . (!empty($item['function']) ? $traceItem['function'] . '()' : '');
        }
        return '';
    }

    public static function prepareTag(string $tag): string
    {
        $tag = (string)$tag;
        if (mb_strlen($tag) > 32) {
            $tag = mb_substr($tag, 29) . '...';
        }
        return mb_strtolower($tag);
    }

    public static function prepareTags(array &$tags): array
    {
        array_walk($tags, function (&$v) {
            $v = self::prepareTag($v);
        });
        return $tags;
    }


    public static function prepareFields(array &$fields, array $excludeKeys): array
    {
        foreach ($excludeKeys as $key) {
            if (isset($fields[$key])) {
                unset($fields[$key]);
            }
        }

        array_walk($fields, function (&$v) {
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v);
            }
        });
        return $fields;
    }

    public static function prepareMessage(&$message, int $limitString): string
    {
        if (!is_string($message)) {
            $message = Tools::dumpAsString($message, $limitString);
        }
        $message = mb_substr($message, 0, $limitString);
        return $message;
    }

    /**
     * Экранирование
     * @param mixed $value
     * @return string
     * @throws Exception
     */
    public static function esc(mixed $value): string
    {
        if (!is_string($value)) {
            $value = self::safeJsonEncode($value, JSON_UNESCAPED_UNICODE);
        }
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_IGNORE, 'utf-8');
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
}
