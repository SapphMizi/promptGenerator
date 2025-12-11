<?php

require_once __DIR__ . '/DebugLogger.php';

/**
 * OpenAI API クライアント
 * 画像分析、プロンプト生成、画像生成、埋め込み取得などの機能を提供
 */
class OpenAIClient {
    private $apiKey;
    private $apiBase;
    private $imageModel;
    private $embeddingModel;

    public function __construct($config) {
        $this->apiKey = $config['openai_api_key'] ?? '';
        $this->apiBase = $config['openai_api_base'] ?? 'https://api.openai.com/v1';
        $this->imageModel = $config['image_model'] ?? 'dall-e-3';
        $this->embeddingModel = $config['embedding_model'] ?? 'text-embedding-3-large';

        // 埋め込みモデルの検証（画像生成モデルが誤って設定されていないか確認）
        $imageModels = ['gpt-image-1', 'gpt-5.1', 'dall-e-3', 'dall-e-2'];
        if (in_array($this->embeddingModel, $imageModels)) {
            DebugLogger::warning("Embedding model appears to be an image generation model, using default", [
                'configured' => $this->embeddingModel,
                'fallback' => 'text-embedding-3-large'
            ]);
            $this->embeddingModel = 'text-embedding-3-large';
        }

        // デバッグログの初期化
        $debugEnabled = $config['debug_mode'] ?? false;
        $debugLogFile = $config['debug_log_file'] ?? null;
        $logToConsole = $config['debug_log_to_console'] ?? true;
        $logToFile = $config['debug_log_to_file'] ?? true;
        DebugLogger::init($debugEnabled, $debugLogFile, $logToConsole, $logToFile);

        DebugLogger::info("OpenAIClient initialized", [
            'api_base' => $this->apiBase,
            'image_model' => $this->imageModel,
            'embedding_model' => $this->embeddingModel
        ]);

        if (empty($this->apiKey) || $this->apiKey === 'YOUR_OPENAI_API_KEY') {
            DebugLogger::error("OpenAI API key is not configured");
            throw new Exception('OpenAI API key is not configured. Please set OPENAI_API_KEY environment variable or configure it in config/config.php');
        }
        
        // API キーの形式を簡易チェック（sk- で始まることを確認）
        if (strpos($this->apiKey, 'sk-') !== 0 && strlen($this->apiKey) < 20) {
            DebugLogger::error("OpenAI API key format appears to be invalid");
            throw new Exception('OpenAI API key format appears to be invalid. API keys should start with "sk-"');
        }
    }

    /**
     * 画像を分析して初期プロンプトを生成
     * 
     * @param string $imagePath 画像ファイルのパス
     * @return string 生成されたプロンプト
     * @throws Exception API エラーまたは無効なレスポンスの場合
     */
    public function generateInitialPrompt($imagePath) {
        $startTime = microtime(true);
        DebugLogger::info("Generating initial prompt", ['image_path' => $imagePath]);
        
        // 画像をbase64エンコード
        $imageData = file_get_contents($imagePath);
        if ($imageData === false) {
            DebugLogger::error("Failed to read image file", ['image_path' => $imagePath]);
            throw new Exception("Failed to read image file: {$imagePath}");
        }
        
        // 画像サイズのチェック（OpenAI APIの制限: 通常20MB以下）
        $imageSize = strlen($imageData);
        $maxSize = 20 * 1024 * 1024; // 20MB
        if ($imageSize > $maxSize) {
            DebugLogger::error("Image file too large", [
                'image_path' => $imagePath,
                'size' => $imageSize,
                'max_size' => $maxSize
            ]);
            throw new Exception("画像ファイルが大きすぎます（最大20MB）。画像をリサイズしてから再度お試しください。");
        }
        
        $base64Image = base64_encode($imageData);
        $mimeType = mime_content_type($imagePath);
        
        if (!$mimeType || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'])) {
            $mimeType = 'image/png'; // デフォルト
        }
        
        DebugLogger::debug("Image encoded", [
            'mime_type' => $mimeType,
            'image_size' => $imageSize,
            'base64_size' => strlen($base64Image)
        ]);

        $messages = [
            [
                'role' => 'system',
                'content' => 
                'あなたは画像分析と画像生成のためのプロンプト作成の専門家です。提供された画像を詳しく分析し、その画像に似た画像を生成するための詳細なプロンプトを日本語で作成してください。プロンプトには、スタイル、色、構図、被写体の特徴、雰囲気、等身，髪型，その他の関連する詳細を含めてください。プロンプトテキストのみを返答し、説明や追加のテキストは含めないでください。なお，画像は gpt-image-1 によって生成された画像です．
                              例：超リアルな女性のポートレートを生成してください。
                                  髪型は鮮やかで明るいネオンオレンジカラーのあご丈ボブで、前髪は眉の上でまっすぐ切り揃えられている。
                                  彼女は左側を向き、力強く決意に満ちた表情をしている。
                                  服装は彩度の高い緑のタートルネックで、明るい黄色のアクセントと、胸に特徴的な丸いロゴがある。
                                  左の拳を前方に強く握りしめ、意欲とモチベーションを示している。
                                  背景は深い紫色で、明るくはっきりとした斜めの白いレンズフレアが入り、コントラストの強いライティングになっている。'
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'あなたは画像分析と画像生成のためのプロンプト作成の専門家です。提供された画像を詳しく分析し、その画像に似た画像を生成するための詳細なプロンプトを日本語で作成してください。プロンプトには、スタイル、色、構図、被写体の特徴、雰囲気、等身，髪型，その他の関連する詳細を含めてください。プロンプトテキストのみを返答し、説明や追加のテキストは含めないでください。なお，画像は gpt-image-1 によって生成された画像です．'
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$base64Image}"
                        ]
                    ]
                ]
            ]
        ];

        $requestData = [
            'model' => 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => 500,
            'temperature' => 0.7
        ];
        
        $url = rtrim($this->apiBase, '/') . '/chat/completions';
        DebugLogger::apiRequest('POST', $url, $requestData);
        
        $response = $this->makeRequest('chat/completions', $requestData);
        
        DebugLogger::apiResponse($url, $response);

        // レスポンスの検証
        if (!isset($response['choices']) || empty($response['choices'])) {
            DebugLogger::error("Invalid API response: no choices returned");
            throw new Exception("Invalid API response: no choices returned");
        }

        $content = trim($response['choices'][0]['message']['content'] ?? '');
        
        DebugLogger::debug("Initial prompt generated (raw)", [
            'prompt_length' => strlen($content),
            'prompt_preview' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
        ]);
        
        // プロンプトの前にある説明文を除去
        // 「---」や「プロンプト:」などの区切り文字の後を抽出
        $promptPatterns = [
            '/---\s*\n(.*)/s',  // 「---」の後の内容
            '/プロンプト[：:]\s*\n(.*)/s',  // 「プロンプト:」の後の内容
            '/以下[はが]プロンプト[です。：:]\s*\n(.*)/s',  // 「以下がプロンプトです。」の後の内容
            '/\n\n(.*)/s',  // 空行2つ以降の内容（説明文の後にプロンプトがある場合）
        ];
        
        $extractedPrompt = $content;
        foreach ($promptPatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $extractedPrompt = trim($matches[1]);
                if (strlen($extractedPrompt) > 50) {  // 十分な長さがある場合のみ使用
                    DebugLogger::debug("Extracted prompt from explanation", [
                        'original_length' => strlen($content),
                        'extracted_length' => strlen($extractedPrompt)
                    ]);
                    break;
                }
            }
        }
        
        // 説明文が含まれている場合、説明文を除去
        // 「申し訳ありませんが」で始まり「しかし」「ただし」などが続く場合は説明文として扱う
        if (preg_match('/^申し訳ありません[が、]?.*?(しかし|ただし|ただ|なお|なお、|ただし、).*?\n\n(.*)/s', $extractedPrompt, $matches)) {
            $extractedPrompt = trim($matches[2]);
            DebugLogger::debug("Removed apology prefix", ['extracted_length' => strlen($extractedPrompt)]);
        }
        
        $content = $extractedPrompt;
        
        DebugLogger::debug("Initial prompt generated (cleaned)", [
            'prompt_length' => strlen($content),
            'prompt_preview' => substr($content, 0, 100) . (strlen($content) > 100 ? '...' : '')
        ]);
        
        // エラーメッセージや拒否メッセージを検出（英語と日本語の両方）
        // ただし、プロンプトが含まれている場合はエラーとしない
        $errorPatterns = [
            // 完全な拒否メッセージ（プロンプトが含まれていない場合）
            "/^申し訳ありません[が、]?.*?(できません|お手伝いできません|お役に立てません|詳しい分析.*できません|その画像についての分析.*できません).*?$/s",
            "/^I'm sorry.*?(can't help|cannot help|unable to help).*?$/is",
            "/^.*?顔が含まれています.*?詳しい分析.*できません.*?$/s",
            "/^.*?その画像には.*?できません.*?$/s",
            "/^.*?その画像についての分析.*できません.*?$/s",
        ];

        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $content) && strlen($content) < 100) {
                // 短いメッセージで、プロンプトらしい内容が含まれていない場合はエラー
                $errorDetails = substr($content, 0, 500);
                DebugLogger::error("API returned an error or refusal message", ['content' => $errorDetails]);
                
                // より詳細なエラーメッセージを提供
                $errorMessage = "API returned an error or refusal message: " . substr($content, 0, 200);
                if (strpos($content, '顔') !== false || strpos($content, 'face') !== false) {
                    $errorMessage .= "\n\n考えられる原因: 画像に顔や人物が含まれている可能性があります。OpenAI APIは、プライバシー保護のため、顔を含む画像の詳細な分析を制限する場合があります。";
                } elseif (strpos($content, '分析') !== false || strpos($content, 'analyze') !== false) {
                    $errorMessage .= "\n\n考えられる原因: 画像の内容がOpenAI APIの利用規約に抵触している可能性があります。別の画像を試すか、画像の内容を確認してください。";
                }
                
                throw new Exception($errorMessage);
            }
        }

        if (empty($content)) {
            DebugLogger::error("Empty prompt generated from API response");
            throw new Exception("Empty prompt generated from API response");
        }

        DebugLogger::endTimer("generateInitialPrompt", $startTime);
        return $content;
    }

    /**
     * プロンプトを改良する
     * 
     * @param string $currentPrompt 現在のプロンプト
     * @param string $referenceImagePath 参照画像のパス
     * @param string $generatedImagePath 生成された画像のパス
     * @param float $similarityScore 類似度スコア
     * @param int $iteration 現在の反復回数
     * @return string 改良されたプロンプト
     * @throws Exception API エラーまたは無効なレスポンスの場合
     */
    public function refinePrompt($currentPrompt, $referenceImagePath, $generatedImagePath, $similarityScore, $iteration) {
        $startTime = microtime(true);
        DebugLogger::info("Refining prompt", [
            'iteration' => $iteration,
            'similarity_score' => $similarityScore,
            'current_prompt_preview' => substr($currentPrompt, 0, 100)
        ]);
        
        // 画像をbase64エンコード
        $refImageData = file_get_contents($referenceImagePath);
        if ($refImageData === false) {
            DebugLogger::error("Failed to read reference image", ['path' => $referenceImagePath]);
            throw new Exception("Failed to read reference image: {$referenceImagePath}");
        }
        $refBase64 = base64_encode($refImageData);
        $refMime = mime_content_type($referenceImagePath);
        if (!$refMime || !in_array($refMime, ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'])) {
            $refMime = 'image/png';
        }

        $genImageData = file_get_contents($generatedImagePath);
        if ($genImageData === false) {
            throw new Exception("Failed to read generated image: {$generatedImagePath}");
        }
        $genBase64 = base64_encode($genImageData);
        $genMime = mime_content_type($generatedImagePath);
        if (!$genMime || !in_array($genMime, ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'])) {
            $genMime = 'image/png';
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'あなたは画像生成プロンプトを改良する専門家です。参照画像により近い画像を生成するためにプロンプトを改善することが目標です。視覚的な要素（アートスタイル、色調、構図、照明、雰囲気、テクスチャ，等身，髪型など）に焦点を当てて，与えられた文章を改善したり，描写を追加したりしてプロンプトを改良してください。改良されたプロンプトテキストのみを日本語で返答し、説明や追加のテキストは含めないでください。プロンプトは詳細で具体的なものにしてください。なお，画像は gpt-image-1 によって生成された画像です．'
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                            'text' => "現在のプロンプト: {$currentPrompt}\n\n類似度スコア: " . number_format($similarityScore, 3) . "\n反復回数: {$iteration}\n\n参照画像と生成画像の視覚的な違いを比較してください。参照画像により近い画像を生成するために、アートスタイル、色調、構図、照明、雰囲気、テクスチャなどの視覚的要素を調整してプロンプトを改良してください。改良されたプロンプトテキストのみを日本語で返答し、説明や追加のテキストは含めないでください。詳細で具体的なプロンプトにしてください。なお，画像は gpt-image-1 によって生成された画像です．"           
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$refMime};base64,{$refBase64}"
                        ]
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$genMime};base64,{$genBase64}"
                        ]
                    ]
                ]
            ]
        ];

        $requestData = [
            'model' => 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => 500,
            'temperature' => 0.7
        ];
        
        $url = rtrim($this->apiBase, '/') . '/chat/completions';
        DebugLogger::apiRequest('POST', $url, $requestData);
        
        $response = $this->makeRequest('chat/completions', $requestData);
        
        DebugLogger::apiResponse($url, $response);

        // レスポンスの検証
        if (!isset($response['choices']) || empty($response['choices'])) {
            DebugLogger::error("Invalid API response: no choices returned");
            throw new Exception("Invalid API response: no choices returned");
        }

        $content = trim($response['choices'][0]['message']['content'] ?? '');
        
        DebugLogger::debug("Prompt refined", [
            'iteration' => $iteration,
            'prompt_length' => strlen($content),
            'prompt_preview' => substr($content, 0, 100) . (strlen($content) > 100 ? '...' : '')
        ]);
        
        // エラーメッセージや拒否メッセージを検出（英語と日本語の両方）
        // ただし、プロンプトが含まれている場合はエラーとしない
        $errorPatterns = [
            // 完全な拒否メッセージ（プロンプトが含まれていない場合）
            "/^申し訳ありません[が、]?.*?(できません|お手伝いできません|お役に立てません|詳しい分析.*できません|その画像についての分析.*できません).*?$/s",
            "/^I'm sorry.*?(can't help|cannot help|unable to help).*?$/is",
            "/^.*?顔が含まれています.*?詳しい分析.*できません.*?$/s",
            "/^.*?その画像には.*?できません.*?$/s",
            "/^.*?その画像についての分析.*できません.*?$/s",
        ];

        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $content) && strlen($content) < 100) {
                // 短いメッセージで、プロンプトらしい内容が含まれていない場合はエラー
                $errorDetails = substr($content, 0, 500);
                DebugLogger::error("API returned an error or refusal message", ['content' => $errorDetails]);
                
                // より詳細なエラーメッセージを提供
                $errorMessage = "API returned an error or refusal message: " . substr($content, 0, 200);
                if (strpos($content, '顔') !== false || strpos($content, 'face') !== false) {
                    $errorMessage .= "\n\n考えられる原因: 画像に顔や人物が含まれている可能性があります。OpenAI APIは、プライバシー保護のため、顔を含む画像の詳細な分析を制限する場合があります。";
                } elseif (strpos($content, '分析') !== false || strpos($content, 'analyze') !== false) {
                    $errorMessage .= "\n\n考えられる原因: 画像の内容がOpenAI APIの利用規約に抵触している可能性があります。別の画像を試すか、画像の内容を確認してください。";
                }
                
                throw new Exception($errorMessage);
            }
        }

        if (empty($content)) {
            DebugLogger::error("Empty prompt generated from API response");
            throw new Exception("Empty prompt generated from API response");
        }

        DebugLogger::endTimer("refinePrompt", $startTime);
        return $content;
    }

    /**
     * chat historyを使用してプロンプトを改良する（論文のAlgorithm 1に準拠）
     * 各ストリームの独立した履歴に基づいてプロンプトを更新
     * 
     * @param string $currentPrompt 現在のプロンプト
     * @param string $referenceImagePath 参照画像のパス
     * @param string $generatedImagePath 生成された画像のパス
     * @param float $similarityScore 類似度スコア
     * @param int $iteration 現在の反復回数
     * @param array $chatHistory ストリームのchat history（前の反復の結果）
     * @return string 改良されたプロンプト
     * @throws Exception API エラーまたは無効なレスポンスの場合
     */
    public function refinePromptWithHistory($currentPrompt, $referenceImagePath, $generatedImagePath, $similarityScore, $iteration, $chatHistory = []) {
        $startTime = microtime(true);
        DebugLogger::info("Refining prompt with history", [
            'iteration' => $iteration,
            'similarity_score' => $similarityScore,
            'history_length' => count($chatHistory),
            'current_prompt_preview' => substr($currentPrompt, 0, 100)
        ]);
        
        // 画像をbase64エンコード
        $refImageData = file_get_contents($referenceImagePath);
        if ($refImageData === false) {
            DebugLogger::error("Failed to read reference image", ['path' => $referenceImagePath]);
            throw new Exception("Failed to read reference image: {$referenceImagePath}");
        }
        $refBase64 = base64_encode($refImageData);
        $refMime = mime_content_type($referenceImagePath);
        if (!$refMime || !in_array($refMime, ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'])) {
            $refMime = 'image/png';
        }

        $genImageData = file_get_contents($generatedImagePath);
        if ($genImageData === false) {
            throw new Exception("Failed to read generated image: {$generatedImagePath}");
        }
        $genBase64 = base64_encode($genImageData);
        $genMime = mime_content_type($generatedImagePath);
        if (!$genMime || !in_array($genMime, ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'])) {
            $genMime = 'image/png';
        }

        // メッセージを構築（chat historyを含む）
        $messages = [
            [
                'role' => 'system',
                'content' => 'あなたは画像生成プロンプトを改良する専門家です。参照画像により近い画像を生成するためにプロンプトを改善することが目標です。視覚的な要素（アートスタイル、色調、構図、照明、雰囲気、テクスチャ，等身，髪型など）に焦点を当てて，与えられた文章を改善したり，描写を追加したりしてプロンプトを改良してください。改良されたプロンプトテキストのみを日本語で返答し、説明や追加のテキストは含めないでください。プロンプトは詳細で具体的なものにしてください。なお，画像は gpt-image-1 によって生成された画像です．'
            ]
        ];
        
        // 以前の反復の履歴を追加（論文のAlgorithm 1 Line 9: chat history of stream n）
        foreach ($chatHistory as $histItem) {
            if (isset($histItem['prompt']) && isset($histItem['score']) && isset($histItem['iteration'])) {
                // 前の反復でのプロンプトとスコアの情報を追加
                $messages[] = [
                    'role' => 'user',
                    'content' => "前の反復（反復{$histItem['iteration']}）でのプロンプト: {$histItem['prompt']}\n\nスコア: " . number_format($histItem['score'], 3)
                ];
                
                // 前の反復での改善点のフィードバックを追加（ある場合）
                if (isset($histItem['improvement'])) {
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => "改善点: {$histItem['improvement']}"
                    ];
                }
            }
        }
        
        // 現在の反復の情報を追加
        $messages[] = [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => "現在のプロンプト: {$currentPrompt}\n\n類似度スコア: " . number_format($similarityScore, 3) . "\n反復回数: {$iteration}\n\n参照画像と生成画像の視覚的な違いを比較してください。参照画像により近い画像を生成するために、アートスタイル、色調、構図、照明、雰囲気、テクスチャなどの視覚的要素を調整してプロンプトを改良してください。改良されたプロンプトテキストのみを日本語で返答し、説明や追加のテキストは含めないでください。詳細で具体的なプロンプトにしてください。なお，画像は gpt-image-1 によって生成された画像です．"
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$refMime};base64,{$refBase64}"
                    ]
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$genMime};base64,{$genBase64}"
                    ]
                ]
            ]
        ];

        $requestData = [
            'model' => 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => 500,
            'temperature' => 0.7
        ];
        
        $url = rtrim($this->apiBase, '/') . '/chat/completions';
        DebugLogger::apiRequest('POST', $url, $requestData);
        
        $response = $this->makeRequest('chat/completions', $requestData);
        
        DebugLogger::apiResponse($url, $response);

        // レスポンスの検証
        if (!isset($response['choices']) || empty($response['choices'])) {
            DebugLogger::error("Invalid API response: no choices returned");
            throw new Exception("Invalid API response: no choices returned");
        }

        $content = trim($response['choices'][0]['message']['content'] ?? '');
        
        DebugLogger::debug("Prompt refined with history", [
            'iteration' => $iteration,
            'prompt_length' => strlen($content),
            'prompt_preview' => substr($content, 0, 100) . (strlen($content) > 100 ? '...' : '')
        ]);
        
        // エラーメッセージや拒否メッセージを検出（refinePromptと同じパターン）
        $errorPatterns = [
            "/^申し訳ありません[が、]?.*?(できません|お手伝いできません|お役に立てません|詳しい分析.*できません|その画像についての分析.*できません).*?$/s",
            "/^I'm sorry.*?(can't help|cannot help|unable to help).*?$/is",
            "/^.*?顔が含まれています.*?詳しい分析.*できません.*?$/s",
            "/^.*?その画像には.*?できません.*?$/s",
            "/^.*?その画像についての分析.*できません.*?$/s",
        ];

        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $content) && strlen($content) < 100) {
                $errorDetails = substr($content, 0, 500);
                DebugLogger::error("API returned an error or refusal message", ['content' => $errorDetails]);
                
                $errorMessage = "API returned an error or refusal message: " . substr($content, 0, 200);
                if (strpos($content, '顔') !== false || strpos($content, 'face') !== false) {
                    $errorMessage .= "\n\n考えられる原因: 画像に顔や人物が含まれている可能性があります。";
                } elseif (strpos($content, '分析') !== false || strpos($content, 'analyze') !== false) {
                    $errorMessage .= "\n\n考えられる原因: 画像の内容がOpenAI APIの利用規約に抵触している可能性があります。";
                }
                
                throw new Exception($errorMessage);
            }
        }

        if (empty($content)) {
            DebugLogger::error("Empty prompt generated from API response");
            throw new Exception("Empty prompt generated from API response");
        }

        DebugLogger::endTimer("refinePromptWithHistory", $startTime);
        return $content;
    }

    /**
     * プロンプトから画像を生成
     * 
     * @param string $prompt プロンプト
     * @param string $outputPath 保存先パス
     * @return string 生成された画像のパス
     */
    public function generateImage($prompt, $outputPath) {
        $startTime = microtime(true);
        DebugLogger::info("Generating image", [
            'model' => $this->imageModel,
            'output_path' => $outputPath,
            'prompt_preview' => substr($prompt, 0, 100)
        ]);
        
        // リクエストデータの基本設定
        $requestData = [
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024'
        ];
        
        // モデル名に応じてパラメータを調整
        // gpt-image-1, gpt-5.1 などのカスタムモデルでは model パラメータが必要
        // DALL-E 2 では response_format をサポート
        // DALL-E 3 では model パラメータと response_format パラメータは不要
        
        $customModels = ['gpt-image-1', 'gpt-5.1'];
        $isCustomModel = in_array($this->imageModel, $customModels);
        
        if ($isCustomModel) {
            // カスタムモデル（gpt-image-1, gpt-5.1など）の場合は model パラメータを追加
            $requestData['model'] = $this->imageModel;
            // response_format は不要（デフォルトでURLを返す）
            DebugLogger::debug("Using custom model", ['model' => $this->imageModel]);
        } elseif ($this->imageModel === 'dall-e-2') {
            // DALL-E 2 の場合は response_format を追加
            $requestData['response_format'] = 'url';
            // model パラメータは不要
            DebugLogger::debug("Using DALL-E 2", ['response_format' => 'url']);
        }
        // DALL-E 3 の場合は model パラメータも response_format も不要（デフォルト設定）
        
        $url = rtrim($this->apiBase, '/') . '/images/generations';
        DebugLogger::apiRequest('POST', $url, $requestData);
        
        $response = $this->makeRequest('images/generations', $requestData, 'POST');
        
        DebugLogger::apiResponse($url, $response);

        // レスポンス構造を確認（複数の形式に対応）
        $imageUrl = null;
        $imageData = null;
        
        // パターン1: 標準形式 (data[0].url)
        if (isset($response['data'][0]['url'])) {
            $imageUrl = $response['data'][0]['url'];
            DebugLogger::debug("Image URL found in data[0].url", ['url' => $imageUrl]);
        }
        // パターン2: 直接 url が返される場合
        elseif (isset($response['url'])) {
            $imageUrl = $response['url'];
            DebugLogger::debug("Image URL found in response.url", ['url' => $imageUrl]);
        }
        // パターン3: data 配列の最初の要素に直接 url がある場合
        elseif (isset($response['data']) && is_array($response['data']) && isset($response['data'][0])) {
            $firstItem = $response['data'][0];
            if (isset($firstItem['url'])) {
                $imageUrl = $firstItem['url'];
                DebugLogger::debug("Image URL found in data array", ['url' => $imageUrl]);
            }
            // パターン4: base64エンコードされた画像データが返される場合
            elseif (isset($firstItem['b64_json'])) {
                $imageData = base64_decode($firstItem['b64_json']);
                DebugLogger::debug("Base64 image data found in data[0].b64_json", ['size' => strlen($imageData)]);
            }
        }
        // パターン5: 直接 base64 データが返される場合
        elseif (isset($response['b64_json'])) {
            $imageData = base64_decode($response['b64_json']);
            DebugLogger::debug("Base64 image data found in response.b64_json", ['size' => strlen($imageData)]);
        }

        // URL から画像をダウンロード
        if ($imageUrl && !$imageData) {
            DebugLogger::debug("Downloading image from URL", ['url' => $imageUrl]);
            $imageData = file_get_contents($imageUrl);
            if ($imageData === false) {
                DebugLogger::error("Failed to download generated image", ['url' => $imageUrl]);
                throw new Exception('Failed to download generated image');
            }
        }

        // 画像データが取得できなかった場合
        if (!$imageData) {
            DebugLogger::error("Failed to generate image: no URL or image data returned", [
                'response_structure' => array_keys($response),
                'response_sample' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
            throw new Exception('Failed to generate image: no URL or image data returned. Response structure: ' . json_encode(array_keys($response)));
        }

        // 画像を保存
        file_put_contents($outputPath, $imageData);
        DebugLogger::info("Image saved", [
            'output_path' => $outputPath,
            'image_size' => strlen($imageData),
            'source' => $imageUrl ? 'url' : 'base64'
        ]);
        
        DebugLogger::endTimer("generateImage", $startTime);
        return $outputPath;
    }

    /**
     * テキストの埋め込みベクトルを取得
     * 
     * @param string $text テキスト
     * @return array 埋め込みベクトル
     */
    public function getTextEmbedding($text) {
        $startTime = microtime(true);
        DebugLogger::debug("Getting text embedding", [
            'model' => $this->embeddingModel,
            'text_length' => strlen($text)
        ]);
        
        $requestData = [
            'model' => $this->embeddingModel,
            'input' => $text
        ];
        
        $url = rtrim($this->apiBase, '/') . '/embeddings';
        DebugLogger::apiRequest('POST', $url, $requestData);
        
        $response = $this->makeRequest('embeddings', $requestData, 'POST');
        
        DebugLogger::apiResponse($url, $response);
        
        $embedding = $response['data'][0]['embedding'] ?? [];
        DebugLogger::debug("Text embedding received", [
            'embedding_dimension' => count($embedding)
        ]);
        
        DebugLogger::endTimer("getTextEmbedding", $startTime);
        return $embedding;
    }

    /**
     * 画像の埋め込みベクトルを取得（CLIPベース）
     * 注意: OpenAI API では画像埋め込みを直接取得できないため、
     * 画像を説明するテキストを生成してから埋め込みを取得する方法を使用
     * 
     * @param string $imagePath 画像パス
     * @return array 埋め込みベクトル
     */
    public function getImageEmbedding($imagePath) {
        $startTime = microtime(true);
        DebugLogger::info("Getting image embedding", ['image_path' => $imagePath]);
        
        // 画像を説明するテキストを生成
        $imageData = file_get_contents($imagePath);
        if ($imageData === false) {
            DebugLogger::error("Failed to read image file", ['path' => $imagePath]);
            throw new Exception("Failed to read image file: {$imagePath}");
        }
        
        $base64Image = base64_encode($imageData);
        $mimeType = mime_content_type($imagePath);

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'あなたは画像分析と画像生成のためのプロンプト作成の専門家です。提供された画像を詳しく分析し、その画像に似た画像を生成するための詳細なプロンプトを日本語で作成してください。プロンプトには、スタイル、色、構図、被写体の特徴、雰囲気、等身，髪型，その他の関連する詳細を含めてください。プロンプトテキストのみを返答し、説明や追加のテキストは含めないでください。なお，画像は gpt-image-1 によって生成された画像です．
                              例：超リアルな女性のポートレートを生成してください。
                                  髪型は鮮やかで明るいネオンオレンジカラーのあご丈ボブで、前髪は眉の上でまっすぐ切り揃えられている。
                                  彼女は左側を向き、力強く決意に満ちた表情をしている。
                                  服装は彩度の高い緑のタートルネックで、明るい黄色のアクセントと、胸に特徴的な丸いロゴがある。
                                  左の拳を前方に強く握りしめ、意欲とモチベーションを示している。
                                  背景は深い紫色で、明るくはっきりとした斜めの白いレンズフレアが入り、コントラストの強いライティングになっている。'
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$base64Image}"
                        ]
                    ]
                ]
            ]
        ];

        $requestData = [
            'model' => 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => 300,
            'temperature' => 0.3
        ];
        
        $url = rtrim($this->apiBase, '/') . '/chat/completions';
        DebugLogger::apiRequest('POST', $url, $requestData);
        
        $response = $this->makeRequest('chat/completions', $requestData);
        
        DebugLogger::apiResponse($url, $response);

        $description = trim($response['choices'][0]['message']['content'] ?? '');
        
        DebugLogger::debug("Image description generated", [
            'description_length' => strlen($description),
            'description_preview' => substr($description, 0, 100)
        ]);
        
        // 説明テキストから埋め込みを取得
        $embedding = $this->getTextEmbedding($description);
        
        DebugLogger::endTimer("getImageEmbedding", $startTime);
        return $embedding;
    }

    /**
     * API リクエストを送信
     * 
     * @param string $endpoint API エンドポイント
     * @param array $data リクエストデータ
     * @param string $method HTTP メソッド
     * @return array レスポンス
     */
    private function makeRequest($endpoint, $data, $method = 'POST') {
        $url = rtrim($this->apiBase, '/') . '/' . ltrim($endpoint, '/');
        
        // cURL が利用可能な場合は cURL を使用、そうでない場合は file_get_contents を使用
        if (function_exists('curl_init')) {
            return $this->makeRequestWithCurl($url, $data, $method);
        } else {
            return $this->makeRequestWithFileGetContents($url, $data, $method);
        }
    }

    /**
     * cURL を使用して API リクエストを送信
     * 
     * @param string $url API URL
     * @param array $data リクエストデータ
     * @param string $method HTTP メソッド
     * @return array レスポンス
     */
    private function makeRequestWithCurl($url, $data, $method) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => ($method === 'POST'),
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => 120
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            DebugLogger::error("cURL error", ['error' => $error, 'url' => $url]);
            throw new Exception("cURL error: {$error}");
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['error']['message'] ?? "HTTP {$httpCode}";
            DebugLogger::error("OpenAI API error", [
                'http_code' => $httpCode,
                'error_message' => $errorMsg,
                'url' => $url
            ]);
            throw new Exception("OpenAI API error: {$errorMsg}");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            DebugLogger::error("JSON decode error", [
                'error' => json_last_error_msg(),
                'url' => $url
            ]);
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * file_get_contents を使用して API リクエストを送信（cURL が利用できない場合のフォールバック）
     * 
     * @param string $url API URL
     * @param array $data リクエストデータ
     * @param string $method HTTP メソッド
     * @return array レスポンス
     */
    private function makeRequestWithFileGetContents($url, $data, $method) {
        $jsonData = json_encode($data);
        
        $options = [
            'http' => [
                'method' => $method,
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Length: ' . strlen($jsonData)
                ],
                'content' => $jsonData,
                'timeout' => 120,
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new Exception("HTTP request failed: " . ($error['message'] ?? 'Unknown error'));
        }

        // HTTP ステータスコードを取得
        if (isset($http_response_header) && !empty($http_response_header)) {
            $statusLine = $http_response_header[0];
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches);
            $httpCode = isset($matches[1]) ? (int)$matches[1] : 200;
        } else {
            $httpCode = 200;
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['error']['message'] ?? "HTTP {$httpCode}";
            throw new Exception("OpenAI API error: {$errorMsg}");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }
}

