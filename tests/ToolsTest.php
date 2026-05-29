<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\Tests;

use PHPUnit\Framework\TestCase;
use Xakki\PhpErrorCatcher\Tools;

/**
 * Basic coverage of the Tools helpers (the class had no tests at all before).
 */
class ToolsTest extends TestCase
{
    public function testConvertMemoryToByte(): void
    {
        $this->assertSame(1048576, Tools::convertMemoryToByte('1M'));
        $this->assertSame(2 * 1024 * 1024 * 1024, Tools::convertMemoryToByte('2G'));
        $this->assertSame(512 * 1024, Tools::convertMemoryToByte('512K'));
        $this->assertSame(0, Tools::convertMemoryToByte(false));
    }

    public function testSafeJsonEncodeValidData(): void
    {
        $this->assertSame('{"a":1,"b":"x"}', Tools::safeJsonEncode(['a' => 1, 'b' => 'x']));
    }

    public function testSafeJsonEncodeUnescapedUnicode(): void
    {
        $this->assertSame('"café"', Tools::safeJsonEncode('café', JSON_UNESCAPED_UNICODE));
    }

    public function testPrepareFieldsDropsExcludedKeysAndEncodesComplex(): void
    {
        $fields = ['keep' => 'v', 'drop' => 'x', 'arr' => ['a' => 1]];
        $out = Tools::prepareFields($fields, ['drop']);

        $this->assertArrayNotHasKey('drop', $out);
        $this->assertSame('v', $out['keep']);
        $this->assertSame('{"a":1}', $out['arr']);
    }

    public function testEscEscapesHtml(): void
    {
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', Tools::esc('<b>x</b>'));
    }

    public function testContainExclude(): void
    {
        $this->assertTrue(Tools::containExclude('/var/log/app.php', ['vendor', 'app']));
        $this->assertFalse(Tools::containExclude('/var/log/x.php', ['vendor']));
    }

    public function testPrepareTagTruncatesFromStart(): void
    {
        $out = Tools::prepareTag(str_repeat('a', 40));
        // Regression M5: mb_substr($tag, 29) used to keep the tail instead of the start.
        $this->assertSame(str_repeat('a', 29) . '...', $out);
        $this->assertSame(32, mb_strlen($out));
    }

    public function testPrepareTagLowercasesShortTag(): void
    {
        $this->assertSame('payments', Tools::prepareTag('Payments'));
    }

    public function testEscAttrEscapesQuotesButEscDoesNot(): void
    {
        $this->assertSame('a&quot;b', Tools::escAttr('a"b'));
        // esc() (ENT_NOQUOTES) leaves quotes alone — attributes need escAttr().
        $this->assertSame('a"b', Tools::esc('a"b'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sanitizeProvider(): array
    {
        return [
            'simple traversal'   => ['../etc', 'etc'],
            'mid-path traversal' => ['a/../../etc', 'a/etc'],
            'backslash'          => ['..\\..\\', ''],
            'leading slash'      => ['/foo', 'foo'],
            'dot only'           => ['.', ''],
            'empty'              => ['', ''],
            'benign dotted dir'  => ['2026.05/29.plog', '2026.05/29.plog'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sanitizeProvider')]
    public function testSanitizeRelativePath(string $input, string $expected): void
    {
        $this->assertSame($expected, Tools::sanitizeRelativePath($input));
    }
}
