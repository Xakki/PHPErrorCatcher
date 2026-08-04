<?php

namespace Xakki\PhpErrorCatcher\Tests;

use PHPUnit\Framework\TestCase;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;
use Xakki\PhpErrorCatcher\Tools;

class StringableValue
{
    /** @var string */
    private $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function __toString()
    {
        return $this->value;
    }
}

/**
 * Exposes applyConfig() without running the real constructor (which registers global error
 * handlers and requires a storage config).
 */
class ConfigurableCatcher extends PhpErrorCatcher
{
    public function __construct($config = array())
    {
        $this->applyConfig($config);
    }
}

class ContextTypeCorrectorTest extends TestCase
{
    /**
     * @return array
     */
    public function correctorProvider()
    {
        $cases = array(
            // --- 'id' is an int WORD: the bare key and every '*_id' ---
            array('id', '42', 42),
            array('id', 42, 42),
            array('user_id', '17', 17),
            array('order_id', '42', 42),
            array('smq_id', '2921172', 2921172),
            array('smq_id', 2921172, 2921172),
            array('smq_id', 0, 0),
            array('smq_id', null, null),
            array('analysis_id', 5, 5),
            // Pinned to string in 'exact': the sibling logger writes a UUID here. It must NOT
            // depend on the string fallback — an all-digit value would flip the field to int.
            array('request_id', '3f2a1c8e-5b6d-4e7f-9a01-2b3c4d5e6f70', '3f2a1c8e-5b6d-4e7f-9a01-2b3c4d5e6f70'),
            array('request_id', '2921172', '2921172'),
            array('request_id', 2921172, '2921172'),
            // The '_int'/'_float'/'_bool' escape hatch still outranks the 'id' word.
            array('some_id_bool', '1', true),
            array('some_id_bool', 'off', false),
            array('some_id_float', '42', 42.0),

            // --- STRING IS THE DEFAULT: codes and free-form keys have no rule ---
            array('http_code', '404', '404'),
            array('http_code', 404, '404'),
            // 'status' IS a typed word (int, to match the sibling logger), so a word-valued
            // status simply is not castable and falls back to the string default.
            array('resp_status', 'pending', 'pending'),
            array('order_status', 0, 0),
            array('cache_key', '42', '42'),
            array('body_hash', 12345, '12345'),
            array('req_uuid', '123', '123'),
            array('client_ip', '127.0.0.1', '127.0.0.1'),
            array('created_at', '2026-08-04 10:00:00', '2026-08-04 10:00:00'),
            array('app_version', 5.6, '5.6'),
            array('first_name', 777, '777'),

            // --- NAMED TRAPS of the int 'id' rule; remedy for both = pin the key to string
            // via config ('exact'). Deliberately NOT auto-detected: exceptions go through
            // config, not heuristics.
            // 1. Leading zeros are destroyed.
            array('smq_id', '00123', 123),
            array('smq_id', '007', 7),
            // 2. An id past PHP_INT_MAX cannot be cast and falls back to string — so THAT
            // key is int for short values and string for long ones: the very divergence
            // this method exists to prevent.
            array('smq_id', '99999999999999999999', '99999999999999999999'),

            // --- int rule: the LAST segment is a typed word ---
            array('retry_count', '3', 3),
            array('item_cnt', '10', 10),
            array('file_size', '1024', 1024),
            array('body_len', '5', 5),
            array('name_length', '5', 5),
            array('page_num', '2', 2),
            array('order_qty', '9', 9),
            array('row_total', '11', 11),
            array('payload_bytes', '77', 77),
            array('db_port', '3306', 3306),
            array('nest_level', '2', 2),
            array('query_limit', '50', 50),
            array('query_offset', '100', 100),
            array('list_page', '3', 3),
            array('tree_depth', '4', 4),
            array('send_attempt', '2', 2),
            array('job_retries', '5', 5),
            array('retry_count', true, 1),
            // Deliberate: truncation beats a type flip between int and float values on one key.
            array('retry_count', 7.0, 7),
            array('retry_count', null, null),

            // --- int rule, non-castable values fall back to the default (string) ---
            array('retry_count', 'many', 'many'),
            array('retry_count', '', ''),
            array('retry_count', '99999999999999999999', '99999999999999999999'),
            array('retry_count', '-99999999999999999999', '-99999999999999999999'),
            // is_numeric() would read hex on PHP 5.6 ('0x1A' + 0 === 26) and not on PHP 7+.
            array('retry_count', '0x1A', '0x1A'),
            array('retry_count', '1_000', '1_000'),
            array('retry_count', 'INF', 'INF'),
            array('retry_count', '1e5', 100000),
            array('retry_count', ' 42 ', 42),
            array('retry_count', '00123', 123),
            array('retry_count', '+7', 7),

            // --- float rule ---
            array('job_duration', 3, 3.0),
            array('hit_rate', '0.75', 0.75),
            array('fill_ratio', '0.5', 0.5),
            array('cpu_percent', '12.5', 12.5),
            array('load_avg', '1.25', 1.25),
            array('bot_score', '0.9', 0.9),
            array('pay_amount', '100.10', 100.10),
            array('cart_money', '5', 5.0),
            array('item_price', '19.99', 19.99),
            array('cart_money', '.5', 0.5),
            array('cart_money', ' 1.5 ', 1.5),
            array('cart_money', null, null),
            array('cart_money', '0x1A', '0x1A'),
            array('cart_money', 'free', 'free'),
            // 'sum' is INT: money named '*_sum' truncates — use money/amount/price.
            array('cart_sum', '5', 5),
            array('cart_sum', '19.99', 19),

            // --- bool rule: prefixes ---
            array('is_admin', 1, true),
            array('has_access', 0, false),
            array('can_edit', 'yes', true),
            array('should_retry', 'off', false),
            array('allow_list', '1', true),
            array('enable_cache', 'false', false),
            array('use_cache', 'no', false),
            // --- bool rule: exact + suffixes ---
            array('success', 1, true),
            array('flag', '0', false),
            array('debug_flag', '', false),
            array('cache_enabled', 'true', true),
            array('is_active', null, null),

            // --- legacy 'F'/'N' truthiness, (bool)'F' would be true ---
            array('is_bot', 'F', false),
            array('is_bot', 'f', false),
            array('is_bot', 'N', false),
            array('is_bot', 'FALSE', false),
            array('is_bot', 'Off', false),
            array('is_bot', 'T', true),
            array('is_bot', 'anything', true),

            // --- matching fires on whole segments only: a word merely CONTAINING a rule
            //     word is a single segment of its own and gets no rule at all ---
            array('country', 'RU', 'RU'),
            array('discount', '15%', '15%'),
            array('precis_data', 'F', 'F'),
            array('this_is_fine', 'F', 'F'),   // 'is' is not the FIRST segment
            array('accounts', '12', '12'),
            array('recount', '5', '5'),
            array('subtotal', '5', '5'),
            array('report', '2024', '2024'),
            array('passport', '5', '5'),
            array('export', '5', '5'),
            array('datetime', '5', '5'),
            array('runtime', '5', '5'),
            array('homepage', '5', '5'),
            array('underscore', '5', '5'),
            array('separate', '5', '5'),
            // These merely END IN THE LETTERS 'id' — the last SEGMENT is 'uuid'/'android'/
            // 'valid'/'orderid', so the int 'id' word never fires. camelCase 'orderId'
            // lowercases to one segment too and needs an 'exact' entry to be typed at all.
            array('uuid', '5', '5'),
            array('orderid', '5', '5'),
            array('android', '5', '5'),
            array('valid', '5', '5'),

            // --- last-segment words: one entry covers the bare key and every prefixed form ---
            array('count', '3', 3),
            array('order_count', '3', 3),
            array('cnt', '3', 3),
            array('size', '1024', 1024),
            array('qty', '9', 9),
            array('bytes', '77', 77),
            array('depth', '4', 4),
            array('offset', '100', 100),
            array('limit', '50', 50),
            array('attempt', '2', 2),
            array('retries', '5', 5),
            array('total', '11', 11),
            array('port', '3306', 3306),
            array('page', '3', 3),
            array('level', '2', 2),
            array('num', '2', 2),
            array('len', '5', 5),
            array('duration', '1.5', 1.5),
            array('percent', '12.5', 12.5),
            array('ratio', '0.5', 0.5),
            array('price', '19.99', 19.99),
            array('rate', '0.75', 0.75),
            array('score', '0.9', 0.9),
            array('avg', '1.25', 1.25),
            // 'seconds' is the sole time-unit word left, and it's INT (not float).
            array('seconds', '2', 2),
            array('wait_seconds', '2', 2),
            // Was pinned as a non-match while the rule was the '_amount' suffix; the bare
            // key is the same word and gets the same type now.
            array('amount', '5', 5.0),
            array('money', '5', 5.0),
            array('order_money', '99.50', 99.50),
            // A non-numeric value under a wrong guess is not castable and stays a string.
            array('size', 'XL', 'XL'),
            array('ttl', '600', 600),
            array('cache_ttl', '600', 600),

            // --- 'ms'/'millisecond(s)'/'sec'/'secs' were removed as words: these keys now
            //     match no rule and fall back to the string default ---
            array('exec_ms', '250', '250'),
            array('exec_ms', 1.5, '1.5'),
            array('wait_sec', '1.5', '1.5'),
            array('milliseconds', '250', '250'),
            array('wait_milliseconds', '250', '250'),
            array('exec_millisecond', 1.5, '1.5'),
            // ...except the bare legacy key, pinned INT via 'exact' (not a word).
            array('millisecond', '250', 250),

            // --- '_int' / '_float' / '_bool' are simply the words 'int' / 'float' / 'bool' ---
            array('anything_int', '42', 42),
            array('anything_float', '42', 42.0),
            array('anything_bool', 'F', false),
            array('count_bool', '1', true),    // last segment is 'bool', not 'count'
            array('price_int', '19.99', 19),   // last segment is 'int', not 'price'
            array('is_ready_int', '1', 1),     // last segment beats the first one
            array('anything_int', 'abc', 'abc'),

            // --- scalars under no rule become strings; null never does ---
            array('free_text', 123, '123'),
            array('free_text', 1.5, '1.5'),
            array('free_text', false, 'false'),
            array('free_text', true, 'true'),
            array('free_text', 'plain', 'plain'),
            array('unknown_field', null, null),

            // --- reserved library fields keep their declared type ---
            array('tag', 123, '123'),
            array('log_type', 5, '5'),
            array('file', 12, '12'),
            array('error_code', '500', 500),
            array('exception', 500, '500'),
            array('exception_code', '7', 7),
            array('exception_prev_code', '7', 7),
            array('exception_prev_message', 500, '500'),
        );

        $out = array();
        foreach ($cases as $i => $case) {
            $out['#' . $i . ' ' . $case[0]] = $case;
        }
        return $out;
    }

    /**
     * @dataProvider correctorProvider
     * @param string $key
     * @param mixed $input
     * @param mixed $expected
     */
    public function testCorrector($key, $input, $expected)
    {
        $context = array($key => $input);
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame($expected, $context[$key]);
    }

    /**
     * The library's own numeric fields must not be swept up by the string default.
     */
    public function testLibraryOwnedNumericFieldsStayNumeric()
    {
        $context = array(
            PhpErrorCatcher::FIELD_ERR_CODE => '2',
            'exception_code' => '7',
            'exception_prev_code' => '13',
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertSame(2, $context[PhpErrorCatcher::FIELD_ERR_CODE]);
        $this->assertSame(7, $context['exception_code']);
        $this->assertSame(13, $context['exception_prev_code']);
    }

    /**
     * Keys shared with the sibling logger (laralog) writing into the same cluster.
     * Types verified against laralog src/LogManager.php:248-258 and src/const.php.
     *
     * @return array
     */
    public function sharedMonitoringKeyProvider()
    {
        return array(
            'time' => array('time', '1754300000', 1754300000),
            'status' => array('status', '500', 500),
            'count' => array('count', '0', 0),
            'sum' => array('sum', '42', 42),
            'mctime' => array('mctime', '1.000001', 1.000001),
            'memory_usage' => array('memory_usage', '2097152', 2097152),
            'memory_peak' => array('memory_peak', '4194304', 4194304),
            'millisecond' => array('millisecond', '250', 250),
            'success' => array('success', 'F', false),
        );
    }

    /**
     * @dataProvider sharedMonitoringKeyProvider
     * @param string $key
     * @param mixed $input
     * @param mixed $expected
     */
    public function testSharedMonitoringKeysKeepTheirType($key, $input, $expected)
    {
        $context = array($key => $input);
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame($expected, $context[$key]);
    }

    /**
     * 'sum' is INT as a word, so the bare key and every '*_sum' agree — there is no longer
     * an 'exact' override splitting them. Money must be named money/amount/price instead.
     */
    public function testSumIsIntForBareKeyAndSuffixAlike()
    {
        $context = array('sum' => '42', 'cart_sum' => '42', 'cart_money' => '42');
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertSame(42, $context['sum']);
        $this->assertSame(42, $context['cart_sum']);
        $this->assertSame(42.0, $context['cart_money']);
    }

    /**
     * Known limitation, documented on the method: a word-valued status is not castable and
     * falls back to string, so the key re-diverges — the host must pin it.
     */
    public function testHostCanOverrideASharedMonitoringKey()
    {
        $context = array('status' => 'ok', 'other_status' => 500);
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame('ok', $context['status']);
        // 'status' is a last-segment word, so every '*_status' key is typed with it.
        $this->assertSame(500, $context['other_status']);

        $backup = PhpErrorCatcher::$contextTypeRules;
        PhpErrorCatcher::$contextTypeRules['exact']['status'] = PhpErrorCatcher::CONTEXT_TYPE_STRING;

        $pinned = array('status' => 'ok');
        $alsoPinned = array('status' => 500);
        PhpErrorCatcher::contextTypeCorrector($pinned);
        PhpErrorCatcher::contextTypeCorrector($alsoPinned);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame('ok', $pinned['status']);
        $this->assertSame('500', $alsoPinned['status']);
    }

    /**
     * (int)array(1,2) === 1 — a structure under a scalar rule must be encoded, never cast.
     */
    public function testArrayUnderScalarRuleIsEncodedNotCast()
    {
        $context = array(
            'retry_count' => array(1, 2),
            'is_bot' => array('a' => 'b'),
            'cart_sum' => array(1.5),
            PhpErrorCatcher::FIELD_TAG => array('k' => 'v'),
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertSame('[1,2]', $context['retry_count']);
        $this->assertSame('{"a":"b"}', $context['is_bot']);
        $this->assertSame('[1.5]', $context['cart_sum']);
        $this->assertSame('{"k":"v"}', $context[PhpErrorCatcher::FIELD_TAG]);
    }

    /**
     * A key with no rule promises no scalar type, so its structure must survive:
     * flattening it would break existing queries on nested paths.
     */
    public function testUnruledArrayStaysArray()
    {
        $context = array(
            'payload' => array('a' => 1, 'deep' => array('b' => 2)),
            'free_text' => array(1, 2),
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertSame(array('a' => 1, 'deep' => array('b' => 2)), $context['payload']);
        $this->assertSame(array(1, 2), $context['free_text']);
    }

    public function testUnruledObjectStaysObject()
    {
        $obj = new \stdClass();
        $obj->a = 1;
        $context = array('payload' => $obj);
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame($obj, $context['payload']);
    }

    public function testObjectUnderScalarRuleIsEncoded()
    {
        $obj = new \stdClass();
        $obj->a = 1;
        $context = array('retry_count' => $obj);
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame('{"a":1}', $context['retry_count']);
    }

    /**
     * A value object under a declared string rule renders itself instead of '{}'.
     */
    public function testStringifiableObjectUsesToString()
    {
        $context = array(PhpErrorCatcher::FIELD_TAG => new StringableValue('cron'));
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame('cron', $context[PhpErrorCatcher::FIELD_TAG]);
    }

    /**
     * json_encode must never leak `false` (which becomes '' downstream). A self-referencing
     * array copied by value hits the depth bound rather than JSON_ERROR_RECURSION.
     */
    public function testRecursiveArrayDoesNotReturnFalse()
    {
        $arr = array('a' => 1);
        $arr['self'] = &$arr;
        $context = array('retry_count' => $arr);
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertInternalType('string', $context['retry_count']);
        $this->assertNotSame('', $context['retry_count']);
    }

    public function testDeepArrayIsBounded()
    {
        $deep = 'leaf';
        for ($i = 0; $i < 60; $i++) {
            $deep = array($deep);
        }
        $context = array('retry_count' => $deep);
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertInternalType('string', $context['retry_count']);
        $this->assertNotSame('', $context['retry_count']);
    }

    /**
     * Cap measured in BYTES, and the result must stay valid UTF-8.
     */
    public function testByteCapKeepsValidUtf8()
    {
        $cap = PhpErrorCatcher::CONTEXT_MAX_STRING_BYTES;
        // 'я' is 2 bytes: a byte-budget cut would split it without mb_strcut.
        $value = str_repeat('a', $cap - 4) . str_repeat('я', 100);
        $context = array('free_text' => $value);
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertLessThanOrEqual($cap, strlen($context['free_text']));
        $this->assertTrue(mb_check_encoding($context['free_text'], 'UTF-8'));
        $this->assertStringEndsWith('...', $context['free_text']);
    }

    public function testShortStringIsNotTouched()
    {
        $context = array('free_text' => 'привет');
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame('привет', $context['free_text']);
    }

    /**
     * Every element is corrected, including the last one, and copying the corrected array
     * does not alias any slot back to the original.
     *
     * NB: this does NOT pin the unset($r) inside the corrector — verified on 5.6.40 that
     * the reference dies with the function scope, so no cross-scope leak is observable.
     * The unset() there is hygiene for whoever adds a second loop to that method.
     */
    public function testEveryElementIsCorrectedAndCopiesAreIndependent()
    {
        $context = array('a_count' => '1', 'b_count' => '2', 'c_count' => '3');
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame(array('a_count' => 1, 'b_count' => 2, 'c_count' => 3), $context);

        $copy = $context;
        $copy['c_count'] = 99;
        $this->assertSame(3, $context['c_count']);
    }

    /**
     * The same key must produce the same json type from every call site — the whole point.
     */
    public function testSameKeyAlwaysGetsSameType()
    {
        $docs = array(
            array('smq_id' => 0),
            array('smq_id' => '2921172'),
            array('smq_id' => 3),
        );
        foreach ($docs as $doc) {
            PhpErrorCatcher::contextTypeCorrector($doc);
            $this->assertInternalType('integer', $doc['smq_id']);
        }
    }

    /**
     * The PHP type is not what OpenSearch sees — the EMITTED JSON is. A float 5.0 encoded
     * as `5` maps the field as long while 19.99 maps as double, and the document is rejected.
     */
    public function testEmittedJsonKeepsFloatFraction()
    {
        $context = array('cart_money' => '5', 'item_price' => '19.99', 'retry_count' => '7');
        PhpErrorCatcher::contextTypeCorrector($context);

        // Tools::safeJsonEncode is what StreamStorage writes with.
        $expected = '{"cart_money":5.0,"item_price":19.99,"retry_count":7}';
        $this->assertSame($expected, Tools::safeJsonEncode($context));
        $this->assertSame($expected, PhpErrorCatcher::safe_json_encode($context));
    }

    public function testEmittedJsonTypesAreStableAcrossCallSites()
    {
        $docs = array(
            array('cart_money' => '5'),
            array('cart_money' => 5),
            array('cart_money' => 5.0),
            array('cart_money' => '5.00'),
        );
        foreach ($docs as $doc) {
            PhpErrorCatcher::contextTypeCorrector($doc);
            $this->assertSame('{"cart_money":5.0}', Tools::safeJsonEncode($doc));
        }
    }

    public function testEmittedJsonForIdIsAlwaysInt()
    {
        $docs = array(
            array('smq_id' => 0),
            array('smq_id' => '2921172'),
            array('smq_id' => 2921172),
        );
        $seen = array();
        foreach ($docs as $doc) {
            PhpErrorCatcher::contextTypeCorrector($doc);
            $seen[] = Tools::safeJsonEncode($doc);
        }
        $this->assertSame(
            array('{"smq_id":0}', '{"smq_id":2921172}', '{"smq_id":2921172}'),
            $seen
        );
    }

    /**
     * request_id is pinned to string, so it must NOT follow the int 'id' word even when the
     * value happens to be all digits — that is what would make the field diverge.
     */
    public function testEmittedJsonForRequestIdIsAlwaysString()
    {
        $docs = array(
            array('request_id' => '3f2a1c8e-5b6d-4e7f-9a01-2b3c4d5e6f70'),
            array('request_id' => '2921172'),
            array('request_id' => 2921172),
        );
        $seen = array();
        foreach ($docs as $doc) {
            PhpErrorCatcher::contextTypeCorrector($doc);
            $seen[] = Tools::safeJsonEncode($doc);
        }
        $this->assertSame(
            array('{"request_id":"3f2a1c8e-5b6d-4e7f-9a01-2b3c4d5e6f70"}', '{"request_id":"2921172"}', '{"request_id":"2921172"}'),
            $seen
        );
    }

    /**
     * The last segment is looked up first; the first segment only types a key whose last
     * segment carries no rule.
     */
    public function testPatternPrecedence()
    {
        $context = array(
            // 'id' is an int word, and 'word' outranks 'prefix' — so a bool prefix LOSES
            // here. Counter-intuitive but deliberate: name it 'is_bot' if you want a bool.
            'is_bot_id' => '5',
            'has_count' => '7',        // last 'count' -> int
            'enable_cache' => 'F',     // 'cache' has no rule, first 'enable' -> bool
            'is_admin' => 'F',         // 'admin' has no rule, first 'is' -> bool
            'has_access' => '1',       // 'access' has no rule, first 'has' -> bool
            'has_total' => '3',        // last 'total' -> int
            'is_sum' => '1',           // last 'sum' -> int, not the 'is' bool
            'use_money' => '2',        // last 'money' -> float, not the 'use' bool
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertSame(5, $context['is_bot_id']);
        $this->assertSame(7, $context['has_count']);
        $this->assertSame(false, $context['enable_cache']);
        $this->assertSame(false, $context['is_admin']);
        $this->assertSame(true, $context['has_access']);
        $this->assertSame(3, $context['has_total']);
        $this->assertSame(1, $context['is_sum']);
        $this->assertSame(2.0, $context['use_money']);
    }

    /**
     * Degenerate keys still resolve by segment, and never blow up: '_count' has an empty
     * first segment and 'count' as its last one, 'is_' the other way round.
     */
    public function testDegenerateKeysResolveBySegment()
    {
        $context = array('_count' => '5', 'is_' => 'F', '_' => 'x', '' => 'y');
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame(5, $context['_count']);
        $this->assertSame(false, $context['is_']);
        $this->assertSame('x', $context['_']);
        $this->assertSame('y', $context['']);
    }

    /**
     * log() consumes FIELD_TRACE itself and needs the raw array.
     */
    public function testTraceFieldIsSkipped()
    {
        $trace = array(array('file' => 'a.php', 'line' => 1));
        $context = array(
            PhpErrorCatcher::FIELD_TRACE => $trace,
            PhpErrorCatcher::FIELD_NO_TRACE => true,
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertSame($trace, $context[PhpErrorCatcher::FIELD_TRACE]);
        $this->assertSame(true, $context[PhpErrorCatcher::FIELD_NO_TRACE]);
    }

    /**
     * The rule table is extendable at runtime by the host app — the documented way to get
     * a numeric type back for a key the defaults leave as string.
     */
    public function testHostAppCanExtendRules()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        PhpErrorCatcher::$contextTypeRules['word']['rub'] = PhpErrorCatcher::CONTEXT_TYPE_FLOAT;
        PhpErrorCatcher::$contextTypeRules['exact']['memory_usage'] = PhpErrorCatcher::CONTEXT_TYPE_INT;

        $context = array('order_rub' => '99.50', 'memory_usage' => '2097152');
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame(99.50, $context['order_rub']);
        $this->assertSame(2097152, $context['memory_usage']);
    }

    public function testIdempotent()
    {
        $context = array(
            'smq_id' => '42',
            'retry_count' => '42',
            'is_bot' => 'F',
            'cart_money' => '1.5',
            'app_version' => '5.6',
            'payload' => array(1),
            'retry_count_raw' => array(1),
            'nothing' => null,
        );
        PhpErrorCatcher::contextTypeCorrector($context);
        $once = $context;
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertSame($once, $context);
    }

    public function testEmptyContext()
    {
        $context = array();
        PhpErrorCatcher::contextTypeCorrector($context);
        $this->assertSame(array(), $context);
    }

    /**
     * One word entry covers the bare key and every prefixed form, and segment matching is
     * what keeps 'recount' / 'account' out — no per-word exception list needed.
     */
    public function testWordRuleMatchesWholeSegmentsOnly()
    {
        $context = array(
            'count' => '3',
            'order_count' => '3',
            'recount' => '3',
            'account' => '3',
            'accountcount' => '3',
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertSame(3, $context['count']);
        $this->assertSame(3, $context['order_count']);
        $this->assertSame('3', $context['recount']);
        $this->assertSame('3', $context['account']);
        $this->assertSame('3', $context['accountcount']);
    }

    /**
     * 'seconds' is the sole time-unit word (INT); ms/millisecond(s)/sec/secs were removed
     * to force one naming convention and now fall back to the string default.
     */
    public function testSecondsIsIntAndRemovedTimeWordsStayString()
    {
        $context = array(
            'millisecond' => '250',
            'milliseconds' => '250',
            'render_milliseconds' => '250',
            'wait_seconds' => '2',
            'wait_sec' => '2',
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        // Asymmetry on purpose: bare 'millisecond' is an 'exact' pin for one legacy key,
        // every other millisecond-ish spelling stays a string.
        $this->assertSame(250, $context['millisecond']);
        $this->assertSame('250', $context['milliseconds']);
        $this->assertSame('250', $context['render_milliseconds']);
        $this->assertSame(2, $context['wait_seconds']);
        $this->assertSame('2', $context['wait_sec']);
    }

    /**
     * The escape hatch needs no mechanism of its own: 'int' / 'float' / 'bool' are words,
     * and a key has exactly one last segment, so there is nothing to resolve.
     */
    public function testTypeNamingSuffixWins()
    {
        $context = array(
            'count_bool' => '1',
            'price_int' => '19.99',
            'retry_count_float' => '3',
            'is_ready_int' => '1',
            'seconds_float' => '2',
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        $this->assertSame(true, $context['count_bool']);
        $this->assertSame(19, $context['price_int']);
        $this->assertSame(3.0, $context['retry_count_float']);
        $this->assertSame(1, $context['is_ready_int']);
        $this->assertSame(2.0, $context['seconds_float']);
    }

    /**
     * The escape hatch is the TOP of the chain: it beats 'reserved' (which otherwise wins
     * over everything) and 'exact' (the host override level). Without that, a host could
     * not force a type on a key the library already owns.
     */
    public function testTypeNamingSuffixBeatsReservedAndExact()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        PhpErrorCatcher::$contextTypeRules['reserved']['owned_int'] = PhpErrorCatcher::CONTEXT_TYPE_STRING;
        PhpErrorCatcher::$contextTypeRules['reserved']['skipped_bool'] = PhpErrorCatcher::CONTEXT_TYPE_SKIP;
        PhpErrorCatcher::$contextTypeRules['exact']['pinned_float'] = PhpErrorCatcher::CONTEXT_TYPE_STRING;

        $context = array('owned_int' => '42', 'skipped_bool' => '1', 'pinned_float' => '1.5');
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame(42, $context['owned_int']);
        $this->assertSame(true, $context['skipped_bool']);
        $this->assertSame(1.5, $context['pinned_float']);
    }

    /**
     * Resolution order end to end: suffix > reserved > exact > word > prefix > string.
     */
    public function testResolutionOrder()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        PhpErrorCatcher::$contextTypeRules['exact']['is_count'] = PhpErrorCatcher::CONTEXT_TYPE_STRING;

        $context = array(
            'price_int' => '19.99',  // 1. suffix beats the 'price' word
            PhpErrorCatcher::FIELD_ERR_CODE => '7',  // 2. reserved
            'is_count' => '5',       // 3. exact beats the 'count' word and the 'is' prefix
            'has_total' => '3',      // 4. word beats the 'has' prefix
            'is_admin' => '1',       // 5. prefix, nothing else matched
            'whatever' => '9',       // 6. no rule -> string
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame(19, $context['price_int']);
        $this->assertSame(7, $context[PhpErrorCatcher::FIELD_ERR_CODE]);
        $this->assertSame('5', $context['is_count']);
        $this->assertSame(3, $context['has_total']);
        $this->assertSame(true, $context['is_admin']);
        $this->assertSame('9', $context['whatever']);
    }

    /**
     * ... and it beats 'reserved' too — the escape hatch is the top of the chain, so a
     * '*_int' name always wins. (This inverts the earlier rule, where reserved won.)
     */
    public function testTypeNamingSuffixBeatsReserved()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        PhpErrorCatcher::$contextTypeRules['reserved']['payload_int'] = PhpErrorCatcher::CONTEXT_TYPE_STRING;

        $context = array('payload_int' => '42');
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame(42, $context['payload_int']);
    }

    /**
     * The 'exact' host override beats the word set, which is how a key whose last segment
     * lies about its type gets pinned back.
     */
    public function testExactOverrideBeatsWordSet()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        PhpErrorCatcher::addContextTypeRules(array(
            'exact' => array('legacy_count' => PhpErrorCatcher::CONTEXT_TYPE_STRING),
        ));

        $context = array('legacy_count' => '007', 'retry_count' => '007');
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame('007', $context['legacy_count']);
        $this->assertSame(7, $context['retry_count']);
    }

    /**
     * The documented remedy for BOTH named traps of the int 'id' rule, proven executable:
     * there is no '_string' escape hatch, so a key carrying zero-padded or oversized ids
     * can only be pinned back to string through config 'exact'.
     *
     * Left unpinned (see the provider) '00123' becomes 123 and a 20-digit id falls back to
     * string, so THAT key would be int for short values and string for long ones.
     */
    public function testIdTrapsAreFixedByPinningTheKeyToString()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        PhpErrorCatcher::addContextTypeRules(array(
            'exact' => array('legacy_id' => PhpErrorCatcher::CONTEXT_TYPE_STRING),
        ));

        $context = array(
            'legacy_id' => '00123',
            'legacy_id_long' => '99999999999999999999',
            'order_id' => '00123',
        );
        // The oversized case needs its own pin: 'legacy_id_long' ends in the segment 'long'.
        PhpErrorCatcher::addContextTypeRules(array(
            'exact' => array('legacy_id_long' => PhpErrorCatcher::CONTEXT_TYPE_STRING),
        ));
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        // Pinned: leading zeros survive, and the key is a string for EVERY value length.
        $this->assertSame('00123', $context['legacy_id']);
        $this->assertSame('99999999999999999999', $context['legacy_id_long']);
        // Unpinned: the trap, asserted so it can never regress silently.
        $this->assertSame(123, $context['order_id']);
    }

    /**
     * Dropping a word unpins every key whose last segment is that word.
     */
    public function testHostCanRemoveALibraryWord()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        unset(PhpErrorCatcher::$contextTypeRules['word']['status']);

        $context = array('order_status' => 0, 'status' => 0);
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame('0', $context['order_status']);
        $this->assertSame('0', $context['status']);
    }

    /**
     * A host with a PARTIAL table (no 'word' bucket at all) must still work.
     */
    public function testPartialRuleTableIsTolerated()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        PhpErrorCatcher::$contextTypeRules = array('exact' => array('foo' => PhpErrorCatcher::CONTEXT_TYPE_INT));

        $context = array('foo' => '1', 'bar_int' => '2', 'count' => '3');
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame(1, $context['foo']);
        $this->assertSame('2', $context['bar_int']);
        $this->assertSame('3', $context['count']);
    }

    /**
     * The declarative path: addContextTypeRules() is what the 'contextTypeRulesExtra'
     * config key funnels into. Host entries override the library defaults for the same
     * pattern; other buckets and other patterns are untouched.
     */
    public function testAddContextTypeRulesMergesAndOverrides()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        PhpErrorCatcher::addContextTypeRules(array(
            'exact' => array('orderId' => PhpErrorCatcher::CONTEXT_TYPE_INT),
            'word' => array(
                'total' => PhpErrorCatcher::CONTEXT_TYPE_FLOAT,
                'rub' => PhpErrorCatcher::CONTEXT_TYPE_FLOAT,
                // Stray underscores are the natural typo and would match no segment at
                // all — they are trimmed instead.
                '_slots' => PhpErrorCatcher::CONTEXT_TYPE_INT,
            ),
            'prefix' => array('flag_' => PhpErrorCatcher::CONTEXT_TYPE_BOOL),
            // Skipped: unknown bucket, multi-segment word, unknown type — none of them may
            // break logging.
            'nonsense' => array('x' => PhpErrorCatcher::CONTEXT_TYPE_INT),
            'exact2' => array('y' => PhpErrorCatcher::CONTEXT_TYPE_INT),
        ));
        PhpErrorCatcher::addContextTypeRules(array('word' => array('two_words' => PhpErrorCatcher::CONTEXT_TYPE_INT)));
        PhpErrorCatcher::addContextTypeRules(array('exact' => array('z' => 'nosuchtype')));

        $context = array(
            'orderid' => '77',       // host entries are lowercased to be reachable
            'total' => '19.99',      // overrides the library default (int)
            'row_total' => '19.99',
            'order_rub' => '99.50',
            'slots' => '4',
            'free_slots' => '4',
            'flag_ready' => 'F',
            'retry_count' => '3',    // untouched library default
            'x' => '1',
            'y' => '1',
            'z' => '1',
            'a_two_words' => '1',
        );
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame(77, $context['orderid']);
        $this->assertSame(19.99, $context['total']);
        $this->assertSame(19.99, $context['row_total']);
        $this->assertSame(99.50, $context['order_rub']);
        $this->assertSame(4, $context['slots']);
        $this->assertSame(4, $context['free_slots']);
        $this->assertSame(false, $context['flag_ready']);
        $this->assertSame(3, $context['retry_count']);
        $this->assertSame('1', $context['x']);
        $this->assertSame('1', $context['y']);
        $this->assertSame('1', $context['z']);
        $this->assertSame('1', $context['a_two_words']);
    }

    /**
     * The host app declares its rules in the SAME $params array it configures everything
     * else with — the config key must actually reach the table.
     */
    public function testConfigKeyFeedsTheRuleTable()
    {
        $backup = PhpErrorCatcher::$contextTypeRules;
        new ConfigurableCatcher(array(
            'logFields' => array('project' => 'student'),
            'contextTypeRulesExtra' => array(
                'exact' => array('engagedtier' => PhpErrorCatcher::CONTEXT_TYPE_INT),
                'word' => array('ttl' => PhpErrorCatcher::CONTEXT_TYPE_INT),
            ),
        ));

        $context = array('engagedTier' => '2', 'cache_ttl' => '600');
        PhpErrorCatcher::contextTypeCorrector($context);

        PhpErrorCatcher::$contextTypeRules = $backup;

        $this->assertSame(2, $context['engagedTier']);
        $this->assertSame(600, $context['cache_ttl']);
    }
}
