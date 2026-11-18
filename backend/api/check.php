<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/private/config.php';
require __DIR__ . '/license_lib.php';
require __DIR__ . '/auth_token.php'; // STAGE14
require_once __DIR__ . '/migrations.php'; // S00
require_once __DIR__ . '/features.php'; // S00
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$phoneRaw = (string)($input['phone'] ?? '');
$token    = (string)($input['token'] ?? '');

if ($phoneRaw === '' || $token === '') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request']);
    exit;
}

$phone = normalize_phone($phoneRaw);

try {
    $pdo = db();
    ensure_plan_column($pdo);
    pb_migrate($pdo); // S00
    $customer = tok_auth($pdo, $phone, $token); // STAGE14

    if (!$customer) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }

    $device = lic_clean_device($input['device'] ?? '');
    echo json_encode([
        'server_time' => time(),
        'lic'         => license_issue($phone, $customer, $device),
        'name'        => $customer['name'],
        'active'      => (bool)$customer['active'],
        'expiry_date' => $customer['expiry_date'],
        'plan'        => customer_plan($customer),
        'edition'     => edition_of($customer),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
