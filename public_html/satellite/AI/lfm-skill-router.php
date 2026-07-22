<?php
/**
 * Sparse Expert Router
 *
 * A lightweight MoE-style retrieval layer. The router selects a small number
 * of domain experts, while each expert reads only hashed posting shards for
 * the query instead of scanning every JSON record on every request.
 */
declare(strict_types=1);

function api_skill_experts(): array
{
    return array(
        'sakurazaka-members' => array(
            'patterns' => '/メンバー|加入|卒業|現役|誕生日|身長|出身|プロフィール|誰/u',
            'sources' => array('member.json' => 'メンバー', 'member_grad.json' => '卒業メンバー'),
        ),
        'sakurazaka-music' => array(
            'patterns' => '/曲|楽曲|歌|センター|歌詞|作曲|作詞|編曲|MV|シングル|アルバム|リリース|ペンライト|サイリウム/u',
            'sources' => array(
                'sakamichi_sakura_songs.json' => '楽曲', 'sakurazaka46_songs.json' => '楽曲',
                'sakamichi_sakura_mvs.json' => 'MV', 'cyalume.json' => 'ペンライトカラー',
            ),
        ),
        'sakurazaka-live' => array(
            'patterns' => '/ライブ|公演|セトリ|セットリスト|フォーメーション|ポジション|ツアー|会場/u',
            'sources' => array(
                'sakamichi_sakura_lives.json' => 'ライブ', 'sakamichi_sakura_setlists.json' => 'セットリスト',
                'sakamichi_sakura_formations.json' => 'フォーメーション',
            ),
        ),
        'sakurazaka-media' => array(
            'patterns' => '/櫻坂のさ|さくみみ|ブログ|ニュース|予定|出演|スケジュール|最新|今日|明日/u',
            'sources' => array(
                'sakurazaka46_no_sa.json' => '櫻坂のさ', 'sakumimi_data.json' => 'さくみみ',
                'blogs.json' => 'ブログ', 'sakurazaka_news.json' => 'ニュース', 'schedule.json' => 'スケジュール',
            ),
        ),
        'sakurazaka-dictionary' => array(
            'patterns' => '/用語|意味|とは|ファン|Buddies|欅坂|BACKS|三期生|二期生|一期生/u',
            'sources' => array('sakurazaka_terms.json' => '櫻坂用語辞書'),
        ),
    );
}

function api_skill_plan(string $query, ?string $dataDir = null): array
{
    $query = trim($query);
    $dataDir = $dataDir ?: dirname(__DIR__) . '/data';
    $domain = preg_match('/櫻坂|欅坂|サクラミーツ|さくみみ|Buddies|BACKS|三期生|二期生|一期生/u', $query) === 1;
    $compact = preg_replace('/[\s　]+/u', '', $query) ?? $query;
    $memberMatch = false;
    if (!$domain && preg_match('/誕生日|身長|出身|プロフィール|メンバー|誰/u', $query)) {
        foreach (api_skill_member_names($dataDir) as $name) {
            if ($name !== '' && mb_strpos($compact, $name, 0, 'UTF-8') !== false) {
                $memberMatch = true;
                $domain = true;
                break;
            }
        }
    }

    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $plan = array(
        'requires_skill' => false, 'skills' => array(), 'intent' => 'conversation',
        'sources' => array(), 'sort' => 'relevance', 'date_filter' => '',
        'description' => '', 'reference_date' => $today->format('Y-m-d'),
    );

    if (preg_match('/今日(?:の)?(?:予定|スケジュール|出演)|本日(?:の)?(?:予定|出演)/u', $query) || ($domain && preg_match('/今日|本日/u', $query))) {
        return array_merge($plan, array(
            'requires_skill' => true, 'skills' => array('sakurazaka-media'), 'intent' => 'schedule_today',
            'sources' => array('schedule.json'), 'sort' => 'date_asc', 'date_filter' => 'today',
            'description' => '今日（' . $today->format('Y年n月j日') . '）のスケジュールを日付で絞り込み',
        ));
    }
    if (preg_match('/明日(?:の)?(?:予定|スケジュール|出演)/u', $query)) {
        return array_merge($plan, array(
            'requires_skill' => true, 'skills' => array('sakurazaka-media'), 'intent' => 'schedule_tomorrow',
            'sources' => array('schedule.json'), 'sort' => 'date_asc', 'date_filter' => 'tomorrow',
            'description' => '明日（' . $today->modify('+1 day')->format('Y年n月j日') . '）のスケジュールを日付で絞り込み',
        ));
    }
    if (preg_match('/予定|スケジュール|出演情報/u', $query)) {
        return array_merge($plan, array(
            'requires_skill' => true, 'skills' => array('sakurazaka-media'), 'intent' => 'schedule_upcoming',
            'sources' => array('schedule.json'), 'sort' => 'date_asc', 'date_filter' => 'upcoming',
            'description' => '今後のスケジュールを日付の近い順に検索',
        ));
    }
    if (preg_match('/さくみみ/u', $query)) {
        return array_merge($plan, array(
            'requires_skill' => true, 'skills' => array('sakurazaka-media'), 'intent' => 'sakumimi_latest',
            'sources' => array('sakumimi_data.json'), 'sort' => 'latest', 'date_filter' => 'latest',
            'description' => 'さくみみの配信情報を新しい順に検索',
        ));
    }
    if (preg_match('/ニュース|最新情報|最近(?:の)?(?:話題|情報|記事)/u', $query) || ($domain && preg_match('/最新|最近|近況|動向/u', $query))) {
        return array_merge($plan, array(
            'requires_skill' => true, 'skills' => array('sakurazaka-media'), 'intent' => 'news_latest',
            'sources' => array('sakurazaka_news.json', 'blogs.json'), 'sort' => 'latest', 'date_filter' => 'latest',
            'description' => '櫻坂46の公式ニュースとメンバーブログをそれぞれ新しい順に検索',
        ));
    }
    if (preg_match('/ブログ|最近(?:の)?記事/u', $query)) {
        return array_merge($plan, array(
            'requires_skill' => true, 'skills' => array('sakurazaka-media'), 'intent' => 'blog_latest',
            'sources' => array('blogs.json'), 'sort' => 'latest', 'date_filter' => 'latest',
            'description' => 'メンバーブログを投稿日が新しい順に並べて検索',
        ));
    }

    if (!$domain && !$memberMatch) return $plan;
    $scores = array();
    foreach (api_skill_experts() as $id => $expert) {
        $scores[$id] = preg_match_all($expert['patterns'], $query) ?: 0;
    }
    if ($memberMatch) $scores['sakurazaka-members'] += 4;
    arsort($scores);
    foreach ($scores as $id => $score) {
        if ($score <= 0) continue;
        $plan['skills'][] = $id;
        if (count($plan['skills']) >= 2) break;
    }
    if (!$plan['skills'] && preg_match('/誰|何|いつ|どこ|教えて|調べ|検索|とは|\?/u', $query)) {
        $plan['skills'][] = 'sakurazaka-dictionary';
    }
    if ($plan['skills']) {
        $plan['requires_skill'] = true;
        $plan['intent'] = 'knowledge_search';
        $plan['description'] = '関連する櫻坂46データを絞り込んで検索';
    }
    return $plan;
}

function api_skill_normalize(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('~https?://\S+~u', ' ', $text) ?? $text;
    return trim(preg_replace('/[^\p{L}\p{N}ー]+/u', ' ', $text) ?? $text);
}

function api_skill_tokens(string $text, int $limit = 160): array
{
    $normalized = api_skill_normalize(lfm_substr($text, 0, 5000));
    if ($normalized === '') return array();
    $tokens = array();
    foreach (preg_split('/\s+/u', $normalized) ?: array() as $part) {
        $length = mb_strlen($part, 'UTF-8');
        if ($length < 2) continue;
        if ($length <= 24) $tokens[$part] = true;
        $window = min($length, 900);
        foreach (array(2, 3) as $size) {
            if ($length < $size) continue;
            for ($i = 0; $i <= $window - $size; $i++) {
                $tokens[mb_substr($part, $i, $size, 'UTF-8')] = true;
                if (count($tokens) >= $limit) break 3;
            }
        }
    }
    return array_map('strval', array_keys($tokens));
}

function api_skill_member_names(string $dataDir): array
{
    $cacheFile = LFM_SKILL_INDEX_DIR . '/member-names.json';
    $sources = array($dataDir . '/member.json', $dataDir . '/member_grad.json');
    $signature = implode(':', array_map(static fn(string $path): string => is_file($path) ? (string) filemtime($path) : '0', $sources));
    $cached = lfm_read_json($cacheFile);
    if (($cached['signature'] ?? '') === $signature && is_array($cached['names'] ?? null)) return $cached['names'];
    $names = array();
    foreach ($sources as $path) {
        $items = json_decode((string) @file_get_contents($path), true);
        if (!is_array($items)) continue;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            foreach (array('name', 'name_kana', 'kana', 'english_name') as $field) {
                $name = preg_replace('/[\s　]+/u', '', trim((string) ($item[$field] ?? ''))) ?? '';
                if ($name !== '') $names[$name] = true;
            }
        }
    }
    lfm_ensure_dir(LFM_SKILL_INDEX_DIR);
    lfm_write_json($cacheFile, array('signature' => $signature, 'names' => array_keys($names)));
    return array_keys($names);
}

function api_skill_route(string $query, string $dataDir): array
{
    return api_skill_plan($query, $dataDir)['skills'];
}

function api_skill_bucket(string $token): int
{
    return (int) (sprintf('%u', crc32($token)) % 32);
}

function api_skill_source_cache(string $path): string
{
    return LFM_SKILL_INDEX_DIR . '/' . substr(hash('sha256', $path), 0, 20);
}

function api_skill_index_valid(string $path, string $cacheDir): bool
{
    $meta = lfm_read_json($cacheDir . '/meta.json');
    return ($meta['version'] ?? 0) === 1
        && (int) ($meta['mtime'] ?? -1) === (int) @filemtime($path)
        && (int) ($meta['size'] ?? -1) === (int) @filesize($path)
        && is_file($cacheDir . '/documents.jsonl');
}

function api_skill_build_index(string $path): bool
{
    if (!is_file($path)) return false;
    lfm_prepare_runtime();
    lfm_ensure_dir(LFM_SKILL_INDEX_DIR);
    $cacheDir = api_skill_source_cache($path);
    lfm_ensure_dir($cacheDir);
    if (api_skill_index_valid($path, $cacheDir)) return true;

    $lock = @fopen($cacheDir . '/build.lock', 'c');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        return false;
    }
    if (api_skill_index_valid($path, $cacheDir)) {
        @flock($lock, LOCK_UN); fclose($lock); return true;
    }
    $items = json_decode((string) @file_get_contents($path), true);
    if (!is_array($items)) {
        @flock($lock, LOCK_UN); fclose($lock); return false;
    }

    $documentsTmp = $cacheDir . '/documents.jsonl.tmp';
    $handle = @fopen($documentsTmp, 'wb');
    if (!is_resource($handle)) {
        @flock($lock, LOCK_UN); fclose($lock); return false;
    }
    $offsets = array();
    $buckets = array_fill(0, 32, array());
    $documentId = 0;
    foreach ($items as $key => $item) {
        $text = is_string($item) ? $item : (string) json_encode(array('key' => $key, 'value' => $item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($text === '') continue;
        $line = json_encode(array('text' => $text), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $offset = ftell($handle);
        if ($offset === false || fwrite($handle, $line) === false) continue;
        $offsets[$documentId] = array((int) $offset, strlen($line));
        foreach (api_skill_tokens($text, 320) as $token) {
            $bucket = api_skill_bucket($token);
            if (!isset($buckets[$bucket][$token])) $buckets[$bucket][$token] = array();
            if (count($buckets[$bucket][$token]) < 1200) $buckets[$bucket][$token][] = $documentId;
        }
        $documentId++;
    }
    fclose($handle);
    @rename($documentsTmp, $cacheDir . '/documents.jsonl');
    foreach ($buckets as $number => $postings) {
        lfm_write_json($cacheDir . '/bucket-' . $number . '.json', $postings);
    }
    lfm_write_json($cacheDir . '/meta.json', array(
        'version' => 1, 'mtime' => (int) @filemtime($path), 'size' => (int) @filesize($path),
        'documents' => $documentId, 'offsets' => $offsets, 'built_at' => gmdate('c'),
    ));
    @flock($lock, LOCK_UN);
    fclose($lock);
    return true;
}

function api_skill_search_source(string $path, string $query, int $limit = 8): array
{
    if (!api_skill_build_index($path)) return array();
    $cacheDir = api_skill_source_cache($path);
    $meta = lfm_read_json($cacheDir . '/meta.json');
    $queryTokens = api_skill_tokens($query, 100);
    if (!$queryTokens) return array();
    $tokensByBucket = array();
    foreach ($queryTokens as $token) $tokensByBucket[api_skill_bucket($token)][] = $token;
    $scores = array();
    foreach ($tokensByBucket as $bucket => $tokens) {
        $postings = lfm_read_json($cacheDir . '/bucket-' . $bucket . '.json');
        foreach ($tokens as $token) {
            $weight = mb_strlen($token, 'UTF-8') >= 3 ? 3 : 1;
            foreach (($postings[$token] ?? array()) as $documentId) {
                $scores[(int) $documentId] = ($scores[(int) $documentId] ?? 0) + $weight;
            }
        }
    }
    arsort($scores);
    $handle = @fopen($cacheDir . '/documents.jsonl', 'rb');
    if (!is_resource($handle)) return array();
    $hits = array();
    foreach (array_slice($scores, 0, $limit, true) as $documentId => $score) {
        $position = $meta['offsets'][(string) $documentId] ?? $meta['offsets'][$documentId] ?? null;
        if (!is_array($position) || fseek($handle, (int) $position[0]) !== 0) continue;
        $decoded = json_decode((string) fread($handle, (int) $position[1]), true);
        if (!is_array($decoded) || !isset($decoded['text'])) continue;
        $hits[] = array('score' => (int) $score, 'text' => (string) $decoded['text']);
    }
    fclose($handle);
    return $hits;
}

function api_skill_item_sort_key(array $value, string $file): int
{
    foreach (array('date', 'published_at', 'start_date', 'datetime') as $field) {
        $raw = trim((string) ($value[$field] ?? ''));
        if ($raw === '') continue;
        $timestamp = strtotime(str_replace('/', '-', $raw));
        if ($timestamp !== false) return $timestamp;
    }
    if ($file === 'sakurazaka_news.json') {
        $link = (string) ($value['link'] ?? $value['url'] ?? '');
        if (preg_match('/detail\/[A-Z]?(\d+)/i', $link, $match)) return (int) $match[1];
    }
    return 0;
}

function api_skill_temporal_hits(string $path, string $file, string $label, array $plan, int $limit = 8): array
{
    $items = json_decode((string) @file_get_contents($path), true);
    if (!is_array($items)) return array();
    $timezone = new DateTimeZone('Asia/Tokyo');
    $today = new DateTimeImmutable('today', $timezone);
    $target = $plan['date_filter'] === 'tomorrow' ? $today->modify('+1 day') : $today;
    $targetDate = $target->format('Y-m-d');
    $hits = array();
    foreach ($items as $key => $item) {
        if (!is_array($item)) continue;
        $date = str_replace('/', '-', trim((string) ($item['date'] ?? '')));
        if ($plan['date_filter'] === 'today' || $plan['date_filter'] === 'tomorrow') {
            if ($date !== $targetDate) continue;
        } elseif ($plan['date_filter'] === 'upcoming') {
            if ($date === '' || $date < $today->format('Y-m-d')) continue;
        }
        $sortKey = api_skill_item_sort_key($item, $file);
        $time = preg_replace('/\D+/', '', (string) ($item['time'] ?? '')) ?? '';
        if (strlen($time) >= 3 && $sortKey > 100000000) {
            $clock = str_pad(substr($time, 0, 4), 4, '0', STR_PAD_LEFT);
            $sortKey += min(86399, ((int) substr($clock, 0, 2) * 3600) + ((int) substr($clock, 2, 2) * 60));
        }
        $text = (string) json_encode(array('key' => $key, 'value' => $item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hits[] = array('score' => 100, 'sort_key' => $sortKey, 'label' => $label, 'file' => $file, 'text' => $text);
    }
    $ascending = ($plan['sort'] ?? '') === 'date_asc';
    usort($hits, static function (array $a, array $b) use ($ascending): int {
        return $ascending ? ($a['sort_key'] <=> $b['sort_key']) : ($b['sort_key'] <=> $a['sort_key']);
    });
    return array_slice($hits, 0, $limit);
}

function api_learning_context(string $query): string
{
    $entries = lfm_read_json(LFM_LEARNING_FILE);
    if (!$entries) return '';
    $normalized = mb_strtolower($query, 'UTF-8');
    $matches = array();
    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        $term = trim((string) ($entry['term'] ?? ''));
        $definition = trim((string) ($entry['definition'] ?? ''));
        if ($term === '' || $definition === '') continue;
        $needles = array_merge(array($term), is_array($entry['aliases'] ?? null) ? $entry['aliases'] : array());
        foreach ($needles as $needle) {
            $needle = trim((string) $needle);
            if ($needle !== '' && mb_strpos($normalized, mb_strtolower($needle, 'UTF-8'), 0, 'UTF-8') !== false) {
                $matches[] = $term . ': ' . lfm_substr($definition, 0, 320);
                break;
            }
        }
        if (count($matches) >= 3) break;
    }
    return $matches ? "\n\n【基本情報辞書】\n" . implode("\n", $matches) : '';
}

function api_skill_result(array $value, string $label): array
{
    $url = (string) ($value['link'] ?? $value['url'] ?? $value['official_url'] ?? ($value['links'][0] ?? ''));
    $image = (string) ($value['thumb'] ?? $value['image'] ?? $value['image_url'] ?? '');
    if ($image === '' && isset($value['images'][0])) $image = (string) $value['images'][0];
    return array(
        'type' => $label,
        'title' => (string) ($value['title'] ?? $value['name'] ?? $value['song'] ?? $label),
        'url' => $url, 'image' => $image,
        'author' => (string) ($value['member'] ?? $value['author'] ?? ''),
        'date' => (string) ($value['date'] ?? $value['published_at'] ?? ''),
    );
}

function api_skill_context_text(array $value, string $label): string
{
    $parts = array();
    $date = trim((string) ($value['date'] ?? $value['published_at'] ?? $value['start_date'] ?? ''));
    $time = trim((string) ($value['time'] ?? ''));
    $title = trim((string) ($value['title'] ?? $value['name'] ?? $value['song'] ?? ''));
    $author = trim((string) ($value['member'] ?? $value['author'] ?? ''));
    $category = trim((string) ($value['category'] ?? ''));
    $content = trim((string) ($value['content'] ?? $value['description'] ?? ''));
    $members = is_array($value['members'] ?? null) ? implode('、', array_map('strval', $value['members'])) : '';
    $episode = trim((string) ($value['episode'] ?? ''));
    if ($date !== '') $parts[] = '日付=' . $date;
    if ($time !== '') $parts[] = '時刻=' . $time;
    if ($category !== '') $parts[] = '分類=' . $category;
    if ($author !== '') $parts[] = 'メンバー=' . $author;
    if ($members !== '') $parts[] = '出演=' . $members;
    if ($episode !== '') $parts[] = '回=' . $episode;
    if ($title !== '') $parts[] = '題名=' . lfm_substr($title, 0, 220);
    if ($content !== '') $parts[] = '概要=' . lfm_substr(preg_replace('/\s+/u', ' ', $content) ?? $content, 0, 320);
    return $label . ': ' . implode(' | ', $parts);
}

function api_sakurazaka_skill(string $query, ?array $requestedPlan = null): array
{
    $dataDir = dirname(__DIR__) . '/data';
    // Recompute the plan server-side. The client only grants consent and must
    // not be able to select arbitrary experts or source files.
    $plan = api_skill_plan($query, $dataDir);
    $selected = $plan['skills'];
    if (!$selected) return array('skills' => array(), 'context' => '', 'results' => array());
    $experts = api_skill_experts();
    $hits = array();
    foreach ($selected as $expertId) {
        $expertSources = $experts[$expertId]['sources'];
        if (!empty($plan['sources'])) {
            $orderedSources = array();
            foreach ($plan['sources'] as $plannedFile) {
                if (isset($expertSources[$plannedFile])) $orderedSources[$plannedFile] = $expertSources[$plannedFile];
            }
            $expertSources = $orderedSources;
        }
        foreach ($expertSources as $file => $label) {
            if (!empty($plan['sources']) && !in_array($file, $plan['sources'], true)) continue;
            $temporal = in_array($plan['intent'], array('schedule_today', 'schedule_tomorrow', 'schedule_upcoming', 'news_latest', 'blog_latest', 'sakumimi_latest'), true);
            $sourceLimit = count($plan['sources'] ?? array()) > 1 ? 3 : 10;
            $sourceHits = $temporal
                ? api_skill_temporal_hits($dataDir . '/' . $file, $file, $label, $plan, $sourceLimit)
                : api_skill_search_source($dataDir . '/' . $file, $query, 7);
            foreach ($sourceHits as $hit) {
                $hit['label'] = $label;
                $hit['file'] = $file;
                $hits[] = $hit;
            }
        }
    }
    if (($plan['sort'] ?? '') === 'relevance') {
        usort($hits, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    }
    $context = array();
    $results = array();
    $used = 0;
    foreach ($hits as $hit) {
        $decoded = json_decode($hit['text'], true);
        $value = is_array($decoded) && isset($decoded['value']) && is_array($decoded['value']) ? $decoded['value'] : $decoded;
        if (!is_array($value)) continue;
        $piece = api_skill_context_text($value, $hit['label']);
        if ($used + lfm_strlen($piece) > 3600) continue;
        $context[] = $piece;
        $used += lfm_strlen($piece);
        $result = api_skill_result($value, $hit['label']);
        if ($result['url'] !== '' || $result['image'] !== '') $results[] = $result;
        if (count($context) >= 6) break;
    }
    return array(
        'skills' => $selected,
        'context' => $context
            ? "検索計画: " . $plan['description'] . "\n検索結果（指定済みの順序）:\n" . implode("\n", $context)
            : "検索計画: " . $plan['description'] . "\n検索結果: 0件。該当データなし。推測で予定や記事を補わないこと。",
        'results' => array_slice($results, 0, 6),
        'plan' => $plan,
    );
}
