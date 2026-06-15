<?php
/**
 * OpenAI Chat Completions client for the LegalPro assistant.
 */

require_once __DIR__ . '/chatbot_context.php';

class ChatbotOpenAI
{
    private PDO $pdo;
    private array $context;
    private string $apiKey;
    private string $model;

    public function __construct(PDO $pdo, array $context, string $apiKey, string $model)
    {
        $this->pdo = $pdo;
        $this->context = $context;
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public static function resolveApiKey(): ?string
    {
        $env = getenv('OPENAI_API_KEY');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        if (function_exists('getSetting')) {
            $stored = trim((string) getSetting('openai_api_key', ''));
            if ($stored !== '') {
                return $stored;
            }
        }

        return null;
    }

    public static function resolveModel(): string
    {
        if (function_exists('getSetting')) {
            $model = trim((string) getSetting('openai_model', ''));
            if ($model !== '') {
                return $model;
            }
        }

        return 'gpt-4o-mini';
    }

    public static function isEnabled(): bool
    {
        if (function_exists('getSetting')) {
            $flag = getSetting('chatbot_ai_enabled', '1');
            if ($flag === '0' || $flag === 0 || $flag === false) {
                return false;
            }
        }

        return self::resolveApiKey() !== null;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    public function chat(string $userMessage, array $history, string $assistantName): array
    {
        $builder = new ChatbotContextBuilder($this->pdo, $this->context);
        $system = $builder->buildSystemPrompt($assistantName);

        $messages = [['role' => 'system', 'content' => $system]];

        foreach ($history as $entry) {
            $role = $entry['role'] ?? '';
            $content = trim((string) ($entry['content'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.45,
            'max_tokens' => 1200,
        ];

        $response = $this->postJson('https://api.openai.com/v1/chat/completions', $payload);
        if ($response === null) {
            return ['ok' => false, 'error' => 'Could not reach the AI service.'];
        }

        if (!empty($response['error'])) {
            $msg = is_array($response['error']) ? ($response['error']['message'] ?? 'AI error') : (string) $response['error'];
            error_log('chatbot openai: ' . $msg);

            return ['ok' => false, 'error' => $msg];
        }

        $reply = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        if ($reply === '') {
            return ['ok' => false, 'error' => 'Empty response from AI.'];
        }

        $tokens = (int) ($response['usage']['total_tokens'] ?? 0);

        return [
            'ok' => true,
            'reply' => $reply,
            'tokens_used' => $tokens > 0 ? $tokens : null,
            'mode' => 'ai',
            'links' => [],
        ];
    }

  /**
   * @return array<string, mixed>|null
   */
    private function postJson(string $url, array $payload): ?array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return null;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $code < 200 || $code >= 300) {
                error_log('chatbot openai http ' . $code . ': ' . substr((string) $raw, 0, 500));

                return is_string($raw) ? (json_decode($raw, true) ?: null) : null;
            }

            $decoded = json_decode((string) $raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$this->apiKey}\r\n",
                'content' => $body,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ];
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
