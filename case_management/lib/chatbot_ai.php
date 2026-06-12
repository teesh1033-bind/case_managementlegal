<?php
/**
 * Smart assistant entry point — local intelligence only (no external API).
 */

require_once __DIR__ . '/chatbot_smart.php';

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
        return false;
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

        $this->appendHistory('user', $displayMessage);

        $result = $this->engine->chat($displayMessage, $tokenNote);

        $this->appendHistory('assistant', $result['reply'] ?? '');
        $result['tokens_used'] = $this->estimateTokens($displayMessage) + $this->estimateTokens($result['reply'] ?? '');
        $result['mode'] = 'smart';

        return $result;
    }

    public function clearHistory(): void
    {
        unset($_SESSION[$this->historySessionKey()]);
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
