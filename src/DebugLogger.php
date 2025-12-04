<?php

/**
 * デバッグログクラス
 * コンソール出力とログファイルへの出力を提供
 */
class DebugLogger {
    private static $enabled = false;
    private static $logFile = null;
    private static $logToConsole = true;
    private static $logToFile = true;

    /**
     * デバッグログを初期化
     * 
     * @param bool $enabled デバッグを有効にするか
     * @param string|null $logFile ログファイルのパス（null の場合はデフォルト）
     * @param bool $logToConsole コンソールに出力するか
     * @param bool $logToFile ファイルに出力するか
     */
    public static function init($enabled = false, $logFile = null, $logToConsole = true, $logToFile = true) {
        self::$enabled = $enabled;
        self::$logToConsole = $logToConsole;
        self::$logToFile = $logToFile;
        
        if ($logFile === null) {
            $logFile = __DIR__ . '/../logs/debug.log';
        }
        self::$logFile = $logFile;
        
        // ログディレクトリを作成
        if (self::$enabled && self::$logToFile) {
            $logDir = dirname(self::$logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
        }
    }

    /**
     * デバッグログを出力
     * 
     * @param string $message メッセージ
     * @param array $context 追加のコンテキスト情報
     */
    public static function log($message, $context = []) {
        if (!self::$enabled) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $logMessage = "[{$timestamp}] {$message}{$contextStr}\n";

        // コンソールに出力（複数の方法を試行）
        if (self::$logToConsole) {
            $sapiName = php_sapi_name();
            
            // CLI環境の場合
            if ($sapiName === 'cli' || $sapiName === 'cli-server') {
                // STDOUT/STDERR が定義されているかチェック
                if (defined('STDOUT') && is_resource(STDOUT)) {
                    fwrite(STDOUT, $logMessage);
                }
                if (defined('STDERR') && is_resource(STDERR)) {
                    fwrite(STDERR, $logMessage);
                }
                
                // STDOUT/STDERR が利用できない場合は php://stdout を使用
                if (!defined('STDOUT') || !is_resource(STDOUT)) {
                    $stdout = fopen('php://stdout', 'w');
                    if ($stdout) {
                        fwrite($stdout, $logMessage);
                        fclose($stdout);
                    }
                }
            } else {
                // Web環境の場合
                // 1. error_log を使用（サーバーのエラーログに記録、PHPビルトインサーバーではターミナルに表示される）
                error_log($logMessage, 4);
                
                // 2. PHPビルトインサーバーの場合、php://stdout にも出力
                // （php -S で起動している場合、標準出力がターミナルに表示される）
                if (isset($_SERVER['SERVER_SOFTWARE']) && 
                    strpos($_SERVER['SERVER_SOFTWARE'], 'PHP') !== false) {
                    $stdout = @fopen('php://stdout', 'w');
                    if ($stdout) {
                        fwrite($stdout, $logMessage);
                        fclose($stdout);
                    }
                }
                
                // 3. 開発環境ではHTMLコメントとしても出力（ブラウザのソース表示で確認可能）
                if (ini_get('display_errors') || defined('DEBUG_FORCE_OUTPUT')) {
                    echo "<!-- DEBUG: " . htmlspecialchars($logMessage, ENT_QUOTES, 'UTF-8') . " -->\n";
                }
            }
        }

        // ファイルに出力
        if (self::$logToFile && self::$logFile) {
            @file_put_contents(self::$logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * 情報レベルのログ
     */
    public static function info($message, $context = []) {
        self::log("[INFO] {$message}", $context);
    }

    /**
     * 警告レベルのログ
     */
    public static function warning($message, $context = []) {
        self::log("[WARNING] {$message}", $context);
    }

    /**
     * エラーレベルのログ
     */
    public static function error($message, $context = []) {
        self::log("[ERROR] {$message}", $context);
    }

    /**
     * デバッグレベルのログ
     */
    public static function debug($message, $context = []) {
        self::log("[DEBUG] {$message}", $context);
    }

    /**
     * API リクエストのログ（機密情報をマスク）
     */
    public static function apiRequest($method, $url, $data, $headers = []) {
        if (!self::$enabled) {
            return;
        }

        // 機密情報をマスク
        $maskedData = $data;
        if (isset($maskedData['prompt'])) {
            $maskedData['prompt'] = substr($maskedData['prompt'], 0, 100) . '...';
        }
        if (isset($maskedData['input'])) {
            $maskedData['input'] = is_string($maskedData['input']) 
                ? substr($maskedData['input'], 0, 100) . '...' 
                : $maskedData['input'];
        }

        $maskedHeaders = $headers;
        foreach ($maskedHeaders as $key => $value) {
            if (stripos($key, 'authorization') !== false || stripos($key, 'api-key') !== false) {
                $maskedHeaders[$key] = 'Bearer sk-***';
            }
        }

        self::debug("API Request: {$method} {$url}", [
            'data' => $maskedData,
            'headers' => $maskedHeaders
        ]);
    }

    /**
     * API レスポンスのログ
     */
    public static function apiResponse($url, $response, $httpCode = null) {
        if (!self::$enabled) {
            return;
        }

        $context = ['url' => $url];
        if ($httpCode !== null) {
            $context['http_code'] = $httpCode;
        }

        // レスポンスが大きすぎる場合は切り詰める
        $responsePreview = $response;
        if (is_array($response)) {
            $responsePreview = json_encode($response, JSON_UNESCAPED_UNICODE);
            if (strlen($responsePreview) > 500) {
                $responsePreview = substr($responsePreview, 0, 500) . '...';
            }
        } elseif (is_string($response) && strlen($response) > 500) {
            $responsePreview = substr($response, 0, 500) . '...';
        }

        $context['response'] = $responsePreview;
        self::debug("API Response: {$url}", $context);
    }

    /**
     * 処理時間の計測開始
     */
    public static function startTimer($label) {
        if (!self::$enabled) {
            return;
        }
        self::debug("Timer started: {$label}");
    }

    /**
     * 処理時間の計測終了
     */
    public static function endTimer($label, $startTime) {
        if (!self::$enabled) {
            return;
        }
        $elapsed = microtime(true) - $startTime;
        self::debug("Timer ended: {$label}", ['elapsed_seconds' => number_format($elapsed, 3)]);
    }
}

