## Prompt Generator (PRISM + OpenAI API)

PHP を用いて、ユーザがアップロードした画像に近い画像を生成するためのプロンプトを自動生成する Web アプリです。  
Prompt Refinement via Iterative Search with Multimodal feedback (PRISM) と OpenAI API（画像生成・画像埋め込みなど）を組み合わせて実装していくことを想定しています。

### 現状のステータス

- ✅ **PRISM アルゴリズムの実装完了**
  - 反復的なプロンプト更新ロジックを実装
  - 画像類似度評価（埋め込みベクトルベースのコサイン類似度）
  - 初期プロンプト生成 → 画像生成 → 評価 → プロンプト改良のループ
- ✅ **OpenAI API 連携完了**
  - 画像分析とプロンプト生成（GPT-4o）
  - 画像生成（DALL-E 3）
  - 画像埋め込み取得（CLIPベース）
- ✅ **UI 実装完了**
  - 画像アップロードフォーム
  - 反復履歴の表示
  - 最良のプロンプトと生成画像の表示

### ディレクトリ構成

- `public/`
  - `index.php` : Web アプリのエントリポイント。画像アップロードフォームと結果表示画面。
  - `uploads/` : アップロードされた画像の保存先（自動生成）
  - `generated/` : PRISM で生成された画像の保存先（自動生成）
- `src/`
  - `OpenAIClient.php` : OpenAI API クライアント（画像分析、プロンプト生成、画像生成、埋め込み取得）
  - `ImageSimilarity.php` : 画像類似度評価クラス（コサイン類似度計算）
  - `PrismEngine.php` : PRISM アルゴリズムのメインエンジン
  - `config.php` : 設定ファイル読み込み関数
- `config/`
  - `config.example.php` : OpenAI API キー等の設定サンプル。実運用用の `config.php` をこのファイルからコピーして作成します（Git 管理外を推奨）。
- `.env.example` : 環境変数の設定サンプル。実運用用の `.env` をこのファイルからコピーして作成します（Git 管理外）。

### 事前準備

1. PHP 実行環境（例: PHP 8.1 以上）を用意してください。
2. Web サーバ（`php -S` のビルトインサーバでも可）から `public/index.php` にアクセスできるようにしてください。
3. **HTTP リクエスト機能**: 
   - **推奨**: PHP の cURL 拡張機能（`php-curl`）がインストールされていること
   - **代替**: cURL が利用できない場合、`allow_url_fopen` が有効になっていれば `file_get_contents()` を使用します
4. `config/config.php` を作成するか、`.env` ファイルで OpenAI API の設定を行います（詳細は後述）。

### 設定方法

**推奨: 環境変数を使用する方法**

1. `.env.example` を `.env` にコピー:

```bash
cp .env.example .env
```

2. `.env` ファイルを編集し、実際の API キーに置き換えます:

```bash
# エディタで .env を開いて編集
nano .env
# または
vim .env
```

`.env` ファイルの内容例:

```env
OPENAI_API_KEY=your_openai_api_key_here
OPENAI_API_BASE=https://api.openai.com/v1
IMAGE_MODEL=gpt-image-1
EMBEDDING_MODEL=text-embedding-3-large
```

**利用可能な画像生成モデル:**
- `gpt-image-1`: カスタム画像生成モデル（推奨）
- `gpt-5.1`: カスタム画像生成モデル
- `dall-e-3`: DALL-E 3（標準モデル）
- `dall-e-2`: DALL-E 2（標準モデル）

**注意:** `gpt-image-1` と `gpt-5.1` はカスタムモデルのため、`model` パラメータが自動的に追加されます。`response_format` パラメータは不要です。

**デバッグモードの有効化:**

デバッグログを有効にするには、`.env` ファイルに以下を追加してください：

```env
DEBUG_MODE=true
DEBUG_LOG_FILE=logs/debug.log
DEBUG_LOG_TO_CONSOLE=true
DEBUG_LOG_TO_FILE=true
```

デバッグログには以下の情報が記録されます：
- API リクエスト/レスポンス（機密情報はマスクされます）
- 各処理の開始/終了時間
- エラーと警告
- 反復処理の詳細
- 類似度スコアの変化

3. 実際の API キーに置き換えてください。

**代替: 設定ファイルを使用する方法**

1. サンプル設定をコピー:

```bash
cp config/config.example.php config/config.php
```

2. `config/config.php` の中身を編集し、少なくとも以下を設定します:

- OpenAI の API キー
- （必要に応じて）API ベース URL や利用するモデル名 など

**注意**: 環境変数が設定されている場合、環境変数の値が優先されます。

### 簡易なローカル起動方法（例）

プロジェクトルート（この `README.md` があるディレクトリ）で以下を実行し、PHP のビルトインサーバを使って動作確認できます。

```bash
php -S localhost:8000 -t public
```

ブラウザで `http://localhost:8000` にアクセスします。

### PRISM アルゴリズムの動作フロー

1. **初期プロンプト生成**
   - ユーザーがアップロードした画像を GPT-4o で分析
   - 画像の特徴（スタイル、色、構図、被写体など）を抽出して初期プロンプトを生成

2. **反復的なプロンプト改良ループ**（最大反復回数まで、または類似度閾値に達するまで）
   - 現在のプロンプトで DALL-E 3 を使って画像を生成
   - 生成画像と参照画像の埋め込みベクトルを取得
   - コサイン類似度を計算
   - 類似度が閾値を超えていない場合、GPT-4o を使ってプロンプトを改良
   - 改良されたプロンプトで次の反復に進む

3. **結果の出力**
   - 最良の類似度スコアを持つプロンプトと生成画像を返す
   - 全反復の履歴を記録

### 使用方法

1. **設定ファイルの準備**
   ```bash
   cp config/config.example.php config/config.php
   ```
   その後、`config/config.php` を編集して OpenAI API キーを設定してください。

2. **Web サーバーの起動**
   ```bash
   php -S localhost:8000 -t public
   ```

3. **ブラウザでアクセス**
   - `http://localhost:8000` にアクセス
   - 画像をアップロードして「画像からプロンプトを生成」ボタンをクリック
   - 最大反復回数と類似度閾値を調整可能

### 注意事項

- OpenAI API の利用には API キーが必要です（有料）
- 画像生成と画像分析には時間がかかる場合があります（1回の反復で数分かかる可能性）
- 生成された画像は `public/generated/` ディレクトリに保存されます
- アップロードされた画像は `public/uploads/` ディレクトリに保存されます

### トラブルシューティング

#### cURL 拡張機能がインストールされていない場合

エラー: `Call to undefined function curl_init()`

**解決方法:**

1. **Ubuntu/Debian の場合:**
   ```bash
   sudo apt-get update
   sudo apt-get install php-curl
   sudo systemctl restart apache2  # または php-fpm
   ```

2. **CentOS/RHEL の場合:**
   ```bash
   sudo yum install php-curl
   sudo systemctl restart httpd  # または php-fpm
   ```

3. **cURL が利用できない場合:**
   - このアプリケーションは、cURL が利用できない場合に自動的に `file_get_contents()` を使用します
   - その場合、`php.ini` で `allow_url_fopen = On` が設定されている必要があります

4. **cURL のインストール確認:**
   ```bash
   php -m | grep curl
   ```
   または
   ```bash
   php -r "echo function_exists('curl_init') ? 'cURL is available' : 'cURL is NOT available';"
   ```

#### プロンプトが「申し訳ありませんが、その画像についての分析はできません。」などのエラーメッセージになる場合

この問題は、OpenAI API がリクエストを拒否した場合に発生します。

**重要な注意: ChatGPT Web UI と API のポリシーの違い**

ChatGPT Web UI では画像分析が成功しても、同じ画像を API 経由で分析すると拒否される場合があります。これは以下の理由によるものです：

1. **API はより厳格なポリシーを適用**
   - API は自動化された使用を想定しており、より厳格なコンテンツモデレーションが適用されます
   - Web UI は人間の直接的な操作を想定しており、より柔軟な対応が可能です

2. **プライバシー保護の観点**
   - 特に顔や人物が含まれる画像の場合、API はプライバシー保護のため詳細な分析を制限する場合があります
   - Web UI では人間が直接操作するため、より詳細な分析が可能な場合があります

3. **コンテンツモデレーションの違い**
   - API と Web UI では、コンテンツモデレーションの実装が異なる場合があります
   - 同じ画像でも、プラットフォームによって結果が異なることがあります

**考えられる原因と解決方法:**

1. **画像に顔や人物が含まれている**
   - プライバシー保護のため、顔を含む画像の詳細な分析が制限される場合があります
   - 人物が含まれていない画像を試してみてください

2. **API キーが正しく設定されていない**
   - `.env` ファイルまたは `config/config.php` で API キーが正しく設定されているか確認
   - API キーは `sk-` で始まる必要があります

3. **画像の内容が不適切**
   - 一部の画像（暴力的、性的、その他不適切な内容）は API が拒否する場合があります
   - 別の画像で試してみてください

4. **画像サイズが大きすぎる**
   - 画像サイズが 20MB を超える場合はエラーになります
   - 画像をリサイズしてから再度お試しください

5. **API のレート制限**
   - API の使用制限に達している可能性があります
   - しばらく待ってから再試行してください

6. **ネットワークエラー**
   - インターネット接続を確認してください
   - プロキシ設定が必要な場合は、PHP の設定を確認してください

**デバッグ方法:**
- エラーログを確認: `error_log` に詳細なエラー情報が記録されます
- ブラウザの開発者ツールでネットワークリクエストを確認
- API キーが有効かどうかを確認（別のツールでテスト）

### 今後の改善予定

- 非同期処理（AJAX）によるリアルタイム進捗表示
- 生成画像のキャッシュ機能
- 複数のプロンプト候補の並列探索
- より高度な類似度評価手法の実装


