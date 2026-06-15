<?php
/**
 * Multi-step client updates via chatbot — profile fields and case comments.
 */

require_once __DIR__ . '/case_events.php';

class ChatbotClientUpdateFlow
{
    private PDO $pdo;
    private array $context;

    private const PROFILE_FIELDS = [
        'phone' => ['label' => 'phone number', 'prompt' => 'What is your new phone number? (e.g. +230 5xxx xxxx)'],
        'email' => ['label' => 'email address', 'prompt' => 'What is your new email address?'],
        'address' => ['label' => 'address', 'prompt' => 'What is your new address?'],
        'first_name' => ['label' => 'first name', 'prompt' => 'What is your new first name?'],
        'last_name' => ['label' => 'last name', 'prompt' => 'What is your new last name?'],
        'emergency_contact_name' => ['label' => 'emergency contact name', 'prompt' => "What is your emergency contact's name?"],
        'emergency_contact_phone' => ['label' => 'emergency contact phone', 'prompt' => "What is your emergency contact's phone number?"],
    ];

    public function __construct(PDO $pdo, array $context)
    {
        $this->pdo = $pdo;
        $this->context = $context;
    }

    public static function clearPending(array $context): void
    {
        unset($_SESSION[self::sessionKey($context)]);
    }

    public function tryHandle(string $message, string $lower): ?array
    {
        if (($this->context['role'] ?? '') !== 'client') {
            return null;
        }

        $clientId = (int) ($this->context['client_id'] ?? 0);
        if ($clientId <= 0) {
            return null;
        }

        if ($this->isCancelIntent($lower)) {
            if ($this->getPending() !== null) {
                self::clearPending($this->context);

                return $this->ok('Okay, I cancelled that update. Let me know if you need anything else.');
            }

            return null;
        }

        $pending = $this->getPending();
        if ($pending !== null) {
            return $this->continuePending($message, $lower, $pending);
        }

        return $this->tryStart($message, $lower);
    }

    public function tryCaseUpdateForCase(array $case, string $message): ?array
    {
        if (($this->context['role'] ?? '') !== 'client') {
            return null;
        }

        $body = $this->extractInlineCaseUpdate($message, $case);
        if ($body !== null) {
            return $this->saveCaseComment((int) $case['id'], $body);
        }

        return $this->startCaseCommentFlow($case);
    }

    private function tryStart(string $message, string $lower): ?array
    {
        if (preg_match('/\b(update|change|edit)\s+(?:my\s+)?(phone|email|address)\s+(?:to\s+)?(.+)/i', $message, $m)) {
            $field = $this->mapProfileFieldToken(strtolower($m[2]));
            $value = trim($m[3]);
            if ($field && $value !== '' && !$this->looksLikeIntentOnly($value)) {
                return $this->applyProfileField($field, $value);
            }
        }

        if (preg_match('/\b(update|change|edit)\s+(?:my\s+)?(phone|email|address|first\s*name|last\s*name|name)\s*$/i', $message, $m)) {
            $field = $this->mapProfileFieldToken(strtolower(str_replace(' ', '_', trim($m[2]))));
            if ($field === 'name') {
                return $this->startProfileFieldChoice();
            }
            if ($field) {
                return $this->startProfileField($field);
            }
        }

        if (preg_match('/\b(update|change|edit)\s+(?:my\s+)?(?:profile|details|information|info|account)\b/i', $lower)) {
            return $this->startProfileMenu();
        }

        if (preg_match('/\bemergency\s+contact\b/i', $lower)
            && preg_match('/\b(update|change|edit)\b/i', $lower)) {
            return $this->ok(
                "Which emergency contact detail should I update?\n\n"
                . "• Say **emergency contact name**\n"
                . "• Say **emergency contact phone**"
            );
        }

        if (preg_match('/\b(update|change|edit)\s+(?:my\s+)?emergency\s+contact\s+(name|phone)\s*$/i', $message, $m)) {
            $field = $m[1] === 'phone' ? 'emergency_contact_phone' : 'emergency_contact_name';

            return $this->startProfileField($field);
        }

        if (preg_match('/\b(add|post|send|submit)\s+(?:a\s+)?(?:case\s+)?(?:update|comment|note|message)\b/i', $lower)
            || preg_match('/\b(update|change|edit)\s+(?:my\s+)?cases?\b/i', $lower)
            || (preg_match('/\b(update|change|edit|modify)\b/i', $lower) && preg_match('/\bcases?\b/i', $lower))) {
            return $this->tryStartCaseUpdate($message, $lower);
        }

        return null;
    }

    private function tryStartCaseUpdate(string $message, string $lower): ?array
    {
        $case = $this->resolveCaseFromMessage($message);
        if ($case === null) {
            $this->setPending(['type' => 'case_pick', 'step' => 'awaiting_case']);

            return $this->replyWithCasePicker(
                "I can post an update to your case for your legal team. Which case is this about?"
            );
        }

        $body = $this->extractInlineCaseUpdate($message, $case);
        if ($body !== null) {
            return $this->saveCaseComment((int) $case['id'], $body);
        }

        return $this->startCaseCommentFlow($case);
    }

    private function continuePending(string $message, string $lower, array $pending): ?array
    {
        $type = $pending['type'] ?? '';

        if ($type === 'profile_menu' && ($pending['step'] ?? '') === 'awaiting_field') {
            $field = $this->parseProfileFieldChoice($message, $lower);
            if ($field === null) {
                return $this->ok('Please choose: phone, email, address, first name, or last name.');
            }

            return $this->startProfileField($field);
        }

        if ($type === 'profile_field' && ($pending['step'] ?? '') === 'awaiting_value') {
            $field = (string) ($pending['field'] ?? '');
            $value = trim($message);
            if ($value === '' || $this->isCancelIntent($lower)) {
                return $this->ok('Please type the new value, or say cancel to stop.');
            }
            self::clearPending($this->context);

            return $this->applyProfileField($field, $value);
        }

        if ($type === 'case_pick' && ($pending['step'] ?? '') === 'awaiting_case') {
            $case = $this->resolveCaseFromMessage($message);
            if ($case === null) {
                return $this->replyWithCasePicker('I could not match that case. Please pick one from the list or say e.g. C-0003.');
            }
            self::clearPending($this->context);

            $body = $this->extractInlineCaseUpdate($message, $case);
            if ($body !== null) {
                return $this->saveCaseComment((int) $case['id'], $body);
            }

            return $this->startCaseCommentFlow($case);
        }

        if ($type === 'case_update' && ($pending['step'] ?? '') === 'awaiting_comment') {
            $caseId = (int) ($pending['case_id'] ?? 0);
            $comment = trim($message);
            if ($comment === '' || strlen($comment) < 3) {
                return $this->ok('Please type your update (at least a few words), or say cancel.');
            }
            self::clearPending($this->context);

            return $this->saveCaseComment($caseId, $comment);
        }

        self::clearPending($this->context);

        return null;
    }

    private function startProfileMenu(): array
    {
        $this->setPending(['type' => 'profile_menu', 'step' => 'awaiting_field']);

        return $this->ok(
            "Sure — what would you like to update on your profile?\n\n"
            . "You can say:\n"
            . "• phone\n"
            . "• email\n"
            . "• address\n"
            . "• first name\n"
            . "• last name\n"
            . "• emergency contact name\n"
            . "• emergency contact phone"
        );
    }

    private function startProfileFieldChoice(): array
    {
        $this->setPending(['type' => 'profile_menu', 'step' => 'awaiting_field']);

        return $this->ok('Do you want to update your first name or last name?');
    }

    private function startProfileField(string $field): array
    {
        if (!isset(self::PROFILE_FIELDS[$field])) {
            return $this->ok('That profile field cannot be updated here.');
        }

        $meta = self::PROFILE_FIELDS[$field];
        $this->setPending([
            'type' => 'profile_field',
            'field' => $field,
            'label' => $meta['label'],
            'step' => 'awaiting_value',
        ]);

        return $this->ok($meta['prompt']);
    }

    private function startCaseCommentFlow(array $case): array
    {
        $caseId = (int) $case['id'];
        $label = $this->caseLabel($caseId);
        $title = (string) ($case['title'] ?? '');

        $this->setPending([
            'type' => 'case_update',
            'case_id' => $caseId,
            'case_label' => $label,
            'case_title' => $title,
            'step' => 'awaiting_comment',
        ]);

        return $this->ok(
            "What would you like to tell your lawyer about **{$label} — {$title}**?\n\n"
            . "Type your update here and I will add it to the case comments for your legal team."
        );
    }

    private function applyProfileField(string $field, string $value): array
    {
        if (!isset(self::PROFILE_FIELDS[$field])) {
            return $this->ok('That field cannot be updated here.');
        }

        $label = self::PROFILE_FIELDS[$field]['label'];
        $value = trim(preg_replace('/\s*(please|thanks|thank you)\.?$/i', '', trim($value)));

        if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->startProfileField($field);

            return $this->ok('That does not look like a valid email. Please try again, e.g. you@example.com');
        }

        if (in_array($field, ['phone', 'emergency_contact_phone'], true) && !preg_match('/[+\d][\d\s\-().]{6,}/', $value)) {
            $this->startProfileField($field);

            return $this->ok('Please enter a valid phone number with at least 7 digits.');
        }

        if (in_array($field, ['first_name', 'last_name', 'emergency_contact_name'], true)
            && !preg_match('/^[a-zA-Z\-\' ]{2,60}$/', $value)) {
            $this->startProfileField($field);

            return $this->ok('Please enter a valid name (letters only, at least 2 characters).');
        }

        $clientId = (int) ($this->context['client_id'] ?? 0);

        try {
            $this->pdo->prepare("UPDATE clients SET {$field} = ? WHERE id = ?")->execute([$value, $clientId]);
            if ($field === 'first_name' || $field === 'last_name') {
                $stmt = $this->pdo->prepare('SELECT first_name, last_name FROM clients WHERE id = ?');
                $stmt->execute([$clientId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $_SESSION['client_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
                    $this->context['display_name'] = $_SESSION['client_name'];
                }
            }

            return [
                'ok' => true,
                'reply' => "Done — your **{$label}** has been updated to **{$value}**.",
                'links' => [['label' => 'Profile', 'url' => 'client-profile.php']],
                'actions' => ['profile_updated'],
            ];
        } catch (PDOException $e) {
            error_log('chatbot client update profile: ' . $e->getMessage());

            return $this->ok('I could not save that change. Please try again or update your Profile page.', [
                ['label' => 'Profile', 'url' => 'client-profile.php'],
            ]);
        }
    }

    private function saveCaseComment(int $caseId, string $comment): array
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT id FROM cases WHERE id = ? AND client_id = ?');
        $stmt->execute([$caseId, $clientId]);
        if (!$stmt->fetch()) {
            return $this->ok('That case was not found on your account.');
        }

        $userId = $this->resolveClientUserId();
        if ($userId <= 0) {
            return $this->ok('Could not verify your user account. Please add your update on the case page instead.', [
                ['label' => 'Open case', 'url' => 'client-case-view.php?id=' . $caseId],
            ]);
        }

        try {
            $stmt = $this->pdo->prepare("INSERT INTO case_comments (case_id, user_id, comment, comment_type) VALUES (?, ?, ?, 'client')");
            $stmt->execute([$caseId, $userId, $comment]);
            CaseEvents::trackCommentAdded($caseId, [
                'comment' => $comment,
                'comment_type' => 'client',
                'source' => 'chatbot',
            ]);
        } catch (PDOException $e) {
            error_log('chatbot case comment: ' . $e->getMessage());

            return $this->ok('I could not save your update. Please try again on the case page.', [
                ['label' => 'Open case', 'url' => 'client-case-view.php?id=' . $caseId],
            ]);
        }

        $label = $this->caseLabel($caseId);
        $preview = strlen($comment) > 120 ? substr($comment, 0, 117) . '...' : $comment;

        return [
            'ok' => true,
            'reply' => "Your update has been posted to **{$label}** for your legal team:\n\n\"{$preview}\"",
            'links' => [
                ['label' => 'View case', 'url' => 'client-case-view.php?id=' . $caseId],
            ],
            'actions' => ['case_comment_added'],
        ];
    }

    private function resolveClientUserId(): int
    {
        if (!empty($this->context['client_user_id'])) {
            return (int) $this->context['client_user_id'];
        }
        if (!empty($_SESSION['client_user_id'])) {
            return (int) $_SESSION['client_user_id'];
        }

        $clientId = (int) ($this->context['client_id'] ?? 0);
        if ($clientId <= 0) {
            return 0;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE client_id = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$clientId]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    private function parseProfileFieldChoice(string $message, string $lower): ?string
    {
        if (strpos($lower, 'phone') !== false && strpos($lower, 'emergency') === false) {
            return 'phone';
        }
        if (strpos($lower, 'email') !== false) {
            return 'email';
        }
        if (strpos($lower, 'address') !== false) {
            return 'address';
        }
        if (preg_match('/\bfirst\s*name\b/', $lower) || $lower === 'first') {
            return 'first_name';
        }
        if (preg_match('/\blast\s*name\b/', $lower) || $lower === 'last') {
            return 'last_name';
        }
        if (strpos($lower, 'emergency') !== false && strpos($lower, 'phone') !== false) {
            return 'emergency_contact_phone';
        }
        if (strpos($lower, 'emergency') !== false) {
            return 'emergency_contact_name';
        }

        return $this->mapProfileFieldToken($lower);
    }

    private function mapProfileFieldToken(string $token): ?string
    {
        $token = str_replace([' ', '-'], '_', strtolower(trim($token)));
        $map = [
            'phone' => 'phone',
            'email' => 'email',
            'address' => 'address',
            'first_name' => 'first_name',
            'first' => 'first_name',
            'last_name' => 'last_name',
            'last' => 'last_name',
            'name' => 'name',
            'emergency_contact_name' => 'emergency_contact_name',
            'emergency_contact_phone' => 'emergency_contact_phone',
        ];

        return $map[$token] ?? null;
    }

    private function extractInlineCaseUpdate(string $message, array $case): ?string
    {
        $body = $message;
        $body = preg_replace('/\b(i want to|i need to|please|can you|help me|kindly)\b/i', '', $body);
        $body = preg_replace('/\b(add|post|send|submit)\s+(?:a\s+)?(?:case\s+)?(?:update|comment|note|message)\s+(?:to|on|for)\b/i', '', $body);
        $body = preg_replace('/\b(update|change|edit|modify)\s+(?:my\s+)?(?:the\s+)?case\b/i', '', $body);
        $body = preg_replace('/\bc[\-\s]?\d{1,6}\b/i', '', $body);
        $title = preg_quote((string) ($case['title'] ?? ''), '/');
        if ($title !== '') {
            $body = preg_replace('/' . $title . '/i', '', $body);
        }
        $body = trim(preg_replace('/\s+/', ' ', $body));
        $body = trim($body, " .,:;-\t\n\r");

        if (strlen($body) >= 12 && !$this->looksLikeIntentOnly($body)) {
            return $body;
        }

        return null;
    }

    private function looksLikeIntentOnly(string $text): bool
    {
        $t = strtolower(trim($text));

        return (bool) preg_match('/^(update|change|edit|my|the|case|cases|profile|phone|email|address|please|thanks)$/', $t);
    }

    private function isCancelIntent(string $lower): bool
    {
        return (bool) preg_match('/^(cancel|never mind|nevermind|stop|forget it|abort|no thanks)$/', trim($lower));
    }

    private function resolveCaseFromMessage(string $message): ?array
    {
        if (preg_match('/\bc[\-\s]?(\d{1,6})\b/i', $message, $m)) {
            $caseId = (int) $m[1];
            $stmt = $this->pdo->prepare('SELECT id, title, status FROM cases WHERE id = ? AND client_id = ?');
            $stmt->execute([$caseId, (int) ($this->context['client_id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        }

        $clientId = (int) ($this->context['client_id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT id, title, status FROM cases WHERE client_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$clientId]);
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $lower = strtolower($message);

        foreach ($cases as $c) {
            $title = strtolower(trim((string) $c['title']));
            if ($title !== '' && strpos($lower, $title) !== false) {
                return $c;
            }
            foreach (preg_split('/\s+/', $title) as $word) {
                if (strlen($word) >= 4 && strpos($lower, $word) !== false) {
                    return $c;
                }
            }
        }

        return null;
    }

    private function replyWithCasePicker(string $intro): array
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT id, title, status FROM cases WHERE client_id = ? ORDER BY updated_at DESC LIMIT 8');
        $stmt->execute([$clientId]);
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$cases) {
            self::clearPending($this->context);

            return $this->ok('You have no cases on file yet.');
        }

        $lines = $intro . "\n\n";
        foreach ($cases as $c) {
            $lines .= '• **' . $this->caseLabel((int) $c['id']) . '** — ' . ($c['title'] ?? '') . ' (' . ($c['status'] ?? '') . ")\n";
        }
        $lines .= "\nReply with a case number or title, then type your update.";

        return $this->ok(trim($lines), [['label' => 'My cases', 'url' => 'client-cases.php']]);
    }

    private function getPending(): ?array
    {
        $key = self::sessionKey($this->context);
        $data = $_SESSION[$key] ?? null;

        return is_array($data) ? $data : null;
    }

    private function setPending(array $data): void
    {
        $_SESSION[self::sessionKey($this->context)] = $data;
    }

    private static function sessionKey(array $context): string
    {
        return 'chatbot_pending_update_client_' . (int) ($context['client_id'] ?? 0);
    }

    private function caseLabel(int $caseId): string
    {
        return 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
    }

    private function ok(string $reply, array $links = []): array
    {
        return ['ok' => true, 'reply' => $reply, 'links' => $links];
    }
}
