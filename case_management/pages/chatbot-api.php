<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/chatbot_ai.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$action = is_array($payload) ? (string) ($payload['action'] ?? 'chat') : 'chat';

$context = ChatbotAssistant::resolveContextFromSession();
if ($context['role'] === 'guest') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please log in to use the assistant.']);
    exit;
}

try {
    $engine = new ChatbotAI($pdo, $context);

    if ($action === 'clear') {
        $engine->clearHistory();
        echo json_encode(['ok' => true, 'reply' => 'Conversation cleared.']);
        exit;
    }

    $message = is_array($payload) && isset($payload['message']) ? trim((string) $payload['message']) : '';
    $result = $engine->chat($message);

    echo json_encode([
        'ok' => $result['ok'] ?? true,
        'reply' => $result['reply'] ?? '',
        'links' => $result['links'] ?? [],
        'actions' => $result['actions'] ?? [],
        'mode' => $result['mode'] ?? 'smart',
        'tokens_used' => $result['tokens_used'] ?? null,
        'role' => $context['role'],
        'ai_enabled' => ChatbotAI::openAiConfigured(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('chatbot-api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Sorry, something went wrong. Please try again.',
    ]);
}
