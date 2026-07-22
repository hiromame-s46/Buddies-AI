<?php
/**
 * LFM Web Chat installer / manager
 * Fresh package 4.0.0 with a new installer architecture.
 * Place in public_html/satellite/AI/lfm_setup.php
 */
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@set_time_limit(180);
@ignore_user_abort(true);

require_once __DIR__ . '/lfm-common.php';

const LFM_BOOTSTRAP_SETUP_KEY = 'lfm-setup-change-me-400'; // 必ず変更してください。
const LFM_DOWNLOAD_CHUNK_BYTES = 8 * 1024 * 1024;
const LFM_SOURCE_URL = 'https://github.com/ggml-org/llama.cpp/archive/refs/heads/master.tar.gz';

function setup_key_provided(): string
{
    return trim((string) ($_GET['key'] ?? $_POST['key'] ?? $_SERVER['HTTP_X_INSTALL_KEY'] ?? ''));
}

function setup_authorized(): bool
{
    $provided = setup_key_provided();
    if ($provided === '') {
        return false;
    }
    if (hash_equals(LFM_BOOTSTRAP_SETUP_KEY, $provided)) {
        return true;
    }
    $stored = lfm_parse_env();
    $expected = (string) ($stored['LFM_SETUP_KEY'] ?? '');
    return $expected !== '' && hash_equals($expected, $provided);
}

function setup_require_auth(bool $json = false): void
{
    if (setup_authorized()) {
        return;
    }
    if ($json) {
        lfm_json_response(array('ok' => false, 'error' => 'インストールキーが正しくありません。'), 403);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n\nURLに ?key=インストールキー を付けてください。\n";
    exit;
}

function setup_ok(string $message, array $extra = array()): void
{
    lfm_json_response(array_merge(array('ok' => true, 'message' => $message), $extra));
}

function setup_fail(string $message, int $status = 500, array $extra = array()): void
{
    lfm_json_response(array_merge(array('ok' => false, 'error' => $message), $extra), $status);
}

function setup_dir_size(string $path): int
{
    if (!is_dir($path)) {
        return 0;
    }
    $size = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    } catch (Throwable $e) {
        return $size;
    }
    return $size;
}

function setup_mask(string $value): string
{
    if ($value === '') {
        return '未設定';
    }
    if (strlen($value) <= 10) {
        return str_repeat('•', strlen($value));
    }
    return substr($value, 0, 4) . str_repeat('•', 12) . substr($value, -4);
}

function setup_status(): array
{
    $env = lfm_load_env(false);
    if (!is_file(LFM_ENV_FILE)) {
        $env['LFM_SETUP_KEY'] = '';
        $env['LFM_API_KEY'] = '';
    }
    $binary = (string) ($env['LFM_BINARY_PATH'] ?? LFM_BINARY_PATH_DEFAULT);
    $model = (string) ($env['LFM_MODEL_PATH'] ?? LFM_MODEL_PATH_DEFAULT);
    $part = LFM_DOWNLOAD_DIR . '/' . LFM_MODEL_FILENAME . '.part';
    $binaryVersion = '';
    $dependencyReport = '';
    if (is_file($binary) && is_executable($binary)) {
        $runEnv = array();
        $lib = trim((string) ($env['LFM_LIBRARY_PATH'] ?? ''));
        if ($lib !== '') {
            $runEnv['LD_LIBRARY_PATH'] = $lib;
        }
        $version = lfm_run_command(array($binary, '--version'), 15, $runEnv, LFM_RUNTIME_DIR);
        $binaryVersion = trim((string) ($version['stdout'] !== '' ? $version['stdout'] : $version['stderr']));
        if (lfm_command_exists('ldd')) {
            $ldd = lfm_run_command(array(lfm_command_path_raw('ldd'), $binary), 15, $runEnv);
            $dependencyReport = trim((string) ($ldd['stdout'] !== '' ? $ldd['stdout'] : $ldd['stderr']));
        }
    }

    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    $commands = array();
    foreach (array('cmake', 'g++', 'c++', 'tar', 'nohup', 'git', 'curl', 'wget', 'unzip', 'ldd', 'timeout', 'nice') as $command) {
        $commands[$command] = lfm_command_path_raw($command);
    }

    return array(
        'version' => LFM_PACKAGE_VERSION,
        'build_id' => LFM_BUILD_ID,
        'executing_file' => __FILE__,
        'file_sha256' => hash_file('sha256', __FILE__),
        'base_dir' => LFM_BASE_DIR,
        'runtime_dir' => LFM_RUNTIME_DIR,
        'env_file' => LFM_ENV_FILE,
        'binary_path' => $binary,
        'model_path' => $model,
        'cache_dir' => LFM_CACHE_DIR,
        'runtime_size' => lfm_human_bytes((float) setup_dir_size(LFM_RUNTIME_DIR)),
        'model_installed' => is_file($model),
        'model_size' => is_file($model) ? lfm_human_bytes((float) filesize($model)) : '0 B',
        'model_partial_size' => is_file($part) ? (int) filesize($part) : 0,
        'binary_installed' => is_file($binary) && is_executable($binary),
        'binary_size' => is_file($binary) ? lfm_human_bytes((float) filesize($binary)) : '0 B',
        'binary_version' => lfm_substr($binaryVersion, 0, 800),
        'dependency_report' => lfm_substr($dependencyReport, 0, 4000),
        'env_exists' => is_file(LFM_ENV_FILE),
        'setup_key_masked' => setup_mask((string) ($env['LFM_SETUP_KEY'] ?? '')),
        'api_key_masked' => setup_mask((string) ($env['LFM_API_KEY'] ?? '')),
        'public_chat' => lfm_bool($env['LFM_PUBLIC_CHAT'] ?? 'true', true),
        'api_directory' => (string) ($env['LFM_API_DIRECTORY'] ?? 'lfm-api.php'),
        'system_prompt' => (string) ($env['LFM_SYSTEM_PROMPT'] ?? ''),
        'rate_limit' => array(
            'requests' => lfm_int($env['LFM_RATE_LIMIT_REQUESTS'] ?? 6, 6, 1, 1000),
            'window' => lfm_int($env['LFM_RATE_LIMIT_WINDOW'] ?? 600, 600, 10, 86400),
        ),
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'architecture' => php_uname('m'),
        'process_backend' => lfm_process_backend(),
        'disabled_functions' => array_values($disabled),
        'extensions' => array(
            'curl' => extension_loaded('curl'),
            'zip' => extension_loaded('zip'),
            'mbstring' => extension_loaded('mbstring'),
        ),
        'commands' => $commands,
        'free_space' => ($free = @disk_free_space(LFM_BASE_DIR)) !== false ? lfm_human_bytes((float) $free) : '取得不可',
    );
}

function setup_initialize(): void
{
    $already = is_file(LFM_ENV_FILE);
    lfm_prepare_runtime();
    $env = lfm_parse_env();
    if (!$already) {
        $env = lfm_default_env();
        // Keep the currently used key valid after initialization.
        $env['LFM_SETUP_KEY'] = setup_key_provided() !== '' ? setup_key_provided() : LFM_BOOTSTRAP_SETUP_KEY;
    } else {
        $env = array_replace(lfm_default_env(), $env);
    }
    $env['LFM_BINARY_PATH'] = $env['LFM_BINARY_PATH'] ?? LFM_BINARY_PATH_DEFAULT;
    $env['LFM_MODEL_PATH'] = $env['LFM_MODEL_PATH'] ?? LFM_MODEL_PATH_DEFAULT;
    $env['LFM_LIBRARY_PATH'] = $env['LFM_LIBRARY_PATH'] ?? LFM_LIB_DIR;
    lfm_write_env($env);
    lfm_write_json(LFM_CONFIG_FILE, array(
        'package_version' => LFM_PACKAGE_VERSION,
        'build_id' => LFM_BUILD_ID,
        'model_repo' => LFM_MODEL_REPO,
        'model_file' => LFM_MODEL_FILENAME,
        'initialized_at' => gmdate('c'),
    ));
    setup_ok($already ? 'ランタイム設定を更新しました。' : 'ランタイムを初期化しました。', array('status' => setup_status()));
}

function setup_save_settings(): void
{
    lfm_prepare_runtime();
    $env = lfm_load_env(true);
    $env['LFM_PUBLIC_CHAT'] = isset($_POST['public_chat']) && $_POST['public_chat'] === 'true' ? 'true' : 'false';
    $env['LFM_ALLOWED_ORIGIN'] = trim((string) ($_POST['allowed_origin'] ?? ''));
    $env['LFM_API_DIRECTORY'] = trim((string) ($_POST['api_directory'] ?? 'lfm-api.php')) ?: 'lfm-api.php';
    $env['LFM_SYSTEM_PROMPT'] = trim((string) ($_POST['system_prompt'] ?? '')) ?: lfm_default_env()['LFM_SYSTEM_PROMPT'];
    $env['LFM_RATE_LIMIT_REQUESTS'] = (string) lfm_int($_POST['rate_requests'] ?? 6, 6, 1, 1000);
    $env['LFM_RATE_LIMIT_WINDOW'] = (string) lfm_int($_POST['rate_window'] ?? 600, 600, 10, 86400);
    $env['LFM_MAX_PROMPT_CHARS'] = (string) lfm_int($_POST['max_prompt_chars'] ?? 20000, 20000, 256, 20000);
    $env['LFM_MAX_OUTPUT_TOKENS'] = (string) lfm_int($_POST['max_output_tokens'] ?? 1024, 1024, 16, 1024);
    $env['LFM_TIMEOUT_SECONDS'] = (string) lfm_int($_POST['timeout_seconds'] ?? 300, 300, 10, 300);
    $env['LFM_THREADS'] = (string) lfm_int($_POST['threads'] ?? 2, 2, 1, 8);
    $env['LFM_CONTEXT'] = (string) lfm_int($_POST['context'] ?? 8192, 8192, 512, 8192);
    lfm_write_env($env);
    setup_ok('設定を保存しました。', array('status' => setup_status()));
}

function setup_regenerate_keys(): void
{
    $confirmation = trim((string) ($_POST['confirm'] ?? ''));
    if ($confirmation !== 'REGENERATE') {
        setup_fail('確認文字列が正しくありません。', 422);
    }
    $env = lfm_load_env(true);
    $env['LFM_SETUP_KEY'] = bin2hex(random_bytes(24));
    $env['LFM_API_KEY'] = bin2hex(random_bytes(32));
    lfm_write_env($env);
    setup_ok('キーを再生成しました。新しいセットアップキーを必ず保存してください。', array(
        'setup_key' => $env['LFM_SETUP_KEY'],
        'api_key' => $env['LFM_API_KEY'],
    ));
}

function setup_remote_size(string $url): int
{
    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'LFM-Web-Chat/' . LFM_PACKAGE_VERSION,
        ));
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        if ($code >= 200 && $code < 400 && $size > 0) {
            return $size;
        }
    }
    if (ini_get('allow_url_fopen')) {
        $headers = @get_headers($url, true);
        if (is_array($headers)) {
            $length = $headers['Content-Length'] ?? $headers['content-length'] ?? 0;
            if (is_array($length)) {
                $length = end($length);
            }
            return max(0, (int) $length);
        }
    }
    return 0;
}

function setup_download_range(string $url, int $start, int $end, string $destination): array
{
    $expected = $end - $start + 1;
    if (extension_loaded('curl')) {
        $handle = @fopen($destination, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }
        $headers = array();
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RANGE => $start . '-' . $end,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT => 'LFM-Web-Chat/' . LFM_PACKAGE_VERSION,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ));
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($handle);
        $bytes = is_file($destination) ? (int) filesize($destination) : 0;
        if (!$ok || ($code !== 206 && !($code === 200 && $start === 0))) {
            @unlink($destination);
            throw new RuntimeException('ダウンロード失敗 HTTP ' . $code . ($error !== '' ? ': ' . $error : ''));
        }
        if ($bytes <= 0 || ($code === 206 && $bytes > $expected + 1024)) {
            @unlink($destination);
            throw new RuntimeException('取得サイズが不正です。');
        }
        $total = 0;
        $contentRange = (string) ($headers['content-range'] ?? '');
        if (preg_match('/\/(\d+)$/', $contentRange, $match)) {
            $total = (int) $match[1];
        }
        return array('bytes' => $bytes, 'http_code' => $code, 'total' => $total);
    }

    $curlPath = lfm_command_path_raw('curl');
    if ($curlPath !== '') {
        $headerFile = $destination . '.headers';
        $result = lfm_run_command(array(
            $curlPath, '-L', '--fail', '--silent', '--show-error',
            '--range', $start . '-' . $end,
            '--dump-header', $headerFile,
            '--output', $destination,
            $url,
        ), 100);
        $bytes = is_file($destination) ? (int) filesize($destination) : 0;
        $total = 0;
        $headersText = is_file($headerFile) ? (string) file_get_contents($headerFile) : '';
        @unlink($headerFile);
        if (preg_match_all('/^Content-Range:\s*bytes\s+\d+-\d+\/(\d+)/im', $headersText, $matches) && !empty($matches[1])) {
            $total = (int) end($matches[1]);
        }
        if (!$result['ok'] || $bytes <= 0) {
            @unlink($destination);
            throw new RuntimeException('curlコマンドでのモデル取得に失敗しました: ' . trim((string) $result['stderr']));
        }
        return array('bytes' => $bytes, 'http_code' => 206, 'total' => $total);
    }

    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException('cURL拡張・curlコマンド・allow_url_fopenがすべて利用できません。');
    }
    $context = stream_context_create(array('http' => array(
        'method' => 'GET',
        'header' => "Range: bytes={$start}-{$end}\r\nUser-Agent: LFM-Web-Chat/" . LFM_PACKAGE_VERSION . "\r\n",
        'follow_location' => 1,
        'timeout' => 90,
        'ignore_errors' => true,
    )));
    $in = @fopen($url, 'rb', false, $context);
    if (!is_resource($in)) {
        throw new RuntimeException('モデルを取得できません。');
    }
    $out = @fopen($destination, 'wb');
    if (!is_resource($out)) {
        fclose($in);
        throw new RuntimeException('一時ファイルを作成できません。');
    }
    $bytes = (int) stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if ($bytes <= 0) {
        @unlink($destination);
        throw new RuntimeException('モデルの取得結果が空です。');
    }
    $total = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^Content-Range:\s*bytes\s+\d+-\d+\/(\d+)/i', (string) $headerLine, $match)) {
                $total = (int) $match[1];
            }
        }
    }
    return array('bytes' => $bytes, 'http_code' => 206, 'total' => $total);
}

function setup_model_info(): void
{
    lfm_prepare_runtime();
    $part = LFM_DOWNLOAD_DIR . '/' . LFM_MODEL_FILENAME . '.part';
    $totalFile = LFM_DOWNLOAD_DIR . '/' . LFM_MODEL_FILENAME . '.size';
    $total = is_file($totalFile) ? (int) trim((string) file_get_contents($totalFile)) : 0;
    if ($total <= 0) {
        $total = setup_remote_size(LFM_MODEL_URL);
        if ($total > 0) {
            file_put_contents($totalFile, (string) $total, LOCK_EX);
        }
    }
    setup_ok('モデル情報を取得しました。', array(
        'total' => $total,
        'downloaded' => is_file($part) ? (int) filesize($part) : (is_file(LFM_MODEL_PATH_DEFAULT) ? (int) filesize(LFM_MODEL_PATH_DEFAULT) : 0),
        'installed' => is_file(LFM_MODEL_PATH_DEFAULT),
        'filename' => LFM_MODEL_FILENAME,
    ));
}

function setup_download_model_chunk(): void
{
    lfm_prepare_runtime();
    if (is_file(LFM_MODEL_PATH_DEFAULT)) {
        setup_ok('モデルは既にインストール済みです。', array('done' => true, 'downloaded' => filesize(LFM_MODEL_PATH_DEFAULT), 'total' => filesize(LFM_MODEL_PATH_DEFAULT)));
    }
    $part = LFM_DOWNLOAD_DIR . '/' . LFM_MODEL_FILENAME . '.part';
    $totalFile = LFM_DOWNLOAD_DIR . '/' . LFM_MODEL_FILENAME . '.size';
    $total = is_file($totalFile) ? (int) trim((string) file_get_contents($totalFile)) : setup_remote_size(LFM_MODEL_URL);
    if ($total > 0) {
        file_put_contents($totalFile, (string) $total, LOCK_EX);
    }
    $start = is_file($part) ? (int) filesize($part) : 0;
    if ($total > 0 && $start >= $total) {
        @rename($part, LFM_MODEL_PATH_DEFAULT);
        @chmod(LFM_MODEL_PATH_DEFAULT, 0640);
        setup_ok('モデルのインストールが完了しました。', array('done' => true, 'downloaded' => $total, 'total' => $total));
    }
    $end = $total > 0 ? min($total - 1, $start + LFM_DOWNLOAD_CHUNK_BYTES - 1) : ($start + LFM_DOWNLOAD_CHUNK_BYTES - 1);
    $chunk = LFM_DOWNLOAD_DIR . '/model-' . $start . '.chunk';
    try {
        $result = setup_download_range(LFM_MODEL_URL, $start, $end, $chunk);
        if ($total <= 0 && !empty($result['total'])) {
            $total = (int) $result['total'];
            file_put_contents($totalFile, (string) $total, LOCK_EX);
        }
        if ($total <= 0 && (int) $result['bytes'] < LFM_DOWNLOAD_CHUNK_BYTES) {
            $total = $start + (int) $result['bytes'];
            file_put_contents($totalFile, (string) $total, LOCK_EX);
        }
        $in = fopen($chunk, 'rb');
        $out = fopen($part, 'ab');
        if (!is_resource($in) || !is_resource($out)) {
            throw new RuntimeException('モデルファイルへ追記できません。');
        }
        flock($out, LOCK_EX);
        stream_copy_to_stream($in, $out);
        fflush($out);
        flock($out, LOCK_UN);
        fclose($in);
        fclose($out);
        @unlink($chunk);
        clearstatcache(true, $part);
        $downloaded = (int) filesize($part);
        $done = $total > 0 && $downloaded >= $total;
        if ($done) {
            if (!@rename($part, LFM_MODEL_PATH_DEFAULT)) {
                throw new RuntimeException('完成したモデルを移動できません。');
            }
            @chmod(LFM_MODEL_PATH_DEFAULT, 0640);
        }
        setup_ok($done ? 'モデルのインストールが完了しました。' : 'モデルを分割ダウンロードしました。', array(
            'done' => $done,
            'downloaded' => $done ? $total : $downloaded,
            'total' => $total,
            'chunk_bytes' => $result['bytes'],
        ));
    } catch (Throwable $e) {
        @unlink($chunk);
        setup_fail($e->getMessage(), 502);
    }
}

function setup_delete_model(): void
{
    if (trim((string) ($_POST['confirm'] ?? '')) !== 'DELETE MODEL') {
        setup_fail('確認文字列が正しくありません。', 422);
    }
    $lock = @fopen(LFM_LOCK_FILE, 'c');
    if (is_resource($lock) && !@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        setup_fail('推論中のためモデルを削除できません。', 409);
    }
    foreach (array(
        LFM_MODEL_PATH_DEFAULT,
        LFM_DOWNLOAD_DIR . '/' . LFM_MODEL_FILENAME . '.part',
        LFM_DOWNLOAD_DIR . '/' . LFM_MODEL_FILENAME . '.size',
    ) as $path) {
        lfm_safe_unlink($path);
    }
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
    setup_ok('モデルと途中ダウンロードを削除しました。', array('status' => setup_status()));
}

function setup_delete_cache(): void
{
    if (trim((string) ($_POST['confirm'] ?? '')) !== 'DELETE CACHE') {
        setup_fail('確認文字列が正しくありません。', 422);
    }
    foreach (array(LFM_CACHE_DIR, LFM_DOWNLOAD_DIR, LFM_LOG_DIR, LFM_TMP_DIR, LFM_RATE_DIR) as $dir) {
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: array() as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . '/' . $item;
                if (is_dir($path) && !is_link($path)) {
                    lfm_remove_tree($path);
                } else {
                    @unlink($path);
                }
            }
        }
    }
    setup_ok('キャッシュ、ログ、途中ファイル、レート制限記録を削除しました。モデル・バイナリ・.envは残しています。', array('status' => setup_status()));
}

function setup_delete_runtime(): void
{
    if (trim((string) ($_POST['confirm'] ?? '')) !== 'DELETE ALL RUNTIME') {
        setup_fail('確認文字列が正しくありません。', 422);
    }
    if (is_dir(LFM_RUNTIME_DIR) && !lfm_remove_tree(LFM_RUNTIME_DIR, LFM_BASE_DIR)) {
        setup_fail('ランタイムディレクトリを完全に削除できませんでした。権限を確認してください。');
    }
    setup_ok('ランタイム全体を削除しました。PHPとHTMLは残っています。');
}

function setup_find_uploaded_binary(string $root): string
{
    $candidates = array($root . '/llama-cli', $root . '/bin/llama-cli');
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'llama-cli') {
            return $file->getPathname();
        }
    }
    return '';
}

function setup_upload_bundle(): void
{
    lfm_prepare_runtime();
    if (!isset($_FILES['bundle']) || !is_array($_FILES['bundle']) || (int) $_FILES['bundle']['error'] !== UPLOAD_ERR_OK) {
        setup_fail('アップロードファイルを受信できませんでした。PHPのupload_max_filesizeも確認してください。', 422);
    }
    $tmp = (string) $_FILES['bundle']['tmp_name'];
    $name = basename((string) $_FILES['bundle']['name']);
    $work = LFM_TMP_DIR . '/' . lfm_random_filename('bundle');
    lfm_ensure_dir($work);
    try {
        if (preg_match('/\.zip$/i', $name)) {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($tmp) !== true) {
                    throw new RuntimeException('ZIPを開けません。');
                }
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = (string) $zip->getNameIndex($i);
                    $normalized = str_replace('\\', '/', $entry);
                    if ($normalized === '' || strpos($normalized, '../') !== false || substr($normalized, 0, 1) === '/') {
                        continue;
                    }
                    $base = basename($normalized);
                    $isBinary = $base === 'llama-cli';
                    $isLibrary = preg_match('/\.so(?:\.[0-9.]+)?$/', $base) === 1;
                    if (!$isBinary && !$isLibrary) {
                        continue;
                    }
                    $stream = $zip->getStream($entry);
                    if (!is_resource($stream)) {
                        continue;
                    }
                    $destination = $work . '/' . ($isBinary ? 'llama-cli' : 'lib/' . $base);
                    lfm_ensure_dir(dirname($destination));
                    $out = fopen($destination, 'wb');
                    stream_copy_to_stream($stream, $out);
                    fclose($out);
                    fclose($stream);
                }
                $zip->close();
            } elseif (lfm_command_exists('unzip')) {
                $archiveCopy = $work . '/bundle.zip';
                if (!@copy($tmp, $archiveCopy)) {
                    throw new RuntimeException('ZIPを一時保存できません。');
                }
                $unzip = lfm_run_command(array(lfm_command_path_raw('unzip'), '-o', '-j', $archiveCopy, '-d', $work), 60);
                @unlink($archiveCopy);
                if (!$unzip['ok']) {
                    throw new RuntimeException('unzipコマンドでZIPを展開できません: ' . trim((string) $unzip['stderr']));
                }
                foreach (glob($work . '/*.so*') ?: array() as $libFile) {
                    lfm_ensure_dir($work . '/lib');
                    @rename($libFile, $work . '/lib/' . basename($libFile));
                }
            } else {
                throw new RuntimeException('ZipArchive拡張とunzipコマンドがありません。llama-cli単体をアップロードしてください。');
            }
        } else {
            if (!@move_uploaded_file($tmp, $work . '/llama-cli') && !@copy($tmp, $work . '/llama-cli')) {
                throw new RuntimeException('llama-cliを保存できません。');
            }
        }

        $found = setup_find_uploaded_binary($work);
        if ($found === '') {
            throw new RuntimeException('ZIP内にllama-cliが見つかりません。');
        }
        if (!@copy($found, LFM_BINARY_PATH_DEFAULT)) {
            throw new RuntimeException('llama-cliをインストールできません。');
        }
        @chmod(LFM_BINARY_PATH_DEFAULT, 0750);
        $uploadedLib = $work . '/lib';
        if (is_dir($uploadedLib)) {
            foreach (glob($uploadedLib . '/*.so*') ?: array() as $lib) {
                @copy($lib, LFM_LIB_DIR . '/' . basename($lib));
                @chmod(LFM_LIB_DIR . '/' . basename($lib), 0640);
            }
        }
        $env = lfm_load_env(true);
        $env['LFM_BINARY_PATH'] = LFM_BINARY_PATH_DEFAULT;
        $env['LFM_LIBRARY_PATH'] = LFM_LIB_DIR;
        lfm_write_env($env);
        lfm_remove_tree($work);
        setup_ok('ポータブルllama-cliをインストールしました。', array('status' => setup_status()));
    } catch (Throwable $e) {
        if (is_dir($work)) {
            lfm_remove_tree($work);
        }
        setup_fail($e->getMessage(), 422);
    }
}

function setup_test_binary(): void
{
    $env = lfm_load_env(false);
    $binary = (string) ($env['LFM_BINARY_PATH'] ?? LFM_BINARY_PATH_DEFAULT);
    if (!is_file($binary)) {
        setup_fail('llama-cliがありません。', 404);
    }
    $runEnv = array('LD_LIBRARY_PATH' => (string) ($env['LFM_LIBRARY_PATH'] ?? LFM_LIB_DIR));
    $result = lfm_run_command(array($binary, '--version'), 20, $runEnv, LFM_RUNTIME_DIR);
    setup_ok($result['ok'] ? 'llama-cliは起動できました。' : 'llama-cliの起動に失敗しました。', array('result' => $result, 'status' => setup_status()));
}

function setup_test_inference(): void
{
    $env = lfm_load_env(false);
    $binary = (string) ($env['LFM_BINARY_PATH'] ?? LFM_BINARY_PATH_DEFAULT);
    $model = (string) ($env['LFM_MODEL_PATH'] ?? LFM_MODEL_PATH_DEFAULT);
    if (!is_file($binary) || !is_executable($binary) || !is_file($model)) {
        setup_fail('モデルまたはllama-cliがありません。', 404);
    }
    $prompt = LFM_TMP_DIR . '/setup-test-prompt.txt';
    $system = LFM_TMP_DIR . '/setup-test-system.txt';
    file_put_contents($prompt, '「動作確認成功」とだけ日本語で答えてください。', LOCK_EX);
    file_put_contents($system, '指示に簡潔に従ってください。', LOCK_EX);
    $argv = array($binary, '-m', $model, '-f', $prompt, '-sysf', $system, '-cnv', '-st', '-n', '24', '-c', '512', '-t', '1', '--temp', '0.1', '--simple-io', '--no-display-prompt', '--no-warmup', '--log-disable');
    $runEnv = array('LD_LIBRARY_PATH' => (string) ($env['LFM_LIBRARY_PATH'] ?? LFM_LIB_DIR));
    $result = lfm_run_command($argv, min(120, lfm_int($env['LFM_TIMEOUT_SECONDS'] ?? 150, 150, 10, 300)), $runEnv, LFM_RUNTIME_DIR);
    @unlink($prompt);
    @unlink($system);
    setup_ok($result['ok'] ? '推論テストが完了しました。' : '推論テストに失敗しました。', array('result' => $result));
}

function setup_generate_dictionary(): void
{
    $env = lfm_load_env(false);
    $binary = (string) ($env['LFM_BINARY_PATH'] ?? LFM_BINARY_PATH_DEFAULT);
    $model = (string) ($env['LFM_MODEL_PATH'] ?? LFM_MODEL_PATH_DEFAULT);
    if (!is_file($binary) || !is_file($model)) setup_fail('モデルまたはllama-cliがありません。', 404);
    $topic = trim((string) ($_POST['topic'] ?? '櫻坂46の用語'));
    $promptFile = LFM_TMP_DIR . '/' . lfm_random_filename('dict-prompt', '.txt');
    $systemFile = LFM_TMP_DIR . '/' . lfm_random_filename('dict-system', '.txt');
    file_put_contents($promptFile, "櫻坂46に関する『{$topic}』の用語を10件作成してください。JSON配列だけを出力し、各要素はterm, definition, category, aliasesを持たせてください。事実に自信がない項目は作らないでください。", LOCK_EX);
    file_put_contents($systemFile, (string) ($env['LFM_SYSTEM_PROMPT'] ?? '日本語で正確に回答してください。'), LOCK_EX);
    $argv = array($binary, '-m', $model, '-f', $promptFile, '-sysf', $systemFile, '-n', '768', '-c', '2048', '-t', '1', '--temp', '0.2', '--simple-io', '--no-display-prompt', '--no-warmup', '--log-disable');
    $result = lfm_run_command($argv, 150, array('LD_LIBRARY_PATH' => (string) ($env['LFM_LIBRARY_PATH'] ?? LFM_LIB_DIR)), LFM_RUNTIME_DIR);
    @unlink($promptFile); @unlink($systemFile);
    if (!$result['ok']) setup_fail('辞書生成に失敗しました。', 500, array('details' => lfm_substr((string) $result['stderr'], 0, 1200)));
    $raw = trim((string) $result['stdout']);
    $start = strpos($raw, '['); $end = strrpos($raw, ']');
    $items = ($start !== false && $end !== false) ? json_decode(substr($raw, $start, $end - $start + 1), true) : null;
    if (!is_array($items)) setup_fail('AIの出力からJSON辞書を抽出できませんでした。', 422, array('raw' => lfm_substr($raw, 0, 2000)));
    $path = __DIR__ . '/sakurazaka_terms.json';
    $current = is_file($path) ? json_decode((string) file_get_contents($path), true) : array();
    $current = is_array($current) ? $current : array();
    $known = array(); foreach ($current as $item) if (is_array($item) && isset($item['term'])) $known[(string) $item['term']] = true;
    foreach ($items as $item) if (is_array($item) && trim((string) ($item['term'] ?? '')) !== '' && !isset($known[(string) $item['term']])) $current[] = array('term' => trim((string) $item['term']), 'definition' => trim((string) ($item['definition'] ?? '')), 'category' => trim((string) ($item['category'] ?? '用語')), 'aliases' => is_array($item['aliases'] ?? null) ? array_values($item['aliases']) : array());
    file_put_contents($path, json_encode(array_values($current), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    setup_ok('AI生成の辞書候補を登録しました。', array('added' => count($current) - count($known), 'total' => count($current), 'items' => $items));
}

function setup_start_source_build(): void
{
    lfm_prepare_runtime();
    $required = array('cmake', 'tar', 'nohup', 'sh');
    $compiler = lfm_command_path_raw('g++') !== '' ? lfm_command_path_raw('g++') : lfm_command_path_raw('c++');
    $missing = array();
    foreach ($required as $command) {
        if (lfm_command_path_raw($command) === '') {
            $missing[] = $command;
        }
    }
    if ($compiler === '') {
        $missing[] = 'g++/c++';
    }
    if (lfm_process_backend() === 'none') {
        $missing[] = 'PHP process function';
    }
    if (!empty($missing)) {
        setup_fail('ソースビルドに必要な環境がありません。ポータブルZIPのアップロード方式を使用してください。', 422, array('missing' => $missing));
    }

    $archive = LFM_DOWNLOAD_DIR . '/llama-source.tar.gz';
    $sourceRoot = LFM_RUNTIME_DIR . '/source';
    $buildRoot = LFM_RUNTIME_DIR . '/source-build';
    $script = LFM_RUNTIME_DIR . '/build-portable.sh';
    $log = LFM_LOG_DIR . '/source-build.log';
    $done = LFM_RUNTIME_DIR . '/source-build.done';
    $failed = LFM_RUNTIME_DIR . '/source-build.failed';
    @unlink($done);
    @unlink($failed);

    if (!is_file($archive)) {
        $data = @file_get_contents(LFM_SOURCE_URL);
        if ($data === false || strlen($data) < 100000) {
            setup_fail('llama.cppソースを取得できません。', 502);
        }
        file_put_contents($archive, $data, LOCK_EX);
    }
    if (is_dir($sourceRoot)) {
        lfm_remove_tree($sourceRoot);
    }
    lfm_ensure_dir($sourceRoot);
    $extract = lfm_run_command(array(lfm_command_path_raw('tar'), '-xzf', $archive, '-C', $sourceRoot, '--strip-components=1'), 90);
    if (!$extract['ok']) {
        setup_fail('ソースを展開できません。', 500, array('details' => $extract));
    }

    $scriptText = '#!/bin/sh' . "\n" .
        'set -eu' . "\n" .
        'rm -rf ' . escapeshellarg($buildRoot) . "\n" .
        escapeshellarg(lfm_command_path_raw('cmake')) . ' -S ' . escapeshellarg($sourceRoot) . ' -B ' . escapeshellarg($buildRoot) . ' ' .
        '-DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DGGML_STATIC=ON -DGGML_NATIVE=OFF -DGGML_OPENMP=OFF -DGGML_BLAS=OFF -DGGML_BACKEND_DL=OFF -DGGML_CCACHE=OFF -DLLAMA_CURL=OFF -DLLAMA_BUILD_TESTS=OFF -DLLAMA_BUILD_EXAMPLES=OFF -DLLAMA_BUILD_SERVER=OFF -DLLAMA_BUILD_TOOLS=ON' . "\n" .
        escapeshellarg(lfm_command_path_raw('cmake')) . ' --build ' . escapeshellarg($buildRoot) . ' --config Release --target llama-completion -j1' . "\n" .
        'cp ' . escapeshellarg($buildRoot . '/bin/llama-completion') . ' ' . escapeshellarg(LFM_BINARY_PATH_DEFAULT) . "\n" .
        'chmod 750 ' . escapeshellarg(LFM_BINARY_PATH_DEFAULT) . "\n" .
        'touch ' . escapeshellarg($done) . "\n";
    file_put_contents($script, $scriptText, LOCK_EX);
    @chmod($script, 0750);
    $launch = 'nohup ' . escapeshellarg($script) . ' > ' . escapeshellarg($log) . ' 2>&1 || touch ' . escapeshellarg($failed) . ' &';
    $result = lfm_run_command(array(lfm_command_path_raw('sh'), '-lc', $launch), 10);
    if (!$result['ok']) {
        setup_fail('バックグラウンドビルドを開始できません。', 500, array('details' => $result));
    }
    setup_ok('ソースビルドを開始しました。ログから進行状況を確認できます。');
}

function setup_build_status(): void
{
    $log = LFM_LOG_DIR . '/source-build.log';
    $text = is_file($log) ? (string) file_get_contents($log) : '';
    setup_ok('ビルド状況を取得しました。', array(
        'done' => is_file(LFM_RUNTIME_DIR . '/source-build.done'),
        'failed' => is_file(LFM_RUNTIME_DIR . '/source-build.failed'),
        'log' => lfm_substr($text, -12000),
        'binary_installed' => is_file(LFM_BINARY_PATH_DEFAULT) && is_executable(LFM_BINARY_PATH_DEFAULT),
    ));
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($action !== '') {
    setup_require_auth(true);
    try {
        switch ($action) {
            case 'status': lfm_json_response(array('ok' => true, 'status' => setup_status()));
            case 'init': setup_initialize(); break;
            case 'save_settings': setup_save_settings(); break;
            case 'regenerate_keys': setup_regenerate_keys(); break;
            case 'model_info': setup_model_info(); break;
            case 'download_model_chunk': setup_download_model_chunk(); break;
            case 'delete_model': setup_delete_model(); break;
            case 'delete_cache': setup_delete_cache(); break;
            case 'delete_runtime': setup_delete_runtime(); break;
            case 'upload_bundle': setup_upload_bundle(); break;
            case 'test_binary': setup_test_binary(); break;
            case 'test_inference': setup_test_inference(); break;
            case 'generate_dictionary': setup_generate_dictionary(); break;
            case 'start_source_build': setup_start_source_build(); break;
            case 'build_status': setup_build_status(); break;
            default: setup_fail('不明な操作です。', 404);
        }
    } catch (Throwable $e) {
        setup_fail($e->getMessage(), 500);
    }
}

setup_require_auth(false);
$status = setup_status();
$keyForJs = setup_key_provided();
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>LFM セットアップ 4.0</title>
<style>
:root{color-scheme:light dark;--bg:#f4f6f9;--panel:#fff;--text:#17202a;--muted:#68717d;--line:#dce1e8;--accent:#315ee7;--ok:#12805c;--warn:#a76400;--bad:#be3232;--code:#eef1f6;--shadow:0 12px 38px rgba(26,35,52,.08);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}@media(prefers-color-scheme:dark){:root{--bg:#0d1015;--panel:#171b22;--text:#f2f4f7;--muted:#a0a7b2;--line:#2c323d;--accent:#7d9cff;--ok:#5fd5ad;--warn:#ffc166;--bad:#ff8585;--code:#222833;--shadow:0 12px 38px rgba(0,0,0,.3)}}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text)}main{width:min(1100px,calc(100% - 28px));margin:0 auto;padding:calc(22px + env(safe-area-inset-top)) 0 calc(50px + env(safe-area-inset-bottom))}header{display:flex;gap:16px;align-items:flex-start;justify-content:space-between;margin-bottom:18px}h1{font-size:clamp(24px,5vw,38px);margin:0 0 6px}.version{display:inline-flex;padding:5px 9px;border-radius:999px;background:var(--code);font:12px ui-monospace,monospace}.lead{color:var(--muted);margin:6px 0 0}.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}.card{grid-column:span 6;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:18px;box-shadow:var(--shadow)}.wide{grid-column:1/-1}.third{grid-column:span 4}h2{font-size:18px;margin:0 0 13px}h3{font-size:15px;margin:18px 0 8px}.rows{display:grid;gap:7px}.row{display:grid;grid-template-columns:minmax(130px,.8fr) 1.6fr;gap:12px;border-bottom:1px solid var(--line);padding:8px 0}.row:last-child{border:0}.label{color:var(--muted)}.value{word-break:break-all;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}.pill{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700}.pill.ok{background:color-mix(in srgb,var(--ok) 15%,transparent);color:var(--ok)}.pill.bad{background:color-mix(in srgb,var(--bad) 15%,transparent);color:var(--bad)}button,.button{border:0;border-radius:11px;padding:11px 14px;background:var(--accent);color:white;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px}button.secondary{background:var(--code);color:var(--text);border:1px solid var(--line)}button.danger{background:var(--bad)}button:disabled{opacity:.45;cursor:not-allowed}.actions{display:flex;flex-wrap:wrap;gap:9px}.field{display:grid;gap:6px;margin:10px 0}.field label{font-size:13px;font-weight:700}.field input,.field select{width:100%;padding:10px 11px;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--text)}.settings-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.progress{height:11px;border-radius:999px;background:var(--code);overflow:hidden;margin:12px 0}.bar{height:100%;width:0;background:var(--accent);transition:width .2s}.note{font-size:13px;color:var(--muted);line-height:1.65}.warning{border-left:4px solid var(--warn);padding:10px 12px;background:color-mix(in srgb,var(--warn) 9%,transparent);border-radius:8px}.output{background:#0d1117;color:#d7e0ea;border-radius:12px;padding:12px;min-height:90px;max-height:360px;overflow:auto;white-space:pre-wrap;font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace}.toast{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom));transform:translate(-50%,20px);opacity:0;background:#111827;color:#fff;padding:10px 15px;border-radius:999px;transition:.2s;z-index:20}.toast.show{opacity:1;transform:translate(-50%,0)}input[type=file]{max-width:100%}@media(max-width:780px){.card,.third{grid-column:1/-1}.settings-grid{grid-template-columns:1fr 1fr}.row{grid-template-columns:1fr;gap:3px}header{display:block}.actions button{flex:1 1 140px}}@media(max-width:480px){main{width:min(100% - 18px,1100px)}.card{padding:15px;border-radius:15px}.settings-grid{grid-template-columns:1fr}.actions{display:grid;grid-template-columns:1fr}.actions button{width:100%}}
</style>
</head>
<body><main>
<header><div><h1>LFM セットアップ</h1><span class="version">v<?= htmlspecialchars(LFM_PACKAGE_VERSION) ?> / <?= htmlspecialchars(LFM_BUILD_ID) ?></span><p class="lead">LFM2.5-1.2B-JP-202606を共有サーバーで管理します。</p></div><div class="actions"><a class="button" href="chat.html">チャットを開く</a><button class="secondary" id="refresh">再読込</button></div></header>
<div class="grid">
<section class="card wide"><h2>現在の状態</h2><div id="statusRows" class="rows"></div></section>
<section class="card"><h2>1. 初期化</h2><p class="note">秘密情報を <code>.lfm-runtime/.env</code> に作成し、公開アクセスを拒否します。</p><div class="actions"><button data-action="init">ランタイムを初期化</button></div></section>
<section class="card"><h2>2. llama.cpp</h2><p class="note">共有サーバーではビルド環境がない場合が多いため、同梱のGitHub Actionsで作ったポータブルZIPのアップロードを推奨します。</p><form id="bundleForm"><div class="field"><label>llama-cli またはポータブルZIP</label><input type="file" name="bundle" required accept=".zip,application/zip,application/octet-stream"></div><div class="actions"><button type="submit">アップロードして設置</button><button type="button" class="secondary" data-action="test_binary">起動テスト</button></div></form><h3>サーバー内ビルド（任意）</h3><div class="actions"><button class="secondary" data-action="start_source_build">ソースビルド開始</button><button class="secondary" data-action="build_status">ビルドログ</button></div></section>
<section class="card"><h2>3. モデル</h2><p class="note">公式Q4_K_M（約731MB）を8MBずつ取得するため、PHPの1リクエスト制限を回避します。</p><div class="progress"><div id="modelBar" class="bar"></div></div><div id="modelProgress" class="note">未確認</div><div class="actions"><button id="downloadModel">モデルをダウンロード</button><button class="secondary" data-action="model_info">容量を確認</button><button class="secondary" data-action="test_inference">推論テスト</button></div></section>
<section class="card wide"><h2>公開チャット設定</h2><form id="settingsForm"><div class="settings-grid"><div class="field"><label>公開チャット</label><select name="public_chat"><option value="true">有効</option><option value="false">無効</option></select></div><div class="field"><label>10分間等の回数</label><input name="rate_requests" type="number" min="1" max="1000" value="6"></div><div class="field"><label>制限時間（秒）</label><input name="rate_window" type="number" min="10" max="86400" value="600"></div><div class="field"><label>入力文字数上限</label><input name="max_prompt_chars" type="number" min="256" max="20000" value="4000"></div><div class="field"><label>最大出力トークン</label><input name="max_output_tokens" type="number" min="16" max="1024" value="256"></div><div class="field"><label>推論タイムアウト（秒）</label><input name="timeout_seconds" type="number" min="10" max="300" value="150"></div><div class="field"><label>CPUスレッド</label><input name="threads" type="number" min="1" max="8" value="1"></div><div class="field"><label>コンテキスト</label><input name="context" type="number" min="512" max="8192" value="2048"></div><div class="field"><label>許可Origin（空欄＝同一ドメイン）</label><input name="allowed_origin" type="url" placeholder="https://example.com"></div></div><div class="actions"><button type="submit">設定を保存</button></div></form></section>
<section class="card"><h2>削除・整理</h2><p class="warning note">削除操作は元に戻せません。モデル削除とキャッシュ削除は、llama.cpp・.envを残します。</p><div class="actions"><button class="danger" data-confirm-action="delete_model" data-confirm="DELETE MODEL">モデルを削除</button><button class="secondary" data-confirm-action="delete_cache" data-confirm="DELETE CACHE">キャッシュを削除</button><button class="danger" data-confirm-action="delete_runtime" data-confirm="DELETE ALL RUNTIME">ランタイム全削除</button></div></section>
<section class="card"><h2>キー</h2><p class="note">APIキーはHTMLに埋め込まれません。公開チャットはキーなし、外部API呼び出しだけ <code>X-API-Key</code> を使えます。</p><div class="actions"><button class="danger" id="regenerateKeys">セットアップ/APIキーを再生成</button></div></section>
<section class="card wide"><h2>操作結果・診断</h2><pre id="output" class="output">準備完了</pre></section>
</div></main><div id="toast" class="toast"></div>
<script>
'use strict';
const KEY=<?= json_encode($keyForJs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const endpoint=location.pathname;
const $=id=>document.getElementById(id);
let busy=false;
function toast(text){const t=$('toast');t.textContent=text;t.classList.add('show');clearTimeout(toast.timer);toast.timer=setTimeout(()=>t.classList.remove('show'),2100)}
function show(data){$('output').textContent=typeof data==='string'?data:JSON.stringify(data,null,2);$('output').scrollTop=$('output').scrollHeight}
async function request(action,form=null){const url=`${endpoint}?key=${encodeURIComponent(KEY)}&action=${encodeURIComponent(action)}&v=${Date.now()}`;const options={method:form?'POST':'GET',cache:'no-store'};if(form)options.body=form;const response=await fetch(url,options);const raw=await response.text();let data;try{data=JSON.parse(raw)}catch{throw new Error(`JSONではない応答: ${raw.slice(0,500)}`)}if(!response.ok||!data.ok)throw new Error(data.error||`HTTP ${response.status}`);return data}
function badge(ok){return `<span class="pill ${ok?'ok':'bad'}">${ok?'OK':'NG'}</span>`}
function esc(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
function renderStatus(s){const rows=[['パッケージ',`${esc(s.version)} / ${esc(s.build_id)}`],['実行ファイル',esc(s.executing_file)],['SHA-256',esc(s.file_sha256)],['設置ディレクトリ',esc(s.base_dir)],['インストールディレクトリ',esc(s.runtime_dir)],['.env',`${badge(s.env_exists)} ${esc(s.env_file)}`],['llama-cli',`${badge(s.binary_installed)} ${esc(s.binary_path)} (${esc(s.binary_size)})`],['モデル',`${badge(s.model_installed)} ${esc(s.model_path)} (${esc(s.model_size)})`],['公開チャット',badge(s.public_chat)],['PHP実行方式',esc(s.process_backend)],['PHP / CPU',`${esc(s.php)} / ${esc(s.architecture)}`],['空き容量',esc(s.free_space)],['ランタイム容量',esc(s.runtime_size)],['APIキー',esc(s.api_key_masked)],['セットアップキー',esc(s.setup_key_masked)]];$('statusRows').innerHTML=rows.map(([a,b])=>`<div class="row"><div class="label">${a}</div><div class="value">${b}</div></div>`).join('');if(s.binary_version)show(`llama-cli:\n${s.binary_version}\n\n依存関係:\n${s.dependency_report||'lddなし'}`);const f=$('settingsForm');f.public_chat.value=s.public_chat?'true':'false';f.rate_requests.value=s.rate_limit.requests;f.rate_window.value=s.rate_limit.window;updateModelProgress(s.model_partial_size||0,s.model_installed?(s.model_partial_size||1):0,s.model_installed)}
async function refresh(){try{const data=await request('status');renderStatus(data.status);$('settingsForm').api_directory.value=data.status.api_directory||'lfm-api.php';$('settingsForm').system_prompt.value=data.status.system_prompt||''}catch(e){show(e.message);toast('状態取得に失敗しました')}}
async function act(action,form=null){if(busy)return;busy=true;try{show(`${action} を実行中…`);const data=await request(action,form);show(data);toast(data.message||'完了');if(data.status)renderStatus(data.status);else await refresh()}catch(e){show(e.stack||e.message);toast('エラーが発生しました')}finally{busy=false}}
document.querySelectorAll('[data-action]').forEach(b=>b.addEventListener('click',()=>act(b.dataset.action)));
document.querySelectorAll('[data-confirm-action]').forEach(b=>b.addEventListener('click',()=>{const word=b.dataset.confirm;const typed=prompt(`実行するには「${word}」と入力してください。`);if(typed!==word)return;const f=new FormData();f.set('confirm',typed);act(b.dataset.confirmAction,f)}));
$('refresh').addEventListener('click',refresh);
$('bundleForm').addEventListener('submit',e=>{e.preventDefault();const f=new FormData(e.currentTarget);act('upload_bundle',f)});
(()=>{const f=$('settingsForm'),grid=f.querySelector('.settings-grid');const extra=document.createElement('div');extra.className='field';extra.innerHTML='<label>APIディレクトリ</label><input name="api_directory" value="lfm-api.php" autocomplete="off"><label>システムプロンプト</label><textarea name="system_prompt" rows="5" style="width:100%;padding:10px 11px;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--text)"></textarea>';grid.prepend(extra);})();
(()=>{const f=document.createElement('section');f.className='card wide';f.innerHTML='<h2>AI辞書管理</h2><p class="note">櫻坂関連のテーマを指定すると、AIがJSON形式の用語候補を作成し、重複を除いて辞書へ追加します。保存前に操作結果を確認してください。</p><div class="field"><label>生成テーマ</label><input id="dictionaryTopic" value="櫻坂46の基本用語・活動・楽曲・ファン用語"></div><div class="actions"><button type="button" id="generateDictionary">AIで用語候補を生成して登録</button></div>';document.querySelector('.grid').insertBefore(f,document.querySelector('.grid').lastElementChild);$('generateDictionary').onclick=()=>{const form=new FormData();form.set('topic',$('dictionaryTopic').value);act('generate_dictionary',form)};})();
$('settingsForm').addEventListener('submit',e=>{e.preventDefault();const f=new FormData(e.currentTarget);f.set('public_chat',e.currentTarget.public_chat.value);act('save_settings',f)});
$('regenerateKeys').addEventListener('click',async()=>{const typed=prompt('実行するには「REGENERATE」と入力してください。');if(typed!=='REGENERATE')return;const f=new FormData();f.set('confirm',typed);try{const d=await request('regenerate_keys',f);show(`新しいセットアップキー:\n${d.setup_key}\n\n新しい外部APIキー:\n${d.api_key}\n\nこの画面を閉じる前に保存してください。`);toast('キーを再生成しました')}catch(e){show(e.message)}});
function updateModelProgress(done,total,installed=false){let pct=installed?100:(total?Math.min(100,done/total*100):0);$('modelBar').style.width=pct+'%';$('modelProgress').textContent=installed?'インストール済み':`${(done/1024/1024).toFixed(1)} MB / ${total?(total/1024/1024).toFixed(1)+' MB':'容量取得中'}`}
$('downloadModel').addEventListener('click',async()=>{if(busy)return;busy=true;$('downloadModel').disabled=true;try{let info=await request('model_info');let total=Number(info.total)||0,done=Number(info.downloaded)||0;updateModelProgress(done,total,info.installed);if(info.installed){toast('モデルはインストール済みです');return}while(true){const data=await request('download_model_chunk',new FormData());done=Number(data.downloaded)||done;total=Number(data.total)||total;updateModelProgress(done,total,!!data.done);show(data);if(data.done)break}toast('モデルのインストール完了');await refresh()}catch(e){show(e.stack||e.message);toast('ダウンロードに失敗しました')}finally{busy=false;$('downloadModel').disabled=false}});
refresh();
</script></body></html>
