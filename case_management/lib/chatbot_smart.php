<?php
/**
 * Local smart assistant — no external API. Intent detection, live DB analysis,
 * navigation redirects, and client self-service actions.
 */

require_once __DIR__ . '/chatbot_assistant.php';
require_once __DIR__ . '/client-self-service.php';
require_once __DIR__ . '/appointment_availability.php';

class ChatbotSmartEngine
{
    private PDO $pdo;
    private array $context;
    private ?array $clientData = null;

    public function __construct(PDO $pdo, array $context)
    {
        $this->pdo = $pdo;
        $this->context = $context;
    }

    public function chat(string $message, string $tokenNote = ''): array
    {
        $role = $this->context['role'] ?? 'guest';
        $lower = strtolower($message);

        $nav = $this->tryNavigation($message, $lower);
        if ($nav !== null) {
            return $this->finish($nav, $tokenNote);
        }

        if ($role === 'client') {
            $action = $this->tryClientActions($message, $lower);
            if ($action !== null) {
                return $this->finish($action, $tokenNote);
            }

            $insight = $this->tryClientInsights($message, $lower);
            if ($insight !== null) {
                return $this->finish($insight, $tokenNote);
            }
        }

        if (in_array($role, ['lawyer', 'admin'], true)) {
            $navAdmin = $this->tryStaffNavigation($message, $lower, $role);
            if ($navAdmin !== null) {
                return $this->finish($navAdmin, $tokenNote);
            }
        }

        $assistant = new ChatbotAssistant($this->pdo, $this->context);
        $base = $assistant->answer($message);

        if ($role === 'client' && $this->looksLikeAdviceRequest($lower)) {
            if (strpos($base['reply'], "I'm not sure") !== false) {
                $base['reply'] = $this->buildWeeklyBriefing();
            } else {
                $base['reply'] .= "\n\n" . $this->buildPriorityTips();
            }
        }

        $softNav = $this->suggestNavigationLink($lower);
        if ($softNav !== null && empty($base['links'])) {
            $base['links'] = [$softNav];
        }

        return $this->finish(array_merge(['ok' => true], $base), $tokenNote);
    }

    private function finish(array $result, string $tokenNote): array
    {
        if ($tokenNote !== '' && !empty($result['reply'])) {
            $result['reply'] .= $tokenNote;
        }
        $result['mode'] = 'smart';
        $result['links'] = $result['links'] ?? [];
        $result['actions'] = $result['actions'] ?? [];
        return $result;
    }

    private function looksLikeAdviceRequest(string $lower): bool
    {
        if (preg_match('/\b(how many|how much|count|number of)\b/', $lower)) {
            return false;
        }
        return (bool) preg_match('/\b(what should|advise|help me|explain|summarize|summary|focus|prepare|recommend|suggest|what do i|how do i|tell me about)\b/', $lower);
    }

    private function shouldDeferNavigationForAction(string $lower): bool
    {
        if (preg_match('/\b(book|schedule|make|cancel|delete|remove|update|change|edit|want to|need to|work on)\b/', $lower)) {
            return (bool) preg_match('/\b(appointment|phone|email|address|name|case|cases|profile|callback|invoice|billing|evidence|dispute|fight)\b/', $lower);
        }
        if (preg_match('/\b(request|submit)\b/', $lower) && preg_match('/\b(callback|billing|evidence)\b/', $lower)) {
            return true;
        }
        return false;
    }

    // ── Navigation ───────────────────────────────────────────────────────────

    private function tryNavigation(string $message, string $lower): ?array
    {
        if ($this->shouldDeferNavigationForAction($lower)) {
            return null;
        }

        $role = $this->context['role'] ?? 'guest';
        $wantsRedirect = (bool) preg_match(
            '/\b(take me to|go to|open|redirect|navigate|bring me to|send me to|show me the|i want to (?:go|see|open)|let me see|view my|launch|visit)\b/i',
            $message
        );

        if (preg_match('/\b(?:open|view|show|go to)\s+(?:case\s+)?c[\-\s]?(\d{1,6})\b/i', $message, $m)) {
            $caseId = (int) $m[1];
            if ($role === 'client' && $this->clientOwnsCase($caseId)) {
                $url = 'client-case-view.php?id=' . $caseId;
                return $this->navResult('Opening **' . $this->caseLabel($caseId) . '**…', $url, 'View case', $wantsRedirect);
            }
            if ($role === 'lawyer') {
                $url = 'lawyer-case-view.php?id=' . $caseId;
                return $this->navResult('Opening case **C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) . '**…', $url, 'View case', $wantsRedirect);
            }
            if ($role === 'admin') {
                $url = 'case-view.php?id=' . $caseId;
                return $this->navResult('Opening case **C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) . '**…', $url, 'View case', true);
            }
        }

        $pages = $this->pageMap($role);
        foreach ($pages as $page) {
            foreach ($page['triggers'] as $trigger) {
                if (strpos($lower, $trigger) === false) {
                    continue;
                }
                $doRedirect = $wantsRedirect || !empty($page['auto_redirect']);
                return $this->navResult($page['reply'], $page['url'], $page['label'], $doRedirect);
            }
        }

        return null;
    }

    private function tryStaffNavigation(string $message, string $lower, string $role): ?array
    {
        if (!preg_match('/\b(open|go to|take me)\b/i', $message)) {
            return null;
        }
        $pages = $this->pageMap($role);
        foreach ($pages as $page) {
            foreach ($page['triggers'] as $trigger) {
                if (strpos($lower, $trigger) !== false) {
                    return $this->navResult($page['reply'], $page['url'], $page['label'], true);
                }
            }
        }
        return null;
    }

    private function navResult(string $reply, string $url, string $label, bool $redirect): array
    {
        $out = [
            'ok' => true,
            'reply' => $redirect ? $reply : $reply . "\n\nTap **{$label}** below when you're ready.",
            'links' => [['label' => $label, 'url' => $url]],
        ];
        if ($redirect) {
            $out['redirect'] = $url;
            $out['redirect_delay'] = 900;
        }
        return $out;
    }

    private function pageMap(string $role): array
    {
        if ($role === 'client') {
            return [
                ['triggers' => ['dashboard', 'home page'], 'url' => 'client-dashboard.php', 'label' => 'Dashboard', 'reply' => 'Taking you to your **dashboard**…', 'auto_redirect' => false],
                ['triggers' => ['my cases', 'case list', 'cases page', 'all cases'], 'url' => 'client-cases.php', 'label' => 'My cases', 'reply' => 'Opening **My cases**…', 'auto_redirect' => false],
                ['triggers' => ['appointment', 'book', 'schedule', 'calendar'], 'url' => 'client-appointments.php', 'label' => 'Appointments', 'reply' => 'Opening **Appointments** — you can book or manage meetings there.', 'auto_redirect' => false],
                ['triggers' => ['document', 'files', 'upload'], 'url' => 'client-documents.php', 'label' => 'Documents', 'reply' => 'Opening your **Document center**…', 'auto_redirect' => false],
                ['triggers' => ['payment', 'invoice', 'billing', 'pay'], 'url' => 'client-payments.php', 'label' => 'Payments', 'reply' => 'Opening **Payments** so you can review invoices and pay online.', 'auto_redirect' => false],
                ['triggers' => ['court', 'hearing', 'court tracking'], 'url' => 'client-court-tracking.php', 'label' => 'Court tracking', 'reply' => 'Opening **Court tracking** for your upcoming hearings.', 'auto_redirect' => false],
                ['triggers' => ['profile', 'my account', 'personal info'], 'url' => 'client-profile.php', 'label' => 'Profile', 'reply' => 'Opening your **Profile**…', 'auto_redirect' => false],
                ['triggers' => ['settings', 'preferences', 'notification'], 'url' => 'client-settings.php', 'label' => 'Settings', 'reply' => 'Opening **Settings**…', 'auto_redirect' => false],
                ['triggers' => ['request', 'callback', 'my requests'], 'url' => 'client-requests.php', 'label' => 'My requests', 'reply' => 'Opening **My requests**…', 'auto_redirect' => false],
            ];
        }
        if ($role === 'lawyer') {
            return [
                ['triggers' => ['dashboard'], 'url' => 'lawyer-dashboard.php', 'label' => 'Dashboard', 'reply' => 'Opening your dashboard…'],
                ['triggers' => ['cases', 'my cases'], 'url' => 'lawyer-cases.php', 'label' => 'Cases', 'reply' => 'Opening your cases…'],
                ['triggers' => ['appointment', 'calendar'], 'url' => 'lawyer-appointments.php', 'label' => 'Appointments', 'reply' => 'Opening appointments…'],
                ['triggers' => ['task'], 'url' => 'lawyer-tasks.php', 'label' => 'Tasks', 'reply' => 'Opening tasks…'],
                ['triggers' => ['court'], 'url' => 'lawyer-court-tracking.php', 'label' => 'Court', 'reply' => 'Opening court tracking…'],
            ];
        }
        return [
            ['triggers' => ['dashboard'], 'url' => 'dashboard.php', 'label' => 'Dashboard', 'reply' => 'Opening admin dashboard…'],
            ['triggers' => ['cases', 'case list'], 'url' => 'tables.php', 'label' => 'Cases', 'reply' => 'Opening cases…'],
            ['triggers' => ['appointment'], 'url' => 'appointments.php', 'label' => 'Appointments', 'reply' => 'Opening appointments…'],
            ['triggers' => ['client'], 'url' => 'clients.php', 'label' => 'Clients', 'reply' => 'Opening clients…'],
            ['triggers' => ['payment', 'invoice'], 'url' => 'payments.php', 'label' => 'Payments', 'reply' => 'Opening payments…'],
            ['triggers' => ['setting'], 'url' => 'settings.php', 'label' => 'Settings', 'reply' => 'Opening settings…'],
        ];
    }

    private function suggestNavigationLink(string $lower): ?array
    {
        if ($this->context['role'] !== 'client') {
            return null;
        }
        if (strpos($lower, 'pay') !== false || strpos($lower, 'invoice') !== false) {
            return ['label' => 'Payments', 'url' => 'client-payments.php'];
        }
        if (strpos($lower, 'appointment') !== false || strpos($lower, 'meeting') !== false) {
            return ['label' => 'Appointments', 'url' => 'client-appointments.php'];
        }
        return null;
    }

    // ── Client actions ─────────────────────────────────────────────────────────

    private function tryClientActions(string $message, string $lower): ?array
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        if ($clientId <= 0) {
            return null;
        }

        $caseWork = $this->tryCaseWorkIntent($message, $lower);
        if ($caseWork !== null) {
            return $caseWork;
        }

        if (preg_match('/update\s+(?:my\s+)?phone\s+(?:to\s+|number\s+)?([+\d\s\-().]{7,})/i', $message, $m)) {
            return $this->updateProfileField('phone', trim($m[1]), 'phone number');
        }
        if (preg_match('/update\s+(?:my\s+)?email\s+(?:to\s+)?([^\s]+@[^\s]+)/i', $message, $m)) {
            return $this->updateProfileField('email', trim($m[1]), 'email');
        }
        if (preg_match('/update\s+(?:my\s+)?address\s+(?:to\s+)?(.+)/i', $message, $m)) {
            $addr = trim(preg_replace('/\s*(please|thanks|thank you)\.?$/i', '', trim($m[1])));
            if ($addr !== '') {
                return $this->updateProfileField('address', $addr, 'address');
            }
        }
        if (preg_match('/update\s+(?:my\s+)?(?:first\s+)?name\s+(?:to\s+)?([a-z\-\' ]{2,40})/i', $message, $m)) {
            return $this->updateProfileField('first_name', trim($m[1]), 'first name');
        }
        if (preg_match('/update\s+(?:my\s+)?last\s+name\s+(?:to\s+)?([a-z\-\' ]{2,40})/i', $message, $m)) {
            return $this->updateProfileField('last_name', trim($m[1]), 'last name');
        }
        if (preg_match('/emergency\s+contact\s+(?:name\s+)?(?:to\s+)?([a-z\-\' ]{2,60})/i', $message, $m)) {
            return $this->updateProfileField('emergency_contact_name', trim($m[1]), 'emergency contact name');
        }
        if (preg_match('/emergency\s+(?:contact\s+)?phone\s+(?:to\s+)?([+\d\s\-().]{7,})/i', $message, $m)) {
            return $this->updateProfileField('emergency_contact_phone', trim($m[1]), 'emergency contact phone');
        }

        if (preg_match('/(?:request|need)\s+(?:a\s+)?callback|call\s+me\s+back/i', $lower)) {
            $caseId = $this->extractCaseId($message);
            $r = legalpro_client_submit_request($this->pdo, $clientId, [
                'request_type' => 'callback',
                'case_id' => $caseId,
                'message' => $message,
                'subject' => 'Callback request (via assistant)',
            ], null);
            if ($r['ok']) {
                return [
                    'ok' => true,
                    'reply' => "Done — I've sent a **callback request** to your legal team. They'll reach out soon.",
                    'links' => [['label' => 'My requests', 'url' => 'client-requests.php']],
                    'actions' => ['callback_submitted'],
                ];
            }
        }

        if (preg_match('/billing\s+question|question\s+about\s+(?:my\s+)?invoice|dispute\s+(?:an\s+)?invoice/i', $lower)
            && !preg_match('/\bcase\b/i', $lower)) {
            $r = legalpro_client_submit_request($this->pdo, $clientId, [
                'request_type' => 'billing',
                'case_id' => $this->extractCaseId($message),
                'message' => $message,
                'subject' => 'Billing question (via assistant)',
            ], null);
            if ($r['ok']) {
                return [
                    'ok' => true,
                    'reply' => 'Your **billing question** has been submitted. The billing team will review it.',
                    'links' => [['label' => 'My requests', 'url' => 'client-requests.php']],
                    'actions' => ['billing_submitted'],
                ];
            }
        }

        if (preg_match('/submit\s+evidence|upload\s+evidence|send\s+evidence/i', $lower)) {
            $r = legalpro_client_submit_request($this->pdo, $clientId, [
                'request_type' => 'evidence',
                'case_id' => $this->extractCaseId($message),
                'message' => $message,
                'subject' => 'Evidence submission (via assistant)',
            ], null);
            if ($r['ok']) {
                return [
                    'ok' => true,
                    'reply' => "I've logged your **evidence request**. For file uploads, open **Documents** or tell your lawyer what you're sending.",
                    'links' => [
                        ['label' => 'Documents', 'url' => 'client-documents.php'],
                        ['label' => 'My requests', 'url' => 'client-requests.php'],
                    ],
                    'actions' => ['evidence_submitted'],
                ];
            }
        }

        if (preg_match('/\b(book|schedule|make)\s+(?:an?\s+)?appointment\b/i', $lower)) {
            return $this->handleBookAppointment($message, $lower);
        }

        if (preg_match('/\b(cancel|delete|remove)\s+(?:my\s+)?(?:rejected\s+)?appointment\b/i', $lower)) {
            return $this->handleRemoveAppointment($message);
        }

        if (preg_match('/\b(update|change|edit|manage)\s+(?:my\s+)?appointments?\b/i', $lower)) {
            return $this->navResult(
                'You can **book**, view, or remove rejected appointments on the Appointments page. Tell me a date & time here, or I can open it for you.',
                'client-appointments.php',
                'Appointments',
                (bool) preg_match('/\b(open|go|take me)\b/i', $message)
            );
        }

        return null;
    }

    private function tryCaseWorkIntent(string $message, string $lower): ?array
    {
        $mentionsCase = (bool) preg_match('/\bcases?\b/i', $lower);
        $wantsWork = (bool) preg_match(
            '/\b(update|change|edit|modify|fix|work on|open|view|see|check|go to|want to|need to|help with|something on|about my)\b/i',
            $lower
        );

        $matchedCase = $this->resolveCaseFromMessage($message);

        if ($matchedCase === null) {
            if ($wantsWork && $mentionsCase) {
                return $this->replyWithCasePicker(
                    "I'd like to help — which case do you mean? Here are yours:"
                );
            }
            return null;
        }

        if (!$wantsWork && !$mentionsCase) {
            $titleWords = preg_split('/\s+/', strtolower((string) $matchedCase['title']));
            $hitTitle = false;
            foreach ($titleWords as $w) {
                if (strlen($w) >= 4 && strpos($lower, $w) !== false) {
                    $hitTitle = true;
                    break;
                }
            }
            if (!$hitTitle) {
                return null;
            }
        }

        return $this->handleCaseWork($matchedCase, $message, $lower);
    }

    private function handleCaseWork(array $case, string $message, string $lower): array
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        $caseId = (int) $case['id'];
        $label = $this->caseLabel($caseId);
        $title = (string) ($case['title'] ?? '');
        $status = ucfirst(str_replace('_', ' ', (string) ($case['status'] ?? '')));
        $url = 'client-case-view.php?id=' . $caseId;

        $wantsUpdate = (bool) preg_match('/\b(update|change|edit|modify|fix|work on)\b/i', $lower);
        $submitted = false;

        if ($wantsUpdate && $clientId > 0) {
            $r = legalpro_client_submit_request($this->pdo, $clientId, [
                'request_type' => 'callback',
                'case_id' => $caseId,
                'message' => $message,
                'subject' => 'Case update: ' . $title,
            ], null);
            $submitted = $r['ok'] ?? false;
        }

        $lines = "Got it — **{$label} — {$title}** ({$status}).\n\n";

        if ($submitted) {
            $lines .= "I've sent your update request to your **legal team** for this case.\n\n";
        }

        $lines .= "Opening the case page where you can:\n";
        $lines .= "• **Add a comment** with details\n";
        $lines .= "• **Upload documents**\n";
        $lines .= "• See appointments & court dates\n\n";
        $lines .= "_Status and legal details are updated by your lawyer — your message has been logged._";

        return [
            'ok' => true,
            'reply' => $lines,
            'redirect' => $url,
            'redirect_delay' => 1400,
            'links' => [
                ['label' => 'Open ' . $label, 'url' => $url],
                ['label' => 'My requests', 'url' => 'client-requests.php'],
            ],
            'actions' => $submitted ? ['case_update_submitted', 'case_opened'] : ['case_opened'],
        ];
    }

    private function replyWithCasePicker(string $intro): array
    {
        $data = $this->loadClientData();
        $cases = $data['cases'] ?? [];
        if (!$cases) {
            return [
                'ok' => true,
                'reply' => 'You have no cases on file yet.',
                'links' => [['label' => 'My cases', 'url' => 'client-cases.php']],
            ];
        }

        $lines = $intro . "\n\n";
        foreach ($cases as $c) {
            $lines .= '• **' . $this->caseLabel((int) $c['id']) . '** — ' . ($c['title'] ?? '') . ' (' . ($c['status'] ?? '') . ")\n";
        }
        $lines .= "\nSay e.g. _update the **" . strtolower((string) ($cases[0]['title'] ?? 'dispute')) . "** case_ or _open C-"
            . str_pad((string) ($cases[0]['id'] ?? 0), 4, '0', STR_PAD_LEFT) . '_';

        return [
            'ok' => true,
            'reply' => trim($lines),
            'links' => [['label' => 'My cases', 'url' => 'client-cases.php']],
        ];
    }

    private function resolveCaseFromMessage(string $message): ?array
    {
        $caseId = $this->extractCaseId($message);
        if ($caseId > 0) {
            $stmt = $this->pdo->prepare('SELECT id, title, status FROM cases WHERE id = ? AND client_id = ?');
            $stmt->execute([$caseId, (int) ($this->context['client_id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        return $this->findCaseByTitleKeywords($message);
    }

    private function findCaseByTitleKeywords(string $message): ?array
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        if ($clientId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, title, status FROM cases WHERE client_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$clientId]);
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$cases) {
            return null;
        }

        $lower = strtolower($message);
        $noise = ['case', 'cases', 'my', 'the', 'a', 'an', 'to', 'update', 'change', 'edit', 'open', 'view', 'want', 'i', 'need', 'please', 'help', 'with', 'on', 'for', 'about'];
        $lower = preg_replace('/\b(' . implode('|', $noise) . ')\b/i', ' ', $lower);
        $lower = preg_replace('/\s+/', ' ', trim($lower));

        $best = null;
        $bestScore = 0;

        foreach ($cases as $c) {
            $title = strtolower(trim((string) $c['title']));
            if ($title === '') {
                continue;
            }

            if ($lower !== '' && strpos($lower, $title) !== false) {
                return $c;
            }

            if ($title !== '' && strpos($message, $title) !== false) {
                return $c;
            }

            $titleWords = preg_split('/\s+/', $title);
            foreach ($titleWords as $word) {
                if (strlen($word) < 3) {
                    continue;
                }
                if (strpos($lower, $word) !== false || preg_match('/\b' . preg_quote($word, '/') . '\b/i', $message)) {
                    $score = strlen($word);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $c;
                    }
                }
            }
        }

        return $best;
    }

    private function updateProfileField(string $column, string $value, string $label): array
    {
        $allowed = ['phone', 'email', 'address', 'first_name', 'last_name', 'emergency_contact_name', 'emergency_contact_phone'];
        if (!in_array($column, $allowed, true)) {
            return ['ok' => false, 'reply' => 'That field cannot be updated here.'];
        }

        $clientId = (int) ($this->context['client_id'] ?? 0);
        if ($column === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => true, 'reply' => 'That does not look like a valid **email**. Please try again, e.g. `update my email to you@example.com`'];
        }

        try {
            $this->pdo->prepare("UPDATE clients SET {$column} = ? WHERE id = ?")->execute([$value, $clientId]);
            if ($column === 'first_name' || $column === 'last_name') {
                $stmt = $this->pdo->prepare('SELECT first_name, last_name FROM clients WHERE id = ?');
                $stmt->execute([$clientId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $_SESSION['client_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
                }
            }
            return [
                'ok' => true,
                'reply' => "Updated your **{$label}** to **{$value}**.",
                'links' => [['label' => 'Profile', 'url' => 'client-profile.php']],
                'actions' => ['profile_updated'],
            ];
        } catch (PDOException $e) {
            error_log('chatbot profile update: ' . $e->getMessage());
            return ['ok' => true, 'reply' => 'I could not save that change. Please update your **Profile** page directly.', 'links' => [['label' => 'Profile', 'url' => 'client-profile.php']]];
        }
    }

    private function handleBookAppointment(string $message, string $lower): array
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        $caseId = $this->extractCaseId($message);

        $dateStr = null;
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $message, $m)) {
            $dateStr = $m[1];
        } elseif (preg_match('/\b(tomorrow|today|next\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b/i', $message, $m)) {
            $ts = strtotime($m[1]);
            if ($ts) {
                $dateStr = date('Y-m-d', $ts);
            }
        } elseif (preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}(?:st|nd|rd|th)?(?:\s+\d{4})?\b/i', $message, $m)) {
            $ts = strtotime($m[0]);
            if ($ts) {
                $dateStr = date('Y-m-d', $ts);
            }
        }

        $timeStr = null;
        if (preg_match('/\b(\d{1,2}):(\d{2})\s*(am|pm)?\b/i', $message, $m)) {
            $h = (int) $m[1];
            $min = $m[2];
            $ampm = strtolower($m[3] ?? '');
            if ($ampm === 'pm' && $h < 12) {
                $h += 12;
            }
            if ($ampm === 'am' && $h === 12) {
                $h = 0;
            }
            $timeStr = sprintf('%02d:%s', $h, $min);
        } elseif (preg_match('/\b(\d{1,2})\s*(am|pm)\b/i', $message, $m)) {
            $h = (int) $m[1];
            if (strtolower($m[2]) === 'pm' && $h < 12) {
                $h += 12;
            }
            if (strtolower($m[2]) === 'am' && $h === 12) {
                $h = 0;
            }
            $timeStr = sprintf('%02d:00', $h);
        }

        if (!$caseId || !$dateStr || !$timeStr) {
            $missing = [];
            if (!$caseId) {
                $missing[] = 'case (e.g. C-0003)';
            }
            if (!$dateStr) {
                $missing[] = 'date (e.g. tomorrow or 2026-06-15)';
            }
            if (!$timeStr) {
                $missing[] = 'time (e.g. 2:30 pm)';
            }
            return [
                'ok' => true,
                'reply' => "To **book an appointment**, I need: " . implode(', ', $missing) . ".\n\nExample: _Book appointment for case C-0003 tomorrow at 2:30 pm_\n\nOr I can open the booking page for you.",
                'links' => [['label' => 'Book appointment', 'url' => 'client-appointments.php']],
                'redirect' => preg_match('/\b(open|just)\b/i', $message) ? 'client-appointments.php' : null,
                'redirect_delay' => 1200,
            ];
        }

        $lawyerId = $this->resolveLawyerForCase($caseId, $clientId);
        if ($lawyerId <= 0) {
            return [
                'ok' => true,
                'reply' => "No lawyer is assigned to that case yet. I've opened **Appointments** — pick a lawyer there, or request a **callback**.",
                'links' => [['label' => 'Appointments', 'url' => 'client-appointments.php']],
                'redirect' => 'client-appointments.php',
                'redirect_delay' => 1500,
            ];
        }

        $check = validateLawyerBookingAvailability($this->pdo, $lawyerId, $dateStr, $timeStr);
        if (!$check['ok']) {
            return [
                'ok' => true,
                'reply' => '**' . ($check['message'] ?? 'That slot is not available.') . "**\n\nTry another time or open **Appointments** to see available slots.",
                'links' => [['label' => 'Appointments', 'url' => 'client-appointments.php']],
            ];
        }

        try {
            $start = $dateStr . ' ' . $timeStr . ':00';
            $end = date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));
            $notes = 'Booked via AI assistant';
            $stmt = $this->pdo->prepare("INSERT INTO appointments (client_id, case_id, lawyer_id, starts_at, ends_at, notes, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$clientId, $caseId, $lawyerId, $start, $end, $notes]);
            $apptId = (int) $this->pdo->lastInsertId();
            syncAppointmentAvailabilitySlot($this->pdo, [
                'id' => $apptId,
                'lawyer_id' => $lawyerId,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => 'pending',
            ]);

            $when = date('l, M j \a\t g:i A', strtotime($start));
            return [
                'ok' => true,
                'reply' => "**Appointment requested** for {$when} on **" . $this->caseLabel($caseId) . "**.\n\nStatus: **pending** — your lawyer will confirm.",
                'links' => [['label' => 'Appointments', 'url' => 'client-appointments.php']],
                'actions' => ['appointment_booked'],
            ];
        } catch (PDOException $e) {
            error_log('chatbot book appt: ' . $e->getMessage());
            return ['ok' => true, 'reply' => 'Could not book that slot. Please use the **Appointments** page.', 'links' => [['label' => 'Appointments', 'url' => 'client-appointments.php']]];
        }
    }

    private function handleRemoveAppointment(string $message): ?array
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        $apptId = 0;
        if (preg_match('/appointment\s*#?(\d+)/i', $message, $m)) {
            $apptId = (int) $m[1];
        }

        if ($apptId <= 0) {
            return [
                'ok' => true,
                'reply' => 'To remove a **rejected** appointment, say e.g. _remove appointment #12_ or open **Appointments**.',
                'links' => [['label' => 'Appointments', 'url' => 'client-appointments.php']],
                'redirect' => preg_match('/\b(open|go)\b/i', $message) ? 'client-appointments.php' : null,
            ];
        }

        try {
            $stmt = $this->pdo->prepare("SELECT id, status FROM appointments WHERE id = ? AND client_id = ?");
            $stmt->execute([$apptId, $clientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['ok' => true, 'reply' => 'Appointment not found.'];
            }
            if (($row['status'] ?? '') !== 'rejected') {
                return [
                    'ok' => true,
                    'reply' => 'Only **rejected** appointments can be removed here. For pending/accepted ones, request a **callback** to reschedule.',
                    'links' => [['label' => 'Appointments', 'url' => 'client-appointments.php']],
                ];
            }
            $this->pdo->prepare("DELETE FROM appointments WHERE id = ? AND client_id = ? AND status = 'rejected'")->execute([$apptId, $clientId]);
            return [
                'ok' => true,
                'reply' => "Removed rejected appointment **#{$apptId}**.",
                'links' => [['label' => 'Appointments', 'url' => 'client-appointments.php']],
                'actions' => ['appointment_removed'],
            ];
        } catch (PDOException $e) {
            return ['ok' => true, 'reply' => 'Could not remove that appointment.'];
        }
    }

    // ── Insights & advice ──────────────────────────────────────────────────────

    private function tryClientInsights(string $message, string $lower): ?array
    {
        if (preg_match('/\b(weekly|full)\s+summary\b|\bsummarize\s+(?:my\s+)?(?:account|everything|cases)\b/i', $lower)) {
            return ['ok' => true, 'reply' => $this->buildWeeklyBriefing(), 'links' => $this->defaultClientLinks()];
        }

        if (preg_match('/\b(court|hearing)\s+prep|\bprepare\b.*\b(court|hearing)\b/i', $lower)) {
            return ['ok' => true, 'reply' => $this->buildCourtPrepAdvice(), 'links' => [
                ['label' => 'Court tracking', 'url' => 'client-court-tracking.php'],
                ['label' => 'Documents', 'url' => 'client-documents.php'],
            ]];
        }

        if (preg_match('/\b(how much|what).*\b(owe|due|balance)\b|\boutstanding\s+invoice/i', $lower)) {
            return ['ok' => true, 'reply' => $this->buildPaymentSummary(), 'links' => [
                ['label' => 'Pay now', 'url' => 'client-payments.php'],
            ], 'redirect' => preg_match('/\b(pay|open)\b/i', $lower) ? 'client-payments.php' : null, 'redirect_delay' => 1000];
        }

        if (preg_match('/\bwhat should i (?:do|focus)|priority|this week\b/i', $lower)) {
            return ['ok' => true, 'reply' => $this->buildWeeklyBriefing() . "\n\n" . $this->buildPriorityTips(), 'links' => $this->defaultClientLinks()];
        }

        return null;
    }

    private function buildWeeklyBriefing(): string
    {
        $data = $this->loadClientData();
        $name = $this->context['display_name'] ?? 'there';
        $lines = ["Hello **{$name}**, here's your account briefing:\n"];

        $cases = $data['cases'] ?? [];
        $open = array_filter($cases, fn($c) => ($c['status'] ?? '') !== 'closed');
        $lines[] = '**Cases:** ' . count($open) . ' active';
        foreach (array_slice($open, 0, 4) as $c) {
            $lines[] = '• **' . $this->caseLabel((int) $c['id']) . '** — ' . ($c['title'] ?? '') . ' (' . ($c['status'] ?? '') . ')';
        }

        $appts = $data['upcoming_appointments'] ?? [];
        $lines[] = "\n**Upcoming appointments:** " . count($appts);
        foreach (array_slice($appts, 0, 3) as $a) {
            $when = !empty($a['starts_at']) ? date('M j, g:i A', strtotime($a['starts_at'])) : 'TBD';
            $lines[] = '• ' . $when . ' — ' . ($a['case_title'] ?? 'General') . ' (' . ($a['status'] ?? '') . ')';
        }

        $courts = $data['upcoming_court_dates'] ?? [];
        if ($courts) {
            $lines[] = "\n**Court dates:**";
            foreach (array_slice($courts, 0, 3) as $cd) {
                $when = !empty($cd['court_date']) ? date('M j, Y g:i A', strtotime($cd['court_date'])) : '';
                $lines[] = '• ' . $when . ' — ' . ($cd['title'] ?? 'Hearing') . ' (' . ($cd['case_title'] ?? '') . ')';
            }
        }

        $owed = 0.0;
        $dueCount = 0;
        foreach ($data['invoices'] ?? [] as $inv) {
            $bal = (float) ($inv['balance_due'] ?? 0);
            if ($bal > 0) {
                $owed += $bal;
                $dueCount++;
            }
        }
        if ($dueCount > 0) {
            $lines[] = "\n**Billing:** **{$dueCount}** invoice(s) with **" . $this->formatMoney($owed) . '** outstanding';
        } else {
            $lines[] = "\n**Billing:** No outstanding balance — you're up to date.";
        }

        $pct = (int) ($data['profile_completeness_percent'] ?? 100);
        if ($pct < 100) {
            $lines[] = "\n**Profile:** {$pct}% complete — say _update my phone to …_ or open **Profile**.";
        }

        return implode("\n", $lines);
    }

    private function buildCourtPrepAdvice(): string
    {
        $data = $this->loadClientData();
        $courts = $data['upcoming_court_dates'] ?? [];
        if (!$courts) {
            return "You have **no upcoming court dates** on file. Check **Court tracking** or ask your lawyer if a date was recently set.";
        }

        $next = $courts[0];
        $when = date('l, F j, Y \a\t g:i A', strtotime((string) $next['court_date']));
        $case = $next['case_title'] ?? 'your case';

        return "**Next hearing:** {$when}\n**Case:** {$case}\n\n**How to prepare:**\n"
            . "• Review documents for this case in **Documents**\n"
            . "• Note questions for your lawyer — I can **request a callback**\n"
            . "• Arrive 15–20 minutes early with ID\n"
            . "• Bring any evidence your lawyer requested\n\n"
            . "_Your lawyer has final say on legal strategy — this is practical preparation only._";
    }

    private function buildPaymentSummary(): string
    {
        $data = $this->loadClientData();
        $lines = ["**Invoice summary:**\n"];
        $totalDue = 0.0;
        $found = false;
        foreach ($data['invoices'] ?? [] as $inv) {
            $bal = (float) ($inv['balance_due'] ?? 0);
            if ($bal <= 0) {
                continue;
            }
            $found = true;
            $totalDue += $bal;
            $num = $inv['invoice_number'] ?? ('INV-' . $inv['id']);
            $due = !empty($inv['due_date']) ? date('M j, Y', strtotime($inv['due_date'])) : '—';
            $lines[] = '• **' . $num . '** — ' . $this->formatMoney($bal) . ' due ' . $due . ' (' . ($inv['case_title'] ?? '') . ')';
        }
        if (!$found) {
            return 'You have **no outstanding invoices**. Thank you!';
        }
        $lines[] = "\n**Total due:** **" . $this->formatMoney($totalDue) . '**';
        $lines[] = "\nSay **take me to payments** to pay online, or ask a **billing question**.";
        return implode("\n", $lines);
    }

    private function buildPriorityTips(): string
    {
        $data = $this->loadClientData();
        $tips = [];
        foreach ($data['upcoming_court_dates'] ?? [] as $cd) {
            $days = (int) floor((strtotime((string) $cd['court_date']) - time()) / 86400);
            if ($days >= 0 && $days <= 14) {
                $tips[] = 'Court hearing in **' . $days . ' day(s)** — review documents and talk to your lawyer.';
                break;
            }
        }
        foreach ($data['invoices'] ?? [] as $inv) {
            if ((float) ($inv['balance_due'] ?? 0) > 0) {
                $tips[] = 'Pay or discuss outstanding **invoices** to avoid delays.';
                break;
            }
        }
        foreach ($data['upcoming_appointments'] ?? [] as $a) {
            if (($a['status'] ?? '') === 'pending') {
                $tips[] = 'You have a **pending** appointment awaiting lawyer approval.';
                break;
            }
        }
        if (!$tips) {
            $tips[] = 'Keep your **profile** up to date and check **Documents** for anything new.';
        }
        return "**Suggested focus:**\n• " . implode("\n• ", $tips);
    }

    private function defaultClientLinks(): array
    {
        return [
            ['label' => 'Dashboard', 'url' => 'client-dashboard.php'],
            ['label' => 'My cases', 'url' => 'client-cases.php'],
            ['label' => 'Appointments', 'url' => 'client-appointments.php'],
        ];
    }

    // ── Data helpers ───────────────────────────────────────────────────────────

    private function loadClientData(): array
    {
        if ($this->clientData !== null) {
            return $this->clientData;
        }

        $clientId = (int) ($this->context['client_id'] ?? 0);
        $data = ['client_id' => $clientId];

        try {
            $stmt = $this->pdo->prepare("
                SELECT c.id, c.title, c.status, c.priority, c.updated_at
                FROM cases c WHERE c.client_id = ? ORDER BY c.updated_at DESC LIMIT 15
            ");
            $stmt->execute([$clientId]);
            $data['cases'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->pdo->prepare("
                SELECT a.id, a.starts_at, a.status, a.notes, c.title AS case_title
                FROM appointments a LEFT JOIN cases c ON c.id = a.case_id
                WHERE a.client_id = ? AND a.starts_at >= NOW()
                ORDER BY a.starts_at ASC LIMIT 10
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
                GROUP BY i.id ORDER BY i.issue_date DESC LIMIT 15
            ");
            $stmt->execute([$clientId]);
            $data['invoices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->pdo->prepare("
                SELECT cd.id, cd.court_date, cd.title, cd.status, c.title AS case_title
                FROM court_dates cd INNER JOIN cases c ON c.id = cd.case_id
                WHERE c.client_id = ? AND cd.court_date >= NOW()
                ORDER BY cd.court_date ASC LIMIT 10
            ");
            $stmt->execute([$clientId]);
            $data['upcoming_court_dates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data['profile_completeness_percent'] = legalpro_client_profile_completeness($this->pdo, $clientId)['percent'] ?? 100;
        } catch (PDOException $e) {
            error_log('chatbot smart data: ' . $e->getMessage());
        }

        $this->clientData = $data;
        return $data;
    }

    private function extractCaseId(string $message): int
    {
        if (preg_match('/\bc[\-\s]?(\d{1,6})\b/i', $message, $m)) {
            $id = (int) $m[1];
            if ($this->clientOwnsCase($id)) {
                return $id;
            }
        }
        if (preg_match('/\bcase\s*#?(\d{1,6})\b/i', $message, $m)) {
            $id = (int) $m[1];
            if ($this->clientOwnsCase($id)) {
                return $id;
            }
        }
        return 0;
    }

    private function clientOwnsCase(int $caseId): bool
    {
        if ($caseId <= 0 || ($this->context['role'] ?? '') !== 'client') {
            return $caseId > 0 && ($this->context['role'] ?? '') !== 'client';
        }
        $stmt = $this->pdo->prepare('SELECT id FROM cases WHERE id = ? AND client_id = ?');
        $stmt->execute([$caseId, (int) $this->context['client_id']]);
        return (bool) $stmt->fetch();
    }

    private function resolveLawyerForCase(int $caseId, int $clientId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT l.id FROM lawyers l
            INNER JOIN case_lawyers cl ON cl.lawyer_id = l.id
            INNER JOIN cases c ON c.id = cl.case_id
            WHERE c.id = ? AND c.client_id = ? AND l.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$caseId, $clientId]);
        $id = (int) $stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $stmt = $this->pdo->query('SELECT id FROM lawyers WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        return (int) $stmt->fetchColumn();
    }

    private function caseLabel(int $caseId): string
    {
        return 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
    }

    private function formatMoney(float $amount): string
    {
        if (function_exists('formatCurrency')) {
            return formatCurrency($amount);
        }
        return number_format($amount, 2);
    }
}
