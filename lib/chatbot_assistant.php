<?php

require_once __DIR__ . '/case_lawyers.php';

/**
 * Role-aware assistant: matches intents + FAQ knowledge, then queries live DB data.
 */
class ChatbotAssistant
{
    /** @var PDO */
    private $pdo;

    /** @var array */
    private $context;

    /** @var array|null */
    private $knowledge;

    public function __construct(PDO $pdo, array $context)
    {
        $this->pdo = $pdo;
        $this->context = $context;
    }

    public static function resolveContextFromSession(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!empty($_SESSION['admin_id'])) {
            return [
                'role' => 'admin',
                'admin_id' => (int) $_SESSION['admin_id'],
                'display_name' => isset($_SESSION['admin_username']) ? (string) $_SESSION['admin_username'] : 'Admin',
            ];
        }

        if (!empty($_SESSION['lawyer_id'])) {
            return [
                'role' => 'lawyer',
                'lawyer_id' => (int) $_SESSION['lawyer_id'],
                'display_name' => isset($_SESSION['lawyer_name']) ? (string) $_SESSION['lawyer_name'] : 'Lawyer',
            ];
        }

        if (!empty($_SESSION['client_id'])) {
            return [
                'role' => 'client',
                'client_id' => (int) $_SESSION['client_id'],
                'display_name' => isset($_SESSION['client_name']) ? (string) $_SESSION['client_name'] : 'Client',
            ];
        }

        return ['role' => 'guest', 'display_name' => 'Guest'];
    }

    public function answer(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return $this->result('Please type a question or command.');
        }

        if ($this->context['role'] === 'guest') {
            return $this->result('Please log in as admin, lawyer, or client to use the assistant.');
        }

        $normalized = strtolower($message);
        $normalized = preg_replace('/[^\w\s\-]/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        $faq = $this->matchKnowledge($normalized);
        if ($faq !== null) {
            return $faq;
        }

        if (preg_match('/\bc[\-\s]?(\d{1,6})\b/i', $message, $caseMatch)) {
            return $this->handleCaseLookup((int) $caseMatch[1]);
        }

        if ($this->matchesAny($normalized, ['help', 'what can you', 'what do you do', 'how do i use', 'examples', 'commands'])) {
            return $this->handleHelp();
        }

        if ($this->matchesAny($normalized, ['hello', 'hi ', 'hey', 'good morning', 'good afternoon', 'good evening'])) {
            return $this->handleGreeting();
        }

        if ($this->matchesAny($normalized, ['active case', 'open case', 'my cases', 'how many cases', 'list cases', 'show cases'])) {
            return $this->handleCases($normalized);
        }

        if ($this->matchesAny($normalized, ['appointment', 'appointments', 'meeting', 'schedule', 'upcoming meeting'])) {
            return $this->handleAppointments($normalized);
        }

        if ($this->matchesAny($normalized, ['client', 'clients', 'how many clients'])) {
            return $this->handleClients($normalized);
        }

        if ($this->matchesAny($normalized, ['invoice', 'invoices', 'payment', 'payments', 'billing', 'balance', 'fee', 'fees', 'owe', 'paid'])) {
            return $this->handleFinance($normalized);
        }

        if ($this->matchesAny($normalized, ['document', 'documents', 'file', 'files', 'upload'])) {
            return $this->handleDocuments($normalized);
        }

        if ($this->matchesAny($normalized, ['court', 'hearing', 'court date', 'court tracking'])) {
            return $this->handleCourtDates($normalized);
        }

        if ($this->matchesAny($normalized, ['task', 'tasks', 'todo', 'to do', 'my tasks'])) {
            return $this->handleTasks($normalized);
        }

        if ($this->matchesAny($normalized, ['lawyer', 'lawyers', 'counsel', 'attorney'])) {
            return $this->handleLawyers($normalized);
        }

        if ($this->matchesAny($normalized, ['dashboard', 'summary', 'overview', 'stats', 'statistics'])) {
            return $this->handleDashboardSummary();
        }

        return $this->result(
            "I'm not sure about that yet. Try asking about your **cases**, **appointments**, **payments**, **documents**, or **court dates**. Say **help** for examples."
        );
    }

    private function matchKnowledge(string $normalized): ?array
    {
        $topics = $this->loadKnowledge()['topics'] ?? [];
        foreach ($topics as $topic) {
            if (!empty($topic['roles']) && !in_array($this->context['role'], $topic['roles'], true)) {
                continue;
            }
            foreach ($topic['patterns'] as $pattern) {
                if (strpos($normalized, strtolower($pattern)) !== false) {
                    $response = $topic['response'];
                    if ($response === '__HELP__') {
                        return $this->handleHelp();
                    }
                    if ($response === '__GREETING__') {
                        return $this->handleGreeting();
                    }
                    $links = [];
                    foreach ($topic['links'] ?? [] as $link) {
                        $links[] = [
                            'label' => $link['label'],
                            'url' => $this->replaceUrlTokens($link['url']),
                        ];
                    }
                    return $this->result($response, $links);
                }
            }
        }

        return null;
    }

    private function loadKnowledge(): array
    {
        if ($this->knowledge !== null) {
            return $this->knowledge;
        }

        $path = __DIR__ . '/../data/chatbot_knowledge.json';
        if (!is_readable($path)) {
            $this->knowledge = ['topics' => []];
            return $this->knowledge;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->knowledge = is_array($decoded) ? $decoded : ['topics' => []];
        return $this->knowledge;
    }

    private function replaceUrlTokens(string $url): string
    {
        $map = [
            '__CASES_URL__' => $this->casesUrl(),
            '__APPOINTMENTS_URL__' => $this->appointmentsUrl(),
            '__DOCUMENTS_URL__' => $this->documentsUrl(),
        ];

        return str_replace(array_keys($map), array_values($map), $url);
    }

    private function handleGreeting(): array
    {
        $name = $this->context['display_name'];
        $role = ucfirst($this->context['role']);

        return $this->result("Hello {$name}! I'm your {$role} assistant. Ask me about cases, appointments, documents, payments, or court dates. Say **help** for examples.");
    }

    private function handleHelp(): array
    {
        $role = $this->context['role'];
        $examples = [
            'admin' => [
                'How many active cases do we have?',
                'Show upcoming appointments',
                'List recent clients',
                'Any pending invoices?',
                'Upcoming court dates',
                'Case C-1029',
            ],
            'lawyer' => [
                'Show my active cases',
                'My upcoming appointments',
                'My pending tasks',
                'Case C-1029',
                'Upcoming court dates',
            ],
            'client' => [
                'Show my cases',
                'My upcoming appointments',
                'What is my payment balance?',
                'Documents on my cases',
                'Upcoming court dates',
            ],
        ];

        $list = $examples[$role] ?? $examples['admin'];
        $lines = "Here are things you can ask:\n\n";
        foreach ($list as $item) {
            $lines .= '• ' . $item . "\n";
        }
        $lines .= "\nYou can also mention a case number like **C-1029**.";

        return $this->result($lines, [
            ['label' => 'Open dashboard', 'url' => $this->dashboardUrl()],
        ]);
    }

    private function handleCases(string $normalized): array
    {
        $role = $this->context['role'];

        if ($role === 'admin') {
            $active = (int) $this->pdo->query("SELECT COUNT(*) FROM cases WHERE status != 'closed'")->fetchColumn();
            $total = (int) $this->pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
            $stmt = $this->pdo->query("
                SELECT c.id, c.title, c.status, cl.first_name, cl.last_name
                FROM cases c
                INNER JOIN clients cl ON cl.id = c.client_id
                WHERE c.status != 'closed'
                ORDER BY c.updated_at DESC
                LIMIT 5
            ");
            $rows = $stmt->fetchAll();
            $lines = "There are **{$active}** active cases ({$total} total).\n\nRecent active cases:\n";
            foreach ($rows as $row) {
                $lines .= $this->formatCaseLine($row) . "\n";
            }
            return $this->result(trim($lines), [['label' => 'All cases', 'url' => 'tables.php']]);
        }

        if ($role === 'lawyer') {
            $lawyerId = (int) $this->context['lawyer_id'];
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM cases c
                INNER JOIN case_lawyers cl ON cl.case_id = c.id
                WHERE cl.lawyer_id = ? AND c.status != 'closed'
            ");
            $stmt->execute([$lawyerId]);
            $active = (int) $stmt->fetchColumn();

            $stmt = $this->pdo->prepare("
                SELECT c.id, c.title, c.status, cl.first_name, cl.last_name
                FROM cases c
                INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id
                INNER JOIN clients cl ON cl.id = c.client_id
                WHERE cl2.lawyer_id = ? AND c.status != 'closed'
                ORDER BY c.updated_at DESC
                LIMIT 5
            ");
            $stmt->execute([$lawyerId]);
            $rows = $stmt->fetchAll();

            $lines = "You have **{$active}** active cases assigned to you.\n\n";
            if (empty($rows)) {
                $lines .= 'No active cases found.';
            } else {
                $lines .= "Recent cases:\n";
                foreach ($rows as $row) {
                    $lines .= $this->formatCaseLine($row) . "\n";
                }
            }
            return $this->result(trim($lines), [['label' => 'My cases', 'url' => 'lawyer-cases.php']]);
        }

        $clientId = (int) $this->context['client_id'];
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cases WHERE client_id = ? AND status != 'closed'");
        $stmt->execute([$clientId]);
        $active = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT id, title, status FROM cases
            WHERE client_id = ? AND status != 'closed'
            ORDER BY updated_at DESC
            LIMIT 5
        ");
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll();

        $lines = "You have **{$active}** active case(s).\n\n";
        foreach ($rows as $row) {
            $num = $this->caseNumber((int) $row['id']);
            $status = ucfirst(str_replace('_', ' ', (string) $row['status']));
            $lines .= "• **{$num}** — {$row['title']} ({$status})\n";
        }
        return $this->result(trim($lines), [['label' => 'My cases', 'url' => 'client-cases.php']]);
    }

    private function handleCaseLookup(int $caseId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.*, cl.first_name, cl.last_name, cl.email
            FROM cases c
            INNER JOIN clients cl ON cl.id = c.client_id
            WHERE c.id = ?
        ");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();

        if (!$case) {
            return $this->result('No case found with number **' . $this->caseNumber($caseId) . '**.');
        }

        if (!$this->canAccessCase($caseId, (int) $case['client_id'])) {
            return $this->result('You do not have access to case **' . $this->caseNumber($caseId) . '**.');
        }

        $caseNum = $this->caseNumber($caseId);
        $clientName = trim($case['first_name'] . ' ' . $case['last_name']);
        $status = ucfirst(str_replace('_', ' ', (string) $case['status']));
        $fees = formatCurrency((float) ($case['estimated_fees'] ?? 0));

        $lawyerNames = $this->caseLawyerNames($caseId);
        $docCount = $this->countDocumentsForCase($caseId);
        $nextAppt = $this->nextAppointmentForCase($caseId);

        $lines = "**{$caseNum}** — {$case['title']}\n";
        $lines .= "• Client: {$clientName}\n";
        $lines .= "• Status: {$status}\n";
        $lines .= "• Estimated fees: {$fees}\n";
        $lines .= '• Assigned lawyers: ' . ($lawyerNames !== '' ? $lawyerNames : 'None') . "\n";
        $lines .= "• Documents: {$docCount}\n";
        if ($nextAppt !== '') {
            $lines .= "• Next appointment: {$nextAppt}\n";
        }
        if (!empty($case['description'])) {
            $desc = strlen($case['description']) > 180 ? substr($case['description'], 0, 177) . '...' : $case['description'];
            $lines .= "• Summary: {$desc}\n";
        }

        return $this->result(trim($lines), [
            ['label' => 'Open case', 'url' => $this->caseViewUrl($caseId)],
        ]);
    }

    private function handleAppointments(string $normalized): array
    {
        $role = $this->context['role'];

        if ($role === 'admin') {
            $count = (int) $this->pdo->query("
                SELECT COUNT(*) FROM appointments
                WHERE starts_at >= NOW() AND status IN ('pending', 'accepted')
            ")->fetchColumn();
            $stmt = $this->pdo->query("
                SELECT a.starts_at, a.status, c.title AS case_title, cl.first_name, cl.last_name,
                       l.first_name AS lawyer_first, l.last_name AS lawyer_last
                FROM appointments a
                LEFT JOIN cases c ON c.id = a.case_id
                LEFT JOIN clients cl ON cl.id = a.client_id
                LEFT JOIN lawyers l ON l.id = a.lawyer_id
                WHERE a.starts_at >= NOW() AND a.status IN ('pending', 'accepted')
                ORDER BY a.starts_at ASC
                LIMIT 5
            ");
            $rows = $stmt->fetchAll();
            $lines = "**{$count}** upcoming appointment(s).\n\n";
            foreach ($rows as $row) {
                $lines .= $this->formatAppointmentLine($row) . "\n";
            }
            return $this->result(trim($lines), [['label' => 'Appointments', 'url' => 'appointments.php']]);
        }

        if ($role === 'lawyer') {
            $lawyerId = (int) $this->context['lawyer_id'];
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM appointments
                WHERE lawyer_id = ? AND starts_at >= NOW() AND status IN ('pending', 'accepted')
            ");
            $stmt->execute([$lawyerId]);
            $count = (int) $stmt->fetchColumn();

            $stmt = $this->pdo->prepare("
                SELECT a.starts_at, a.status, c.title AS case_title, cl.first_name, cl.last_name
                FROM appointments a
                LEFT JOIN cases c ON c.id = a.case_id
                LEFT JOIN clients cl ON cl.id = a.client_id
                WHERE a.lawyer_id = ? AND a.starts_at >= NOW() AND a.status IN ('pending', 'accepted')
                ORDER BY a.starts_at ASC
                LIMIT 5
            ");
            $stmt->execute([$lawyerId]);
            $rows = $stmt->fetchAll();
            $lines = "You have **{$count}** upcoming appointment(s).\n\n";
            foreach ($rows as $row) {
                $lines .= $this->formatAppointmentLine($row) . "\n";
            }
            return $this->result(trim($lines), [['label' => 'My appointments', 'url' => 'lawyer-appointments.php']]);
        }

        $clientId = (int) $this->context['client_id'];
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM appointments
            WHERE client_id = ? AND starts_at >= NOW() AND status IN ('pending', 'accepted')
        ");
        $stmt->execute([$clientId]);
        $count = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT a.starts_at, a.status, c.title AS case_title,
                   l.first_name AS lawyer_first, l.last_name AS lawyer_last
            FROM appointments a
            LEFT JOIN cases c ON c.id = a.case_id
            LEFT JOIN lawyers l ON l.id = a.lawyer_id
            WHERE a.client_id = ? AND a.starts_at >= NOW() AND a.status IN ('pending', 'accepted')
            ORDER BY a.starts_at ASC
            LIMIT 5
        ");
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll();
        $lines = "You have **{$count}** upcoming appointment(s).\n\n";
        foreach ($rows as $row) {
            $lines .= $this->formatAppointmentLine($row) . "\n";
        }
        return $this->result(trim($lines), [['label' => 'My appointments', 'url' => 'client-appointments.php']]);
    }

    private function handleClients(string $normalized): array
    {
        if ($this->context['role'] === 'client') {
            return $this->result('Clients can view their own profile from the dashboard. Ask about **your cases** or **appointments** instead.');
        }

        if ($this->context['role'] === 'lawyer') {
            $lawyerId = (int) $this->context['lawyer_id'];
            $stmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT c.client_id) FROM cases c
                INNER JOIN case_lawyers cl ON cl.case_id = c.id
                WHERE cl.lawyer_id = ?
            ");
            $stmt->execute([$lawyerId]);
            $count = (int) $stmt->fetchColumn();

            $stmt = $this->pdo->prepare("
                SELECT DISTINCT cl.id, cl.first_name, cl.last_name, cl.email
                FROM clients cl
                INNER JOIN cases c ON c.client_id = cl.id
                INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id
                WHERE cl2.lawyer_id = ?
                ORDER BY cl.last_name, cl.first_name
                LIMIT 5
            ");
            $stmt->execute([$lawyerId]);
            $rows = $stmt->fetchAll();
            $lines = "You work with **{$count}** client(s).\n\n";
            foreach ($rows as $row) {
                $lines .= '• ' . trim($row['first_name'] . ' ' . $row['last_name']);
                if (!empty($row['email'])) {
                    $lines .= ' (' . $row['email'] . ')';
                }
                $lines .= "\n";
            }
            return $this->result(trim($lines), [['label' => 'My clients', 'url' => 'lawyer-clients.php']]);
        }

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
        $stmt = $this->pdo->query('SELECT first_name, last_name, email FROM clients ORDER BY created_at DESC LIMIT 5');
        $rows = $stmt->fetchAll();
        $lines = "There are **{$count}** clients in the system.\n\nRecent clients:\n";
        foreach ($rows as $row) {
            $name = trim($row['first_name'] . ' ' . $row['last_name']);
            $lines .= '• ' . $name . "\n";
        }
        return $this->result(trim($lines), [['label' => 'Clients', 'url' => 'clients.php']]);
    }

    private function handleFinance(string $normalized): array
    {
        $role = $this->context['role'];

        if ($role === 'admin') {
            $pendingInvoices = (int) $this->pdo->query("SELECT COUNT(*) FROM invoices WHERE status IN ('pending', 'sent', 'overdue')")->fetchColumn();
            $totalPaid = (float) $this->pdo->query('SELECT COALESCE(SUM(amount), 0) FROM payments')->fetchColumn();
            $lines = "**{$pendingInvoices}** open invoice(s).\n";
            $lines .= 'Total payments recorded: **' . formatCurrency($totalPaid) . '**.';
            return $this->result($lines, [
                ['label' => 'Invoices', 'url' => 'invoices.php'],
                ['label' => 'Payments', 'url' => 'payments.php'],
            ]);
        }

        if ($role === 'lawyer') {
            return $this->result('Lawyers can review case fees on individual cases. Try **Case C-1029** or open **My cases**.', [
                ['label' => 'My cases', 'url' => 'lawyer-cases.php'],
            ]);
        }

        $clientId = (int) $this->context['client_id'];
        $stmt = $this->pdo->prepare("
            SELECT c.id, c.title, c.estimated_fees,
                   COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.case_id = c.id), 0) AS paid
            FROM cases c
            WHERE c.client_id = ?
            ORDER BY c.updated_at DESC
            LIMIT 5
        ");
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return $this->result('No case billing information found yet.', [
                ['label' => 'Payments', 'url' => 'client-payments.php'],
            ]);
        }

        $lines = "Payment summary for your cases:\n\n";
        $totalBalance = 0.0;
        foreach ($rows as $row) {
            $fees = (float) $row['estimated_fees'];
            $paid = (float) $row['paid'];
            $balance = max($fees - $paid, 0);
            $totalBalance += $balance;
            $num = $this->caseNumber((int) $row['id']);
            $lines .= "• **{$num}** — balance **" . formatCurrency($balance) . "** (fees " . formatCurrency($fees) . ', paid ' . formatCurrency($paid) . ")\n";
        }
        $lines .= "\nTotal outstanding across listed cases: **" . formatCurrency($totalBalance) . '**.';

        return $this->result(trim($lines), [['label' => 'Payments', 'url' => 'client-payments.php']]);
    }

    private function handleDocuments(string $normalized): array
    {
        $role = $this->context['role'];

        if ($role === 'admin') {
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
            $recent = (int) $this->pdo->query('SELECT COUNT(*) FROM documents WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
            return $this->result("There are **{$count}** documents stored. **{$recent}** uploaded in the last 7 days.", [
                ['label' => 'Documents', 'url' => 'documents.php'],
            ]);
        }

        if ($role === 'lawyer') {
            $lawyerId = (int) $this->context['lawyer_id'];
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM documents d
                INNER JOIN cases c ON c.id = d.case_id
                INNER JOIN case_lawyers cl ON cl.case_id = c.id
                WHERE cl.lawyer_id = ?
            ");
            $stmt->execute([$lawyerId]);
            $count = (int) $stmt->fetchColumn();
            return $this->result("There are **{$count}** document(s) on your assigned cases. Open a case to view uploads.", [
                ['label' => 'My cases', 'url' => 'lawyer-cases.php'],
            ]);
        }

        $clientId = (int) $this->context['client_id'];
        $stmt = $this->pdo->prepare("
            SELECT d.label, d.filename, c.title, c.id AS case_id, d.uploaded_at
            FROM documents d
            INNER JOIN cases c ON c.id = d.case_id
            WHERE c.client_id = ?
            ORDER BY d.uploaded_at DESC
            LIMIT 5
        ");
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            return $this->result('No documents found on your cases yet.');
        }
        $lines = "Recent documents on your cases:\n\n";
        foreach ($rows as $row) {
            $name = !empty($row['label']) ? $row['label'] : $row['filename'];
            $num = $this->caseNumber((int) $row['case_id']);
            $lines .= '• ' . $name . " ({$num})\n";
        }
        return $this->result(trim($lines), [['label' => 'My cases', 'url' => 'client-cases.php']]);
    }

    private function handleCourtDates(string $normalized): array
    {
        try {
            $this->pdo->query('SELECT 1 FROM court_dates LIMIT 1');
        } catch (PDOException $e) {
            return $this->result('Court tracking is available from the **Court Tracking** page.', [
                ['label' => 'Court tracking', 'url' => $this->courtUrl()],
            ]);
        }

        $role = $this->context['role'];
        $sqlBase = "
            SELECT cd.court_date, cd.title, cd.location, c.id AS case_id, c.title AS case_title
            FROM court_dates cd
            LEFT JOIN cases c ON c.id = cd.case_id
            WHERE cd.court_date >= NOW() AND cd.status = 'scheduled'
        ";

        if ($role === 'admin') {
            $stmt = $this->pdo->query($sqlBase . ' ORDER BY cd.court_date ASC LIMIT 5');
        } elseif ($role === 'lawyer') {
            $lawyerId = (int) $this->context['lawyer_id'];
            $stmt = $this->pdo->prepare($sqlBase . "
                AND cd.case_id IN (SELECT case_id FROM case_lawyers WHERE lawyer_id = ?)
                ORDER BY cd.court_date ASC
                LIMIT 5
            ");
            $stmt->execute([$lawyerId]);
        } else {
            $clientId = (int) $this->context['client_id'];
            $stmt = $this->pdo->prepare($sqlBase . '
                AND c.client_id = ?
                ORDER BY cd.court_date ASC
                LIMIT 5
            ');
            $stmt->execute([$clientId]);
        }

        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            return $this->result('No upcoming court dates found.', [
                ['label' => 'Court tracking', 'url' => $this->courtUrl()],
            ]);
        }

        $lines = "Upcoming court dates:\n\n";
        foreach ($rows as $row) {
            $when = date('M j, Y g:i A', strtotime($row['court_date']));
            $title = !empty($row['title']) ? $row['title'] : ($row['case_title'] ?? 'Hearing');
            $court = !empty($row['location']) ? ' @ ' . $row['location'] : '';
            $lines .= "• **{$when}** — {$title}{$court}\n";
        }

        return $this->result(trim($lines), [['label' => 'Court tracking', 'url' => $this->courtUrl()]]);
    }

    private function handleTasks(string $normalized): array
    {
        if ($this->context['role'] !== 'lawyer') {
            return $this->result('Tasks are available in the lawyer portal under **My Tasks**.', [
                ['label' => 'Dashboard', 'url' => $this->dashboardUrl()],
            ]);
        }

        try {
            $this->pdo->query('SELECT 1 FROM tasks LIMIT 1');
        } catch (PDOException $e) {
            return $this->result('Task tracking is not set up yet.');
        }

        $lawyerId = (int) $this->context['lawyer_id'];
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM tasks t
                INNER JOIN task_lawyers tl ON tl.task_id = t.id
                WHERE tl.lawyer_id = ? AND t.status NOT IN ('completed', 'cancelled')
            ");
            $stmt->execute([$lawyerId]);
            $count = (int) $stmt->fetchColumn();

            $stmt = $this->pdo->prepare("
                SELECT t.title, t.status, t.priority, t.due_date
                FROM tasks t
                INNER JOIN task_lawyers tl ON tl.task_id = t.id
                WHERE tl.lawyer_id = ? AND t.status NOT IN ('completed', 'cancelled')
                ORDER BY t.due_date IS NULL, t.due_date ASC
                LIMIT 5
            ");
            $stmt->execute([$lawyerId]);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM tasks
                WHERE assigned_lawyer_id = ? AND status NOT IN ('completed', 'cancelled')
            ");
            $stmt->execute([$lawyerId]);
            $count = (int) $stmt->fetchColumn();

            $stmt = $this->pdo->prepare("
                SELECT title, status, priority, due_date FROM tasks
                WHERE assigned_lawyer_id = ? AND status NOT IN ('completed', 'cancelled')
                ORDER BY due_date IS NULL, due_date ASC
                LIMIT 5
            ");
            $stmt->execute([$lawyerId]);
            $rows = $stmt->fetchAll();
        }

        $lines = "You have **{$count}** open task(s).\n\n";
        foreach ($rows as $row) {
            $due = !empty($row['due_date']) ? date('M j', strtotime($row['due_date'])) : 'No due date';
            $lines .= '• ' . $row['title'] . ' (' . ucfirst($row['status']) . ", due {$due})\n";
        }

        return $this->result(trim($lines), [['label' => 'My tasks', 'url' => 'tasks.php']]);
    }

    private function handleLawyers(string $normalized): array
    {
        if ($this->context['role'] !== 'admin') {
            return $this->result('Ask about **your cases** or **appointments** to see which lawyers are involved.');
        }

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM lawyers WHERE status = 'active'")->fetchColumn();
        $stmt = $this->pdo->query("SELECT first_name, last_name, email, specialization FROM lawyers WHERE status = 'active' ORDER BY last_name LIMIT 5");
        $rows = $stmt->fetchAll();
        $lines = "**{$count}** active lawyer(s).\n\n";
        foreach ($rows as $row) {
            $lines .= '• ' . trim($row['first_name'] . ' ' . $row['last_name']);
            if (!empty($row['specialization'])) {
                $lines .= ' — ' . $row['specialization'];
            }
            $lines .= "\n";
        }
        return $this->result(trim($lines), [['label' => 'Lawyers', 'url' => 'lawyers.php']]);
    }

    private function handleDashboardSummary(): array
    {
        return $this->handleCases('active cases');
    }

    private function canAccessCase(int $caseId, int $clientId): bool
    {
        $role = $this->context['role'];
        if ($role === 'admin') {
            return true;
        }
        if ($role === 'client') {
            return (int) $this->context['client_id'] === $clientId;
        }
        if ($role === 'lawyer') {
            return lawyerHasCaseAccess($this->pdo, $caseId, (int) $this->context['lawyer_id']);
        }

        return false;
    }

    private function caseLawyerNames(int $caseId): string
    {
        $stmt = $this->pdo->prepare("
            SELECT l.first_name, l.last_name
            FROM case_lawyers cl
            INNER JOIN lawyers l ON l.id = cl.lawyer_id
            WHERE cl.case_id = ?
            ORDER BY cl.is_primary DESC, l.last_name
        ");
        $stmt->execute([$caseId]);
        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $names[] = trim($row['first_name'] . ' ' . $row['last_name']);
        }

        return implode(', ', $names);
    }

    private function countDocumentsForCase(int $caseId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM documents WHERE case_id = ?');
        $stmt->execute([$caseId]);

        return (int) $stmt->fetchColumn();
    }

    private function nextAppointmentForCase(int $caseId): string
    {
        $stmt = $this->pdo->prepare("
            SELECT starts_at FROM appointments
            WHERE case_id = ? AND starts_at >= NOW() AND status IN ('pending', 'accepted')
            ORDER BY starts_at ASC
            LIMIT 1
        ");
        $stmt->execute([$caseId]);
        $value = $stmt->fetchColumn();
        if (!$value) {
            return '';
        }

        return date('M j, Y g:i A', strtotime($value));
    }

    private function formatCaseLine(array $row): string
    {
        $num = $this->caseNumber((int) $row['id']);
        $status = ucfirst(str_replace('_', ' ', (string) $row['status']));
        $client = isset($row['first_name']) ? trim($row['first_name'] . ' ' . $row['last_name']) : '';

        $line = "• **{$num}** — {$row['title']} ({$status})";
        if ($client !== '') {
            $line .= " — {$client}";
        }

        return $line;
    }

    private function formatAppointmentLine(array $row): string
    {
        $when = date('M j, Y g:i A', strtotime($row['starts_at']));
        $caseTitle = !empty($row['case_title']) ? $row['case_title'] : 'General meeting';
        $status = ucfirst((string) $row['status']);
        $line = "• **{$when}** — {$caseTitle} ({$status})";

        if (!empty($row['first_name']) || !empty($row['last_name'])) {
            $line .= ' with ' . trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        } elseif (!empty($row['lawyer_first']) || !empty($row['lawyer_last'])) {
            $line .= ' with ' . trim(($row['lawyer_first'] ?? '') . ' ' . ($row['lawyer_last'] ?? ''));
        }

        return $line;
    }

    private function caseNumber(int $id): string
    {
        return 'C-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function dashboardUrl(): string
    {
        if ($this->context['role'] === 'lawyer') {
            return 'lawyer-dashboard.php';
        }
        if ($this->context['role'] === 'client') {
            return 'client-dashboard.php';
        }

        return 'dashboard.php';
    }

    private function casesUrl(): string
    {
        if ($this->context['role'] === 'lawyer') {
            return 'lawyer-cases.php';
        }
        if ($this->context['role'] === 'client') {
            return 'client-cases.php';
        }

        return 'tables.php';
    }

    private function appointmentsUrl(): string
    {
        if ($this->context['role'] === 'lawyer') {
            return 'lawyer-appointments.php';
        }
        if ($this->context['role'] === 'client') {
            return 'client-appointments.php';
        }

        return 'appointments.php';
    }

    private function documentsUrl(): string
    {
        return $this->context['role'] === 'admin' ? 'documents.php' : $this->casesUrl();
    }

    private function courtUrl(): string
    {
        if ($this->context['role'] === 'lawyer') {
            return 'lawyer-court-tracking.php';
        }
        if ($this->context['role'] === 'client') {
            return 'client-court-tracking.php';
        }

        return 'court-tracking.php';
    }

    private function caseViewUrl(int $caseId): string
    {
        if ($this->context['role'] === 'lawyer') {
            return 'lawyer-case-view.php?id=' . $caseId;
        }
        if ($this->context['role'] === 'client') {
            return 'client-case-view.php?id=' . $caseId;
        }

        return 'case-view.php?id=' . $caseId;
    }

    private function result(string $reply, array $links = []): array
    {
        return [
            'reply' => $reply,
            'links' => $links,
        ];
    }
}
