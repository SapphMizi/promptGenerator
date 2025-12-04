<?php
// PRISM + OpenAI API によるプロンプト生成 Web アプリ

// エラーレポートを有効化（開発用）
error_reporting(E_ALL);
ini_set('display_errors', '1');

// デバッグ出力を強制（開発環境用）
define('DEBUG_FORCE_OUTPUT', true);

// 出力バッファリングを無効化（リアルタイムでコンソールに出力するため）
if (php_sapi_name() === 'cli-server' || php_sapi_name() === 'cli') {
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    // バッファリングを無効化
    ini_set('output_buffering', 'Off');
    ini_set('zlib.output_compression', 'Off');
}

// クラスファイルの読み込み
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DebugLogger.php';
require_once __DIR__ . '/../src/OpenAIClient.php';
require_once __DIR__ . '/../src/ImageSimilarity.php';
require_once __DIR__ . '/../src/PrismEngine.php';

// 設定の読み込み
try {
    $config = loadConfig();
    
    // デバッグログの初期化
    $debugEnabled = $config['debug_mode'] ?? false;
    $debugLogFile = $config['debug_log_file'] ?? null;
    $logToConsole = $config['debug_log_to_console'] ?? true;
    $logToFile = $config['debug_log_to_file'] ?? true;
    DebugLogger::init($debugEnabled, $debugLogFile, $logToConsole, $logToFile);
    
    if ($debugEnabled) {
        DebugLogger::info("Application started", [
            'script' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
        ]);
    }
} catch (Exception $e) {
    die('設定ファイルの読み込みに失敗しました: ' . htmlspecialchars($e->getMessage()));
}

// 簡易なルーティング
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 変数の初期化
$uploadedImagePath = null;
$prismResult = null;
$errorMessage = null;
$isProcessing = false;

if ($method === 'POST') {
    if (!isset($_FILES['input_image']) || $_FILES['input_image']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = '画像ファイルのアップロードに失敗しました。';
    } else {
        $tmpName = $_FILES['input_image']['tmp_name'];
        $originalName = basename($_FILES['input_image']['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // 簡易的な拡張子チェック
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExts, true)) {
            $errorMessage = '対応していない画像形式です。jpg/png/webp のいずれかをアップロードしてください。';
        } else {
            $uploadDir = __DIR__ . '/uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $destPath = $uploadDir . '/' . uniqid('img_', true) . '.' . $ext;
            if (!move_uploaded_file($tmpName, $destPath)) {
                $errorMessage = '画像ファイルの保存に失敗しました。';
            } else {
                // Web から参照するためのパス
                $uploadedImagePath = 'uploads/' . basename($destPath);
                $fullImagePath = $destPath;

                // PRISM アルゴリズムを実行
                try {
                    $isProcessing = true;
                    
                    // OpenAI クライアントと類似度計算機を初期化
                    $openaiClient = new OpenAIClient($config);
                    $similarityCalculator = new ImageSimilarity($openaiClient);
                    
                    // PRISM エンジンを初期化
                    $prismConfig = [
                        'max_iterations' => $_POST['max_iterations'] ?? 5,
                        'similarity_threshold' => $_POST['similarity_threshold'] ?? 0.85,
                        'output_dir' => __DIR__ . '/generated'
                    ];
                    $prismEngine = new PrismEngine($openaiClient, $similarityCalculator, $prismConfig);
                    
                    // PRISM を実行
                    $prismResult = $prismEngine->execute($fullImagePath);
                    
                    // 生成された画像のパスを Web から参照できる形式に変換
                    if ($prismResult['best_image_path']) {
                        $prismResult['best_image_url'] = 'generated/' . basename($prismResult['best_image_path']);
                    }
                    
                    // 履歴内の画像パスも変換
                    foreach ($prismResult['history'] as &$item) {
                        if (isset($item['image_path'])) {
                            $item['image_url'] = 'generated/' . basename($item['image_path']);
                        }
                    }
                    unset($item);
                    
                } catch (Exception $e) {
                    $errorMessage = 'PRISM の実行中にエラーが発生しました: ' . htmlspecialchars($e->getMessage());
                    error_log('PRISM Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                    
                    // より詳細なエラー情報を提供
                    if (strpos($e->getMessage(), 'API key') !== false || strpos($e->getMessage(), 'authentication') !== false) {
                        $errorMessage .= '<br><small style="color: #94a3b8;">API キーが正しく設定されているか確認してください。</small>';
                    } elseif (strpos($e->getMessage(), 'refused') !== false || strpos($e->getMessage(), "I'm sorry") !== false) {
                        $errorMessage .= '<br><small style="color: #94a3b8;">API がリクエストを拒否しました。画像の内容やプロンプトが適切か確認してください。</small>';
                    } elseif (strpos($e->getMessage(), 'empty') !== false) {
                        $errorMessage .= '<br><small style="color: #94a3b8;">プロンプトが生成されませんでした。API のレスポンスを確認してください。</small>';
                    }
                } finally {
                    $isProcessing = false;
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Prompt Generator (PRISM + OpenAI)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 0;
            background: #0f172a;
            color: #e5e7eb;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }
        .card {
            background: radial-gradient(circle at top left, #1e293b, #020617);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.9);
        }
        h1 {
            font-size: 1.6rem;
            margin-bottom: 0.5rem;
        }
        .subtitle {
            font-size: 0.9rem;
            color: #94a3b8;
            margin-bottom: 1.5rem;
        }
        .form-group {
            margin-bottom: 1.2rem;
        }
        label {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 0.4rem;
        }
        input[type="file"] {
            width: 100%;
            padding: 8px;
            background: #020617;
            border-radius: 8px;
            border: 1px solid #1e293b;
            color: #e5e7eb;
        }
        .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 999px;
            border: none;
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            color: #0b1120;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            box-shadow: 0 12px 30px rgba(59, 130, 246, 0.55);
        }
        .btn-submit:hover {
            filter: brightness(1.05);
        }
        .error {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            background: rgba(248, 113, 113, 0.1);
            border: 1px solid rgba(248, 113, 113, 0.4);
            color: #fecaca;
            font-size: 0.85rem;
        }
        .result {
            margin-top: 1.5rem;
            padding: 1rem;
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid #1e293b;
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.6fr);
            gap: 16px;
        }
        .result-image {
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #1e293b;
            background: #020617;
        }
        .result-image img {
            display: block;
            width: 100%;
            height: auto;
        }
        .result-prompt-title {
            font-size: 0.9rem;
            color: #94a3b8;
            margin-bottom: 0.3rem;
        }
        .result-prompt {
            font-size: 0.95rem;
            line-height: 1.6;
            white-space: pre-wrap;
        }
        .tip {
            margin-top: 1rem;
            font-size: 0.8rem;
            color: #64748b;
        }
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(4px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        .loading-overlay.active {
            display: flex;
        }
        .loading-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid rgba(59, 130, 246, 0.2);
            border-top-color: #3b82f6;
            border-right-color: transparent;
            border-bottom-color: transparent;
            border-left-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        .loading-text {
            margin-top: 20px;
            color: #e5e7eb;
            font-size: 1rem;
            font-weight: 500;
        }
        @media (max-width: 768px) {
            .card {
                padding: 18px;
            }
            .result {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>Prompt Generator (PRISM + OpenAI)</h1>
        <div class="subtitle">
            アップロードした画像に近い画像を生成するためのプロンプトを、PRISM + OpenAI API で探索・生成します。
        </div>

        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="input_image">入力画像をアップロード</label>
                <input type="file" id="input_image" name="input_image" accept="image/*" required>
            </div>
            <div class="form-group">
                <label for="max_iterations">最大反復回数</label>
                <input type="number" id="max_iterations" name="max_iterations" value="5" min="1" max="10" style="width: 100px; padding: 8px; background: #020617; border-radius: 8px; border: 1px solid #1e293b; color: #e5e7eb;">
            </div>
            <div class="form-group">
                <label for="similarity_threshold">類似度閾値（0.0-1.0）</label>
                <input type="number" id="similarity_threshold" name="similarity_threshold" value="0.85" min="0" max="1" step="0.05" style="width: 100px; padding: 8px; background: #020617; border-radius: 8px; border: 1px solid #1e293b; color: #e5e7eb;">
            </div>
            <button type="submit" class="btn-submit" <?= $isProcessing ? 'disabled' : '' ?>>
                <?= $isProcessing ? '処理中...' : '画像からプロンプトを生成' ?>
            </button>
        </form>

        <?php if ($errorMessage): ?>
            <div class="error">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($uploadedImagePath && $prismResult): ?>
            <div class="result">
                <div class="result-image">
                    <div style="margin-bottom: 12px;">
                        <div style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 4px;">参照画像</div>
                        <img src="<?= htmlspecialchars($uploadedImagePath, ENT_QUOTES, 'UTF-8') ?>" alt="アップロード画像">
                    </div>
                    <?php if (isset($prismResult['best_image_url'])): ?>
                        <div>
                            <div style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 4px;">
                                最良の生成画像（類似度: <?= number_format($prismResult['best_similarity'], 3) ?>）
                            </div>
                            <img src="<?= htmlspecialchars($prismResult['best_image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="生成画像">
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="result-prompt-title">最良のプロンプト</div>
                    <div class="result-prompt">
                        <?= htmlspecialchars($prismResult['best_prompt'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div style="margin-top: 12px; font-size: 0.85rem; color: #94a3b8;">
                        類似度スコア: <?= number_format($prismResult['best_similarity'], 3) ?> | 
                        反復回数: <?= $prismResult['total_iterations'] ?>
                    </div>
                    
                    <?php if (!empty($prismResult['history']) && count($prismResult['history']) > 1): ?>
                        <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #1e293b;">
                            <div style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 12px; font-weight: 600;">
                                反復履歴
                            </div>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($prismResult['history'] as $item): ?>
                                    <div style="margin-bottom: 16px; padding: 12px; background: rgba(15, 23, 42, 0.6); border-radius: 8px; border: 1px solid #1e293b;">
                                        <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 6px;">
                                            反復 <?= $item['iteration'] ?> 
                                            <?php if ($item['type'] === 'initial'): ?>
                                                (初期プロンプト)
                                            <?php elseif (isset($item['similarity'])): ?>
                                                (類似度: <?= number_format($item['similarity'], 3) ?>)
                                            <?php elseif (isset($item['error'])): ?>
                                                <span style="color: #f87171;">(エラー: <?= htmlspecialchars($item['error'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (isset($item['image_url'])): ?>
                                            <div style="margin-bottom: 8px;">
                                                <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="生成画像" style="max-width: 200px; border-radius: 4px; border: 1px solid #1e293b;">
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size: 0.85rem; line-height: 1.5; white-space: pre-wrap; word-break: break-word;">
                                            <?= htmlspecialchars($item['prompt'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($uploadedImagePath): ?>
            <div class="result">
                <div class="result-image">
                    <img src="<?= htmlspecialchars($uploadedImagePath, ENT_QUOTES, 'UTF-8') ?>" alt="アップロード画像">
                </div>
                <div>
                    <div class="result-prompt-title">画像がアップロードされました</div>
                    <div class="result-prompt">
                        画像をアップロードしました。フォームを送信してプロンプト生成を開始してください。
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ローディングオーバーレイ -->
<div id="loadingOverlay" class="loading-overlay <?= $isProcessing ? 'active' : '' ?>">
    <div class="loading-spinner"></div>
    <div class="loading-text">APIからの結果を待っています...</div>
</div>

<script>
    // フォーム送信時にローディングを表示
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        const loadingOverlay = document.getElementById('loadingOverlay');
        
        if (form) {
            form.addEventListener('submit', function(e) {
                // ファイルが選択されているか確認
                const fileInput = document.getElementById('input_image');
                if (fileInput && fileInput.files.length > 0) {
                    loadingOverlay.classList.add('active');
                }
            });
        }
        
        // PHPの処理状態に基づいてローディングを表示/非表示
        <?php if (!$isProcessing): ?>
        loadingOverlay.classList.remove('active');
        <?php endif; ?>
    });
</script>
</body>
</html>


