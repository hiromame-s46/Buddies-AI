<?php
/**
 * Shared runtime helpers for LFM Web Chat.
 * Package: 4.0.0 (2026-07-20)
 */
declare(strict_types=1);

if (defined('LFM_COMMON_LOADED')) {
    return;
}
define('LFM_COMMON_LOADED', true);

define('LFM_PACKAGE_VERSION', '4.0.0');
define('LFM_BUILD_ID', 'LFM-WEB-CHAT-FRESH-20260720-400');
define('LFM_RUNTIME_DIRNAME', '.lfm-runtime');
define('LFM_MODEL_REPO', 'LiquidAI/LFM2.5-1.2B-JP-202606-GGUF');
define('LFM_MODEL_FILENAME', 'LFM2.5-1.2B-JP-202606-Q4_K_M.gguf');
define('LFM_MODEL_URL', 'https://huggingface.co/' . LFM_MODEL_REPO . '/resolve/main/' . LFM_MODEL_FILENAME . '?download=true');

define('LFM_BASE_DIR', __DIR__);
define('LFM_RUNTIME_DIR', LFM_BASE_DIR . '/' . LFM_RUNTIME_DIRNAME);
define('LFM_ENV_FILE', LFM_RUNTIME_DIR . '/.env');
define('LFM_CONFIG_FILE', LFM_RUNTIME_DIR . '/config.json');
define('LFM_MODEL_DIR', LFM_RUNTIME_DIR . '/models');
define('LFM_MODEL_PATH_DEFAULT', LFM_MODEL_DIR . '/' . LFM_MODEL_FILENAME);
define('LFM_BIN_DIR', LFM_RUNTIME_DIR . '/bin');
define('LFM_BINARY_PATH_DEFAULT', LFM_BIN_DIR . '/llama-cli');
define('LFM_LIB_DIR', LFM_RUNTIME_DIR . '/lib');
define('LFM_CACHE_DIR', LFM_RUNTIME_DIR . '/cache');
define('LFM_DOWNLOAD_DIR', LFM_RUNTIME_DIR . '/downloads');
define('LFM_LOG_DIR', LFM_RUNTIME_DIR . '/logs');
define('LFM_TMP_DIR', LFM_RUNTIME_DIR . '/tmp');
define('LFM_RATE_DIR', LFM_RUNTIME_DIR . '/rate-limit');
define('LFM_LOCK_FILE', LFM_RUNTIME_DIR . '/generation.lock');
define('LFM_LEARNING_FILE', LFM_RUNTIME_DIR . '/learning-dictionary.json');
define('LFM_SKILL_INDEX_DIR', LFM_CACHE_DIR . '/skill-experts-v1');

function lfm_buddies_admin_user(): ?array
{
    $token = trim((string) ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? ($_COOKIE['sakulabo_token'] ?? '')));
    $token = preg_replace('/^Bearer\s+/i', '', $token) ?? '';
    if ($token === '') return null;

    $configPath = LFM_BASE_DIR . '/../../../api/config.php';
    if (!is_file($configPath)) return null;
    try {
        $config = require $configPath;
        if (!is_array($config)) return null;
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['dbname']),
            $config['username'],
            $config['password'],
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5)
        );
        $statement = $pdo->prepare(
            'SELECT u.id, u.username, u.display_name FROM sakulabo_users u '
            . 'JOIN sakulabo_sessions s ON s.user_id = u.id '
            . 'WHERE s.token = ? AND s.expires_at > NOW() AND u.id = 1 LIMIT 1'
        );
        $statement->execute(array($token));
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    } catch (Throwable $error) {
        error_log('Buddies AI auth: ' . $error->getMessage());
        return null;
    }
}

function lfm_require_buddies_admin(bool $json = false, string $title = 'Buddies AI 管理'): array
{
    $user = lfm_buddies_admin_user();
    if ($user) return $user;
    if ($json) {
        lfm_json_response(array('ok' => false, 'error' => 'uid=1 のBuddies Profileでログインしてください。'), 401);
    }

    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title>' . $safeTitle . '</title><style>'
        . ':root{font-family:-apple-system,BlinkMacSystemFont,"Noto Sans JP",sans-serif;color:#242229;background:#fbfafb}*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:18px}.login{width:min(420px,100%);padding:25px;border:1px solid #eee3e8;border-radius:20px;background:#fff;box-shadow:0 18px 55px #6c315714}h1{margin:0 0 7px;font-size:25px}p{margin:0 0 19px;color:#77717a;line-height:1.7;font-size:13px}.fields{display:grid;gap:10px}input{width:100%;padding:12px 13px;border:1px solid #e3d7dc;border-radius:12px;font:inherit;font-size:16px;outline:0}input:focus{border-color:#d8799b;box-shadow:0 0 0 3px #d8799b1f}button{border:0;border-radius:12px;padding:12px;background:#d8799b;color:#fff;font:inherit;font-weight:800;cursor:pointer}.notice{min-height:20px;margin-top:11px;color:#a72e43;font-size:12px}</style></head><body><main class="login"><h1>' . $safeTitle . '</h1><p>管理機能を開くには、Buddies Profileのuid=1でログインしてください。</p><form id="login" class="fields"><input id="username" autocomplete="username" placeholder="Buddies Profile ユーザー名" required><input id="password" type="password" autocomplete="current-password" placeholder="パスワード" required><button type="submit">ログイン</button></form><div id="notice" class="notice" role="status"></div></main><script>'
        . "const form=document.getElementById('login'),notice=document.getElementById('notice');form.addEventListener('submit',async event=>{event.preventDefault();notice.textContent='ログイン中…';const button=form.querySelector('button');button.disabled=true;try{const response=await fetch('../api.php?action=auth_login',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:document.getElementById('username').value.trim(),password:document.getElementById('password').value})});const data=await response.json();if(!response.ok||data.ok===false||!data.user)throw new Error(data.error||'ログインできませんでした。');if(Number(data.user.id)!==1)throw new Error('このページはuid=1のみ利用できます。');location.reload();}catch(error){notice.textContent=error.message;button.disabled=false;}});"
        . '</script></body></html>';
    exit;
}

function lfm_function_available(string $name): bool
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    return !in_array($name, $disabled, true);
}

function lfm_ensure_dir(string $path, int $mode = 0750): void
{
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, $mode, true) && !is_dir($path)) {
        throw new RuntimeException('ディレクトリを作成できません: ' . $path);
    }
}

function lfm_prepare_runtime(): void
{
    $dirs = array(
        LFM_RUNTIME_DIR,
        LFM_MODEL_DIR,
        LFM_BIN_DIR,
        LFM_LIB_DIR,
        LFM_CACHE_DIR,
        LFM_DOWNLOAD_DIR,
        LFM_LOG_DIR,
        LFM_TMP_DIR,
        LFM_RATE_DIR,
    );
    foreach ($dirs as $dir) {
        lfm_ensure_dir($dir);
    }

    $deny = "# Runtime data is private.\nRequire all denied\nDeny from all\n";
    @file_put_contents(LFM_RUNTIME_DIR . '/.htaccess', $deny, LOCK_EX);
    @file_put_contents(LFM_RUNTIME_DIR . '/index.html', '', LOCK_EX);
    @chmod(LFM_RUNTIME_DIR . '/.htaccess', 0640);
}

function lfm_env_quote(string $value): string
{
    return '"' . str_replace(array('\\', '"', "\n", "\r"), array('\\\\', '\\"', '\\n', ''), $value) . '"';
}

function lfm_parse_env(string $path = LFM_ENV_FILE): array
{
    if (!is_file($path)) {
        return array();
    }
    $result = array();
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return array();
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || substr($line, 0, 1) === '#' || strpos($line, '=') === false) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        if (strlen($value) >= 2) {
            $first = substr($value, 0, 1);
            $last = substr($value, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $value = str_replace(array('\\n', '\\"', '\\\\'), array("\n", '"', '\\'), $value);
        $result[$key] = $value;
    }
    return $result;
}

function lfm_write_env(array $values, string $path = LFM_ENV_FILE): void
{
    lfm_ensure_dir(dirname($path));
    ksort($values);
    $lines = array(
        '# LFM Web Chat private configuration',
        '# Generated by lfm_setup.php. Do not expose this file.',
    );
    foreach ($values as $key => $value) {
        if (!preg_match('/^[A-Z0-9_]+$/', (string) $key)) {
            continue;
        }
        $lines[] = $key . '=' . lfm_env_quote((string) $value);
    }
    if (@file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('.envを書き込めません: ' . $path);
    }
    @chmod($path, 0600);
}

function lfm_default_env(): array
{
    return array(
        'LFM_API_KEY' => bin2hex(random_bytes(32)),
        'LFM_PUBLIC_CHAT' => 'true',
        'LFM_ALLOWED_ORIGIN' => '',
        'LFM_API_DIRECTORY' => 'lfm-api.php',
        'LFM_SYSTEM_PROMPT' => 'あなたは日本語で簡潔かつ正確に回答するアシスタントです。分からない内容は推測で断定しないでください。',
        'LFM_RATE_LIMIT_REQUESTS' => '6',
        'LFM_RATE_LIMIT_WINDOW' => '600',
        'LFM_MAX_PROMPT_CHARS' => '20000',
        'LFM_MAX_OUTPUT_TOKENS' => '1024',
        'LFM_TIMEOUT_SECONDS' => '300',
        'LFM_THREADS' => '2',
        'LFM_CONTEXT' => '8192',
        'LFM_BINARY_PATH' => LFM_BINARY_PATH_DEFAULT,
        'LFM_MODEL_PATH' => LFM_MODEL_PATH_DEFAULT,
        'LFM_LIBRARY_PATH' => LFM_LIB_DIR,
    );
}

function lfm_load_env(bool $create = false): array
{
    if ($create) {
        lfm_prepare_runtime();
    }
    $current = lfm_parse_env();
    if ($create && !is_file(LFM_ENV_FILE)) {
        $current = lfm_default_env();
        lfm_write_env($current);
    }
    return array_replace(lfm_default_env(), $current);
}

function lfm_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed === null ? $default : $parsed;
}

function lfm_int($value, int $default, int $min, int $max): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false) {
        return $default;
    }
    return max($min, min($max, (int) $number));
}

function lfm_float($value, float $default, float $min, float $max): float
{
    if (!is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (float) $value));
}

function lfm_strlen(string $value): int
{
    return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
}

function lfm_substr(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        if ($length === null) {
            return (string) mb_substr($value, $start, null, 'UTF-8');
        }
        return (string) mb_substr($value, $start, $length, 'UTF-8');
    }
    if ($length === null) {
        return (string) substr($value, $start);
    }
    return (string) substr($value, $start, $length);
}

/**
 * Fetch an official Sakurazaka46 blog and return only its article body.
 * The HTML is never persisted. Keeping this here lets chat and Learning use
 * one allow-listed parser instead of exposing a general-purpose URL fetcher.
 */
function lfm_fetch_sakurazaka_blog(string $url, int $maxBodyChars = 14000): array
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, array('sakurazaka46.com', 'www.sakurazaka46.com'), true)
        || preg_match('~^/s/s46/diary/detail/[0-9]+$~', $path) !== 1) {
        throw new InvalidArgumentException('櫻坂46公式ブログの記事URLを指定してください。');
    }

    $html = '';
    $maximumBytes = 2 * 1024 * 1024;
    if (lfm_function_available('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('ブログへ接続できませんでした。');
        curl_setopt_array($handle, array(
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'BuddiesAI/4.0 (+https://sakurazaka46.com/)',
            CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml', 'Accept-Language: ja'),
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$html, $maximumBytes): int {
                if (strlen($html) + strlen($chunk) > $maximumBytes) return 0;
                $html .= $chunk;
                return strlen($chunk);
            },
        ));
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        unset($handle);
        if ($ok === false || $status !== 200 || $html === '') {
            throw new RuntimeException($error !== '' ? 'ブログ本文を取得できませんでした。' : 'ブログが見つかりませんでした。');
        }
    } else {
        $context = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => 15, 'follow_location' => 0, 'ignore_errors' => true,
            'header' => "User-Agent: BuddiesAI/4.0\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: ja\r\n",
        )));
        $html = (string) @file_get_contents($url, false, $context, 0, $maximumBytes);
        if ($html === '' || !preg_match('~^HTTP/\S+\s+200\b~', (string) (($http_response_header ?? array())[0] ?? ''))) {
            throw new RuntimeException('ブログ本文を取得できませんでした。');
        }
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = @$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) throw new RuntimeException('ブログ本文を解析できませんでした。');
    $xpath = new DOMXPath($document);
    $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' box-article ')]");
    $article = $nodes instanceof DOMNodeList ? $nodes->item(0) : null;
    if (!$article) throw new RuntimeException('ブログ本文が見つかりませんでした。');

    foreach (iterator_to_array($xpath->query('.//script|.//style|.//noscript|.//svg|.//picture|.//img', $article) ?: array()) as $node) {
        if ($node->parentNode) $node->parentNode->removeChild($node);
    }
    foreach (iterator_to_array($xpath->query('.//br', $article) ?: array()) as $break) {
        if ($break->parentNode) $break->parentNode->replaceChild($document->createTextNode("\n"), $break);
    }
    $body = html_entity_decode((string) $article->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = preg_replace('/[\t ]+/u', ' ', $body) ?? $body;
    $body = preg_replace('/ *\n */u', "\n", $body) ?? $body;
    $body = trim(preg_replace('/\n{3,}/u', "\n\n", $body) ?? $body);
    if (lfm_strlen($body) < 20) throw new RuntimeException('ブログ本文が空です。');

    $titleNode = $xpath->query('//article//*[self::h1 or self::h2][1]')->item(0)
        ?: $xpath->query('//meta[@property="og:title"]')->item(0);
    $title = $titleNode instanceof DOMElement && $titleNode->hasAttribute('content')
        ? $titleNode->getAttribute('content') : ($titleNode ? trim((string) $titleNode->textContent) : '');
    $ogTitle = $xpath->query('//meta[@property="og:title"]')->item(0);
    $ogTitleText = $ogTitle instanceof DOMElement ? trim($ogTitle->getAttribute('content')) : '';
    $author = preg_match('/^(.+?)\s*公式ブログ/u', $ogTitleText, $authorMatch) ? trim($authorMatch[1]) : '';
    return array('url' => $url, 'title' => $title, 'author' => $author, 'body' => lfm_substr($body, 0, max(1000, $maxBodyChars)));
}

function lfm_human_bytes(float $bytes): string
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    return number_format($bytes, $index === 0 ? 0 : 1) . ' ' . $units[$index];
}

function lfm_read_json(string $path): array
{
    if (!is_file($path)) {
        return array();
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : array();
}

function lfm_write_json(string $path, array $data): void
{
    lfm_ensure_dir(dirname($path));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('JSONを書き込めません: ' . $path);
    }
    @chmod($path, 0640);
}

function lfm_process_backend(): string
{
    foreach (array('proc_open', 'exec', 'shell_exec', 'popen', 'system', 'passthru') as $name) {
        if (lfm_function_available($name)) {
            return $name;
        }
    }
    return 'none';
}

function lfm_shell_command(array $argv, array $env = array()): string
{
    $parts = array();
    if (!empty($env)) {
        $parts[] = 'env';
        foreach ($env as $key => $value) {
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', (string) $key)) {
                $parts[] = $key . '=' . escapeshellarg((string) $value);
            }
        }
    }
    foreach ($argv as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }
    return implode(' ', $parts);
}

function lfm_run_command(array $argv, int $timeout = 30, array $env = array(), ?string $cwd = null): array
{
    $backend = lfm_process_backend();
    if ($backend === 'none') {
        return array('ok' => false, 'exit_code' => 127, 'stdout' => '', 'stderr' => 'PHPから外部コマンドを実行できません。', 'timed_out' => false, 'backend' => 'none');
    }

    $command = lfm_shell_command($argv, $env);
    $timeout = max(1, $timeout);

    if ($backend === 'proc_open') {
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = @proc_open($command, $descriptors, $pipes, $cwd ?: null);
        if (!is_resource($process)) {
            return array('ok' => false, 'exit_code' => 127, 'stdout' => '', 'stderr' => 'proc_openで起動できません。', 'timed_out' => false, 'backend' => $backend);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timedOut = false;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $start) > $timeout) {
                $timedOut = true;
                @proc_terminate($process, 15);
                usleep(200000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    @proc_terminate($process, 9);
                }
                break;
            }
            usleep(50000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = @proc_close($process);
        if ($timedOut) {
            $exit = 124;
        }
        return array('ok' => !$timedOut && $exit === 0, 'exit_code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut, 'backend' => $backend);
    }

    $prefix = '';
    $timeoutPath = lfm_command_path_raw('timeout');
    if ($timeoutPath !== '') {
        $prefix = escapeshellarg($timeoutPath) . ' ' . (int) $timeout . 's ';
    }
    $full = ($cwd ? 'cd ' . escapeshellarg($cwd) . ' && ' : '') . $prefix . $command . ' 2>&1';
    $output = '';
    $exit = 0;

    if ($backend === 'exec') {
        $lines = array();
        @exec($full, $lines, $exit);
        $output = implode("\n", $lines);
    } elseif ($backend === 'shell_exec') {
        $value = @shell_exec($full . '; printf "\\n__LFM_EXIT__%s" $?');
        $output = (string) $value;
        if (preg_match('/\n__LFM_EXIT__(\d+)\s*$/', $output, $match)) {
            $exit = (int) $match[1];
            $output = preg_replace('/\n__LFM_EXIT__\d+\s*$/', '', $output) ?? $output;
        } else {
            $exit = $output === '' ? 1 : 0;
        }
    } elseif ($backend === 'popen') {
        $handle = @popen($full, 'r');
        if (is_resource($handle)) {
            while (!feof($handle)) {
                $output .= (string) fgets($handle);
            }
            $exit = @pclose($handle);
        } else {
            $exit = 127;
        }
    } else {
        ob_start();
        if ($backend === 'system') {
            @system($full, $exit);
        } else {
            @passthru($full, $exit);
        }
        $output = (string) ob_get_clean();
    }

    return array('ok' => $exit === 0, 'exit_code' => $exit, 'stdout' => $output, 'stderr' => $exit === 0 ? '' : $output, 'timed_out' => $exit === 124, 'backend' => $backend);
}

function lfm_command_path_raw(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9._+-]+$/', $name) || lfm_process_backend() === 'none') {
        return '';
    }
    $command = 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
    $output = '';
    if (lfm_function_available('exec')) {
        $lines = array();
        $exit = 1;
        @exec($command, $lines, $exit);
        if ($exit === 0 && !empty($lines)) {
            $output = trim((string) $lines[0]);
        }
    } elseif (lfm_function_available('shell_exec')) {
        $output = trim((string) @shell_exec($command));
    } elseif (lfm_function_available('proc_open')) {
        $result = lfm_run_command(array('sh', '-lc', 'command -v ' . escapeshellarg($name)), 5);
        $output = trim((string) $result['stdout']);
    } elseif (lfm_function_available('popen')) {
        $handle = @popen($command, 'r');
        if (is_resource($handle)) {
            $output = trim((string) stream_get_contents($handle));
            @pclose($handle);
        }
    }
    return ($output !== '' && substr($output, 0, 1) === '/') ? strtok($output, "\r\n") : '';
}

function lfm_command_exists(string $name): bool
{
    return lfm_command_path_raw($name) !== '';
}

function lfm_client_ip(): string
{
    $candidates = array(
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    );
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return 'unknown';
}

function lfm_json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($data, $flags);
    exit;
}

function lfm_random_filename(string $prefix, string $suffix = ''): string
{
    return $prefix . '-' . bin2hex(random_bytes(12)) . $suffix;
}

function lfm_safe_unlink(string $path): bool
{
    if (!is_file($path) && !is_link($path)) {
        return true;
    }
    return @unlink($path);
}

function lfm_remove_tree(string $path, string $mustBeInside = LFM_RUNTIME_DIR): bool
{
    $realBase = realpath($mustBeInside);
    $realPath = realpath($path);
    if ($realBase === false || $realPath === false || strpos($realPath, $realBase . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    if (is_file($realPath) || is_link($realPath)) {
        return @unlink($realPath);
    }
    $items = scandir($realPath);
    if (!is_array($items)) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $realPath . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child) && !is_link($child)) {
            if (!lfm_remove_tree($child, $mustBeInside)) {
                return false;
            }
        } elseif (!@unlink($child)) {
            return false;
        }
    }
    return @rmdir($realPath);
}
