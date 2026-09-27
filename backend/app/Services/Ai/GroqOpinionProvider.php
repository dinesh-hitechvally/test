<?php

namespace App\Services\Ai;

use App\Contracts\AiOpinionProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Groq's free-tier API — open-weight models (Llama, etc.) behind an
 * OpenAI-compatible chat-completions endpoint, with no billing setup but
 * real per-minute rate limits (see GenerateAiOpinionsJob for how a 429 is
 * handled).
 */
class GroqOpinionProvider implements AiOpinionProvider
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    public function isConfigured(): bool
    {
        return filled(config('services.groq.api_key'));
    }

    /**
     * Uses forced tool-calling (OpenAI-compatible function-calling, not
     * just "ask for JSON in the prompt") for reliable structured output —
     * same reasoning Gemini's responseSchema / Claude's tool_choice served
     * here with the earlier providers.
     */
    public function requestOpinion(string $prompt): array
    {
        $response = Http::timeout(40)
            ->withToken(config('services.groq.api_key'))
            // Transient overload/rate-limit responses are retried a
            // couple of times with a short delay before giving up.
            ->retry(2, 1500, throw: false)
            ->post(self::ENDPOINT, [
                'model' => config('services.groq.model'),
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'record_opinion',
                        'description' => 'Record the buy/hold/sell opinion for this stock.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'verdict' => ['type' => 'string', 'enum' => ['buy', 'hold', 'sell']],
                                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                                'reasoning' => ['type' => 'string'],
                            ],
                            'required' => ['verdict', 'confidence', 'reasoning'],
                        ],
                    ],
                ]],
                'tool_choice' => ['type' => 'function', 'function' => ['name' => 'record_opinion']],
            ]);

        $response->throw();

        // OpenAI-compatible shape: the tool call's arguments come back as a
        // JSON *string*, not a nested object — needs its own decode.
        $arguments = $response->json('choices.0.message.tool_calls.0.function.arguments');
        $parsed = json_decode((string) $arguments, true);

        if (! is_array($parsed) || ! isset($parsed['verdict'], $parsed['confidence'], $parsed['reasoning'])) {
            throw new RuntimeException('Groq returned an unexpected response shape.');
        }

        return [
            'verdict' => $parsed['verdict'],
            'confidence' => $parsed['confidence'],
            'reasoning' => $parsed['reasoning'],
        ];
    }
}
