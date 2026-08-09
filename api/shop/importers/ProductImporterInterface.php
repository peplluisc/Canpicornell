<?php
/**
 * Interface for Can Picornell Product Importers
 */

interface ProductImporterInterface {
    /**
     * Parses a public product URL and returns a normalized data array.
     *
     * @param string $url The validated public URL
     * @param string $targetLang Target language ('es', 'en', 'de')
     * @return array Normalized product data array
     */
    public function parseUrl(string $url, string $targetLang = 'es'): array;
}
