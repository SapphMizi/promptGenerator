<?php

require_once __DIR__ . '/OpenAIClient.php';
require_once __DIR__ . '/ImageSimilarity.php';
require_once __DIR__ . '/DebugLogger.php';

/**
 * PRISM (Prompt Refinement via Iterative Search with Multimodal feedback) エンジン
 * 反復的なプロンプト更新により、参照画像に近い画像を生成するプロンプトを探索
 */
class PrismEngine {
    private $openaiClient;
    private $similarityCalculator;
    private $maxIterations;
    private $similarityThreshold;
    private $outputDir;
    private $parallelCount;

    public function __construct($openaiClient, $similarityCalculator, $config = []) {
        $this->openaiClient = $openaiClient;
        $this->similarityCalculator = $similarityCalculator;
        $this->maxIterations = $config['max_iterations'] ?? 5;
        $this->similarityThreshold = $config['similarity_threshold'] ?? 0.85;
        $this->outputDir = $config['output_dir'] ?? __DIR__ . '/../public/generated';
        $this->parallelCount = $config['parallel_count'] ?? 1; // デフォルトは1（後方互換性）
        
        // 並列数の検証
        if ($this->parallelCount < 1) {
            $this->parallelCount = 1;
            DebugLogger::warning("parallel_count must be at least 1, using 1");
        }
        
        // 出力ディレクトリを作成
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0777, true);
        }
    }

    /**
     * PRISM アルゴリズムを実行（並列探索対応、論文のAlgorithm 1に準拠）
     * 
     * @param string|array $referenceImagePath 参照画像のパス（単一画像または配列）
     * @return array 実行結果（プロンプト、類似度スコア、反復履歴など）
     */
    public function execute($referenceImagePath) {
        $startTime = microtime(true);
        
        // 参照画像を配列に正規化（将来的な複数画像対応のため）
        $referenceImages = is_array($referenceImagePath) ? $referenceImagePath : [$referenceImagePath];
        
        DebugLogger::info("PRISM execution started", [
            'reference_images' => $referenceImages,
            'max_iterations' => $this->maxIterations,
            'similarity_threshold' => $this->similarityThreshold,
            'parallel_count' => $this->parallelCount
        ]);
        
        $history = [];
        $bestPrompt = null;
        $bestSimilarity = 0.0;
        $bestImagePath = null;
        
        // 各ストリームのchat historyを保持（論文のAlgorithm 1 Line 3: for n = 1 to N in parallel）
        $streamHistories = [];

        // ステップ1: 初期プロンプトの生成
        try {
            DebugLogger::startTimer("generateInitialPrompt");
            $initialPrompt = $this->openaiClient->generateInitialPrompt($referenceImagePath);
            DebugLogger::endTimer("generateInitialPrompt", microtime(true));
            
            // 初期プロンプトが空またはエラーメッセージの場合は例外を投げる
            if (empty($initialPrompt)) {
                throw new Exception("Failed to generate initial prompt: empty response");
            }
            
            // エラーメッセージパターンをチェック（英語と日本語の両方）
            $errorPatterns = [
                // 英語のパターン
                "/I'm sorry/i",
                "/I can't help/i",
                "/I cannot/i",
                "/I am not able/i",
                "/I don't have/i",
                "/unable to/i",
                "/cannot assist/i",
                "/I'm unable/i",
                "/I cannot provide/i",
                "/I cannot analyze/i",
                "/I cannot create/i",
                // 日本語のパターン
                "/申し訳ありません/i",
                "/申し訳ございません/i",
                "/できません/i",
                "/分析.*できません/i",
                "/詳しい分析.*できません/i",
                "/顔が含まれています/i",
                "/画像には顔が含まれています/i",
                "/その画像には/i",
                "/ご提供いただいた画像/i",
                "/お手伝いできません/i",
                "/お役に立てません/i"
            ];
            
            foreach ($errorPatterns as $pattern) {
                if (preg_match($pattern, $initialPrompt)) {
                    throw new Exception("Initial prompt generation was refused by API: " . substr($initialPrompt, 0, 200));
                }
            }
            
            // 各ストリーム用に初期プロンプトからN個のバリエーションを生成
            // 論文では各ストリームが独立した履歴を持つため、各ストリーム用に初期化
            $streamPrompts = [];
            $streamResults = [];
            
            // 初期プロンプトから各ストリームの初期プロンプトを生成
            for ($n = 0; $n < $this->parallelCount; $n++) {
                // 最初の参照画像を使用（論文では各反復でランダムサンプリングするが、単一画像の場合は同じものを使用）
                $refImageForStream = $referenceImages[0];
                $streamInitialPrompt = ($n === 0) ? $initialPrompt : $initialPrompt; // 現状は同じ初期プロンプトを使用
                
                $streamPrompts[$n] = $streamInitialPrompt;
                $streamHistories[$n] = []; // 各ストリームの独立したchat history
                
                // 初期プロンプトを履歴に追加
                $streamHistories[$n][] = [
                    'role' => 'system',
                    'content' => 'あなたは画像生成プロンプトを改良する専門家です。参照画像により近い画像を生成するためにプロンプトを改善することが目標です。'
                ];
            }
            
            $history[] = [
                'iteration' => 0,
                'prompt' => $initialPrompt,
                'prompts' => $streamPrompts,
                'type' => 'initial',
                'parallel_count' => $this->parallelCount
            ];
        } catch (Exception $e) {
            // 初期プロンプト生成に失敗した場合はエラーを返す
            $history[] = [
                'iteration' => 0,
                'error' => $e->getMessage(),
                'type' => 'error'
            ];
            throw new Exception("Failed to generate initial prompt: " . $e->getMessage());
        }

        // ステップ2-4: 反復的なプロンプト改良（並列探索、論文のAlgorithm 1 Lines 3-11）
        // Line 3: for n = 1 to N in parallel do
        // Line 4: for k = 1 to K do
        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            $iterationStartTime = microtime(true);
            DebugLogger::info("Starting iteration", [
                'iteration' => $iteration,
                'parallel_count' => $this->parallelCount
            ]);
            
            // 各ストリーム（n）を並列に処理
            $streamIterationResults = [];
            $allErrors = [];
            
            foreach ($streamPrompts as $streamIndex => $streamPrompt) {
                try {
                    // 論文 Algorithm 1 Line 5: Randomly sample an x_k,n from {x_i}_{i=1}^M
                    // 単一参照画像の場合は常に同じものを使用、複数画像の場合はランダムサンプリング
                    $sampledRefImage = $referenceImages[count($referenceImages) > 1 ? array_rand($referenceImages) : 0];
                    
                    // 論文 Algorithm 1 Line 6: F samples y_k,n ~ p_θ_F (y | x_k,n)
                    // 初期反復では既にプロンプトがあるので、次の反復で改良される
                    
                    // 論文 Algorithm 1 Line 7: G samples x̂_k,n ~ p_θ_G (x | y_k,n)
                    $generatedImagePath = $this->outputDir . '/generated_iter' . $iteration . '_stream' . $streamIndex . '_' . uniqid() . '.png';
                    DebugLogger::debug("Generating image for stream", [
                        'iteration' => $iteration,
                        'stream' => $streamIndex,
                        'output_path' => $generatedImagePath
                    ]);
                    
                    $this->openaiClient->generateImage($streamPrompt, $generatedImagePath);

                    // 論文 Algorithm 1 Line 8: D calculates an in-iteration score s'(x_k,n, y_k,n) = D(x_k,n, x̂_k,n)
                    DebugLogger::debug("Calculating in-iteration similarity", [
                        'iteration' => $iteration,
                        'stream' => $streamIndex
                    ]);
                    $inIterationScore = $this->similarityCalculator->calculateSimilarity(
                        $sampledRefImage,
                        $generatedImagePath
                    );
                    
                    DebugLogger::info("In-iteration similarity calculated", [
                        'iteration' => $iteration,
                        'stream' => $streamIndex,
                        'in_iteration_score' => $inIterationScore,
                        'threshold' => $this->similarityThreshold
                    ]);

                    // ストリームの結果を記録
                    $streamIterationResults[$streamIndex] = [
                        'prompt' => $streamPrompt,
                        'in_iteration_score' => $inIterationScore,
                        'image_path' => $generatedImagePath,
                        'reference_image' => $sampledRefImage,
                        'stream' => $streamIndex
                    ];

                    // 最良の結果を更新（in-iteration scoreで追跡）
                    if ($inIterationScore > $bestSimilarity) {
                        $bestSimilarity = $inIterationScore;
                        $bestPrompt = $streamPrompt;
                        $bestImagePath = $generatedImagePath;
                        DebugLogger::info("New best result (in-iteration)", [
                            'iteration' => $iteration,
                            'stream' => $streamIndex,
                            'score' => $inIterationScore
                        ]);
                    }
                    
                    // 論文 Algorithm 1 Line 9: Update p_θ_F based on x_k,n, x̂_k,n, y_k,n, s'(x_k,n, y_k,n) and the chat history of stream n
                    // chat historyを更新（次の反復で使用）
                    $streamHistories[$streamIndex][] = [
                        'prompt' => $streamPrompt,
                        'generated_image' => $generatedImagePath,
                        'reference_image' => $sampledRefImage,
                        'score' => $inIterationScore,
                        'iteration' => $iteration
                    ];

                } catch (Exception $e) {
                    DebugLogger::error("Error processing stream", [
                        'iteration' => $iteration,
                        'stream' => $streamIndex,
                        'error' => $e->getMessage()
                    ]);
                    $allErrors[] = [
                        'stream' => $streamIndex,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // 履歴に記録
            $history[] = [
                'iteration' => $iteration,
                'results' => array_values($streamIterationResults),
                'errors' => $allErrors,
                'type' => 'refined',
                'parallel_count' => $this->parallelCount
            ];

            // 閾値を超えた場合は早期終了
            if ($bestSimilarity >= $this->similarityThreshold) {
                DebugLogger::info("Similarity threshold reached", [
                    'iteration' => $iteration,
                    'similarity' => $bestSimilarity,
                    'threshold' => $this->similarityThreshold
                ]);
                break;
            }
            
            // エラーが多すぎる場合は中断
            if (count($allErrors) >= $this->parallelCount) {
                DebugLogger::warning("All streams failed in iteration, stopping");
                break;
            }

            // 4. 次の反復のためにプロンプトを改良（最後の反復でない場合）
            // 論文 Algorithm 1 Line 9: Update p_θ_F based on chat history of stream n
            if ($iteration < $this->maxIterations) {
                DebugLogger::debug("Refining prompts for next iteration", ['iteration' => $iteration]);
                
                // 各ストリームのプロンプトをchat historyに基づいて改良
                $nextStreamPrompts = [];
                foreach ($streamIterationResults as $streamIndex => $result) {
                    try {
                        // chat historyを使用してプロンプトを改良
                        $refinedPrompt = $this->openaiClient->refinePromptWithHistory(
                            $result['prompt'],
                            $result['reference_image'],
                            $result['image_path'],
                            $result['in_iteration_score'],
                            $iteration,
                            $streamHistories[$streamIndex] // ストリームの独立した履歴を渡す
                        );
                        $nextStreamPrompts[$streamIndex] = $refinedPrompt;
                    } catch (Exception $e) {
                        DebugLogger::warning("Failed to refine prompt with history, trying without history", [
                            'stream' => $streamIndex,
                            'error' => $e->getMessage()
                        ]);
                        // 履歴なしでリトライ
                        try {
                            $refinedPrompt = $this->openaiClient->refinePrompt(
                                $result['prompt'],
                                $result['reference_image'],
                                $result['image_path'],
                                $result['in_iteration_score'],
                                $iteration
                            );
                            $nextStreamPrompts[$streamIndex] = $refinedPrompt;
                        } catch (Exception $e2) {
                            DebugLogger::warning("Failed to refine prompt, using original", [
                                'stream' => $streamIndex,
                                'error' => $e2->getMessage()
                            ]);
                            // 改良に失敗した場合は元のプロンプトを使用
                            $nextStreamPrompts[$streamIndex] = $result['prompt'];
                        }
                    }
                }
                
                $streamPrompts = $nextStreamPrompts;
            }

            DebugLogger::endTimer("iteration_{$iteration}", $iterationStartTime);
        }
        
        // 論文 Algorithm 1 Lines 12-14: 最終評価フェーズ
        // Line 12: Collect the subset {y_c}_{c=1}^C with the C-best in-iteration scores
        // 全反復から最良のC個のプロンプトを収集
        $allCandidates = [];
        foreach ($history as $histItem) {
            if (isset($histItem['results']) && is_array($histItem['results'])) {
                foreach ($histItem['results'] as $result) {
                    $allCandidates[] = [
                        'prompt' => $result['prompt'],
                        'in_iteration_score' => $result['in_iteration_score'] ?? 0,
                        'image_path' => $result['image_path'] ?? null,
                        'iteration' => $histItem['iteration']
                    ];
                }
            }
        }
        
        // 最良のC個を選択（C = parallel_countまたは最大10個）
        $cBest = min($this->parallelCount * 2, 10, count($allCandidates));
        usort($allCandidates, function($a, $b) {
            return ($b['in_iteration_score'] ?? 0) <=> ($a['in_iteration_score'] ?? 0);
        });
        $topCandidates = array_slice($allCandidates, 0, $cBest);
        
        DebugLogger::info("Final evaluation phase", [
            'candidates_collected' => count($allCandidates),
            'top_c' => $cBest
        ]);
        
        // Line 13: Re-evaluate this subset with total score Σ_{i=1}^M s(x_i, y_c)
        // 各候補プロンプトを全参照画像で再評価してtotal scoreを計算
        $finalEvaluations = [];
        foreach ($topCandidates as $candidate) {
            $totalScore = 0.0;
            $totalEvaluations = [];
            
            foreach ($referenceImages as $refImage) {
                try {
                    // プロンプトから画像を再生成（既存の画像がある場合はそれを使用）
                    if ($candidate['image_path'] && file_exists($candidate['image_path'])) {
                        $evalImagePath = $candidate['image_path'];
                    } else {
                        // 再生成が必要な場合
                        $evalImagePath = $this->outputDir . '/final_eval_' . uniqid() . '.png';
                        $this->openaiClient->generateImage($candidate['prompt'], $evalImagePath);
                    }
                    
                    $score = $this->similarityCalculator->calculateSimilarity($refImage, $evalImagePath);
                    $totalScore += $score;
                    $totalEvaluations[] = [
                        'reference_image' => $refImage,
                        'score' => $score
                    ];
                } catch (Exception $e) {
                    DebugLogger::warning("Error in final evaluation", [
                        'prompt_preview' => substr($candidate['prompt'], 0, 50),
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $finalEvaluations[] = [
                'prompt' => $candidate['prompt'],
                'total_score' => $totalScore,
                'average_score' => count($referenceImages) > 0 ? $totalScore / count($referenceImages) : 0,
                'individual_scores' => $totalEvaluations,
                'image_path' => $candidate['image_path'],
                'iteration' => $candidate['iteration']
            ];
        }
        
        // Line 14: Return the prompt with the best total score
        usort($finalEvaluations, function($a, $b) {
            return $b['total_score'] <=> $a['total_score'];
        });
        
        $finalBest = $finalEvaluations[0] ?? null;
        if ($finalBest) {
            DebugLogger::info("Final best prompt selected", [
                'total_score' => $finalBest['total_score'],
                'average_score' => $finalBest['average_score'],
                'iteration' => $finalBest['iteration']
            ]);
            
            // 最終結果を更新
            $bestPrompt = $finalBest['prompt'];
            $bestSimilarity = $finalBest['average_score']; // average scoreを使用
            $bestImagePath = $finalBest['image_path'];
        }

        $result = [
            'best_prompt' => $bestPrompt ?? '',
            'best_similarity' => $bestSimilarity,
            'best_image_path' => $bestImagePath,
            'history' => $history,
            'total_iterations' => count($history) - 1, // 初期プロンプトを除く
            'parallel_count' => $this->parallelCount,
            'final_evaluations' => $finalEvaluations ?? [] // 論文の最終評価結果
        ];
        
        DebugLogger::info("PRISM execution completed", [
            'total_iterations' => $result['total_iterations'],
            'best_similarity' => $bestSimilarity,
            'threshold_reached' => $bestSimilarity >= $this->similarityThreshold,
            'parallel_count' => $this->parallelCount
        ]);
        
        DebugLogger::endTimer("PRISM_execution", $startTime);
        
        return $result;
    }
    

    /**
     * 設定を更新
     * 
     * @param array $config 設定配列
     */
    public function updateConfig($config) {
        if (isset($config['max_iterations'])) {
            $this->maxIterations = $config['max_iterations'];
        }
        if (isset($config['similarity_threshold'])) {
            $this->similarityThreshold = $config['similarity_threshold'];
        }
        if (isset($config['output_dir'])) {
            $this->outputDir = $config['output_dir'];
            if (!is_dir($this->outputDir)) {
                mkdir($this->outputDir, 0777, true);
            }
        }
        if (isset($config['parallel_count'])) {
            $parallelCount = $config['parallel_count'];
            if ($parallelCount < 1) {
                $parallelCount = 1;
            }
            $this->parallelCount = $parallelCount;
        }
    }
}

