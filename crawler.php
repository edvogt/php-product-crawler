#!/usr/bin/env php
<?php
declare(strict_types=1);

// Product Discovery & Extraction Crawler - PHP 8+ / JSON Cache + OpenAI / ~700 LOC

function loadEnv(string $path = '.env'): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

class Config {
    public function __construct(
        public string $baseUrl,
        public string $modelsFile,
        public string $outFile,
        public bool $force = false,
        public bool $useAi = false,
        public int $delay = 1,
        public int $scoreThreshold = 20,
        public int $cacheHours = 24
    ) {}
}

class Log {
    public static function info(string $msg): void { echo "[INFO] $msg\n"; }
    public static function warn(string $msg): void { echo "[WARN] $msg\n"; }
    public static function error(string $msg): void { fwrite(STDERR, "[ERROR] $msg\n"); }
}

class Db {
    private string $cachePath;
    private array $cache;
    
    public function __construct(string $path = 'discovery_cache.json') {
        $this->cachePath = $path;
        $this->loadCache();
    }
    
    private function loadCache(): void {
        if (file_exists($this->cachePath)) {
            $content = file_get_contents($this->cachePath);
            $this->cache = json_decode($content, true) ?: [];
        } else {
            $this->cache = [];
        }
    }
    
    private function saveCache(): void {
        file_put_contents($this->cachePath, json_encode($this->cache, JSON_PRETTY_PRINT));
    }
    
    public function isCached(string $url, int $maxAgeHours): bool {
        $cutoff = time() - ($maxAgeHours * 3600);
        return isset($this->cache[$url]) && $this->cache[$url]['discovered_at'] > $cutoff;
    }
    
    public function addUrl(string $url, int $score = 0): void {
        $this->cache[$url] = [
            'discovered_at' => time(),
            'score' => $score
        ];
        $this->saveCache();
    }
    
    public function getCachedUrls(int $maxAgeHours): array {
        $cutoff = time() - ($maxAgeHours * 3600);
        $urls = [];
        foreach ($this->cache as $url => $data) {
            if ($data['discovered_at'] > $cutoff) {
                $urls[] = $url;
            }
        }
        return $urls;
    }
    
    public function clearCache(): void {
        $this->cache = [];
        $this->saveCache();
    }
}

class Http {
    private string $userAgent = 'ProductCrawler/2.1 (+replit)';
    
    public function fetch(string $url): ?string {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($code >= 200 && $code < 300) ? ($response ?: null) : null;
    }
}

class OpenAI {
    private string $apiKey;
    private string $model = 'gpt-4o-mini';
    
    public function __construct() {
        $this->apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if (empty($this->apiKey)) {
            throw new Exception('OPENAI_API_KEY not found in environment');
        }
    }
    
    public function scoreProductPage(string $title, string $content): int {
        $prompt = "Analyze if this is a product page. Score 0-100 based on:\n"
                . "- Product information present\n"
                . "- Technical specifications\n"
                . "- Product images/details\n"
                . "- Purchase or product-focused content\n\n"
                . "Title: $title\n\n"
                . "Content sample: " . substr($content, 0, 1000) . "\n\n"
                . "Respond with ONLY a number 0-100.";
        
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a product page classifier. Respond only with a number 0-100.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.3,
                'max_tokens' => 10
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            $errorSnippet = substr($response ?: 'no response', 0, 100);
            throw new Exception("OpenAI API error: HTTP $httpCode - $errorSnippet");
        }
        
        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            $dataSnippet = substr(json_encode($data), 0, 100);
            throw new Exception("OpenAI response missing content: $dataSnippet");
        }
        
        $scoreText = trim($data['choices'][0]['message']['content']);
        $filtered = filter_var($scoreText, FILTER_SANITIZE_NUMBER_INT);
        
        // Check for empty string (no digits found) or invalid numeric response
        if ($filtered === '' || $filtered === false) {
            throw new Exception("OpenAI returned non-numeric response: $scoreText");
        }
        
        $score = (int)$filtered;
        
        if ($score < 0 || $score > 100) {
            throw new Exception("OpenAI score out of range (0-100): $score");
        }
        
        return $score;
    }
}

class SiteDiscovery {
    public function __construct(private Http $http, private string $baseUrl) {}
    
    public function discover(): array {
        $urls = array_merge(
            $this->parseSitemap(),
            $this->crawlCategories()
        );
        return array_unique(array_filter($urls));
    }
    
    private function parseSitemap(): array {
        $urls = [];
        $sitemapUrls = [
            rtrim($this->baseUrl, '/') . '/sitemap.xml',
            rtrim($this->baseUrl, '/') . '/sitemap_index.xml',
            rtrim($this->baseUrl, '/') . '/product-sitemap.xml'
        ];
        
        foreach ($sitemapUrls as $sitemapUrl) {
            $content = $this->http->fetch($sitemapUrl);
            if (!$content) continue;
            
            try {
                $xml = @simplexml_load_string($content);
                if ($xml === false) continue;
                
                foreach ($xml->children() as $child) {
                    if ($child->getName() === 'sitemap') {
                        $loc = (string)$child->loc;
                        if ($loc) {
                            $subContent = $this->http->fetch($loc);
                            if ($subContent) {
                                $subXml = @simplexml_load_string($subContent);
                                if ($subXml) {
                                    foreach ($subXml->url as $url) {
                                        $urls[] = (string)$url->loc;
                                    }
                                }
                            }
                        }
                    } elseif ($child->getName() === 'url') {
                        $urls[] = (string)$child->loc;
                    }
                }
            } catch (Exception $e) {
                Log::warn("Sitemap parse error: {$e->getMessage()}");
            }
        }
        
        return $urls;
    }
    
    private function crawlCategories(): array {
        $urls = [];
        $categoryUrls = ['/products', '/shop', '/catalog', '/categories'];
        
        foreach ($categoryUrls as $path) {
            $url = rtrim($this->baseUrl, '/') . $path;
            $html = $this->http->fetch($url);
            if (!$html) continue;
            
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            $links = $xpath->query('//a[@href]');
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                $fullUrl = $this->makeAbsolute($href);
                if ($this->isProductUrl($fullUrl)) {
                    $urls[] = $fullUrl;
                }
            }
        }
        
        return $urls;
    }
    
    private function makeAbsolute(string $url): string {
        if (str_starts_with($url, 'http')) return $url;
        if (str_starts_with($url, '//')) return 'https:' . $url;
        if (str_starts_with($url, '/')) return rtrim($this->baseUrl, '/') . $url;
        return rtrim($this->baseUrl, '/') . '/' . $url;
    }
    
    private function isProductUrl(string $url): bool {
        $patterns = ['/product/', '/item/', '/p/', '/shop/', '/catalog/'];
        foreach ($patterns as $pattern) {
            if (str_contains($url, $pattern)) return true;
        }
        return false;
    }
}

class ModelMatcher {
    private array $models;
    
    public function __construct(string $modelsFile) {
        $this->models = array_filter(array_map('trim', file($modelsFile)));
    }
    
    public function findModel(string $text): ?string {
        $cleanText = strtoupper($text);
        foreach ($this->models as $model) {
            $pattern = preg_quote(strtoupper($model), '/');
            if (preg_match("/\b$pattern\b/", $cleanText)) {
                return $model;
            }
        }
        return null;
    }
}

class PageScorer {
    private ?OpenAI $ai;
    
    public function __construct(?OpenAI $ai = null) {
        $this->ai = $ai;
    }
    
    public function score(DOMDocument $dom, DOMXPath $xpath, string $html): int {
        // Use AI scoring if enabled
        if ($this->ai !== null) {
            return $this->scoreWithAI($xpath, $html);
        }
        
        // Fall back to rule-based scoring
        return $this->scoreRuleBased($xpath, $html);
    }
    
    private function scoreWithAI(DOMXPath $xpath, string $html): int {
        try {
            // Extract title
            $titleNodes = $xpath->query('//title');
            $title = $titleNodes->length > 0 ? $titleNodes[0]->textContent : '';
            
            // Extract text content (remove scripts/styles)
            $text = strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html));
            
            // Get AI score (0-100)
            $aiScore = $this->ai->scoreProductPage($title, $text);
            
            // Convert to 0-30 range to match rule-based scoring
            return (int)($aiScore * 0.3);
        } catch (Exception $e) {
            Log::warn("AI scoring failed: {$e->getMessage()}, falling back to rules");
            return $this->scoreRuleBased($xpath, $html);
        }
    }
    
    private function scoreRuleBased(DOMXPath $xpath, string $html): int {
        $score = 0;
        
        // Title presence (5 points)
        $titles = $xpath->query('//title');
        if ($titles->length > 0) $score += 5;
        
        // H1 presence (3 points)
        $h1 = $xpath->query('//h1');
        if ($h1->length > 0) $score += 3;
        
        // Specifications/specs keywords (5 points)
        if (preg_match('/\b(specification|specs|technical|features)\b/i', $html)) $score += 5;
        
        // Images (3 points)
        $imgs = $xpath->query('//img[@src or @data-src]');
        if ($imgs->length >= 3) $score += 3;
        
        // Physical specs keywords (4 points)
        if (preg_match('/\b(dimensions|weight|size|capacity)\b/i', $html)) $score += 4;
        
        // Documentation/manual (3 points)
        if (preg_match('/\b(manual|documentation|guide|instructions)\b/i', $html)) $score += 3;
        
        // JSON-LD Product schema (5 points)
        if (preg_match('/"@type"\s*:\s*"Product"/i', $html)) $score += 5;
        
        // Canonical URL (2 points)
        $canonical = $xpath->query('//link[@rel="canonical"]');
        if ($canonical->length > 0) $score += 2;
        
        return $score;
    }
}

class DataExtractor {
    private function cleanText(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/[^\w\s\-\.\,\:\;\(\)\/]/u', '', $text);
        return trim($text);
    }
    
    public function extract(string $html, string $url): array {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        $description = $this->extractDescription($xpath, $html);
        $shortDesc = $this->extractShortDescription($xpath);
        $images = $this->extractImages($xpath, $url);
        
        return [
            'description' => $description,
            'short_description' => $shortDesc,
            'images' => $images
        ];
    }
    
    private function extractDescription(DOMXPath $xpath, string $html): string {
        // Try JSON-LD
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        foreach ($scripts as $script) {
            $json = json_decode($script->textContent, true);
            if (isset($json['description'])) {
                return $this->cleanText($json['description']);
            }
        }
        
        // Try meta description
        $meta = $xpath->query('//meta[@name="description"]/@content');
        if ($meta->length > 0) {
            return $this->cleanText($meta[0]->value);
        }
        
        // Try product description divs
        $divs = $xpath->query('//*[contains(@class, "description") or contains(@id, "description")]');
        if ($divs->length > 0) {
            return $this->cleanText(substr($divs[0]->textContent, 0, 500));
        }
        
        return '';
    }
    
    private function extractShortDescription(DOMXPath $xpath): string {
        $h1 = $xpath->query('//h1');
        if ($h1->length > 0) {
            return $this->cleanText($h1[0]->textContent);
        }
        
        $title = $xpath->query('//title');
        if ($title->length > 0) {
            return $this->cleanText($title[0]->textContent);
        }
        
        return '';
    }
    
    private function extractImages(DOMXPath $xpath, string $baseUrl): array {
        $images = [];
        
        // Try picture elements
        $pictures = $xpath->query('//picture/source/@srcset | //picture/img/@src');
        foreach ($pictures as $attr) {
            $images[] = $this->extractUrlFromSrcset($attr->value);
        }
        
        // Try images with srcset
        $srcsets = $xpath->query('//img/@srcset');
        foreach ($srcsets as $srcset) {
            $images[] = $this->extractUrlFromSrcset($srcset->value);
        }
        
        // Try data-src
        $dataSrcs = $xpath->query('//img/@data-src');
        foreach ($dataSrcs as $src) {
            $images[] = $src->value;
        }
        
        // Try regular src
        $srcs = $xpath->query('//img/@src');
        foreach ($srcs as $src) {
            $images[] = $src->value;
        }
        
        // Filter and process
        $filtered = [];
        foreach ($images as $img) {
            if (!$img || $this->isIconOrPlaceholder($img)) continue;
            $absolute = $this->makeAbsolute($img, $baseUrl);
            if (!in_array($absolute, $filtered)) {
                $filtered[] = $absolute;
            }
            if (count($filtered) >= 10) break;
        }
        
        return $filtered;
    }
    
    private function extractUrlFromSrcset(string $srcset): string {
        $parts = preg_split('/\s+/', trim($srcset));
        return $parts[0] ?? '';
    }
    
    private function isIconOrPlaceholder(string $url): bool {
        $patterns = ['/icon/', '/logo/', '/placeholder/', '/avatar/', '/sprite/', '.svg'];
        foreach ($patterns as $pattern) {
            if (str_contains(strtolower($url), $pattern)) return true;
        }
        if (preg_match('/\b(16|24|32|48|64)x\1\b/', $url)) return true;
        return false;
    }
    
    private function makeAbsolute(string $url, string $base): string {
        if (str_starts_with($url, 'http')) return $url;
        if (str_starts_with($url, '//')) return 'https:' . $url;
        if (str_starts_with($url, '/')) return rtrim($base, '/') . $url;
        return rtrim($base, '/') . '/' . $url;
    }
}

class App {
    private Config $config;
    private Db $db;
    private Http $http;
    private ModelMatcher $matcher;
    private PageScorer $scorer;
    private DataExtractor $extractor;
    
    public function __construct(Config $config) {
        $this->config = $config;
        $this->db = new Db();
        $this->http = new Http();
        $this->matcher = new ModelMatcher($config->modelsFile);
        
        // Initialize AI scorer if enabled
        $ai = null;
        if ($config->useAi) {
            try {
                $ai = new OpenAI();
                Log::info("OpenAI scoring enabled");
            } catch (Exception $e) {
                Log::error("Failed to initialize OpenAI: {$e->getMessage()}");
                exit(1);
            }
        }
        
        $this->scorer = new PageScorer($ai);
        $this->extractor = new DataExtractor();
    }
    
    public function run(): void {
        Log::info("Starting product crawler");
        
        if ($this->config->force) {
            Log::info("Force mode: clearing cache");
            $this->db->clearCache();
        }
        
        $urls = $this->discoverUrls();
        Log::info("Discovered " . count($urls) . " URLs");
        
        $results = [];
        foreach ($urls as $idx => $url) {
            Log::info("Processing [" . ($idx + 1) . "/" . count($urls) . "]: $url");
            
            $data = $this->processUrl($url);
            if ($data) {
                $results[] = $data;
            }
            
            if ($idx < count($urls) - 1) {
                sleep($this->config->delay);
            }
        }
        
        $this->exportCsv($results);
        Log::info("Export complete: {$this->config->outFile}");
    }
    
    private function discoverUrls(): array {
        if (!$this->config->force) {
            $cached = $this->db->getCachedUrls($this->config->cacheHours);
            if (!empty($cached)) {
                Log::info("Using cached URLs (count: " . count($cached) . ")");
                return $cached;
            }
        }
        
        $discovery = new SiteDiscovery($this->http, $this->config->baseUrl);
        $urls = $discovery->discover();
        
        foreach ($urls as $url) {
            $this->db->addUrl($url);
        }
        
        return $urls;
    }
    
    private function processUrl(string $url): ?array {
        $html = $this->http->fetch($url);
        if (!$html) return null;
        
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        $score = $this->scorer->score($dom, $xpath, $html);
        if ($score < $this->config->scoreThreshold) {
            Log::warn("Score too low ($score): $url");
            return null;
        }
        
        $model = $this->matcher->findModel($html);
        if (!$model) {
            Log::warn("No model match: $url");
            return null;
        }
        
        $data = $this->extractor->extract($html, $url);
        
        return [
            'model' => $model,
            'url' => $url,
            'description' => $data['description'],
            'short_description' => $data['short_description'],
            'images' => implode(' ', $data['images'])
        ];
    }
    
    private function exportCsv(array $results): void {
        $fp = fopen($this->config->outFile, 'w');
        foreach ($results as $row) {
            $line = implode(';', [
                str_replace(';', '&#59;', $row['model']),
                str_replace(';', '&#59;', $row['url']),
                str_replace(';', '&#59;', $row['description']),
                str_replace(';', '&#59;', $row['short_description']),
                str_replace(';', '&#59;', $row['images'])
            ]);
            fwrite($fp, $line . "\n");
        }
        fclose($fp);
    }
}

// Main entry point
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Load environment variables from .env file
loadEnv();

$options = getopt('', ['base:', 'models:', 'out:', 'force', 'use-ai']);

if (!isset($options['base']) || !isset($options['models']) || !isset($options['out'])) {
    echo "Usage: php crawler.php --base=<url> --models=<file> --out=<file> [--force] [--use-ai]\n";
    echo "  --base    Base URL of the site to crawl\n";
    echo "  --models  File containing model names (one per line)\n";
    echo "  --out     Output CSV file\n";
    echo "  --force   Force refresh (bypass cache)\n";
    echo "  --use-ai  Use OpenAI for intelligent page scoring (requires OPENAI_API_KEY in .env)\n";
    exit(1);
}

$config = new Config(
    baseUrl: $options['base'],
    modelsFile: $options['models'],
    outFile: $options['out'],
    force: isset($options['force']),
    useAi: isset($options['use-ai'])
);

$app = new App($config);
$app->run();
