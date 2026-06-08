<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/chatbot_assistant.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$message = is_array($payload) && isset($payload['message']) ? trim((string) $payload['message']) : '';

$context = ChatbotAssistant::resolveContextFromSession();
if ($context['role'] === 'guest') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please log in to use the assistant.']);
    exit;
}

try {
    $assistant = new ChatbotAssistant($pdo, $context);
    $result = $assistant->answer($message);
    echo json_encode([
        'ok' => true,
        'reply' => $result['reply'],
        'links' => $result['links'],
        'role' => $context['role'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Sorry, something went wrong while looking that up.',
    ]);
}
