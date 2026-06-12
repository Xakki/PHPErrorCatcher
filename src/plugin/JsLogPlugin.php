<?php

declare(strict_types=1);

namespace Xakki\PhpErrorCatcher\plugin;

use Xakki\PhpErrorCatcher\PhpErrorCatcher;

class JsLogPlugin extends BasePlugin
{
    protected string $catcherLogName = '';
    protected string $level = PhpErrorCatcher::LEVEL_NOTICE;
    /** Maximum length of textual request fields. */
    protected int $maxFieldLen = 2000;
    /** Maximum length of the context JSON block (the `ctx` field). */
    protected int $ctxMaxLen = 4000;
    /**
     * Signing key for the stateless token and, at the same time, the legacy
     * shared-secret. Optional and empty by default — then tokens are not used and
     * access to the endpoint is gated by `initGetKey` only (as without this
     * authentication plugin). When set — `/catcher.js` is served with an embedded
     * token, and on log intake the token is verified (see checkSecret). This is
     * not strict authentication (the token value is visible in client-side JS),
     * but it requires the client to have actually fetched `/catcher.js` and cuts
     * out noise/bots. The comparison is timing-safe.
     */
    protected string $secret = '';
    /**
     * Path on which `__construct()` serves the generated catcher.js (GET).
     * Empty — dynamic delivery is disabled (static inclusion only).
     */
    protected string $scriptUrl = '/catcher.js';
    /** HTTP cache TTL of the served catcher.js, in seconds (default 1 day). */
    protected int $scriptCacheTtl = 86400;
    /**
     * Token TTL, in seconds. Must exceed $scriptCacheTtl: otherwise on long-lived
     * pages (an SPA without reloads) the cached script outlives the token and new
     * logs are silently dropped. Default is 2× the cache TTL.
     */
    protected int $tokenTtl = 172800;

    /**
     * @param mixed[] $config
     */
    public function __construct(PhpErrorCatcher $owner, array $config = [])
    {
        parent::__construct($owner, $config);

        // Serving the generated catcher.js (GET on $scriptUrl) — checked FIRST,
        // before the initGetKey log gate: otherwise a GET with ?initGetKey would
        // return JSON-ok instead of the script.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $this->matchScriptUrl()) {
            header('Content-Type: application/javascript; charset=UTF-8');
            header('Cache-Control: max-age=' . $this->scriptCacheTtl . ', immutable');
            echo $this->buildScript();
            exit();
        }

        if (!empty($this->initGetKey) && isset($_GET[$this->initGetKey])) {
            $this->initLogRequest($owner);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'ok']);
            exit();
        }
    }

    /**
     * Accepts a browser error (catcher.js). The endpoint is public by nature
     * (JS posts from arbitrary clients), so all input is treated as untrusted:
     * only POST is accepted and field lengths are capped; escaping on display is
     * the viewer's responsibility (Tools::esc/escAttr).
     */
    public function initLogRequest(PhpErrorCatcher $owner): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        if (!count($_POST)) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $_POST = is_array($decoded) ? $decoded : [];
        }
        if (!$this->checkSecret()) {
            return;
        }
        if (!isset($_POST['m'], $_POST['u'], $_POST['r'])) {
            return;
        }

        $mess = str_replace('||', PHP_EOL, $this->field($_POST['m'], 1600));

        $vars = [
            PhpErrorCatcher::FIELD_NO_TRACE => true,
            PhpErrorCatcher::FIELD_FILE => '',
            'ver' => $this->field($_POST['v'] ?? '', 32),
            'url' => $this->field($_POST['u'], $this->maxFieldLen),
            'referrer' => $this->field($_POST['r'], $this->maxFieldLen),
            'userAgent' => $this->field($_POST['ua'] ?? '', 512),
            'js',
            $this->catcherLogName,
        ];
        if (!empty($_POST['s'])) {
            $vars[PhpErrorCatcher::FIELD_TRACE] = str_replace('||', PHP_EOL, $this->field($_POST['s'], 3200));
        }
        if (!empty($_POST['l'])) {
            $vars[PhpErrorCatcher::FIELD_FILE] = $this->field($_POST['l'], 512);
        }
        // New format: extended context as a single JSON block. The old format
        // (without `ctx`) keeps working — the field is simply absent.
        if (!empty($_POST['ctx'])) {
            $vars['ctx'] = $this->field($_POST['ctx'], $this->ctxMaxLen);
        }

        $owner->log($this->level, $mess, $vars);
    }

    /**
     * Builds the served catcher.js: a preamble with key/token/URL + the contents
     * of the static src/catcher.js. A pure method (no header/exit) — tested
     * directly. Values are injected via json_encode, which yields valid JS
     * literals and rules out injection through the operator config.
     */
    public function buildScript(): string
    {
        $key = !empty($this->initGetKey) ? $this->initGetKey : 'catcherLogName';
        $preamble = 'window.jsLogKey=' . (string) json_encode($key) . ';'
            . 'window.jsLogToken=' . (string) json_encode($this->generateToken()) . ';'
            . 'window.jsLogUrl=' . (string) json_encode('/') . ';' . PHP_EOL;

        return $preamble . (string) file_get_contents(__DIR__ . '/../catcher.js');
    }

    /**
     * Stateless token: base64url(exp ":" hmac_sha256(secret, exp)). Without a
     * secret no tokens are used (empty — the client sends a legacy secret or nothing).
     */
    public function generateToken(): string
    {
        if ($this->secret === '') {
            return '';
        }
        $exp = time() + $this->tokenTtl;
        $payload = $exp . ':' . hash_hmac('sha256', (string) $exp, $this->secret);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Checks access. Without a secret the check is disabled (true) and access is
     * gated by initGetKey only. Otherwise the value from `X-Log-Secret`/`k` is
     * routed structurally: if it parses as a token it is judged as a token (an
     * expired/forged one gets no second chance as a legacy secret), otherwise it
     * is compared as a shared-secret.
     */
    private function checkSecret(): bool
    {
        if ($this->secret === '') {
            return true;
        }
        $provided = (string) ($_SERVER['HTTP_X_LOG_SECRET'] ?? $_POST['k'] ?? '');
        if ($provided === '') {
            return false;
        }

        $token = $this->parseToken($provided);
        if ($token !== null) {
            [$exp, $mac] = $token;
            if ($exp <= time()) {
                return false;
            }
            return hash_equals(hash_hmac('sha256', (string) $exp, $this->secret), $mac);
        }

        return hash_equals($this->secret, $provided);
    }

    /**
     * Parses the value into a token. null — the value is structurally not a token
     * (the caller then treats it as a legacy secret).
     *
     * @return array{0: int, 1: string}|null
     */
    private function parseToken(string $provided): ?array
    {
        $decoded = base64_decode(strtr($provided, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $parts = explode(':', $decoded, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || preg_match('/^[0-9a-f]{64}$/', $parts[1]) !== 1) {
            return null;
        }

        return [(int) $parts[0], $parts[1]];
    }

    private function matchScriptUrl(): bool
    {
        if ($this->scriptUrl === '') {
            return false;
        }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return is_string($path) && $path === $this->scriptUrl;
    }

    /**
     * Coerces an arbitrary request value to a string of the given length.
     */
    private function field(mixed $value, int $limit): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        return mb_substr($value, 0, $limit);
    }
}
