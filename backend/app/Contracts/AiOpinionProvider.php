<?php

namespace App\Contracts;

/**
 * An LLM backend that turns an already-built analyst prompt into a
 * structured buy/hold/sell opinion. AiStockOpinionService owns WHAT is
 * asked (context + prompt); an implementation only owns HOW it's asked —
 * which is the part that has changed three times so far (Gemini, Claude,
 * Groq), so swapping providers is now a new class plus one binding in
 * AppServiceProvider, not an edit to the service.
 */
interface AiOpinionProvider
{
    /** False when the provider has no credentials set — callers treat that as "feature off", not an error. */
    public function isConfigured(): bool;

    /**
     * Throws on any failure (HTTP error, unexpected response shape).
     *
     * @return array{verdict: string, confidence: string, reasoning: string}
     */
    public function requestOpinion(string $prompt): array;
}
