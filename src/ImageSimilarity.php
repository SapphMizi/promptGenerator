<?php

/**
 * 画像類似度評価クラス
 * 埋め込みベクトル間のコサイン類似度を計算
 */
class ImageSimilarity {
    private $openaiClient;

    public function __construct($openaiClient) {
        $this->openaiClient = $openaiClient;
    }

    /**
     * 2つの画像間の類似度を計算
     * 
     * @param string $imagePath1 参照画像のパス
     * @param string $imagePath2 比較対象画像のパス
     * @return float 類似度スコア（0.0 ～ 1.0）
     */
    public function calculateSimilarity($imagePath1, $imagePath2) {
        // 両方の画像の埋め込みベクトルを取得
        $embedding1 = $this->openaiClient->getImageEmbedding($imagePath1);
        $embedding2 = $this->openaiClient->getImageEmbedding($imagePath2);

        if (empty($embedding1) || empty($embedding2)) {
            throw new Exception('Failed to get image embeddings');
        }

        // コサイン類似度を計算
        return $this->cosineSimilarity($embedding1, $embedding2);
    }

    /**
     * コサイン類似度を計算
     * 
     * @param array $vec1 ベクトル1
     * @param array $vec2 ベクトル2
     * @return float コサイン類似度（-1.0 ～ 1.0、通常は 0.0 ～ 1.0）
     */
    private function cosineSimilarity($vec1, $vec2) {
        if (count($vec1) !== count($vec2)) {
            throw new Exception('Vector dimensions do not match');
        }

        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($vec1); $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $magnitude1 += $vec1[$i] * $vec1[$i];
            $magnitude2 += $vec2[$i] * $vec2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0.0;
        }

        $similarity = $dotProduct / ($magnitude1 * $magnitude2);
        
        // コサイン類似度は -1 ～ 1 の範囲だが、埋め込みベクトルの場合は通常 0 ～ 1
        // 負の値の場合は 0 にクリップ
        return max(0.0, $similarity);
    }
}

