<?php
/**
 * Builds live account context for the AI assistant system prompt.
 */

class ChatbotContextBuilder
{
    private PDO $pdo;
    private array $context;

    public function __construct(PDO $pdo, array $context)
    {
        $this->pdo = $pdo;
        $this->context = $context;
    }

    public function build(): array
    {
        $role = $this->context['role'] ?? 'guest';
        $data = [
            'user' => [
                'name' => $this->context['display_name'] ?? 'User',
                'role' => $role,
            ],
            'firm' => [
                'name' => function_exists('getCompanyName') ? getCompanyName() : 'LegalPro',
            ],
            'portal_capabilities' => $this->portalCapabilities($role),
        ];

        if ($role === 'client') {
            $data['account'] = $this->buildClientAccount();
        } elseif ($role === 'lawyer') {
            $data['workload'] = $this->buildLawyerWorkload();
        } elseif ($role === 'admin') {
            $data['firm_stats'] = $this->buildAdminStats();
        }

        return $data;
    }

    public function buildSystemPrompt(string $assistantName): string
    {
        $ctx = $this->build();
        $role = $ctx['user']['role'];
        $name = $ctx['user']['name'];
        $firm = $ctx['firm']['name'];
        $json = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $lines = [
            "You are {$assistantName}, the AI assistant for {$firm}, a legal case management portal.",
            "The user is {$name} (role: {$role}).",
            '',
            'Rules:',
            '- Answer in clear, friendly plain text. Do not use markdown (no **, _, or #).',
            '- Use only facts from the account context below. Never invent case numbers, dates, or amounts.',
            '- Do not give specific legal advice; recommend speaking with their assigned lawyer for legal strategy.',
            '- For portal actions (open a page, book an appointment, update profile, request callback), explain what the user can say, e.g. "take me to payments" or "book appointment tomorrow at 2pm for case C-0003".',
            '- Keep answers focused and practical. Use short paragraphs or bullet lists with plain dashes.',
            '- If asked about something not in context, say you do not have that information and suggest where to look in the portal.',
            '',
            'Account context (JSON):',
            $json,
        ];

        return implode("\n", $lines);
    }

    private function portalCapabilities(string $role): array
    {
        if ($role === 'client') {
            return [
                'pages' => ['dashboard', 'my cases', 'appointments', 'documents', 'payments', 'court tracking', 'profile', 'settings', 'my requests'],
                'voice_commands' => [
                    'take me to [page]',
                    'book appointment [date] [time] case C-XXXX',
                    'update my phone/email/address to ...',
                    'request a callback',
                    'weekly summary',
                    'how much do I owe',
                    'open case C-XXXX',
                ],
            ];
        }
        if ($role === 'lawyer') {
            return [
                'pages' => ['dashboard', 'cases', 'appointments', 'tasks', 'court tracking', 'clients'],
                'queries' => ['show my active cases', 'my appointments', 'my tasks', 'case C-XXXX'],
            ];
        }
        if ($role === 'admin') {
            return [
                'pages' => ['dashboard', 'cases', 'clients', 'appointments', 'payments', 'invoices', 'settings'],
                'queries' => ['how many active cases', 'upcoming appointments', 'pending invoices'],
            ];
        }

        return [];
    }

    private function buildClientAccount(): array
    {
        $clientId = (int) ($this->context['client_id'] ?? 0);
        if ($clientId <= 0) {
            return [];
        }

        $account = ['cases' => [], 'appointments' => [], 'invoices' => [], 'court_dates' => []];

        try {
            $stmt = $this->pdo->prepare('
                SELECT id, title, status, priority, updated_at
                FROM cases WHERE client_id = ? ORDER BY updated_at DESC LIMIT 12
            ');
            $stmt->execute([$clientId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $account['cases'][] = [
                    'number' => $this->caseNumber((int) $row['id']),
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'priority' => $row['priority'] ?? null,
                ];
            }

            $stmt = $this->pdo->prepare("
                SELECT a.id, a.starts_at, a.status, c.title AS case_title
                FROM appointments a LEFT JOIN cases c ON c.id = a.case_id
                WHERE a.client_id = ? AND a.starts_at >= NOW()
                ORDER BY a.starts_at ASC LIMIT 8
            ");
            $stmt->execute([$clientId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $account['appointments'][] = [
                    'when' => $row['starts_at'],
                    'status' => $row['status'],
                    'case' => $row['case_title'],
                ];
            }

            $stmt = $this->pdo->prepare("
                SELECT i.invoice_number, i.amount, i.due_date, i.status,
                       (i.amount - COALESCE(SUM(p.amount), 0)) AS balance_due, c.title AS case_title
                FROM invoices i
                LEFT JOIN payments p ON p.invoice_id = i.id
                LEFT JOIN cases c ON c.id = i.case_id
                WHERE i.client_id = ?
                GROUP BY i.id ORDER BY i.issue_date DESC LIMIT 10
            ");
            $stmt->execute([$clientId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bal = (float) ($row['balance_due'] ?? 0);
                if ($bal <= 0) {
                    continue;
                }
                $account['invoices'][] = [
                    'number' => $row['invoice_number'],
                    'balance_due' => $bal,
                    'due_date' => $row['due_date'],
                    'case' => $row['case_title'],
                    'status' => $row['status'],
                ];
            }

            $stmt = $this->pdo->prepare("
                SELECT cd.court_date, cd.title, cd.location, c.title AS case_title
                FROM court_dates cd INNER JOIN cases c ON c.id = cd.case_id
                WHERE c.client_id = ? AND cd.court_date >= NOW()
                ORDER BY cd.court_date ASC LIMIT 6
            ");
            $stmt->execute([$clientId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $account['court_dates'][] = [
                    'when' => $row['court_date'],
                    'title' => $row['title'],
                    'location' => $row['location'],
                    'case' => $row['case_title'],
                ];
            }

            if (function_exists('legalpro_client_profile_completeness')) {
                $account['profile_completeness_percent'] = legalpro_client_profile_completeness($this->pdo, $clientId)['percent'] ?? 100;
            }
        } catch (PDOException $e) {
            error_log('chatbot context client: ' . $e->getMessage());
        }

        return $account;
    }

    private function buildLawyerWorkload(): array
    {
        $lawyerId = (int) ($this->context['lawyer_id'] ?? 0);
        if ($lawyerId <= 0) {
            return [];
        }

        $data = ['cases' => [], 'appointments' => [], 'tasks' => []];

        try {
            $stmt = $this->pdo->prepare("
                SELECT c.id, c.title, c.status, cl.first_name, cl.last_name
                FROM cases c
                INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id
                INNER JOIN clients cl ON cl.id = c.client_id
                WHERE cl2.lawyer_id = ? AND LOWER(TRIM(c.status)) != 'closed'
                ORDER BY c.updated_at DESC LIMIT 10
            ");
            $stmt->execute([$lawyerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $data['cases'][] = [
                    'number' => $this->caseNumber((int) $row['id']),
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'client' => trim($row['first_name'] . ' ' . $row['last_name']),
                ];
            }

            $stmt = $this->pdo->prepare("
                SELECT a.starts_at, a.status, c.title AS case_title, cl.first_name, cl.last_name
                FROM appointments a
                LEFT JOIN cases c ON c.id = a.case_id
                LEFT JOIN clients cl ON cl.id = a.client_id
                WHERE a.lawyer_id = ? AND a.starts_at >= NOW() AND a.status IN ('pending', 'accepted')
                ORDER BY a.starts_at ASC LIMIT 8
            ");
            $stmt->execute([$lawyerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $data['appointments'][] = [
                    'when' => $row['starts_at'],
                    'status' => $row['status'],
                    'case' => $row['case_title'],
                    'client' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                ];
            }

            try {
                $stmt = $this->pdo->prepare("
                    SELECT t.title, t.status, t.priority, t.due_date
                    FROM tasks t INNER JOIN task_lawyers tl ON tl.task_id = t.id
                    WHERE tl.lawyer_id = ? AND t.status NOT IN ('completed', 'cancelled')
                    ORDER BY t.due_date IS NULL, t.due_date ASC LIMIT 8
                ");
                $stmt->execute([$lawyerId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $data['tasks'][] = $row;
                }
            } catch (PDOException $e) {
                $stmt = $this->pdo->prepare("
                    SELECT title, status, priority, due_date FROM tasks
                    WHERE assigned_lawyer_id = ? AND status NOT IN ('completed', 'cancelled')
                    ORDER BY due_date IS NULL, due_date ASC LIMIT 8
                ");
                $stmt->execute([$lawyerId]);
                $data['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log('chatbot context lawyer: ' . $e->getMessage());
        }

        return $data;
    }

    private function buildAdminStats(): array
    {
        $stats = [];

        try {
            $stats['total_clients'] = (int) $this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
            $stats['active_lawyers'] = (int) $this->pdo->query("SELECT COUNT(*) FROM lawyers WHERE is_active = 1 OR status = 'active'")->fetchColumn();
            $stats['open_cases'] = (int) $this->pdo->query("SELECT COUNT(*) FROM cases WHERE LOWER(TRIM(status)) != 'closed'")->fetchColumn();
            $stats['upcoming_appointments'] = (int) $this->pdo->query("
                SELECT COUNT(*) FROM appointments WHERE starts_at >= NOW() AND status IN ('pending', 'accepted')
            ")->fetchColumn();
            $stats['pending_invoices'] = (int) $this->pdo->query("
                SELECT COUNT(*) FROM invoices WHERE status IN ('pending', 'sent', 'overdue')
            ")->fetchColumn();

            $stmt = $this->pdo->query("
                SELECT c.id, c.title, c.status, cl.first_name, cl.last_name
                FROM cases c INNER JOIN clients cl ON cl.id = c.client_id
                WHERE LOWER(TRIM(c.status)) != 'closed'
                ORDER BY c.updated_at DESC LIMIT 8
            ");
            $stats['recent_cases'] = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stats['recent_cases'][] = [
                    'number' => $this->caseNumber((int) $row['id']),
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'client' => trim($row['first_name'] . ' ' . $row['last_name']),
                ];
            }
        } catch (PDOException $e) {
            error_log('chatbot context admin: ' . $e->getMessage());
        }

        return $stats;
    }

    private function caseNumber(int $id): string
    {
        return 'C-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }
}
