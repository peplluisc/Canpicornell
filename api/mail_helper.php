<?php
require_once __DIR__ . '/env_helper.php';

function send_booking_email($booking, $template_type) {
    $base_url = get_env_var('BASE_URL', 'https://canpicornell.com/');
    $contact_email = get_env_var('CONTACT_EMAIL', 'info@canpicornell.com');
    
    $lang = isset($booking['preferred_language']) ? strtolower($booking['preferred_language']) : 'es';
    if (!in_array($lang, ['es', 'en', 'de'])) {
        $lang = 'es';
    }

    // Load master layout
    $layout_file = __DIR__ . '/mail_templates/layout.html';
    if (file_exists($layout_file)) {
        $email_html = file_get_contents($layout_file);
    } else {
        // Fallback layout if file missing
        $email_html = '<html><body>{{EMAIL_BODY}}<br>{{BOOKING_SUMMARY_TABLE}}</body></html>';
    }

    // Translations for UI table labels
    $table_labels = [
        'es' => [
            'subject_solicitud_recibida' => 'Solicitud de reserva recibida - Can Picornell',
            'subject_solicitud_aprobada' => 'Tu solicitud de reserva ha sido aprobada - Can Picornell',
            'subject_reserva_confirmada' => '¡Reserva confirmada! - Can Picornell',
            'subject_recordatorio_saldo' => 'Recordatorio de saldo pendiente - Can Picornell',
            'subject_pago_total' => 'Pago total recibido - Can Picornell',
            'subject_partee_checkin' => 'Registro de viajeros obligatorio - Can Picornell',
            'subject_informacion_llegada' => 'Información útil para tu llegada - Can Picornell',
            'subject_cancelacion' => 'Tu reserva ha sido cancelada - Can Picornell',
            'summary_title' => 'Resumen de la Estancia',
            'req_number' => 'Nº Solicitud',
            'res_number' => 'Nº Reserva',
            'checkin' => 'Entrada',
            'checkout' => 'Salida',
            'nights' => 'Noches',
            'guests' => 'Huéspedes',
            'total' => 'Total Presupuestado',
            'deposit' => 'Anticipo Necesario',
            'balance' => 'Saldo Pendiente',
            'status' => 'Estado',
            'adults' => 'Adultos',
            'children' => 'Niños',
            'babies' => 'Bebés',
            'cancellation_title' => 'Condiciones de Cancelación',
            'disclaimer' => 'Nota: Este correo electrónico y los acuerdos aquí contenidos están sujetos a las condiciones generales de reserva de Can Picornell. Por favor, revise el resumen antes de proceder.',
            'status_values' => [
                'solicitud_recibida' => 'Solicitud recibida',
                'pendiente_revision' => 'Pendiente de revisión',
                'presupuesto_enviado' => 'Presupuesto enviado',
                'pendiente_anticipo' => 'Pendiente de pago de anticipo',
                'anticipo_recibido' => 'Anticipo recibido',
                'reserva_confirmada' => 'Reserva confirmada',
                'pendiente_saldo' => 'Pendiente de saldo',
                'pagada' => 'Totalmente pagada',
                'cancelada' => 'Cancelada',
                'caducada' => 'Caducada'
            ]
        ],
        'en' => [
            'subject_solicitud_recibida' => 'Booking request received - Can Picornell',
            'subject_solicitud_aprobada' => 'Your booking request has been approved - Can Picornell',
            'subject_reserva_confirmada' => 'Booking confirmed! - Can Picornell',
            'subject_recordatorio_saldo' => 'Reminder: Outstanding balance payment - Can Picornell',
            'subject_pago_total' => 'Full payment received - Can Picornell',
            'subject_partee_checkin' => 'Action required: Online check-in registration - Can Picornell',
            'subject_informacion_llegada' => 'Useful arrival information - Can Picornell',
            'subject_cancelacion' => 'Your booking has been cancelled - Can Picornell',
            'summary_title' => 'Stay Summary',
            'req_number' => 'Request No.',
            'res_number' => 'Booking No.',
            'checkin' => 'Check-in',
            'checkout' => 'Check-out',
            'nights' => 'Nights',
            'guests' => 'Guests',
            'total' => 'Estimated Total',
            'deposit' => 'Required Deposit',
            'balance' => 'Remaining Balance',
            'status' => 'Status',
            'adults' => 'Adults',
            'children' => 'Children',
            'babies' => 'Babies',
            'cancellation_title' => 'Cancellation Policy',
            'disclaimer' => 'Note: This email and the agreements contained herein are subject to the general booking conditions of Can Picornell. Please check the summary details.',
            'status_values' => [
                'solicitud_recibida' => 'Request received',
                'pendiente_revision' => 'Pending review',
                'presupuesto_enviado' => 'Quote sent',
                'pendiente_anticipo' => 'Pending deposit payment',
                'anticipo_recibido' => 'Deposit received',
                'reserva_confirmada' => 'Booking confirmed',
                'pendiente_saldo' => 'Pending balance payment',
                'pagada' => 'Fully paid',
                'cancelada' => 'Cancelled',
                'caducada' => 'Expired'
            ]
        ],
        'de' => [
            'subject_solicitud_recibida' => 'Buchungsanfrage erhalten - Can Picornell',
            'subject_solicitud_aprobada' => 'Ihre Buchungsanfrage wurde genehmigt - Can Picornell',
            'subject_reserva_confirmada' => 'Buchung bestätigt! - Can Picornell',
            'subject_recordatorio_saldo' => 'Zahlungserinnerung: Ausstehender Restbetrag - Can Picornell',
            'subject_pago_total' => 'Vollständige Zahlung erhalten - Can Picornell',
            'subject_partee_checkin' => 'Erforderlich: Online-Registrierung der Reisenden - Can Picornell',
            'subject_informacion_llegada' => 'Nützliche Anreiseinformationen - Can Picornell',
            'subject_cancelacion' => 'Ihre Buchung wurde storniert - Can Picornell',
            'summary_title' => 'Zusammenfassung des Aufenthalts',
            'req_number' => 'Anfrage-Nr.',
            'res_number' => 'Buchungs-Nr.',
            'checkin' => 'Anreise',
            'checkout' => 'Abreise',
            'nights' => 'Nächte',
            'guests' => 'Gäste',
            'total' => 'Gesamtbetrag',
            'deposit' => 'Erforderliche Anzahlung',
            'balance' => 'Restbetrag',
            'status' => 'Status',
            'adults' => 'Erwachsene',
            'children' => 'Kinder',
            'babies' => 'Babys',
            'cancellation_title' => 'Stornierungsbedingungen',
            'disclaimer' => 'Hinweis: Diese E-Mail und die darin enthaltenen Vereinbarungen unterliegen den allgemeinen Buchungsbedingungen von Can Picornell. Bitte überprüfen Sie die Zusammenfassung.',
            'status_values' => [
                'solicitud_recibida' => 'Anfrage erhalten',
                'pendiente_revision' => 'Ausstehende Überprüfung',
                'presupuesto_enviado' => 'Angebot gesendet',
                'pendiente_anticipo' => 'Ausstehende Anzahlung',
                'anticipo_recibido' => 'Anzahlung erhalten',
                'reserva_confirmada' => 'Buchung bestätigt',
                'pendiente_saldo' => 'Ausstehender Restbetrag',
                'pagada' => 'Vollständig bezahlt',
                'cancelada' => 'Storniert',
                'caducada' => 'Abgelaufen'
            ]
        ]
    ];

    $lbl = $table_labels[$lang];

    // Localized bodies for the 8 emails
    $bodies = [
        'solicitud_recibida' => [
            'es' => '<h2>Solicitud de Reserva Recibida</h2><p>Hola, <strong>{{GUEST_NAME}}</strong>:</p><p>Hemos recibido correctamente tu solicitud de reserva directa para alojarte en Can Picornell. Estamos revisando la disponibilidad de las fechas solicitadas.</p><p>Te informamos de que <strong>esto no constituye una reserva confirmada</strong>. En un plazo máximo de 24 horas te enviaremos la confirmación del presupuesto y, en su caso, el enlace de pago seguro para abonar el anticipo del {{DEPOSIT_PCT}}% y bloquear formalmente tus fechas.</p>',
            'en' => '<h2>Booking Request Received</h2><p>Hello, <strong>{{GUEST_NAME}}</strong>:</p><p>We have successfully received your direct booking request to stay at Can Picornell. We are currently checking the availability of your requested dates.</p><p>Please note that <strong>this does not constitute a confirmed booking</strong>. Within a maximum of 24 hours, we will send you the confirmed quote and, if available, the secure payment link to pay the {{DEPOSIT_PCT}}% deposit and lock in your dates.</p>',
            'de' => '<h2>Buchungsanfrage erhalten</h2><p>Hallo, <strong>{{GUEST_NAME}}</strong>:</p><p>Wir haben Ihre direkte Buchungsanfrage für einen Aufenthalt im Can Picornell erhalten. Wir prüfen derzeit die Verfügbarkeit der gewünschten Daten.</p><p>Bitte beachten Sie, dass <strong>dies noch keine bestätigte Buchung darstellt</strong>. Innerhalb von maximal 24 Stunden senden wir Ihnen das bestätigte Angebot und gegebenenfalls den sicheren Zahlungslink, um die Anzahlung von {{DEPOSIT_PCT}}% zu leisten und Ihre Termine fest zu blockieren.</p>'
        ],
        'solicitud_aprobada' => [
            'es' => '<h2>¡Tu solicitud ha sido aprobada!</h2><p>Hola, <strong>{{GUEST_NAME}}</strong>:</p><p>Nos complace informarte de que las fechas solicitadas están disponibles. Hemos aprobado tu solicitud y preparado el presupuesto confirmado.</p><p>Para confirmar formalmente la reserva, es necesario realizar el pago seguro del anticipo del {{DEPOSIT_PCT}}% pulsando el siguiente botón:</p><p style="text-align: center;"><a href="{{PAYMENT_LINK}}" class="btn">Realizar pago seguro del anticipo</a></p><p><em>El enlace de pago caducará en 48 horas. Si el pago no es recibido en ese plazo, las fechas volverán a liberarse para otros huéspedes. Can Picornell no recibe ni almacena los datos de tu tarjeta; los pagos son procesados de forma 100% segura por Stripe.</em></p>',
            'en' => '<h2>Your request has been approved!</h2><p>Hello, <strong>{{GUEST_NAME}}</strong>:</p><p>We are pleased to inform you that your requested dates are available. We have approved your request and prepared the confirmed quote.</p><p>To formally confirm your booking, please make the secure deposit payment of {{DEPOSIT_PCT}}% by clicking the button below:</p><p style="text-align: center;"><a href="{{PAYMENT_LINK}}" class="btn">Pay secure deposit</a></p><p><em>The payment link will expire in 48 hours. If payment is not received within this period, the dates will be released. Can Picornell does not receive or store your card details; payments are processed 100% securely by Stripe.</em></p>',
            'de' => '<h2>Ihre Anfrage wurde genehmigt!</h2><p>Hallo, <strong>{{GUEST_NAME}}</strong>:</p><p>Wir freuen uns, Ihnen mitteilen zu können, dass Ihre gewünschten Termine verfügbar sind. Wir haben Ihre Anfrage genehmigt und das bestätigte Angebot vorbereitet.</p><p>Um die Buchung formell zu bestätigen, leisten Sie bitte die sichere Anzahlung von {{DEPOSIT_PCT}}%, indem Sie auf die folgende Schaltfläche klicken:</p><p style="text-align: center;"><a href="{{PAYMENT_LINK}}" class="btn">Sichere Anzahlung leisten</a></p><p><em>Der Zahlungslink läuft in 48 Stunden ab. Wenn die Zahlung nicht innerhalb dieses Zeitraums eingeht, werden die Termine wieder freigegeben. Can Picornell erhält oder speichert Ihre Kartendaten nicht. Die Zahlungen werden zu 100% sicher von Stripe verarbeitet.</em></p>'
        ],
        'reserva_confirmada' => [
            'es' => '<h2>¡Reserva Confirmada!</h2><p>Hola, <strong>{{GUEST_NAME}}</strong>:</p><p>¡Excelentes noticias! Hemos recibido el pago del anticipo de forma correcta y tus fechas han quedado bloqueadas en nuestro calendario oficial. <strong>Tu reserva en Can Picornell ya está confirmada.</strong></p><p>A continuación encontrarás los datos actualizados de tu reserva. El saldo restante deberá abonarse antes del <strong>{{BALANCE_DUE_DATE}}</strong>. Te enviaremos un recordatorio cuando se acerque la fecha.</p><p>¡Estamos deseando darte la bienvenida a Mallorca!</p>',
            'en' => '<h2>Booking Confirmed!</h2><p>Hello, <strong>{{GUEST_NAME}}</strong>:</p><p>Excellent news! We have successfully received your deposit payment, and your dates are locked in our official calendar. <strong>Your booking at Can Picornell is now confirmed.</strong></p><p>Below are the details of your confirmed booking. The remaining balance must be paid before <strong>{{BALANCE_DUE_DATE}}</strong>. We will send you a reminder as the date approaches.</p><p>We look forward to welcoming you to Mallorca!</p>',
            'de' => '<h2>Buchung bestätigt!</h2><p>Hallo, <strong>{{GUEST_NAME}}</strong>:</p><p>Tolle Neuigkeiten! Wir haben Ihre Anzahlung erhalten und Ihre Daten sind in unserem offiziellen Kalender fest reserviert. <strong>Ihre Buchung im Can Picornell ist nun bestätigt.</strong></p><p>Unten finden Sie die Details Ihrer bestätigten Buchung. Der Restbetrag muss vor dem <strong>{{BALANCE_DUE_DATE}}</strong> bezahlt werden. Wir werden Ihnen vor dem Termin eine Erinnerung senden.</p><p>Wir freuen uns darauf, Sie auf Mallorca begrüßen zu dürfen!</p>'
        ],
        'recordatorio_saldo' => [
            'es' => '<h2>Recordatorio de pago de saldo pendiente</h2><p>Hola, <strong>{{GUEST_NAME}}</strong>:</p><p>Te escribimos para recordarte que la fecha límite de pago para el saldo pendiente de tu reserva en Can Picornell es el <strong>{{BALANCE_DUE_DATE}}</strong>.</p><p>Para realizar el pago del saldo restante de forma protegida, pulsa el siguiente enlace:</p><p style="text-align: center;"><a href="{{PAYMENT_LINK}}" class="btn">Pagar saldo pendiente seguro</a></p><p>Si tienes alguna duda o necesitas asistencia, responde directamente a este correo o escríbenos por WhatsApp.</p>',
            'en' => '<h2>Reminder: Outstanding balance payment</h2><p>Hello, <strong>{{GUEST_NAME}}</strong>:</p><p>This is a reminder that the payment deadline for the outstanding balance of your booking at Can Picornell is <strong>{{BALANCE_DUE_DATE}}</strong>.</p><p>To make the remaining balance payment securely, please click the link below:</p><p style="text-align: center;"><a href="{{PAYMENT_LINK}}" class="btn">Pay remaining balance securely</a></p><p>If you have any questions or need assistance, reply directly to this email or write to us on WhatsApp.</p>',
            'de' => '<h2>Zahlungserinnerung: Ausstehender Restbetrag</h2><p>Hallo, <strong>{{GUEST_NAME}}</strong>:</p><p>Wir möchten Sie daran erinnern, dass die Zahlungsfrist für den Restbetrag Ihrer Buchung im Can Picornell der <strong>{{BALANCE_DUE_DATE}}</strong> ist.</p><p>Um den ausstehenden Restbetrag sicher zu bezahlen, klicken Sie bitte auf den folgenden Link:</p><p style="text-align: center;"><a href="{{PAYMENT_LINK}}" class="btn">Restbetrag sicher bezahlen</a></p><p>Wenn Sie Fragen haben oder Hilfe benötigen, antworten Sie direkt auf diese E-Mail oder schreiben Sie uns per WhatsApp.</p>'
        ],
        'pago_total' => [
            'es' => '<h2>Pago total recibido</h2><p>Hola, <strong>{{GUEST_NAME}}</strong>:</p><p>Hemos recibido el pago restante de forma correcta. <strong>Tu reserva en Can Picornell se encuentra totalmente pagada.</strong></p><p>Muchas gracias por completar la transacción. Unas semanas antes de tu llegada, te enviaremos las instrucciones de check-in online a través del sistema Partee y los detalles de acceso a la finca.</p>',
            'en' => '<h2>Full payment received</h2><p>Hello, <strong>{{GUEST_NAME}}</strong>:</p><p>We have successfully received the remaining balance payment. <strong>Your booking at Can Picornell is now fully paid.</strong></p><p>Thank you very much. A few weeks before your arrival, we will send you the online check-in instructions via Partee and access details for the finca.</p>',
            'de' => '<h2>Vollständige Zahlung erhalten</h2><p>Hallo, <strong>{{GUEST_NAME}}</strong>:</p><p>Wir haben die Restzahlung erhalten. <strong>Ihre Buchung im Can Picornell ist nun vollständig bezahlt.</strong></p><p>Vielen Dank. Einige Wochen vor Ihrer Anreise senden wir Ihnen die Anweisungen für den Online-Check-in über das Partee-System und die Details für den Zugang zur Finca zu.</p>'
        ],
        'partee_checkin' => [
            'es' => '<h2>Registro obligatorio de viajeros (Online Check-in)</h2><p>Hola, <strong>{{GUEST_NAME}}</strong>:</p><p>De acuerdo con la legislación local de las Islas Baleares, es obligatorio registrar la identidad de todos los huéspedes mayores de 14 años antes de la llegada.</p><p>Para facilitar este proceso y agilizar tu entrada el día de llegada, por favor realiza el registro online seguro haciendo clic en el siguiente enlace de Partee:</p><p style="text-align: center;"><a href="https://partee.es/checkin?owner=canpicornell" target="_blank" rel="noopener noreferrer" class="btn">Realizar Registro en Partee</a></p>',
            'en' => '<h2>Mandatory Guest Registration (Online Check-in)</h2><p>Hello, <strong>{{GUEST_NAME}}</strong>:</p><p>In accordance with Balearic Islands local regulations, we are required to register the identity details of all guests aged 14 and over before check-in.</p><p>To make the arrival smooth, please complete the secure online check-in by clicking the Partee link below:</p><p style="text-align: center;"><a href="https://partee.es/checkin?owner=canpicornell" target="_blank" rel="noopener noreferrer" class="btn">Register on Partee</a></p>',
            'de' => '<h2>Pflichtregistrierung der Reisenden (Online-Check-in)</h2><p>Hallo, <strong>{{GUEST_NAME}}</strong>:</p><p>Gemäß den örtlichen Gesetzen der Balearen sind wir verpflichtet, die Identitätsdaten aller Gäste ab 14 Jahren vor der Ankunft zu registrieren.</p><p>Um Ihren Check-in am Anreisetag zu beschleunigen, führen Sie bitte die sichere Online-Registrierung über den folgenden Partee-Link durch:</p><p style="text-align: center;"><a href="https://partee.es/checkin?owner=canpicornell" target="_blank" rel="noopener noreferrer" class="btn">In Partee registrieren</a></p>'
        ],
        'informacion_llegada' => [
            'es' => '<h2>Información útil para tu llegada</h2><p>Hola, <strong>{{GUEST_NAME}}</strong>:</p><p>¡Tu viaje a Can Picornell está muy cerca! Te enviamos algunos datos prácticos para tu llegada:</p><ul><li><strong>Hora de Entrada (Check-in):</strong> A partir de las 16:00.</li><li><strong>Hora de Salida (Check-out):</strong> Antes de las 10:00.</li><li><strong>Instrucciones de llegada:</strong> Para abrir el portal automático, introduce el código temporal que te enviaremos por WhatsApp 24h antes.</li><li><strong>EcoTasa:</strong> Recuerda tener preparado el importe de la EcoTasa en efectivo ({{TAX_TOTAL}}€) para abonar a la llegada.</li></ul><p>Buen viaje y no dudes en escribirnos si necesitas recomendaciones o tienes alguna pregunta.</p>',
            'en' => '<h2>Useful Arrival Information</h2><p>Hello, <strong>{{GUEST_NAME}}</strong>:</p><p>Your trip to Can Picornell is just around the corner! Here is some useful check-in information:</p><ul><li><strong>Check-in Time:</strong> From 16:00 (4:00 PM).</li><li><strong>Check-out Time:</strong> Before 10:00 AM.</li><li><strong>Arrival Instructions:</strong> To open the automated gate, use the temporary code we will send you via WhatsApp 24 hours prior.</li><li><strong>EcoTasa:</strong> Please prepare the EcoTasa amount (€{{TAX_TOTAL}}) in cash for payment upon arrival.</li></ul><p>Safe travels! Contact us if you have any questions or need recommendations.</p>',
            'de' => '<h2>Nützliche Anreiseinformationen</h2><p>Hallo, <strong>{{GUEST_NAME}}</strong>:</p><p>Ihre Reise nach Can Picornell steht kurz bevor! Hier sind einige nützliche Informationen für Ihre Anreise:</p><ul><li><strong>Check-in Zeit:</strong> Ab 16:00 Uhr.</li><li><strong>Check-out Zeit:</strong> Bis 10:00 Uhr.</li><li><strong>Anreisedetails:</strong> Um das automatische Tor zu öffnen, verwenden Sie den temporären Code, den wir Ihnen 24 Stunden vorher per WhatsApp senden.</li><li><strong>EcoTasa:</strong> Bitte halten Sie den EcoTasa-Betrag ({{TAX_TOTAL}} €) bei Ihrer Ankunft in bar bereit.</li></ul><p>Gute Anreise! Zögern Sie nicht, uns bei Fragen oder für Empfehlungen zu kontaktieren.</p>'
        ],
        'cancelacion' => [
            'es' => '<h2>Tu reserva ha sido cancelada</h2><p>Hola, <strong>{{GUEST_NAME}}</strong>:</p><p>Te confirmamos que, de acuerdo con tu solicitud o por vencimiento del plazo de pago, la solicitud/reserva <strong>{{REQUEST_NUMBER}}</strong> en Can Picornell ha sido cancelada.</p><p>Si ya habías abonado un anticipo, procederemos al reembolso de acuerdo con las condiciones de cancelación firmadas.</p>',
            'en' => '<h2>Your booking has been cancelled</h2><p>Hello, <strong>{{GUEST_NAME}}</strong>:</p><p>We confirm that, in accordance with your request or due to non-payment, the request/booking <strong>{{REQUEST_NUMBER}}</strong> at Can Picornell has been cancelled.</p><p>If you have already paid a deposit, we will proceed with the refund in accordance with the agreed cancellation policy.</p>',
            'de' => '<h2>Ihre Buchung wurde storniert</h2><p>Hallo, <strong>{{GUEST_NAME}}</strong>:</p><p>Wir bestätigen Ihnen, dass auf Ihren Wunsch oder wegen Ablauf der Zahlungsfrist die Anfrage/Buchung <strong>{{REQUEST_NUMBER}}</strong> im Can Picornell storniert wurde.</p><p>Wenn Sie bereits eine Anzahlung geleistet haben, erstatten wir Ihnen diese gemäß den vereinbarten Stornierungsbedingungen.</p>'
        ]
    ];

    $body_text = $bodies[$template_type][$lang];
    $subject = $lbl['subject_' . $template_type];

    // Compute numeric stats/values
    $nights = isset($booking['nights']) ? intval($booking['nights']) : 0;
    $adults = isset($booking['adults']) ? intval($booking['adults']) : 0;
    $children = isset($booking['children']) ? intval($booking['children']) : 0;
    $babies = isset($booking['babies']) ? intval($booking['babies']) : 0;
    
    $total_accommodation = isset($booking['amount_accommodation']) ? floatval($booking['amount_accommodation']) : 0;
    $cleaning_fee = isset($booking['amount_cleaning']) ? floatval($booking['amount_cleaning']) : 0;
    $tax_total = isset($booking['amount_tax']) ? floatval($booking['amount_tax']) : 0;
    $total_cost = isset($booking['amount_total']) ? floatval($booking['amount_total']) : 0;
    $deposit_required = isset($booking['amount_deposit']) ? floatval($booking['amount_deposit']) : 0;
    $pending_balance = isset($booking['amount_balance']) ? floatval($booking['amount_balance']) : 0;
    $balance_due_date = isset($booking['balance_due_date']) ? $booking['balance_due_date'] : '';

    $status_str = isset($lbl['status_values'][$booking['status']]) ? $lbl['status_values'][$booking['status']] : $booking['status'];
    $req_num_lbl = ($booking['status'] === 'reserva_confirmada' || $booking['status'] === 'pagada') ? $lbl['res_number'] : $lbl['req_number'];

    // Generate Booking Summary Table HTML
    $guests_detail = "{$adults} " . $lbl['adults'];
    if ($children > 0) $guests_detail .= ", {$children} " . $lbl['children'];
    if ($babies > 0) $guests_detail .= ", {$babies} " . $lbl['babies'];

    $summary_html = "
    <h3 style='font-family: Georgia, serif; color: #165D81; margin-top: 30px;'>{$lbl['summary_title']}</h3>
    <table class='summary-table'>
        <tr>
            <td><strong>{$req_num_lbl}</strong></td>
            <td class='strong'>{$booking['request_number']}</td>
        </tr>
        <tr>
            <td><strong>{$lbl['checkin']}</strong></td>
            <td>{$booking['checkin_date']}</td>
        </tr>
        <tr>
            <td><strong>{$lbl['checkout']}</strong></td>
            <td>{$booking['checkout_date']}</td>
        </tr>
        <tr>
            <td><strong>{$lbl['nights']}</strong></td>
            <td>{$nights}</td>
        </tr>
        <tr>
            <td><strong>{$lbl['guests']}</strong></td>
            <td>{$guests_detail}</td>
        </tr>
        <tr>
            <td><strong>{$lbl['status']}</strong></td>
            <td><strong>{$status_str}</strong></td>
        </tr>
        <tr>
            <td><strong>{$lbl['total']}</strong></td>
            <td class='strong'>{$total_cost}€</td>
        </tr>
        <tr>
            <td><strong>{$lbl['deposit']}</strong></td>
            <td>{$deposit_required}€</td>
        </tr>
        <tr>
            <td><strong>{$lbl['balance']}</strong></td>
            <td>{$pending_balance}€ (vence el {$balance_due_date})</td>
        </tr>
    </table>
    ";

    // Load cancel policy from config
    $config_file = __DIR__ . '/booking_config.json';
    $cancel_policy_text = '';
    if (file_exists($config_file)) {
        $config = json_decode(file_get_contents($config_file), true);
        if (isset($config['cancellation_policy'][$lang])) {
            $cancel_policy_text = $config['cancellation_policy'][$lang];
        }
    }
    
    if (!empty($cancel_policy_text)) {
        $summary_html .= "
        <div style='margin-top: 15px;'>
            <strong>{$lbl['cancellation_title']}:</strong>
            <p style='margin-top: 5px; font-size: 14px; color: #718096;'>{$cancel_policy_text}</p>
        </div>";
    }

    // Replace variables in layout and body
    $replacements = [
        '{{BASE_URL}}' => $base_url,
        '{{GUEST_NAME}}' => htmlspecialchars($booking['guest_name'], ENT_QUOTES, 'UTF-8'),
        '{{REQUEST_NUMBER}}' => $booking['request_number'],
        '{{BALANCE_DUE_DATE}}' => $balance_due_date,
        '{{TAX_TOTAL}}' => $tax_total,
        '{{DEPOSIT_PCT}}' => isset($config['deposit_percentage']) ? $config['deposit_percentage'] : 30,
        '{{PAYMENT_LINK}}' => isset($booking['payment_link']) ? $booking['payment_link'] : '',
        '{{EMAIL_BODY}}' => $body_text,
        '{{BOOKING_SUMMARY_TABLE}}' => $summary_html,
        '{{DISCLAIMER}}' => "<p class='disclaimer'>{$lbl['disclaimer']}</p>"
    ];

    // Double replacement for body injection
    $body_text = str_replace(array_keys($replacements), array_values($replacements), $body_text);
    $replacements['{{EMAIL_BODY}}'] = $body_text;
    $final_html = str_replace(array_keys($replacements), array_values($replacements), $email_html);

    // Setup headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8" . "\r\n";
    $headers .= "From: " . get_env_var('SMTP_FROM_NAME', 'Can Picornell') . " <" . get_env_var('SMTP_FROM_EMAIL', $contact_email) . ">" . "\r\n";
    $headers .= "Reply-To: " . $contact_email . "\r\n";
    
    // Check SMTP override or native php mail()
    $smtp_host = get_env_var('SMTP_HOST', '');
    if (!empty($smtp_host)) {
        // Here you would integrate PHPMailer or a real SMTP sender.
        // For the Hostgator environment, standard PHP mail() with correct headers is standard and pre-configured.
        // We will default to PHP mail() and log the SMTP settings fallback.
        error_log("SMTP is configured but we are using PHP mail() for compatibility. To use true SMTP, configure PHPMailer in mail_helper.php");
    }

    // Send email to Guest
    $guest_sent = mail($booking['guest_email'], $subject, $final_html, $headers);

    // Send a copy to the owner if it is a new request or payment alert
    if (in_array($template_type, ['solicitud_recibida', 'reserva_confirmada', 'pago_total', 'cancelacion'])) {
        $owner_subject = "[PROPIETARIO] " . $subject;
        mail($contact_email, $owner_subject, $final_html, $headers);
    }

    return $guest_sent;
}
?>
