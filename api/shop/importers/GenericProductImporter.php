<?php
/**
 * Generic Product Importer base class handling safe HTTP fetching with cURL.
 */

require_once __DIR__ . '/ProductImporterInterface.php';
require_once __DIR__ . '/SSRFProtection.php';

abstract class GenericProductImporter implements ProductImporterInterface {
    
    protected function fetchHtml(string $url): string {
        // Validate URL against SSRF
        $cleanUrl = SSRFProtection::validateUrl($url, $this->getAllowedHostnames());

        $html = $this->execCurlRequest($cleanUrl, true);
        
        if (empty($html)) {
            // Retry with SSL fallback if shared host lacks CA bundle
            $html = $this->execCurlRequest($cleanUrl, false);
        }

        if (empty($html)) {
            throw new Exception("La respuesta obtenida del servidor del supermercado está vacía.");
        }

        return $html;
    }

    private function execCurlRequest(string $cleanUrl, bool $verifySsl): string {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $cleanUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Force IPv4 on shared hosting
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: es-ES,es;q=0.9,en;q=0.8,de;q=0.7',
            'Referer: https://www.elcorteingles.es/supermercado/',
            'Cookie: eci_lang=es; eci_store=010130',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
            'Cache-Control: no-cache'
        ]);

        if ($verifySsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        curl_setopt($ch, CURLOPT_BUFFERSIZE, 128000);

        // Maximum size limit (5MB)
        $maxBytes = 5 * 1024 * 1024;
        $receivedBytes = 0;
        $html = '';

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$html, &$receivedBytes, $maxBytes) {
            $len = strlen($chunk);
            $receivedBytes += $len;
            if ($receivedBytes > $maxBytes) {
                return 0; // Terminate transfer
            }
            $html .= $chunk;
            return $len;
        });

        $exec = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if (!empty($error)) {
            error_log("cURL Importer Error (errno {$errno}, SSL verify " . ($verifySsl ? 'ON' : 'OFF') . "): {$error}");
        }

        // Re-validate effective URL in case of redirect
        if (!empty($effectiveUrl) && $effectiveUrl !== $cleanUrl) {
            SSRFProtection::validateUrl($effectiveUrl, $this->getAllowedHostnames());
        }

        if ($httpCode === 403 || $httpCode === 429) {
            if ($verifySsl) {
                return ''; // Allow fallback attempt
            }
            throw new Exception("El supermercado ha rechazado la consulta automática (HTTP {$httpCode}). Puede introducir los datos manualmente.");
        }

        if (($httpCode >= 400 || !empty($error)) && $verifySsl) {
            return ''; // Allow fallback attempt
        }

        if (!empty($error)) {
            throw new Exception("No se pudo conectar con la web del supermercado (Error cURL {$errno}: {$error}).");
        }

        return $html;
    }

    abstract protected function getAllowedHostnames(): array;
}
