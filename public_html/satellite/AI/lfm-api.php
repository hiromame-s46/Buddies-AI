<?php
/**
 * LFM Web Chat API
 * Public chat is controlled by .lfm-runtime/.env.
 * Package: 4.0.0
 */
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@set_time_limit(180);

require_once __DIR__ . '/lfm-common.php';

function api_error(string $message, int $status = 500, array $extra = array()): void
{
    lfm_json_response(array_merge(array('ok' => false, 'error' => $message), $extra), $status);
}

function api_header_value(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$key] ?? ''));
}

function api_valid_key(array $env): bool
{
    $expected = (string) ($env['LFM_API_KEY'] ?? '');
    if ($expected === '') {
        return false;
    }
    $provided = api_header_value('X-API-Key');
    if ($provided === '') {
        $authorization = api_header_value('Authorization');
        if (stripos($authorization, 'Bearer ') === 0) {
            $provided = trim(substr($authorization, 7));
        }
    }
    return $provided !== '' && hash_equals($expected, $provided);
}

function api_same_origin(array $env): bool
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return true;
    }
    $allowed = trim((string) ($env['LFM_ALLOWED_ORIGIN'] ?? ''));
    if ($allowed !== '') {
        return rtrim($origin, '/') === rtrim($allowed, '/');
    }
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return false;
    }
    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $originHost = strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
        $originHost .= ':' . (int) $parts['port'];
    }
    return strtolower($host) === $originHost;
}

function api_rate_limit(array $env): array
{
    lfm_prepare_runtime();
    $limit = lfm_int($env['LFM_RATE_LIMIT_REQUESTS'] ?? 6, 6, 1, 1000);
    $window = lfm_int($env['LFM_RATE_LIMIT_WINDOW'] ?? 600, 600, 10, 86400);
    $ip = lfm_client_ip();
    $file = LFM_RATE_DIR . '/' . hash('sha256', $ip) . '.json';
    $now = time();
    $handle = @fopen($file, 'c+');
    if (!is_resource($handle)) {
        return array('allowed' => true, 'remaining' => $limit, 'retry_after' => 0);
    }
    @flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $events = json_decode((string) $raw, true);
    if (!is_array($events)) {
        $events = array();
    }
    $cutoff = $now - $window;
    $events = array_values(array_filter($events, static function ($timestamp) use ($cutoff): bool {
        return is_int($timestamp) && $timestamp > $cutoff;
    }));
    $allowed = count($events) < $limit;
    $retry = 0;
    if ($allowed) {
        $events[] = $now;
    } elseif (!empty($events)) {
        $retry = max(1, $window - ($now - (int) $events[0]));
    }
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($events));
    fflush($handle);
    @flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($file, 0640);

    if (random_int(1, 100) === 1) {
        foreach (glob(LFM_RATE_DIR . '/*.json') ?: array() as $candidate) {
            if (@filemtime($candidate) < ($now - 86400)) {
                @unlink($candidate);
            }
        }
    }

    return array('allowed' => $allowed, 'remaining' => max(0, $limit - count($events)), 'retry_after' => $retry);
}

function api_runtime_status(array $env): array
{
    $binary = (string) ($env['LFM_BINARY_PATH'] ?? LFM_BINARY_PATH_DEFAULT);
    $model = (string) ($env['LFM_MODEL_PATH'] ?? LFM_MODEL_PATH_DEFAULT);
    return array(
        'ready' => is_file($binary) && is_executable($binary) && is_file($model),
        'binary' => is_file($binary) && is_executable($binary),
        'model' => is_file($model),
        'public_chat' => lfm_bool($env['LFM_PUBLIC_CHAT'] ?? 'true', true),
        'model_name' => LFM_MODEL_REPO . ':Q4_K_M',
        'version' => LFM_PACKAGE_VERSION,
    );
}

function api_build_prompt(array $input, int $maxChars): array
{
    $system = trim((string) ($input['system'] ?? 'あなたは日本語で簡潔かつ正確に回答するアシスタントです。'));
    $prompt = trim((string) ($input['prompt'] ?? ''));

    if ($prompt === '' && isset($input['messages']) && is_array($input['messages'])) {
        $parts = array();
        foreach (array_slice($input['messages'], -12) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? 'user');
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'system') {
                $system = $content;
            } elseif ($role === 'assistant') {
                $parts[] = "アシスタント:\n" . $content;
            } else {
                $parts[] = "ユーザー:\n" . $content;
            }
        }
        $prompt = implode("\n\n", $parts);
    }

    if ($prompt === '') {
        api_error('prompt must not be empty', 422);
    }
    if (lfm_strlen($prompt) > $maxChars) {
        api_error('prompt must be shorter', 422, array('max_chars' => $maxChars));
    }
    $system = lfm_substr($system, 0, 1200);
    return array($system, $prompt);
}

function api_clean_output(string $text, string $prompt = ''): string
{
    $text = preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $text) ?? $text;
    $text = str_replace("\r", '', $text);
    $lines = explode("\n", $text);

    // Some llama.cpp builds still print the conversation banner and prompt
    // even with --simple-io/--no-display-prompt. Keep only the generated
    // response when that interactive marker is present.
    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*>\s*ユーザー\s*[:：]?\s*$/u', $line)) {
            $lines = array_slice($lines, $index + 1);
            if ($prompt !== '' && isset($lines[0]) && trim($lines[0]) === trim($prompt)) {
                array_shift($lines);
            }
            break;
        }
    }

    $clean = array();
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^Exiting\.\.\.\s*$/i', $trimmed)) {
            break;
        }
        if ($trimmed === '') {
            $clean[] = '';
            continue;
        }
        if (preg_match('/^(llama_|ggml_|build:|main:|system_info:|sampling:|sampler |load_|print_info:|common_)/i', $trimmed)) {
            continue;
        }
        if (preg_match('/^\[.*\]\s*(debug|info|warn|error)\s*:/i', $trimmed)) {
            continue;
        }
        $clean[] = $line;
    }
    $text = trim(implode("\n", $clean));
    $text = preg_replace('/^(assistant|アシスタント)\s*[:：]\s*/iu', '', $text) ?? $text;
    return trim($text);
}

$env = lfm_load_env(false);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Cache-Control: no-store');
    exit;
}

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'health');
    if ($action !== 'health') {
        api_error('not found', 404);
    }
    lfm_json_response(array('ok' => true, 'status' => api_runtime_status($env)));
}

if ($method !== 'POST') {
    api_error('method not allowed', 405);
}

$hasKey = api_valid_key($env);
$public = lfm_bool($env['LFM_PUBLIC_CHAT'] ?? 'true', true);
if (!$hasKey) {
    if (!$public) {
        api_error('API key required', 401);
    }
    if (!api_same_origin($env)) {
        api_error('origin not allowed', 403);
    }
    $rate = api_rate_limit($env);
    if (!$rate['allowed']) {
        header('Retry-After: ' . (int) $rate['retry_after']);
        api_error('rate limit exceeded', 429, array('retry_after' => $rate['retry_after']));
    }
}

$status = api_runtime_status($env);
if (!$status['ready']) {
    api_error('runtime files missing', 503, array('status' => $status));
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 65536) {
    api_error('request too large', 413);
}
$raw = (string) file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    api_error('invalid JSON', 400);
}

$maxPromptChars = lfm_int($env['LFM_MAX_PROMPT_CHARS'] ?? 4000, 4000, 256, 20000);
list($systemPrompt, $prompt) = api_build_prompt($input, $maxPromptChars);
$maxAllowedTokens = lfm_int($env['LFM_MAX_OUTPUT_TOKENS'] ?? 256, 256, 16, 1024);
$maxTokens = lfm_int($input['max_tokens'] ?? min(192, $maxAllowedTokens), min(192, $maxAllowedTokens), 16, $maxAllowedTokens);
$temperature = lfm_float($input['temperature'] ?? 0.2, 0.2, 0.0, 1.5);
$threads = lfm_int($env['LFM_THREADS'] ?? 1, 1, 1, 8);
$context = lfm_int($env['LFM_CONTEXT'] ?? 2048, 2048, 512, 8192);
$timeout = lfm_int($env['LFM_TIMEOUT_SECONDS'] ?? 150, 150, 10, 300);
$binary = (string) ($env['LFM_BINARY_PATH'] ?? LFM_BINARY_PATH_DEFAULT);
$model = (string) ($env['LFM_MODEL_PATH'] ?? LFM_MODEL_PATH_DEFAULT);
$libraryPath = trim((string) ($env['LFM_LIBRARY_PATH'] ?? LFM_LIB_DIR));

lfm_prepare_runtime();
$lock = @fopen(LFM_LOCK_FILE, 'c');
if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    api_error('model is busy', 429);
}

$promptFile = LFM_TMP_DIR . '/' . lfm_random_filename('prompt', '.txt');
$systemFile = LFM_TMP_DIR . '/' . lfm_random_filename('system', '.txt');
@file_put_contents($promptFile, $prompt, LOCK_EX);
@file_put_contents($systemFile, $systemPrompt, LOCK_EX);
@chmod($promptFile, 0600);
@chmod($systemFile, 0600);

$argv = array(
    $binary,
    '-m', $model,
    '-f', $promptFile,
    '-sysf', $systemFile,
    '-cnv',
    '-st',
    '-n', (string) $maxTokens,
    '-c', (string) $context,
    '-t', (string) $threads,
    '--temp', (string) $temperature,
    '--top-k', '40',
    '--top-p', '0.95',
    '--repeat-penalty', '1.05',
    '--simple-io',
    '--no-display-prompt',
    '--no-warmup',
    '--no-show-timings',
    '--log-disable',
);

if (lfm_command_exists('nice')) {
    array_unshift($argv, '10');
    array_unshift($argv, '-n');
    array_unshift($argv, lfm_command_path_raw('nice'));
}

$commandEnv = array();
if ($libraryPath !== '') {
    $currentLd = (string) getenv('LD_LIBRARY_PATH');
    $commandEnv['LD_LIBRARY_PATH'] = $libraryPath . ($currentLd !== '' ? ':' . $currentLd : '');
}
$started = microtime(true);
$result = lfm_run_command($argv, $timeout, $commandEnv, LFM_RUNTIME_DIR);
$elapsed = round(microtime(true) - $started, 3);

lfm_safe_unlink($promptFile);
lfm_safe_unlink($systemFile);
@flock($lock, LOCK_UN);
fclose($lock);

if ($result['timed_out']) {
    api_error('generation timed out', 504, array('elapsed_seconds' => $elapsed));
}
if (!$result['ok']) {
    $details = trim((string) ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
    api_error('llama.cpp execution failed', 500, array(
        'exit_code' => $result['exit_code'],
        'details' => lfm_substr($details, 0, 1600),
        'backend' => $result['backend'],
    ));
}

$text = api_clean_output((string) $result['stdout'], $prompt);
if ($text === '') {
    $details = trim((string) $result['stderr']);
    api_error('empty generation result', 500, array('details' => lfm_substr($details, 0, 1600)));
}

lfm_json_response(array(
    'ok' => true,
    'text' => $text,
    'model' => LFM_MODEL_REPO . ':Q4_K_M',
    'usage' => array('max_output_tokens' => $maxTokens),
    'elapsed_seconds' => $elapsed,
));
