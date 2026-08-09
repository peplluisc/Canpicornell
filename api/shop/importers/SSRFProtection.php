<?php
/**
 * Strict SSRF Protection for Can Picornell Product Importer
 * Validates schemes, hostnames, and IP ranges (IPv4 & IPv6 private/loopback/link-local)
 * before making any external HTTP request.
 */

class SSRFProtection {
    
    public static function validateUrl(string $url, array $allowedHostnames = ['elcorteingles.es', 'www.elcorteingles.es']): string {
        $url = trim($url);
        
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new Exception("La URL especificada no tiene un formato válido.");
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['https', 'http'])) {
            throw new Exception("Esquema no permitido. Se requiere una conexión HTTPS o HTTP segura.");
        }

        $host = strtolower($parts['host']);
        
        // Remove trailing dot if present
        $host = rtrim($host, '.');

        // Check Whitelist if configured
        if (!empty($allowedHostnames)) {
            $matched = false;
            foreach ($allowedHostnames as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new Exception("El dominio '{$host}' no está dentro de la lista de proveedores autorizados.");
            }
        }

        // Resolve DNS and check all IPs against private/reserved ranges
        $ips = gethostbynamel($host);
        if (empty($ips)) {
            throw new Exception("No se pudo resolver el nombre de dominio en DNS.");
        }

        foreach ($ips as $ip) {
            self::checkPrivateIP($ip);
        }

        // Normalize URL by stripping tracking parameters if possible
        $cleanUrl = self::sanitizeUrlParameters($url);

        return $cleanUrl;
    }

    public static function checkPrivateIP(string $ip): void {
        // Filter out private and loopback ranges
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            throw new Exception("Acceso denegado: La IP de destino ({$ip}) pertenece a una red privada o reservada.");
        }

        // Additional explicit checks for IPv4 ranges
        $long = ip2long($ip);
        if ($long !== false) {
            // 127.0.0.0/8
            if (($long & 0xFF000000) === 0x7F000000) throw new Exception("IP en rango loopback no permitida.");
            // 10.0.0.0/8
            if (($long & 0xFF000000) === 0x0A000000) throw new Exception("IP en rango privado 10.0.0.0/8 no permitida.");
            // 172.16.0.0/12
            if (($long & 0xFFF00000) === 0xAC100000) throw new Exception("IP en rango privado 172.16.0.0/12 no permitida.");
            // 192.168.0.0/16
            if (($long & 0xFFFF0000) === 0xC0A80000) throw new Exception("IP en rango privado 192.168.0.0/16 no permitida.");
            // 169.254.0.0/16
            if (($long & 0xFFFF0000) === 0xA9FE0000) throw new Exception("IP en rango link-local no permitida.");
        }
    }

    public static function sanitizeUrlParameters(string $url): string {
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $queryParams);
        
        // Remove tracking params
        $trackingKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'ph'];
        foreach ($trackingKeys as $tk) {
            unset($queryParams[$tk]);
        }

        $newQuery = http_build_query($queryParams);
        $clean = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
        if (!empty($newQuery)) {
            $clean .= '?' . $newQuery;
        }
        return $clean;
    }
}
