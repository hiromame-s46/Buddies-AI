# LFM Web Chat 4.0.0

スターレンタルサーバーなどの共有サーバーで、`LFM2.5-1.2B-JP-202606-Q4_K_M.gguf`を`llama.cpp`から呼び出すための一式です。

## 含まれるファイル

```text
public_html/satellite/AI/
├── lfm_setup.php       管理・診断・モデル取得・削除
├── lfm-common.php      共通処理（Webからの直接アクセス禁止）
├── lfm-api.php         公開チャット/API
├── chat.html           モバイル対応チャット画面
├── .htaccess           非公開ファイル保護
└── .env.example        設定例

tools/
├── build_portable_linux.sh
└── build_portable_linux_docker.sh

.github/workflows/
└── build-portable-llama.yml
```

実際のモデル、バイナリ、APIキーは、初期化後に以下へ保存されます。

```text
public_html/satellite/AI/.lfm-runtime/
├── .env
├── bin/llama-cli
├── lib/
├── models/LFM2.5-1.2B-JP-202606-Q4_K_M.gguf
├── cache/
├── downloads/
├── logs/
└── tmp/
```

## 設置

1. `public_html/satellite/AI/`の中身を、サーバーの`public_html/satellite/AI/`へアップロードします。
2. `lfm_setup.php`の次の値を長いランダム文字列へ変更します。

```php
const LFM_BOOTSTRAP_SETUP_KEY = 'lfm-setup-change-me-400';
```

3. 次を開きます。

```text
https://あなたのドメイン/satellite/AI/lfm_setup.php?key=変更したキー
```

4. 「ランタイムを初期化」を実行します。
5. `llama-cli`を設置します。
6. モデルをダウンロードします。
7. 推論テスト後、`chat.html`を開きます。

## llama-cliの推奨設置方法

公式Ubuntuバイナリは、共有サーバーに存在しない`libssl.so.3`などを要求することがあります。そのため、`LLAMA_CURL=OFF`、静的なllama/ggmlライブラリ、古いglibcを対象にしたポータブルビルドを推奨します。

### GitHub Actions

mainブランチへ初回pushすると、Actionsの「Build portable llama-cli」が自動実行されます。Actions画面から手動実行することもでき、その場合は`llama.cpp`のブランチ・タグ・コミットを指定できます。完了後、Artifactsの`llama-portable-linux-x64`からZIPをダウンロードし、セットアップ画面へアップロードします。

### Mac + Docker

Dockerを利用できる場合は、ZIPを展開したディレクトリで次を実行します。

```bash
./tools/build_portable_linux_docker.sh
```

`portable-output/llama-portable-linux-x64.zip`が生成されます。

### サーバー内ビルド

セットアップ画面にはサーバー内ビルド機能もあります。ただし、`cmake`、C++コンパイラ、`tar`、`nohup`と、PHPから外部コマンドを起動できる関数が必要です。共有サーバーでは利用できない場合があります。

## 公開チャットとAPIキー

- `chat.html`からの同一ドメインアクセスは、APIキーなしで利用できます。
- IP単位の回数制限が適用されます。
- 外部プログラムから呼び出す場合は、`.lfm-runtime/.env`の`LFM_API_KEY`を`X-API-Key`ヘッダーへ設定します。
- APIキーは`chat.html`へ埋め込まれません。

```bash
curl -X POST 'https://あなたのドメイン/satellite/AI/lfm-api.php' \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: .envのLFM_API_KEY' \
  --data '{"prompt":"こんにちは","max_tokens":128,"temperature":0.2}'
```

## 削除機能

セットアップ画面から次を個別に削除できます。

- モデル：GGUFと途中ダウンロードだけを削除
- キャッシュ：キャッシュ、ログ、途中ファイル、レート制限記録を削除
- ランタイム全削除：`.lfm-runtime`全体を削除。PHPとHTMLは残る

## 初期設定

```dotenv
LFM_PUBLIC_CHAT="true"
LFM_RATE_LIMIT_REQUESTS="6"
LFM_RATE_LIMIT_WINDOW="600"
LFM_MAX_PROMPT_CHARS="4000"
LFM_MAX_OUTPUT_TOKENS="256"
LFM_TIMEOUT_SECONDS="150"
LFM_THREADS="1"
LFM_CONTEXT="2048"
```

CPU負荷を抑えるため、最初は`LFM_THREADS=1`のまま使用してください。

## 注意

共有サーバーでのネイティブLLM推論は、サーバー会社の負荷制限対象になる可能性があります。公開範囲と回数制限を小さく保ち、リソース使用量を確認してください。
