<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt.']);
    exit;
}

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$checkin = isset($_POST['checkin']) ? trim($_POST['checkin']) : '';
$checkout = isset($_POST['checkout']) ? trim($_POST['checkout']) : '';
$nights = isset($_POST['nights']) ? intval($_POST['nights']) : 0;
$guests = isset($_POST['guests']) ? intval($_POST['guests']) : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$totalprice = isset($_POST['totalprice']) ? trim($_POST['totalprice']) : '';

// Validation
if (empty($name) || empty($email) || empty($phone) || empty($checkin) || empty($checkout) || $nights <= 0 || $guests <= 0 || empty($totalprice)) {
    echo json_encode(['success' => false, 'error' => 'Bitte füllen Sie alle erforderlichen Felder aus und wählen Sie Ihre Reisedaten im Kalender.']);
    exit;
}

// Clean phone for WhatsApp Link (keep numbers only, add prefix if needed)
$clean_phone = preg_replace('/[^0-9]/', '', $phone);
if (strlen($clean_phone) === 9 && ($clean_phone[0] === '6' || $clean_phone[0] === '7')) {
    $clean_phone = '34' . $clean_phone; // default to Spanish code if no country code provided
}

$wa_msg = "Hallo " . $name . ", vielen Dank für Ihre Buchungsanfrage im Can Picornell (vom " . $checkin . " bis " . $checkout . "). Ich melde mich bei Ihnen, um die Bestätigung und die Zahlungsdetails zu klären. Liebe Grüße!";
$whatsapp_link = "https://wa.me/" . $clean_phone . "?text=" . urlencode($wa_msg);
$reply_email_link = "mailto:" . $email . "?subject=" . urlencode("Buchung Can Picornell: " . $checkin . " bis " . $checkout) . "&body=" . urlencode("Hallo " . $name . ",\n\nvielen Dank für Ihre Anfrage. Wir freuen uns, Sie im Can Picornell begrüßen zu dürfen...");

// Email recipients
$to = 'info@canpicornell.com, peplluis@gmail.com';
$subject = "Neue Buchungsanfrage von " . $name . " (" . $nights . " Nächte)";

// HTML email body with premium Mediterranean design (German labels)
$email_content = '
<html>
<head>
    <style>
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; color: #1e2d30; line-height: 1.6; background-color: #f2f5f1; padding: 30px 0; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e3e9e2; border-radius: 12px; box-shadow: 0 4px 12px rgba(22, 93, 129, 0.04); }
        .header { background-color: #165d81; color: #ffffff; padding: 35px; text-align: center; border-radius: 12px 12px 0 0; }
        .header h2 { margin: 0; font-family: Georgia, serif; font-weight: 300; font-size: 1.6rem; letter-spacing: 0.1em; text-transform: uppercase; }
        .content { padding: 40px; }
        .highlight-box { background-color: #f2f5f1; border-radius: 8px; padding: 20px; margin-bottom: 30px; border: 1px solid #e3e9e2; }
        .field { margin-bottom: 20px; border-bottom: 1px solid #f2f5f1; padding-bottom: 12px; }
        .field:last-child { margin-bottom: 0; border-bottom: none; padding-bottom: 0; }
        .label { font-weight: 600; color: #165d81; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 6px; }
        .value { font-size: 0.95rem; color: #1e2d30; }
        .message-box { font-size: 0.95rem; color: #1e2d30; background-color: #fafbfa; border: 1px solid #e3e9e2; border-radius: 6px; padding: 15px; margin-top: 5px; white-space: pre-line; }
        .actions-block { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e3e9e2; }
        .btn-action { display: inline-block; padding: 12px 24px; border-radius: 30px; font-size: 0.9rem; font-weight: 600; text-transform: uppercase; text-decoration: none; margin: 5px; }
        .btn-wa { background-color: #25d366; color: #ffffff; }
        .btn-mail { background-color: #005780; color: #ffffff; }
        .footer { padding: 25px; text-align: center; font-size: 0.75rem; color: #5a6d70; background-color: #fafbfa; border-top: 1px solid #e3e9e2; border-radius: 0 0 12px 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>CAN PICORNELL</h2>
        </div>
        <div class="content">
            <h3 style="color: #165d81; margin-top: 0; margin-bottom: 20px; font-weight: 500;">Neue Buchungsanfrage</h3>
            
            <div class="highlight-box">
                <div style="display: flex; justify-content: space-between;">
                    <div>
                        <div class="label">Anreise / Check-in</div>
                        <div class="value" style="font-size: 1.1rem; font-weight: 600; color: #165d81;">' . htmlspecialchars($checkin) . '</div>
                    </div>
                    <div>
                        <div class="label">Abreise / Check-out</div>
                        <div class="value" style="font-size: 1.1rem; font-weight: 600; color: #165d81;">' . htmlspecialchars($checkout) . '</div>
                    </div>
                </div>
                <div style="margin-top: 15px; display: flex; justify-content: space-between; border-top: 1px solid #e3e9e2; padding-top: 15px;">
                    <div>
                        <span style="font-size: 0.85rem; color: #5a6d70;">' . $nights . ' Nächte | ' . $guests . ' Gäste</span>
                    </div>
                    <div>
                        <span style="font-size: 1.1rem; font-weight: 700; color: #005780;">Berechneter Preis: ' . htmlspecialchars($totalprice) . '€</span>
                    </div>
                </div>
            </div>

            <div class="field">
                <div class="label">Name des Gastes</div>
                <div class="value">' . htmlspecialchars($name) . '</div>
            </div>
            <div class="field">
                <div class="label">E-Mail-Adresse</div>
                <div class="value">' . htmlspecialchars($email) . '</div>
            </div>
            <div class="field">
                <div class="label">Telefonnummer</div>
                <div class="value">' . htmlspecialchars($phone) . '</div>
            </div>
            
            ' . (!empty($message) ? '
            <div class="field">
                <div class="label">Nachricht des Gastes / Wünsche</div>
                <div class="message-box">' . htmlspecialchars($message) . '</div>
            </div>' : '') . '

            <div class="actions-block">
                <div style="font-size: 0.85rem; font-weight: 600; text-transform: uppercase; margin-bottom: 15px; color: #5a6d70;">Gast kontaktieren</div>
                <a href="' . $whatsapp_link . '" target="_blank" rel="noopener noreferrer" class="btn-action btn-wa">WhatsApp öffnen</a>
                <a href="' . $reply_email_link . '" class="btn-action btn-mail">Per E-Mail antworten</a>
            </div>
        </div>
        <div class="footer">
            Die Anfrage wurde sicher über das Direktbuchungssystem von canpicornell.com generiert.
        </div>
    </div>
</body>
</html>
';

// Setup email headers
$from_email = 'web@canpicornell.com';
$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8" . "\r\n";
$headers .= "From: Can Picornell Buchungen <" . $from_email . ">" . "\r\n";
$headers .= "Reply-To: " . $email . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

if (mail($to, $subject, $email_content, $headers)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Fehler beim E-Mail-Versand. Bitte versuchen Sie es später noch einmal oder buchen Sie über Airbnb.']);
}
?>
