<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Enable caching (1 hour)
$cache_file = __DIR__ . '/../scratch/ical_cache.json';
$cache_time = 3600; // 1 hour

if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_time)) {
    echo file_get_contents($cache_file);
    exit;
}

// Replace with your real Airbnb iCal URL (can be customized by Neus)
$ical_url = 'https://www.airbnb.es/calendar/ical/4944355.ics?t=a0cc19cb4fe64f71b4f453bab612bd80'; // placeholder, swap with your real one

// Allow overriding via local config.json in the same folder
if (file_exists(__DIR__ . '/config.json')) {
    $config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
    if (!empty($config['ical_url'])) {
        $ical_url = $config['ical_url'];
    }
}

// Fetch the ICS content
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $ical_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$ics_content = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200 || empty($ics_content)) {
    // If Airbnb fetch fails, serve cached file even if expired, or return empty list
    if (file_exists($cache_file)) {
        echo file_get_contents($cache_file);
    } else {
        echo json_encode([]);
    }
    exit;
}

// Parse ICS events
$booked_dates = [];
$lines = explode("\n", $ics_content);
$current_event = null;

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === 'BEGIN:VEVENT') {
        $current_event = [];
    } else if ($line === 'END:VEVENT') {
        if (isset($current_event['start']) && isset($current_event['end'])) {
            $booked_dates[] = [
                'start' => $current_event['start'],
                'end' => $current_event['end']
            ];
        }
        $current_event = null;
    } else if ($current_event !== null) {
        if (preg_match('/^DTSTART;VALUE=DATE:(\d{8})/', $line, $matches) || preg_match('/^DTSTART:(\d{8})/', $line, $matches)) {
            $current_event['start'] = format_date($matches[1]);
        } else if (preg_match('/^DTEND;VALUE=DATE:(\d{8})/', $line, $matches) || preg_match('/^DTEND:(\d{8})/', $line, $matches)) {
            $current_event['end'] = format_date($matches[1]);
        }
    }
}

function format_date($date_str) {
    // YYYYMMDD -> YYYY-MM-DD
    return substr($date_str, 0, 4) . '-' . substr($date_str, 4, 2) . '-' . substr($date_str, 6, 2);
}

$response_json = json_encode($booked_dates);

// Save to cache
$scratch_dir = __DIR__ . '/../scratch';
if (!is_dir($scratch_dir)) {
    mkdir($scratch_dir, 0755, true);
}
file_put_contents($cache_file, $response_json);

echo $response_json;
?>
