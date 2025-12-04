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

    public function __construct($openaiClient, $similarityCalculator, $config = []) {
        $this->openaiClient = $openaiClient;
        $this->similarityCalculator = $similarityCalculator;
        $this->maxIterations = $config['max_iterations'] ?? 5;
        $this->similarityThreshold = $config['similarity_threshold'] ?? 0.85;
        $this->outputDir = $config['output_dir'] ?? __DIR__ . '/../public/generated';
        
        // 出力ディレクトリを作成
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0777, true);
        }
    }

    /**
     * PRISM アルゴリズムを実行
     * 
     * @param string $referenceImagePath 参照画像のパス
     * @return array 実行結果（プロンプト、類似度スコア、反復履歴など）
     */
    public function execute($referenceImagePath) {
        $startTime = microtime(true);
        DebugLogger::info("PRISM execution started", [
            'reference_image' => $referenceImagePath,
            'max_iterations' => $this->maxIterations,
            'similarity_threshold' => $this->similarityThreshold
        ]);
        
        $history = [];
        $bestPrompt = null;
        $bestSimilarity = 0.0;
        $bestImagePath = null;

        // ステップ1: 初期プロンプトの生成
        try {
            DebugLogger::startTimer("generateInitialPrompt");
            $currentPrompt = $this->openaiClient->generateInitialPrompt($referenceImagePath);
            DebugLogger::endTimer("generateInitialPrompt", microtime(true));
            
            // 初期プロンプトが空またはエラーメッセージの場合は例外を投げる
            if (empty($currentPrompt)) {
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
                if (preg_match($pattern, $currentPrompt)) {
                    throw new Exception("Initial prompt generation was refused by API: " . substr($currentPrompt, 0, 200));
                }
            }
            
            $history[] = [
                'iteration' => 0,
                'prompt' => $currentPrompt,
                'type' => 'initial'
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

        // ステップ2-4: 反復的なプロンプト改良
        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            $iterationStartTime = microtime(true);
            DebugLogger::info("Starting iteration", ['iteration' => $iteration]);
            
            try {
                // 2. 現在のプロンプトで画像を生成
                $generatedImagePath = $this->outputDir . '/generated_' . uniqid() . '.png';
                DebugLogger::debug("Generating image for iteration", [
                    'iteration' => $iteration,
                    'output_path' => $generatedImagePath
                ]);
                
                $this->openaiClient->generateImage($currentPrompt, $generatedImagePath);

                // 3. 生成画像と参照画像の類似度を評価
                DebugLogger::debug("Calculating similarity", ['iteration' => $iteration]);
                $similarity = $this->similarityCalculator->calculateSimilarity(
                    $referenceImagePath,
                    $generatedImagePath
                );
                
                DebugLogger::info("Similarity calculated", [
                    'iteration' => $iteration,
                    'similarity' => $similarity,
                    'threshold' => $this->similarityThreshold
                ]);

                // 履歴に記録
                $history[] = [
                    'iteration' => $iteration,
                    'prompt' => $currentPrompt,
                    'similarity' => $similarity,
                    'image_path' => $generatedImagePath,
                    'type' => 'refined'
                ];

                // 最良の結果を更新
                if ($similarity > $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $bestPrompt = $currentPrompt;
                    $bestImagePath = $generatedImagePath;
                    DebugLogger::info("New best result", [
                        'iteration' => $iteration,
                        'similarity' => $similarity
                    ]);
                }

                // 閾値を超えた場合は早期終了
                if ($similarity >= $this->similarityThreshold) {
                    DebugLogger::info("Similarity threshold reached", [
                        'iteration' => $iteration,
                        'similarity' => $similarity,
                        'threshold' => $this->similarityThreshold
                    ]);
                    break;
                }

                // 4. プロンプトを改良（最後の反復でない場合）
                if ($iteration < $this->maxIterations) {
                    DebugLogger::debug("Refining prompt for next iteration", ['iteration' => $iteration]);
                    $currentPrompt = $this->openaiClient->refinePrompt(
                        $currentPrompt,
                        $referenceImagePath,
                        $generatedImagePath,
                        $similarity,
                        $iteration
                    );
                }

                DebugLogger::endTimer("iteration_{$iteration}", $iterationStartTime);

            } catch (Exception $e) {
                DebugLogger::error("Error in iteration", [
                    'iteration' => $iteration,
                    'error' => $e->getMessage()
                ]);
                
                // エラーが発生した場合は履歴に記録して続行
                $history[] = [
                    'iteration' => $iteration,
                    'error' => $e->getMessage(),
                    'type' => 'error'
                ];
                
                // エラーが続く場合は中断
                if ($iteration > 1) {
                    DebugLogger::warning("Multiple errors occurred, stopping iterations");
                    break;
                }
            }
        }

        $result = [
            'best_prompt' => $bestPrompt ?? $currentPrompt,
            'best_similarity' => $bestSimilarity,
            'best_image_path' => $bestImagePath,
            'history' => $history,
            'total_iterations' => count($history) - 1 // 初期プロンプトを除く
        ];
        
        DebugLogger::info("PRISM execution completed", [
            'total_iterations' => $result['total_iterations'],
            'best_similarity' => $bestSimilarity,
            'threshold_reached' => $bestSimilarity >= $this->similarityThreshold
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
    }
}

