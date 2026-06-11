<?php
/**
 * AI-powered chatbot: OpenAI + live DB context + client actions + conversation memory.
 */

require_once __DIR__ . '/chatbot_assistant.php';

class ChatbotAI
{
    private PDO $pdo;
    private array $context;
    private const MAX_HISTORY_MESSAGES = 24;
    private const MAX_USER_INPUT_TOKENS = 6000;
    private const MAX_CONTEXT_CHARS = 28000;

    public function __construct(PDO $pdo, array $context)
    {
        $this->pdo = $pdo;
        $this->context = $context;
    }

    public static function openAiConfigured(): bool
    {
        return trim((string) getSetting('openai_api_key', '')) !== '';
    }

    public static function getModel(): string
    {
        $m = trim((string) getSetting('openai_model', 'gpt-4o-mini'));
        return $m !== '' ? $m : 'gpt-4o-mini';
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

        if (self::openAiConfigured()) {
            $result = $this->chatWithOpenAI($displayMessage, $tokenNote);
        } else {
            $result = $this->chatWithSmartFallback($displayMessage, $tokenNote);
        }

        $this->appendHistory('assistant', $result['reply']);
        $result['tokens_used'] = $this->estimateTokens($displayMessage) + $this->estimateTokens($result['reply']);
        $result['mode'] = self::openAiConfigured() ? 'ai' : 'smart';

        return $result;
    }

    public function clearHistory(): void
    {
        $key = $this->historySessionKey();
        unset($_SESSION[$key]);
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
        // Rough GPT-style estimate: ~4 chars per token for English
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

    private function getHistory(): array
    {
        $key = $this->historySessionKey();
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            return [];
        }
        return array_slice($_SESSION[$key], -self::MAX_HISTORY_MESSAGES);
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

    private function chatWithOpenAI(string $userMessage, string $tokenNote): array
    {
        $system = $this->buildSystemPrompt();
        $messages = [['role' => 'system', 'content' => $system]];

        foreach ($this->getHistory() as $h) {
            if ($h['role'] === 'user' || $h['role'] === 'assistant') {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }

        $tools = $this->getToolsForRole();
        $links = [];
        $actionsTaken = [];

        for ($round = 0; $round < 3; $round++) {
            $response = $this->openAiRequest($messages, $tools);
            if (!$response['ok']) {
                return $this->chatWithSmartFallback($userMessage, $tokenNote);
            }

            $choice = $response['data']['choices'][0]['message'] ?? [];
            $toolCalls = $choice['tool_calls'] ?? null;

            if (!empty($toolCalls) && is_array($toolCalls)) {
                $messages[] = $choice;
                foreach ($toolCalls as $tc) {
                    $fn = $tc['function']['name'] ?? '';
                    $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                    $exec = $this->executeTool($fn, $args);
                    $actionsTaken[] = $exec['summary'] ?? $fn;
                    if (!empty($exec['links'])) {
                        $links = array_merge($links, $exec['links']);
                    }
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc['id'],
                        'content' => json_encode($exec, JSON_UNESCAPED_UNICODE),
                    ];
                }
                continue;
            }

            $reply = trim((string) ($choice['content'] ?? ''));
            if ($reply === '') {
                $reply = 'I processed your request.';
            }
            if ($tokenNote !== '') {
                $reply .= $tokenNote;
            }
            if (!empty($actionsTaken)) {
                $reply .= "\n\n**Done:** " . implode(' · ', $actionsTaken);
            }

            return ['ok' => true, 'reply' => $reply, 'links' => $links, 'actions' => $actionsTaken];
        }

        return ['ok' => true, 'reply' => 'I completed your requests.' . $tokenNote, 'links' => $links, 'actions' => $actionsTaken];
    }

    private function chatWithSmartFallback(string $userMessage, string $tokenNote): array
    {
        $lower = strtolower($userMessage);

        if ($this->context['role'] === 'client') {
            $action = $this->tryLocalClientAction($userMessage);
            if ($action !== null) {
                if ($tokenNote !== '') {
                    $action['reply'] .= $tokenNote;
                }
                return $action;
            }
        }

        $assistant = new ChatbotAssistant($this->pdo, $this->context);
        $base = $assistant->answer($userMessage);

        if ($this->context['role'] === 'client') {
            $ctxBlock = $this->buildClientContextSummary();
            $followUp = $this->detectFollowUpIntent($lower);
            if ($followUp && strpos($base['reply'], "I'm not sure") !== false) {
                $base['reply'] = $this->adviseFromContext($userMessage, $ctxBlock);
            } elseif ($followUp) {
                $base['reply'] .= "\n\n**Your account snapshot:**\n" . $ctxBlock;
            }
        }

        if ($tokenNote !== '') {
            $base['reply'] .= $tokenNote;
        }

        if (!self::openAiConfigured()) {
            $base['reply'] .= "\n\n_Tip: Ask your administrator to add an OpenAI API key in Settings for ChatGPT-style conversations._";
        }

        return array_merge(['ok' => true], $base);
    }

    private function detectFollowUpIntent(string $lower): bool
    {
        return (bool) preg_match('/\b(what|how|why|when|where|should|advise|help me|explain|tell me more|what do you think)\b/', $lower);
    }

    private function adviseFromContext(string $question, string $contextSummary): string
    {
        $name = $this->context['display_name'] ?? 'there';
        return "Hello {$name}, here's what I know from your account:\n\n{$contextSummary}\n\nBased on this, I'd suggest reviewing your **upcoming appointments** and any **outstanding invoices** first. For legal advice on strategy, your assigned lawyer is the best person to speak with — I can help you **request a callback** if you'd like.";
    }

    private function buildSystemPrompt(): string
    {
        $firm = getCompanyBranding()['name'] ?? 'LegalPro';
        $role = $this->context['role'];
        $name = $this->context['display_name'] ?? 'User';

        $prompt = "You are an expert AI assistant for the {$firm} client/legal portal. You behave like ChatGPT: warm, clear, structured, and helpful. The user is {$name} (role: {$role}).\n\n";
        $prompt .= "RULES:\n";
        $prompt .= "- Use **bold** for key terms. Use short paragraphs and bullet lists when helpful.\n";
        $prompt .= "- Only use facts from LIVE DATA below. Never invent cases, dates, or amounts.\n";
        $prompt .= "- For legal strategy, advise practically but remind users their lawyer has final say.\n";
        $prompt .= "- When the user wants to UPDATE something (phone, address, callback, billing question), use the provided tools.\n";
        $prompt .= "- Confirm before destructive actions. Be proactive with next steps and links.\n\n";

        $prompt .= "LIVE DATA:\n" . $this->buildLiveDataContext();

        return $prompt;
    }

    private function buildLiveDataContext(): string
    {
        $role = $this->context['role'];
        if ($role === 'client') {
            return $this->buildClientContextJson();
        }
        if ($role === 'lawyer') {
            return $this->buildLawyerContextJson();
        }
        return $this->buildAdminContextJson();
    }

    private function buildClientContextJson(): string
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        $data = ['client_id' => $clientId, 'generated_at' => date('c')];

        try {
            $stmt = $this->pdo->prepare('SELECT first_name, last_name, email, phone, address, emergency_contact_name, emergency_contact_phone FROM clients WHERE id = ?');
            $stmt->execute([$clientId]);
            $data['profile'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $stmt = $this->pdo->prepare("
                SELECT c.id, c.title, c.status, c.priority, c.category, c.updated_at,
                       GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name)) AS lawyers
                FROM cases c
                LEFT JOIN case_lawyers cl ON cl.case_id = c.id
                LEFT JOIN lawyers l ON l.id = cl.lawyer_id
                WHERE c.client_id = ?
                GROUP BY c.id ORDER BY c.updated_at DESC LIMIT 12
            ");
            $stmt->execute([$clientId]);
            $data['cases'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->pdo->prepare("
                SELECT a.id, a.starts_at, a.status, a.notes, c.title AS case_title
                FROM appointments a LEFT JOIN cases c ON c.id = a.case_id
                WHERE a.client_id = ? AND a.starts_at >= NOW()
                ORDER BY a.starts_at ASC LIMIT 8
            ");
            $stmt->execute([$clientId]);
            $data['upcoming_appointments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->pdo->prepare("
                SELECT i.id, i.invoice_number, i.amount, i.due_date, i.status,
                       COALESCE(SUM(p.amount),0) AS paid,
                       (i.amount - COALESCE(SUM(p.amount),0)) AS balance_due, c.title AS case_title
                FROM invoices i
                LEFT JOIN payments p ON p.invoice_id = i.id
                LEFT JOIN cases c ON c.id = i.case_id
                WHERE i.client_id = ?
                GROUP BY i.id ORDER BY i.issue_date DESC LIMIT 10
            ");
            $stmt->execute([$clientId]);
            $data['invoices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->pdo->prepare("
                SELECT cd.id, cd.court_date, cd.title, cd.status, c.title AS case_title
                FROM court_dates cd INNER JOIN cases c ON c.id = cd.case_id
                WHERE c.client_id = ? AND cd.court_date >= NOW()
                ORDER BY cd.court_date ASC LIMIT 8
            ");
            $stmt->execute([$clientId]);
            $data['upcoming_court_dates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->pdo->prepare("
                SELECT d.id, d.label, d.filename, d.uploaded_at, c.title AS case_title
                FROM documents d INNER JOIN cases c ON c.id = d.case_id
                WHERE c.client_id = ? ORDER BY d.uploaded_at DESC LIMIT 10
            ");
            $stmt->execute([$clientId]);
            $data['recent_documents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $data['error'] = 'partial data load';
        }

        if (function_exists('legalpro_client_profile_completeness')) {
            require_once __DIR__ . '/client-self-service.php';
            $data['profile_completeness_percent'] = legalpro_client_profile_completeness($this->pdo, $clientId)['percent'] ?? 0;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return mb_substr($json ?: '{}', 0, self::MAX_CONTEXT_CHARS);
    }

    private function buildClientContextSummary(): string
    {
        $decoded = json_decode($this->buildClientContextJson(), true) ?: [];
        $lines = [];
        if (!empty($decoded['cases'])) {
            foreach (array_slice($decoded['cases'], 0, 5) as $c) {
                $lines[] = '• Case C-' . str_pad((string) $c['id'], 4, '0', STR_PAD_LEFT) . ': ' . ($c['title'] ?? '') . ' (' . ($c['status'] ?? '') . ')';
            }
        }
        if (!empty($decoded['upcoming_appointments'])) {
            $lines[] = '• ' . count($decoded['upcoming_appointments']) . ' upcoming appointment(s)';
        }
        if (!empty($decoded['invoices'])) {
            $out = 0;
            foreach ($decoded['invoices'] as $inv) {
                if ((float) ($inv['balance_due'] ?? 0) > 0) {
                    $out++;
                }
            }
            if ($out > 0) {
                $lines[] = '• ' . $out . ' invoice(s) with balance due';
            }
        }
        return $lines ? implode("\n", $lines) : 'No cases or activity found yet.';
    }

    private function buildLawyerContextJson(): string
    {
        $lawyerId = (int) ($this->context['lawyer_id'] ?? 0);
        $data = ['lawyer_id' => $lawyerId];
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM cases c INNER JOIN case_lawyers cl ON cl.case_id = c.id
                WHERE cl.lawyer_id = ? AND c.status != 'closed'
            ");
            $stmt->execute([$lawyerId]);
            $data['active_cases'] = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function buildAdminContextJson(): string
    {
        try {
            return json_encode([
                'total_cases' => (int) $this->pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn(),
                'total_clients' => (int) $this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn(),
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            return '{}';
        }
    }

    private function getToolsForRole(): array
    {
        if ($this->context['role'] !== 'client') {
            return [];
        }

        return [
            ['type' => 'function', 'function' => [
                'name' => 'update_client_phone',
                'description' => 'Update the client phone number on their profile',
                'parameters' => ['type' => 'object', 'properties' => ['phone' => ['type' => 'string']], 'required' => ['phone']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_client_address',
                'description' => 'Update the client mailing address',
                'parameters' => ['type' => 'object', 'properties' => ['address' => ['type' => 'string']], 'required' => ['address']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'submit_callback_request',
                'description' => 'Request a callback from the legal team about a case',
                'parameters' => ['type' => 'object', 'properties' => [
                    'case_id' => ['type' => 'integer', 'description' => 'Case ID number without C- prefix'],
                    'message' => ['type' => 'string'],
                    'preferred_time' => ['type' => 'string'],
                ], 'required' => ['message']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'submit_billing_question',
                'description' => 'Submit a billing or invoice question to the billing team',
                'parameters' => ['type' => 'object', 'properties' => [
                    'case_id' => ['type' => 'integer'],
                    'message' => ['type' => 'string'],
                ], 'required' => ['message']],
            ]],
        ];
    }

    private function executeTool(string $name, array $args): array
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        if ($clientId <= 0) {
            return ['success' => false, 'error' => 'Not authorized'];
        }

        try {
            switch ($name) {
                case 'update_client_phone':
                    $phone = trim((string) ($args['phone'] ?? ''));
                    if ($phone === '') {
                        return ['success' => false, 'error' => 'Phone required'];
                    }
                    $this->pdo->prepare('UPDATE clients SET phone = ? WHERE id = ?')->execute([$phone, $clientId]);
                    return ['success' => true, 'summary' => 'Updated phone to ' . $phone, 'links' => [['label' => 'Profile', 'url' => 'client-profile.php']]];

                case 'update_client_address':
                    $address = trim((string) ($args['address'] ?? ''));
                    if ($address === '') {
                        return ['success' => false, 'error' => 'Address required'];
                    }
                    $this->pdo->prepare('UPDATE clients SET address = ? WHERE id = ?')->execute([$address, $clientId]);
                    return ['success' => true, 'summary' => 'Updated address', 'links' => [['label' => 'Profile', 'url' => 'client-profile.php']]];

                case 'submit_callback_request':
                case 'submit_billing_question':
                    require_once __DIR__ . '/client-self-service.php';
                    $type = $name === 'submit_billing_question' ? 'billing' : 'callback';
                    $result = legalpro_client_submit_request($this->pdo, $clientId, [
                        'request_type' => $type,
                        'case_id' => (int) ($args['case_id'] ?? 0),
                        'message' => (string) ($args['message'] ?? ''),
                        'preferred_callback_time' => (string) ($args['preferred_time'] ?? ''),
                        'subject' => $type === 'billing' ? 'Billing question (via AI)' : 'Callback request (via AI)',
                    ], null);
                    return [
                        'success' => $result['ok'],
                        'summary' => $result['ok'] ? 'Submitted ' . $type . ' request' : ($result['message'] ?? 'Failed'),
                        'links' => [['label' => 'My requests', 'url' => 'client-requests.php']],
                    ];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => false, 'error' => 'Unknown tool'];
    }

    private function tryLocalClientAction(string $message): ?array
    {
        $lower = strtolower($message);

        if (preg_match('/update\s+(?:my\s+)?phone\s+(?:to\s+)?([+\d\s\-().]{7,})/i', $message, $m)) {
            $r = $this->executeTool('update_client_phone', ['phone' => trim($m[1])]);
            if ($r['success'] ?? false) {
                return ['ok' => true, 'reply' => "Done — I've updated your phone number to **" . trim($m[1]) . "**.", 'links' => $r['links'] ?? []];
            }
        }

        if (preg_match('/(?:request|need)\s+(?:a\s+)?callback/i', $lower) || preg_match('/call\s+me\s+back/i', $lower)) {
            $r = $this->executeTool('submit_callback_request', ['message' => $message, 'preferred_time' => '']);
            if ($r['success'] ?? false) {
                return ['ok' => true, 'reply' => "I've submitted a **callback request** to your legal team. They'll follow up soon.", 'links' => $r['links'] ?? []];
            }
        }

        if (preg_match('/billing\s+question|question\s+about\s+(?:my\s+)?invoice/i', $lower)) {
            $r = $this->executeTool('submit_billing_question', ['message' => $message]);
            if ($r['success'] ?? false) {
                return ['ok' => true, 'reply' => "Your **billing question** has been sent to the team.", 'links' => $r['links'] ?? []];
            }
        }

        return null;
    }

    private function openAiRequest(array $messages, array $tools): array
    {
        $key = trim((string) getSetting('openai_api_key', ''));
        if ($key === '') {
            return ['ok' => false];
        }

        $body = [
            'model' => self::getModel(),
            'messages' => $messages,
            'temperature' => 0.65,
            'max_tokens' => 1200,
        ];
        if (!empty($tools)) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($code >= 200 && $code < 300 && is_array($data)) {
            return ['ok' => true, 'data' => $data];
        }

        error_log('OpenAI error ' . $code . ': ' . substr((string) $raw, 0, 500));
        return ['ok' => false, 'error' => $data['error']['message'] ?? 'API error'];
    }

    public static function saveAiSettings(array $data): array
    {
        $key = trim((string) ($data['openai_api_key'] ?? ''));
        $model = trim((string) ($data['openai_model'] ?? 'gpt-4o-mini'));
        if ($model === '') {
            $model = 'gpt-4o-mini';
        }
        if ($key !== '') {
            setSetting('openai_api_key', $key);
        }
        setSetting('openai_model', $model);
        return ['ok' => true, 'message' => 'AI assistant settings saved.'];
    }

    public static function renderAiSettingsHtml(): string
    {
        $hasKey = self::openAiConfigured();
        $model = htmlspecialchars(self::getModel());
        $keyPlaceholder = $hasKey ? '•••••••• (saved — leave blank to keep)' : 'sk-…';

        return '<div class="card mb-4"><div class="card-header pb-0"><h6>AI Assistant (OpenAI)</h6></div><div class="card-body">'
            . '<p class="text-sm text-muted">Connect ChatGPT-style intelligence. Without a key, the assistant uses smart database lookups + local actions.</p>'
            . '<form method="post"><input type="hidden" name="form_type" value="openai">'
            . '<div class="mb-3"><label class="form-control-label">OpenAI API key</label>'
            . '<input type="password" class="form-control" name="openai_api_key" placeholder="' . $keyPlaceholder . '" autocomplete="off"></div>'
            . '<div class="mb-3"><label class="form-control-label">Model</label>'
            . '<select class="form-select" name="openai_model">'
            . '<option value="gpt-4o-mini"' . ($model === 'gpt-4o-mini' ? ' selected' : '') . '>gpt-4o-mini (recommended)</option>'
            . '<option value="gpt-4o"' . ($model === 'gpt-4o' ? ' selected' : '') . '>gpt-4o</option>'
            . '<option value="gpt-4.1-mini"' . ($model === 'gpt-4.1-mini' ? ' selected' : '') . '>gpt-4.1-mini</option>'
            . '</select></div>'
            . '<button type="submit" class="btn btn-primary mb-0">Save AI settings</button></form></div></div>';
    }
}
