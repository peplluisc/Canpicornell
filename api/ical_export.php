<?php
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="canpicornell_direct_bookings.ics"');

require_once __DIR__ . '/db.php';

try {
    $db = get_db_connection();
    
    // Select all bookings that are confirmed or paid (active reservations that should block calendar)
    $stmt = $db->query("
        SELECT id, request_number, checkin_date, checkout_date, created_at
        FROM booking_requests
        WHERE status IN ('anticipo_recibido', 'reserva_confirmada', 'pendiente_saldo', 'pagada')
    ");
    $bookings = $stmt->fetchAll();
    
    // Format date strings to iCal format (YYYYMMDD)
    function to_ical_date($date_str) {
        return str_replace('-', '', $date_str);
    }
    
    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:-//Can Picornell//NONSGML Direct Bookings//ES\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "METHOD:PUBLISH\r\n";
    
    foreach ($bookings as $booking) {
        $start = to_ical_date($booking['checkin_date']);
        $end = to_ical_date($booking['checkout_date']);
        $uid = "CP-" . $booking['id'] . "-" . $booking['request_number'] . "@canpicornell.com";
        $stamp = date('Ymd\THis\Z', strtotime($booking['created_at']));
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:" . $uid . "\r\n";
        echo "DTSTAMP:" . $stamp . "\r\n";
        echo "DTSTART;VALUE=DATE:" . $start . "\r\n";
        echo "DTEND;VALUE=DATE:" . $end . "\r\n";
        echo "SUMMARY:Reserva Directa - " . $booking['request_number'] . "\r\n";
        echo "DESCRIPTION:Reserva confirmada en Can Picornell de forma directa.\r\n";
        echo "END:VEVENT\r\n";
    }
    
    echo "END:VCALENDAR\r\n";
    
} catch (Exception $e) {
    error_log("Error exporting iCal: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo "ERROR: Could not generate calendar.";
}
?>
