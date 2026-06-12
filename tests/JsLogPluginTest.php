<?php
declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Xakki\PhpErrorCatcher\plugin\JsLogPlugin;
use Xakki\PhpErrorCatcher\PhpErrorCatcher;

/**
 * Covers JsLogPlugin input hardening (POST only, required fields m/u/r, length
 * trimming), the stateless token (gen/validate, optional when a secret is set),
 * serving /catcher.js and the new context format (ctx) with backward compatibility.
 */
class JsLogPluginTest extends TestCase
{
    /** @var ReflectionClass<PhpErrorCatcher> */
    private ReflectionClass $ref;

    private PhpErrorCatcher $owner;

    protected function setUp(): void
    {
        $this->ref = new ReflectionClass(PhpErrorCatcher::class);
        $this->owner = $this->ref->newInstanceWithoutConstructor();
        $this->ref->getProperty('obj')->setValue(null, $this->owner);

        $statics = [
            'storages' => [],
            'plugins' => [],
            'logTags' => [],
            'logFields' => [],
            'logCookieKey' => '',
            'ignoreRules' => [],
            'stopRules' => [],
            'printHttpRules' => [],
            'printConsoleRules' => [],
            'userCatchLogKeys' => [],
            'userCatchLogFlag' => false,
            'maxLogsPerRequest' => 100,
            'dirRoot' => '',
        ];
        foreach ($statics as $name => $value) {
            $this->ref->getProperty($name)->setValue(null, $value);
        }
        $this->ref->getProperty('viewer')->setValue(null, null);

        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['HTTP_X_LOG_SECRET'], $_SERVER['REQUEST_URI']);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_X_LOG_SECRET'], $_SERVER['REQUEST_URI']);
    }

    private function loggedCount(): int
    {
        return (int) $this->ref->getProperty('count')->getValue($this->owner);
    }

    private function lastMessage(): ?string
    {
        /** @var array<string, \Xakki\PhpErrorCatcher\dto\LogData> $logData */
        $logData = $this->ref->getProperty('logData')->getValue($this->owner);
        if (!$logData) {
            return null;
        }
        return end($logData)->message;
    }

    /** @return array<string, mixed> */
    private function lastFields(): array
    {
        /** @var array<string, \Xakki\PhpErrorCatcher\dto\LogData> $logData */
        $logData = $this->ref->getProperty('logData')->getValue($this->owner);
        if (!$logData) {
            return [];
        }
        return end($logData)->fields;
    }

    /**
     * @param array<string, string|int> $config
     */
    private function makePlugin(array $config = ['catcherLogName' => 'js']): JsLogPlugin
    {
        return new JsLogPlugin($this->owner, $config);
    }

    /** base64url(exp ":" mac) — build a token by hand for negative cases. */
    private function makeRawToken(int $exp, string $mac): string
    {
        return rtrim(strtr(base64_encode($exp . ':' . $mac), '+/', '-_'), '=');
    }

    public function testValidPostLogs(): void
    {
        $_POST = ['m' => 'boom', 'u' => 'http://x', 'r' => 'ref'];

        $this->makePlugin()->initLogRequest($this->owner);

        $this->assertSame(1, $this->loggedCount());
        $this->assertSame('boom', $this->lastMessage());
    }

    public function testNonPostMethodIsIgnored(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = ['m' => 'boom', 'u' => 'http://x', 'r' => 'ref'];

        $this->makePlugin()->initLogRequest($this->owner);

        $this->assertSame(0, $this->loggedCount());
    }

    public function testMissingRequiredFieldIsIgnored(): void
    {
        $_POST = ['m' => 'boom', 'u' => 'http://x']; // no 'r'

        $this->makePlugin()->initLogRequest($this->owner);

        $this->assertSame(0, $this->loggedCount());
    }

    public function testMessageIsLengthCapped(): void
    {
        $_POST = ['m' => str_repeat('a', 5000), 'u' => 'http://x', 'r' => 'ref'];

        $this->makePlugin()->initLogRequest($this->owner);

        $this->assertSame(1, $this->loggedCount());
        $this->assertSame(1600, mb_strlen((string) $this->lastMessage()));
    }

    public function testCtxFieldIsStored(): void
    {
        $_POST = [
            'm' => 'boom',
            'u' => 'http://x',
            'r' => 'ref',
            'ctx' => '{"sid":"abc","vp":"800x600","bc":[{"t":"click","sel":"button#go"}]}',
        ];

        $this->makePlugin()->initLogRequest($this->owner);

        $this->assertSame(1, $this->loggedCount());
        $fields = $this->lastFields();
        $this->assertArrayHasKey('ctx', $fields);
        $this->assertStringContainsString('"sid":"abc"', (string) $fields['ctx']);
    }

    public function testOldFormatWithoutCtxStillWorks(): void
    {
        $_POST = ['m' => 'legacy', 'u' => 'http://x', 'r' => 'ref'];

        $this->makePlugin()->initLogRequest($this->owner);

        $this->assertSame(1, $this->loggedCount());
        $this->assertArrayNotHasKey('ctx', $this->lastFields());
    }

    public function testValidTokenIsAccepted(): void
    {
        $plugin = $this->makePlugin(['catcherLogName' => 'js', 'secret' => 'sekret']);
        $_SERVER['HTTP_X_LOG_SECRET'] = $plugin->generateToken();
        $_POST = ['m' => 'boom', 'u' => 'http://x', 'r' => 'ref'];

        $plugin->initLogRequest($this->owner);

        $this->assertSame(1, $this->loggedCount());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $exp = time() - 10;
        $mac = hash_hmac('sha256', (string) $exp, 'sekret');
        $_SERVER['HTTP_X_LOG_SECRET'] = $this->makeRawToken($exp, $mac);
        $_POST = ['m' => 'boom', 'u' => 'http://x', 'r' => 'ref'];

        $this->makePlugin(['catcherLogName' => 'js', 'secret' => 'sekret'])->initLogRequest($this->owner);

        $this->assertSame(0, $this->loggedCount());
    }

    public function testForgedTokenIsRejected(): void
    {
        // structurally valid token (exp in the future, mac is 64 hex) but a foreign signature
        $_SERVER['HTTP_X_LOG_SECRET'] = $this->makeRawToken(time() + 1000, str_repeat('0', 64));
        $_POST = ['m' => 'boom', 'u' => 'http://x', 'r' => 'ref'];

        $this->makePlugin(['catcherLogName' => 'js', 'secret' => 'sekret'])->initLogRequest($this->owner);

        $this->assertSame(0, $this->loggedCount());
    }

    public function testLegacySharedSecretStillWorks(): void
    {
        $_SERVER['HTTP_X_LOG_SECRET'] = 'sekret';
        $_POST = ['m' => 'boom', 'u' => 'http://x', 'r' => 'ref'];

        $this->makePlugin(['catcherLogName' => 'js', 'secret' => 'sekret'])->initLogRequest($this->owner);

        $this->assertSame(1, $this->loggedCount());
    }

    public function testMissingTokenIsRejectedWhenSecretSet(): void
    {
        $_POST = ['m' => 'boom', 'u' => 'http://x', 'r' => 'ref']; // no X-Log-Secret/k

        $this->makePlugin(['catcherLogName' => 'js', 'secret' => 'sekret'])->initLogRequest($this->owner);

        $this->assertSame(0, $this->loggedCount());
    }

    public function testBuildScriptInjectsKeyAndToken(): void
    {
        $script = $this->makePlugin(['initGetKey' => 'mykey', 'secret' => 'sekret'])->buildScript();

        $this->assertStringContainsString('window.jsLogKey="mykey";', $script);
        $this->assertStringContainsString('window.jsLogToken="', $script);
        $this->assertStringContainsString('window.errorCatcher', $script); // catcher.js body
    }

    public function testBuildScriptWithoutSecretHasEmptyToken(): void
    {
        $script = $this->makePlugin(['initGetKey' => 'mykey'])->buildScript();

        $this->assertStringContainsString('window.jsLogToken="";', $script);
    }
}
