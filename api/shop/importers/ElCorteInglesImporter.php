<?php
/**
 * El Corte Inglés Supermarket Product Importer for Can Picornell
 * Parses public product pages, extracts structured JSON data, handles multi-price offer detection,
 * unit price filtering, EAN/GTIN extraction, and format detection.
 */

require_once __DIR__ . '/GenericProductImporter.php';

class ElCorteInglesImporter extends GenericProductImporter {

    protected function getAllowedHostnames(): array {
        return ['elcorteingles.es', 'www.elcorteingles.es'];
    }

    public function parseUrl(string $url, string $targetLang = 'es'): array {
        $html = $this->fetchHtml($url);

        $extractionMethod = 'unknown';
        $name = '';
        $brand = '';
        $supplierProductId = '';
        $gtin = '';
        $formatText = '';
        $description = '';
        $imageUrl = '';

        $priceCents = 0;
        $originalPriceCents = 0;
        $detectedPrices = [];
        $requiresPriceReview = false;
        $hasActivePromotion = false;

        // 1. Extract supplier_product_id from URL path (e.g., B001018630400341)
        if (preg_match('/(B\d{15})/', $url, $m) || preg_match('/(B\d+)/', $url, $m)) {
            $supplierProductId = $m[1];
        }

        // 2. Extract EAN/GTIN (84xxxxxxxxxxx)
        if (preg_match('/84\d{11}/', $html, $m)) {
            $gtin = $m[0];
        }

        // 3. Stage 1: DataLayer / Embedded Product JSON Object
        if (preg_match('/("product"\s*:\s*\{.*?"page_type"\s*:\s*"PDP"\s*\})/s', $html, $m)) {
            $jsonStr = '{' . $m[1] . '}';
            $pData = json_decode($jsonStr, true);
            if ($pData && isset($pData['product'])) {
                $p = $pData['product'];
                $extractionMethod = 'dataLayer-JSON';

                if (!empty($p['name'])) $name = trim(html_entity_decode($p['name'], ENT_QUOTES, 'UTF-8'));
                if (!empty($p['brand'])) $brand = trim(is_string($p['brand']) ? $p['brand'] : ($p['brand']['name'] ?? ''));
                if (!empty($p['id_ff'])) $supplierProductId = trim($p['id_ff']);

                if (isset($p['price']['final']) && is_numeric($p['price']['final'])) {
                    $finalVal = floatval($p['price']['final']);
                    $priceCents = intval(round($finalVal * 100));
                    $detectedPrices[] = [
                        'label' => 'Precio actual / Oferta',
                        'price_cents' => $priceCents,
                        'formatted' => number_format($finalVal, 2, ',', '.') . ' €'
                    ];
                }

                if (isset($p['price']['original']) && is_numeric($p['price']['original'])) {
                    $origVal = floatval($p['price']['original']);
                    $originalPriceCents = intval(round($origVal * 100));
                    if ($originalPriceCents > 0 && $originalPriceCents !== $priceCents) {
                        $hasActivePromotion = true;
                        $requiresPriceReview = true;
                        $detectedPrices[] = [
                            'label' => 'Precio original sin oferta',
                            'price_cents' => $originalPriceCents,
                            'formatted' => number_format($origVal, 2, ',', '.') . ' €'
                        ];
                    }
                }
            }
        }

        // 4. Stage 2: Fallback to JSON-LD
        if (empty($name) && preg_match_all('/<script[^>]*type=[\'"]application\/ld\+json[\'"][^>]*>(.*?)<\/script>/s', $html, $matches)) {
            foreach ($matches[1] as $jldStr) {
                $jld = json_decode($jldStr, true);
                if (!$jld) continue;

                $items = isset($jld['@graph']) ? $jld['@graph'] : (isset($jld[0]) ? $jld : [$jld]);
                foreach ($items as $item) {
                    if (isset($item['@type']) && $item['@type'] === 'Product') {
                        $extractionMethod = 'JSON-LD';
                        if (empty($name) && !empty($item['name'])) $name = trim($item['name']);
                        if (empty($brand) && !empty($item['brand'])) {
                            $brand = is_string($item['brand']) ? $item['brand'] : ($item['brand']['name'] ?? '');
                        }
                        if (empty($gtin) && !empty($item['gtin13'])) $gtin = trim($item['gtin13']);
                        if (empty($description) && !empty($item['description'])) $description = trim($item['description']);
                        if (empty($imageUrl) && !empty($item['image'])) {
                            $imageUrl = is_array($item['image']) ? $item['image'][0] : $item['image'];
                        }

                        // Offers inside Product
                        if ($priceCents === 0 && !empty($item['offers'])) {
                            $offers = isset($item['offers']['price']) ? [$item['offers']] : (is_array($item['offers']) ? $item['offers'] : []);
                            foreach ($offers as $off) {
                                if (isset($off['price']) && is_numeric($off['price'])) {
                                    $priceCents = intval(round(floatval($off['price']) * 100));
                                }
                            }
                        }
                    }
                }
            }
        }

        // 5. Stage 3: Fallback OpenGraph / Meta Title & Image
        if (empty($name)) {
            if (preg_match('/<meta[^>]*property=[\'"]og:title[\'"][^>]*content=[\'"](.*?)[\'"]/i', $html, $m)) {
                $name = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                $extractionMethod = 'OpenGraph';
            } else if (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) {
                $name = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                // Clean store suffix
                $name = preg_replace('/\s*·\s*Supermercado El Corte Inglés.*$/i', '', $name);
                $extractionMethod = 'HTML Title';
            }
        }

        if (empty($imageUrl)) {
            if (preg_match('/<meta[^>]*property=[\'"]og:image[\'"][^>]*content=[\'"](.*?)[\'"]/i', $html, $m)) {
                $imageUrl = trim($m[1]);
            } else if (preg_match('/(https:\/\/cdn\.grupoelcorteingles\.es\/Swin\/products\/[^\'"\s>]+)/i', $html, $m)) {
                $imageUrl = trim($m[1]);
            }
        }

        // 6. Extract Format text (e.g. garrafa 6,25 l, pack 28 latas 33 cl, envase 405 g)
        if (preg_match('/(garrafa\s+\d+[\.,]?\d*\s*l|pack\s+\d+\s+latas?\s+\d+\s*cl|envase\s+\d+\s*g|\d+[\.,]?\d*\s*l|\d+\s*cl|\d+\s*g)/i', $name, $m)) {
            $formatText = trim($m[1]);
        }

        // Clean Brand & Name formatting
        $brand = strtoupper(trim($brand));
        $name = trim(preg_replace('/\s+/', ' ', $name));

        // 7. Check for promotional text keywords
        if (preg_match('/(oferta|2ª\s*unidad|pack|descuento|promoción|3x2)/i', $html)) {
            $hasActivePromotion = true;
        }

        // Return normalized data array
        return [
            'supplier_name' => 'El Corte Inglés',
            'supplier_product_id' => $supplierProductId,
            'gtin' => $gtin,
            'brand' => $brand,
            'name' => $name,
            'format_text' => $formatText,
            'description' => strip_tags($description),
            'reference_price_cents' => $priceCents,
            'price_formatted' => number_format($priceCents / 100, 2, ',', '.') . ' €',
            'image_url' => $imageUrl,
            'source_url' => $url,
            'target_language' => $targetLang,
            'extraction_method' => $extractionMethod,
            'has_active_promotion' => $hasActivePromotion,
            'requires_price_review' => $requiresPriceReview,
            'detected_prices' => $detectedPrices,
            'extraction_status' => [
                'name' => !empty($name),
                'brand' => !empty($brand),
                'gtin' => !empty($gtin),
                'format' => !empty($formatText),
                'price' => $priceCents > 0,
                'image' => !empty($imageUrl),
                'description' => !empty($description)
            ]
        ];
    }
}
