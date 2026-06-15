<?php
/**
 * Hybrid assistant — local actions + OpenAI for natural language when configured.
 */

require_once __DIR__ . '/chatbot_smart.php';
require_once __DIR__ . '/chatbot_openai.php';
require_once __DIR__ . '/chatbot_client_updates.php';

class ChatbotAI
{
    private PDO $pdo;
    private array $context;
    private ChatbotSmartEngine $engine;
    private const MAX_HISTORY_MESSAGES = 24;
    private const MAX_USER_INPUT_TOKENS = 6000;

    public function __construct(PDO $pdo, array $context)
    {
        $this->pdo = $pdo;
        $this->context = $context;
        $this->engine = new ChatbotSmartEngine($pdo, $context);
    }

    public static function openAiConfigured(): bool
    {
        return ChatbotOpenAI::isEnabled();
    }

    public function chat(string $userMessage): array
    {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            return ['ok' => false, 'reply' => 'Please type a message.', 'links' => []];
        }

        $processed = $this->prepareUserMessage($userMessage);
        $displayMessage = $processed['text'];
        $tokenNote = $processed['token_note'];

        $result = $this->engine->chat($displayMessage, $tokenNote);
        $handler = $result['handler'] ?? 'assistant';

        if (self::openAiConfigured() && $handler === 'assistant' && $this->shouldEnhanceWithAi($result['reply'] ?? '')) {
            $aiResult = $this->callOpenAi($displayMessage);
            if ($aiResult !== null && ($aiResult['ok'] ?? false)) {
                $result['reply'] = $aiResult['reply'] ?? $result['reply'];
                $result['mode'] = 'ai';
                if (!empty($aiResult['tokens_used'])) {
                    $result['tokens_used'] = $aiResult['tokens_used'];
                }
            }
        }

        if (!isset($result['mode'])) {
            $result['mode'] = 'smart';
        }
        if (($result['mode'] ?? '') !== 'ai') {
            $result['mode'] = 'smart';
            $result['tokens_used'] = $this->estimateTokens($displayMessage) + $this->estimateTokens($result['reply'] ?? '');
        }

        unset($result['handler']);

        $this->appendHistory('user', $displayMessage);
        $this->appendHistory('assistant', $result['reply'] ?? '');

        return $result;
    }

    private function shouldEnhanceWithAi(string $reply): bool
    {
        if (strpos($reply, "I'm not sure") !== false) {
            return true;
        }
        if (preg_match('/\b(You have|There are|Upcoming|Recent|Payment summary|cases by status|Hello |Here are things|Got it —|Sounds like|Did you mean|I\'m doing well|You\'re welcome|All good on my end|Yes, I\'m here|Goodbye|What is your new|What would you like to tell|Your update has been posted|Done — your|Done — the)\b/i', $reply)) {
            return false;
        }
        if (preg_match('/\*\*\d+\*\*/', $reply)) {
            return false;
        }

        return true;
    }

    public function clearHistory(): void
    {
        unset($_SESSION[$this->historySessionKey()]);
        ChatbotClientUpdateFlow::clearPending($this->context);
    }

    public function prepareUserMessage(string $text): array
    {
        $tokens = $this->estimateTokens($text);
        $tokenNote = '';

        if ($tokens > self::MAX_USER_INPUT_TOKENS) {
            $text = $this->truncateToTokenBudget($text, self::MAX_USER_INPUT_TOKENS);
            $tokenNote = "\n\n_(Your message was long — about " . number_format($tokens) . " tokens. I focused on the beginning and end.)_";
        }

        return ['text' => $text, 'token_note' => $tokenNote, 'tokens' => $tokens];
    }

    public function estimateTokens(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        return (int) max(1, ceil(mb_strlen($text) / 3.8));
    }

    private function callOpenAi(string $userMessage): ?array
    {
        $apiKey = ChatbotOpenAI::resolveApiKey();
        if ($apiKey === null) {
            return null;
        }

        $assistantName = function_exists('getCompanyName') ? getCompanyName() . ' Assistant' : 'LegalPro Assistant';
        $client = new ChatbotOpenAI($this->pdo, $this->context, $apiKey, ChatbotOpenAI::resolveModel());
        $history = $this->getHistoryForApi();

        try {
            return $client->chat($userMessage, $history, $assistantName);
        } catch (Throwable $e) {
            error_log('chatbot openai call: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function getHistoryForApi(): array
    {
        $key = $this->historySessionKey();
        $raw = $_SESSION[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            $role = $entry['role'] ?? '';
            $content = trim((string) ($entry['content'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }

        if (count($out) > 20) {
            $out = array_slice($out, -20);
        }

        return $out;
    }

    private function truncateToTokenBudget(string $text, int $maxTokens): string
    {
        $maxChars = (int) ($maxTokens * 3.8);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $head = mb_substr($text, 0, (int) ($maxChars * 0.65));
        $tail = mb_substr($text, - (int) ($maxChars * 0.25));
        return $head . "\n\n[...middle of message omitted for length...]\n\n" . $tail;
    }

    private function historySessionKey(): string
    {
        $role = $this->context['role'] ?? 'guest';
        $id = $this->context['client_id'] ?? $this->context['lawyer_id'] ?? $this->context['admin_id'] ?? 0;
        return 'chatbot_history_' . $role . '_' . $id;
    }

    private function appendHistory(string $role, string $content): void
    {
        $key = $this->historySessionKey();
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        $_SESSION[$key][] = ['role' => $role, 'content' => $content, 'ts' => time()];
        if (count($_SESSION[$key]) > self::MAX_HISTORY_MESSAGES) {
            $_SESSION[$key] = array_slice($_SESSION[$key], -self::MAX_HISTORY_MESSAGES);
        }
    }
}
