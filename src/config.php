<?php

/**
 * 設定ファイルの読み込み
 * 環境変数を優先的に使用し、設定ファイルはフォールバックとして使用
 */
function loadConfig() {
    // .env ファイルの読み込み（存在する場合）
    $envPath = __DIR__ . '/../.env';
    if (file_exists($envPath)) {
        loadEnvFile($envPath);
    }

    $configPath = __DIR__ . '/../config/config.php';
    $examplePath = __DIR__ . '/../config/config.example.php';

    // 設定ファイルから基本設定を読み込む
    if (file_exists($configPath)) {
        $config = require $configPath;
    } elseif (file_exists($examplePath)) {
        $config = require $examplePath;
    } else {
        throw new Exception('Config file not found. Please create config/config.php from config.example.php');
    }

    // 環境変数から値を取得（優先）
    // OPENAI_API_KEY
    if (getenv('OPENAI_API_KEY')) {
        $config['openai_api_key'] = getenv('OPENAI_API_KEY');
    } elseif (isset($_ENV['OPENAI_API_KEY'])) {
        $config['openai_api_key'] = $_ENV['OPENAI_API_KEY'];
    }

    // OPENAI_API_BASE
    if (getenv('OPENAI_API_BASE')) {
        $config['openai_api_base'] = getenv('OPENAI_API_BASE');
    } elseif (isset($_ENV['OPENAI_API_BASE'])) {
        $config['openai_api_base'] = $_ENV['OPENAI_API_BASE'];
    }

    // IMAGE_MODEL
    if (getenv('IMAGE_MODEL')) {
        $config['image_model'] = getenv('IMAGE_MODEL');
    } elseif (isset($_ENV['IMAGE_MODEL'])) {
        $config['image_model'] = $_ENV['IMAGE_MODEL'];
    }

    // EMBEDDING_MODEL
    if (getenv('EMBEDDING_MODEL')) {
        $config['embedding_model'] = getenv('EMBEDDING_MODEL');
    } elseif (isset($_ENV['EMBEDDING_MODEL'])) {
        $config['embedding_model'] = $_ENV['EMBEDDING_MODEL'];
    }

    // DEBUG_MODE
    // 環境変数が設定されていない場合は、デフォルトで true（開発環境を想定）
    $debugEnabled = false;
    if (getenv('DEBUG_MODE') !== false) {
        $debugEnabled = (getenv('DEBUG_MODE') === 'true' || getenv('DEBUG_MODE') === '1');
    } elseif (isset($_ENV['DEBUG_MODE'])) {
        $debugEnabled = ($_ENV['DEBUG_MODE'] === 'true' || $_ENV['DEBUG_MODE'] === '1');
    } else {
        // デフォルトで有効化（開発環境を想定）
        // 本番環境では明示的に DEBUG_MODE=false を設定してください
        $debugEnabled = true;
    }
    $config['debug_mode'] = $debugEnabled;

    // DEBUG_LOG_FILE
    $config['debug_log_file'] = getenv('DEBUG_LOG_FILE') ?: ($_ENV['DEBUG_LOG_FILE'] ?? null);

    // DEBUG_LOG_TO_CONSOLE
    $logToConsole = getenv('DEBUG_LOG_TO_CONSOLE') !== false ? (getenv('DEBUG_LOG_TO_CONSOLE') !== 'false' && getenv('DEBUG_LOG_TO_CONSOLE') !== '0') : true;
    if (isset($_ENV['DEBUG_LOG_TO_CONSOLE'])) {
        $logToConsole = ($_ENV['DEBUG_LOG_TO_CONSOLE'] !== 'false' && $_ENV['DEBUG_LOG_TO_CONSOLE'] !== '0');
    }
    $config['debug_log_to_console'] = $logToConsole;

    // DEBUG_LOG_TO_FILE
    $logToFile = getenv('DEBUG_LOG_TO_FILE') !== false ? (getenv('DEBUG_LOG_TO_FILE') !== 'false' && getenv('DEBUG_LOG_TO_FILE') !== '0') : true;
    if (isset($_ENV['DEBUG_LOG_TO_FILE'])) {
        $logToFile = ($_ENV['DEBUG_LOG_TO_FILE'] !== 'false' && $_ENV['DEBUG_LOG_TO_FILE'] !== '0');
    }
    $config['debug_log_to_file'] = $logToFile;

    return $config;
}

/**
 * .env ファイルを読み込む
 * 
 * @param string $filePath .env ファイルのパス
 */
function loadEnvFile($filePath) {
    if (!file_exists($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // コメント行をスキップ
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // KEY=VALUE 形式をパース
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // クォートを削除
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            // 環境変数として設定（まだ設定されていない場合のみ）
            if (!getenv($key) && !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

