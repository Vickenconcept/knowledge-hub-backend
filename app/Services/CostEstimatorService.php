<?php

namespace App\Services;

class CostEstimatorService
{
    // Defaults; adjust from env if needed
    protected float $embeddingCostPer1K = 0.00002; // example for small embedding
    protected float $chatInputCostPer1K = 0.0005;  // placeholder
    protected float $chatOutputCostPer1K = 0.0015; // placeholder

    public function __construct()
    {
        $this->embeddingCostPer1K = (float) env('COST_EMBED_PER_1K', $this->embeddingCostPer1K);
        $this->chatInputCostPer1K = (float) env('COST_CHAT_IN_PER_1K', $this->chatInputCostPer1K);
        $this->chatOutputCostPer1K = (float) env('COST_CHAT_OUT_PER_1K', $this->chatOutputCostPer1K);
    }

    public function estimateEmbeddingsCost(int $tokens): float
    {
        return ($tokens / 1000) * $this->embeddingCostPer1K;
    }

    public function estimateChatCost(int $inputTokens, int $outputTokens): float
    {
        return ($inputTokens / 1000) * $this->chatInputCostPer1K + ($outputTokens / 1000) * $this->chatOutputCostPer1K;
    }
}


