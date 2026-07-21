<?php
/**
 * Kaitoriban Inquiry Form Handler
 * Handles form submission, validation, and email sending
 */

header('Content-Type: application/json');

// Configuration
$config = [
    'recipient_email' => 'song@social-bridge.net',
    'from_email' => 'noreply@social-bridge.net',
    'site_name' => 'カイトリ番',
    'max_message_length' => 5000,
];

// Response helper
function sendResponse($success, $message = '') {
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit;
}

// Validate input
function validateInput($data) {
    $errors = [];

    // Company validation
    if (empty($data['company'])) {
        $errors[] = '会社名は必須項目です。';
    } elseif (strlen($data['company']) > 100) {
        $errors[] = '会社名は100文字以内で入力してください。';
    }

    // Name validation
    if (empty($data['name'])) {
        $errors[] = 'お名前は必須項目です。';
    } elseif (strlen($data['name']) > 100) {
        $errors[] = 'お名前は100文字以内で入力してください。';
    }

    // Email validation
    if (empty($data['email'])) {
        $errors[] = 'メールアドレスは必須項目です。';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = '有効なメールアドレスを入力してください。';
    }

    // Phone validation (optional)
    if (!empty($data['phone'])) {
        if (!preg_match('/^[\d\-\s()+]*$/', $data['phone'])) {
            $errors[] = '電話番号の形式が正しくありません。';
        }
    }

    // Prefecture validation (optional)
    if (!empty($data['prefecture']) && strlen($data['prefecture']) > 20) {
        $errors[] = '都道府県は20文字以内で入力してください。';
    }

    // Subject validation
    $valid_subjects = ['demo', 'quote', 'document', 'other'];
    if (empty($data['subject']) || !in_array($data['subject'], $valid_subjects)) {
        $errors[] = 'ご相談内容を正しく選択してください。';
    }

    // Message validation
    if (empty($data['message'])) {
        $errors[] = 'メッセージは必須項目です。';
    } elseif (strlen($data['message']) > 5000) {
        $errors[] = 'メッセージは5000文字以内で入力してください。';
    }

    return $errors;
}

// Sanitize input
function sanitizeInput($data) {
    $sanitized = [];
    foreach ($data as $key => $value) {
        $sanitized[$key] = htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }
    return $sanitized;
}

// Get subject label
function getSubjectLabel($subject) {
    $subjects = [
        'demo' => 'デモを依頼する',
        'quote' => 'お見積もり',
        'document' => '資料請求',
        'other' => 'その他',
    ];
    return $subjects[$subject] ?? 'その他';
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    sendResponse(false, 'Invalid request format');
}

// Validate input
$validation_errors = validateInput($input);
if (!empty($validation_errors)) {
    sendResponse(false, implode(' ', $validation_errors));
}

// Sanitize input
$data = sanitizeInput($input);

// Prepare email content
$subject_label = getSubjectLabel($data['subject']);
$email_subject = "【{$config['site_name']}】お問い合わせを受け付けました";

$email_body = <<<EOT
{$config['site_name']} へのお問い合わせありがとうございます。

お問い合わせ内容を確認させていただきました。
お返事は{$data['email']}宛にお送りさせていただきます。

【お問い合わせ内容】
━━━━━━━━━━━━━━━━━━━━━━━━━━━━

■ 会社名
{$data['company']}

■ お名前
{$data['name']}

■ メールアドレス
{$data['email']}

EOT;

if (!empty($data['phone'])) {
    $email_body .= "■ 電話番号\n{$data['phone']}\n\n";
}

if (!empty($data['prefecture'])) {
    $email_body .= "■ 都道府県\n{$data['prefecture']}\n\n";
}

$email_body .= <<<EOT
■ ご相談内容
{$subject_label}

■ メッセージ
{$data['message']}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━

このメールに返信いただくか、{$config['recipient_email']}までお問い合わせください。

{$config['site_name']} / Social Bridge 株式会社

EOT;

// Prepare admin notification email
$admin_subject = "【新規問い合わせ】{$subject_label} - {$data['company']} {$data['name']}";
$admin_body = <<<EOT
新しいお問い合わせが入りました。

【送信日時】
{$_SERVER['REQUEST_TIME']}

【お問い合わせ内容】
会社名: {$data['company']}
お名前: {$data['name']}
メールアドレス: {$data['email']}
EOT;

if (!empty($data['phone'])) {
    $admin_body .= "\n電話番号: {$data['phone']}";
}

if (!empty($data['prefecture'])) {
    $admin_body .= "\n都道府県: {$data['prefecture']}";
}

$admin_body .= "\nご相談内容: {$subject_label}\n\n【メッセージ】\n{$data['message']}";

// Send user confirmation email
$user_headers = "From: {$config['from_email']}\r\n";
$user_headers .= "Reply-To: {$config['recipient_email']}\r\n";
$user_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

if (!mail($data['email'], $email_subject, $email_body, $user_headers)) {
    error_log("Failed to send user confirmation email to {$data['email']}");
}

// Send admin notification email
$admin_headers = "From: {$config['from_email']}\r\n";
$admin_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

if (!mail($config['recipient_email'], $admin_subject, $admin_body, $admin_headers)) {
    error_log("Failed to send admin notification email");
}

// Log contact submission
$log_entry = date('Y-m-d H:i:s') . " | " . $data['email'] . " | " . $subject_label . "\n";
$log_file = __DIR__ . '/kaitoriban_log.txt';
if (!is_writable(dirname($log_file))) {
    error_log("Kaitoriban form submission not logged - directory not writable");
} else {
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Send success response
sendResponse(true, 'お問い合わせありがとうございます。確認メールを送信いたしました。');
?>
