<?php
declare(strict_types=1);
@ini_set('display_errors', '0');
require_once __DIR__ . '/lfm-common.php';

$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
lfm_require_buddies_admin($isPost, 'Buddies AI Learning');
lfm_prepare_runtime();

function learning_entries(): array
{
    $entries = lfm_read_json(LFM_LEARNING_FILE);
    return array_values(array_filter($entries, 'is_array'));
}

function learning_reply(array $data, int $status = 200): void
{
    lfm_json_response($data, $status);
}

if ($isPost) {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) learning_reply(array('ok' => false, 'error' => 'invalid JSON'), 400);
    $action = (string) ($input['action'] ?? '');
    $entries = learning_entries();
    if ($action === 'list') learning_reply(array('ok' => true, 'entries' => $entries));
    if ($action === 'fetch_blog') {
        $url = trim((string) ($input['url'] ?? ''));
        try {
            $article = lfm_fetch_sakurazaka_blog($url, 14000);
            // The body is returned for one analysis request only. It is not
            // written to the learning dictionary or any server-side cache.
            learning_reply(array('ok' => true, 'title' => $article['title'], 'author' => $article['author'], 'url' => $article['url'], 'body' => $article['body']));
        } catch (InvalidArgumentException $error) {
            learning_reply(array('ok' => false, 'error' => $error->getMessage()), 422);
        } catch (Throwable $error) {
            error_log('Buddies AI learning blog fetch: ' . $error->getMessage());
            learning_reply(array('ok' => false, 'error' => 'ブログ本文を取得できませんでした。'), 502);
        }
    }
    if ($action === 'save') {
        $term = trim((string) ($input['term'] ?? ''));
        $definition = trim((string) ($input['definition'] ?? ''));
        $aliases = array_values(array_unique(array_filter(array_map('trim', is_array($input['aliases'] ?? null) ? $input['aliases'] : array()))));
        if ($term === '' || $definition === '') learning_reply(array('ok' => false, 'error' => '語句と定義は必須です。'), 422);
        if (lfm_strlen($term) > 80 || lfm_strlen($definition) > 1000) learning_reply(array('ok' => false, 'error' => '入力が長すぎます。'), 422);
        $now = gmdate('c'); $updated = false;
        foreach ($entries as &$entry) {
            if ((string) ($entry['term'] ?? '') !== $term) continue;
            $entry = array('term' => $term, 'aliases' => $aliases, 'definition' => $definition, 'updated_at' => $now);
            $updated = true; break;
        }
        unset($entry);
        if (!$updated) $entries[] = array('term' => $term, 'aliases' => $aliases, 'definition' => $definition, 'updated_at' => $now);
        usort($entries, static fn(array $a, array $b): int => strcmp((string) ($a['term'] ?? ''), (string) ($b['term'] ?? '')));
        lfm_write_json(LFM_LEARNING_FILE, $entries);
        learning_reply(array('ok' => true, 'entries' => $entries));
    }
    if ($action === 'delete') {
        $term = trim((string) ($input['term'] ?? ''));
        $entries = array_values(array_filter($entries, static fn(array $entry): bool => (string) ($entry['term'] ?? '') !== $term));
        lfm_write_json(LFM_LEARNING_FILE, $entries);
        learning_reply(array('ok' => true, 'entries' => $entries));
    }
    learning_reply(array('ok' => false, 'error' => 'unknown action'), 404);
}
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Buddies AI Learning</title><style>
:root{--pink:#d8799b;--ink:#242229;--muted:#77717a;--line:#eee3e8;--soft:#faf2f5;font-family:-apple-system,BlinkMacSystemFont,"Noto Sans JP",sans-serif}*{box-sizing:border-box}body{margin:0;background:#fbfafb;color:var(--ink)}main{width:min(920px,calc(100% - 28px));margin:auto;padding:34px 0 80px}h1{margin:0 0 6px}.lead{margin:0 0 24px;color:var(--muted)}.panel{padding:20px;border:1px solid var(--line);border-radius:18px;background:#fff}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:grid;gap:6px}.field.full{grid-column:1/-1}label{font-size:12px;font-weight:800}input,textarea{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:11px;background:#fff;color:var(--ink);font:inherit}textarea{min-height:110px;resize:vertical}.actions{display:flex;justify-content:flex-end;gap:8px;margin-top:15px}button{border:0;border-radius:10px;padding:10px 13px;font:inherit;font-weight:750;cursor:pointer}.secondary{background:var(--soft)}.primary{background:var(--pink);color:#fff}.status{min-height:20px;margin-top:10px;color:var(--muted);font-size:12px}.entries{display:grid;gap:10px;margin-top:22px}.entry{padding:15px;border:1px solid var(--line);border-radius:14px;background:#fff}.entry-head{display:flex;align-items:center;gap:10px}.entry-head strong{flex:1}.entry p{margin:8px 0 0;white-space:pre-wrap;line-height:1.65}.aliases{color:var(--muted);font-size:11px}.delete{background:#fff0f2;color:#a72e43}@media(max-width:620px){main{width:min(100% - 18px,920px);padding-top:20px}.panel{padding:15px}.grid{grid-template-columns:1fr}.field.full{grid-column:auto}.actions{display:grid;grid-template-columns:1fr 1fr}.actions button{width:100%}}
</style></head><body><main><h1>基本情報辞書</h1><p class="lead">語句をAIで整理し、必要な質問だけに参照される軽量な知識として登録します。ブログ本文は保存せず、抽出した文体特徴だけを登録できます。</p><section class="panel"><div class="grid"><div class="field full"><label for="blogUrl">公式ブログURL（文体学習）</label><input id="blogUrl" type="url" inputmode="url" placeholder="https://sakurazaka46.com/s/s46/diary/detail/..."></div><div class="field"><label for="term">語句</label><input id="term" maxlength="80" placeholder="例：田村保乃の文体"></div><div class="field"><label for="aliases">別名（カンマ区切り）</label><input id="aliases" placeholder="例：田村保乃, ブログ文体"></div><div class="field full"><label for="notes">学習素材</label><textarea id="notes" placeholder="正しい情報、意味、注意点を入力"></textarea></div><div class="field full"><label for="definition">AIが整理した定義・文体特徴</label><textarea id="definition" maxlength="1000"></textarea></div></div><div class="actions"><button id="blogStyle" class="secondary" type="button">ブログから文体を分析</button><button id="draft" class="secondary" type="button">AIで整理</button><button id="save" class="primary" type="button">辞書へ登録</button></div><div id="status" class="status"></div></section><section id="entries" class="entries"></section></main><script>
'use strict';const $=id=>document.getElementById(id);const esc=value=>String(value||'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));async function learning(action,data={}){const response=await fetch('lfm_learning.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,...data})});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'処理できませんでした');return result}async function askAI(prompt,maxTokens=256){const response=await fetch('lfm-api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({messages:[{role:'user',content:prompt}],use_skills:false,max_tokens:maxTokens,temperature:0.1})});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'AIで整理できませんでした');return result.text.trim()}function render(entries){$('entries').innerHTML=entries.map(entry=>`<article class="entry"><div class="entry-head"><strong>${esc(entry.term)}</strong><button class="delete" data-term="${esc(entry.term)}" type="button">削除</button></div>${entry.aliases?.length?`<div class="aliases">別名: ${esc(entry.aliases.join('、'))}</div>`:''}<p>${esc(entry.definition)}</p></article>`).join('');document.querySelectorAll('[data-term]').forEach(button=>button.onclick=async()=>{if(!confirm(`${button.dataset.term}を削除しますか？`))return;try{render((await learning('delete',{term:button.dataset.term})).entries)}catch(error){$('status').textContent=error.message}})}async function reload(){try{render((await learning('list')).entries)}catch(error){$('status').textContent=error.message}}$('blogStyle').onclick=async()=>{const url=$('blogUrl').value.trim();if(!url)return $('status').textContent='公式ブログURLを入力してください。';$('status').textContent='ブログ本文を取得中…';let transientBody='';try{const article=await learning('fetch_blog',{url});transientBody=article.body;$('status').textContent='本文から文体だけを分析中…';const prompt=`次のブログ本文から、再利用可能な日本語の文体特徴だけを抽出してください。内容や事実は学習対象にせず、本文の引用・固有のエピソード・URLは出力しないでください。語尾、敬語、文の長さ、改行、呼びかけ、強調、記号や顔文字、話題のつなぎ方、文章のリズムを700文字以内の箇条書きで説明してください。\n\n執筆者: ${article.author||'不明'}\n記事タイトル: ${article.title||'不明'}\n<BLOG_BODY>\n${transientBody}\n</BLOG_BODY>`;const style=await askAI(prompt,512);$('definition').value=style;if(!$('term').value.trim())$('term').value='ブログ文体: '+String(article.author||article.title||'公式ブログ').replace(/\s*公式ブログ.*$/,'').slice(0,60);if(!$('aliases').value.trim()&&article.author)$('aliases').value=article.author;$('notes').value='';$('status').textContent='文体特徴だけを抽出しました。内容を確認して登録してください。'}catch(error){$('status').textContent=error.message}finally{transientBody=''}};$('draft').onclick=async()=>{const term=$('term').value.trim(),notes=$('notes').value.trim();if(!term||!notes)return $('status').textContent='語句と学習素材を入力してください。';$('status').textContent='AIが辞書向けに整理中…';try{const prompt=`基本情報辞書へ登録するため、次の情報を正確で簡潔な日本語の定義に整理してください。推測や前置きは不要です。定義本文だけを400文字以内で回答してください。\n\n語句: ${term}\n素材: ${notes}`;$('definition').value=await askAI(prompt);$('status').textContent='内容を確認して登録してください。'}catch(error){$('status').textContent=error.message}};$('save').onclick=async()=>{const term=$('term').value.trim(),definition=$('definition').value.trim(),aliases=$('aliases').value.split(/[,、]/).map(v=>v.trim()).filter(Boolean);$('status').textContent='保存中…';try{const result=await learning('save',{term,definition,aliases});render(result.entries);$('status').textContent='辞書へ登録しました。本文は保存されていません。';$('term').value='';$('aliases').value='';$('blogUrl').value='';$('notes').value='';$('definition').value=''}catch(error){$('status').textContent=error.message}};reload();
</script></body></html>
