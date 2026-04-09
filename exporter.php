<?php
/**
 * WikiExporter — Backend
 * Supports two modes:
 *   1. Single URL mode: { url, count } — crawls sub-pages from a root URL
 *   2. Batch mode: { mode: "batch", urls: [...] } — exports each URL directly (no crawling)
 * Streams JSON progress messages back to the client.
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

// Disable output buffering for streaming
while (ob_get_level())
    ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('implicit_flush', 1);
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(600); // 10 minutes for large batch jobs

// -- Debug Logger --
$debugLogFile = __DIR__ . '/exports/debug.log';

function debugLog($message, $level = 'INFO')
{
    global $debugLogFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    @file_put_contents($debugLogFile, $line, FILE_APPEND | LOCK_EX);
}

// Custom error handler to capture PHP warnings/errors
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    debugLog("PHP Error ({$errno}): {$errstr} in {$errfile}:{$errline}", 'PHP_ERROR');
    return false;
});

// -- Helpers --

function sendMsg($data)
{
    echo json_encode($data) . "\n";
    flush();
}

function sendLog($message, $level = 'info')
{
    debugLog($message, strtoupper($level));
    sendMsg(['type' => 'log', 'message' => $message, 'level' => $level]);
}

function sendDebug($message)
{
    debugLog($message, 'DEBUG');
    sendMsg(['type' => 'debug', 'message' => $message]);
}

function sendProgress($current, $total, $message = '')
{
    sendMsg(['type' => 'progress', 'current' => $current, 'total' => $total, 'message' => $message]);
}

function sendError($message)
{
    debugLog($message, 'FATAL');
    sendMsg(['type' => 'error', 'message' => $message]);
    exit;
}

/**
 * Resolve a hostname to an IPv4 address.
 * Tries: 1) PHP's gethostbyname  2) Google DNS-over-HTTPS (connecting by IP, no DNS needed)
 * Returns the IP string or null on failure.
 */
function resolveHost($host)
{
    // Method 1: PHP built-in resolver
    $ip = @gethostbyname($host);
    if ($ip && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        debugLog("DNS resolved via gethostbyname: {$host} → {$ip}");
        return $ip;
    }

    // Method 2: Google DNS-over-HTTPS (connect directly to 8.8.8.8 by IP — no DNS needed)
    if (function_exists('curl_init')) {
        debugLog("Trying Google DNS-over-HTTPS for: {$host}");
        $dnsUrl = "https://dns.google/resolve?name=" . urlencode($host) . "&type=A";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $dnsUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            // Connect to dns.google by its known IP so we don't need DNS to do DNS
            CURLOPT_RESOLVE        => ['dns.google:443:8.8.8.8'],
        ]);

        $resp = curl_exec($ch);
        $dnsErr = curl_error($ch);
        curl_close($ch);

        if ($resp) {
            $json = @json_decode($resp, true);
            if (isset($json['Answer']) && is_array($json['Answer'])) {
                foreach ($json['Answer'] as $answer) {
                    // Type 1 = A record (IPv4)
                    if (isset($answer['type']) && $answer['type'] == 1 && !empty($answer['data'])) {
                        $resolvedIp = $answer['data'];
                        if (filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            debugLog("DNS resolved via Google DoH: {$host} → {$resolvedIp}");
                            return $resolvedIp;
                        }
                    }
                }
            }
            debugLog("Google DoH returned no A records for {$host}", 'WARN');
        } else {
            debugLog("Google DoH request failed: {$dnsErr}", 'WARN');
        }
    }

    // Method 3: Shell nslookup (works on Windows and most systems)
    $output = @shell_exec("nslookup " . escapeshellarg($host) . " 8.8.8.8 2>&1");
    if ($output) {
        // Parse nslookup output for the resolved address
        if (preg_match('/Address:\s*(\d+\.\d+\.\d+\.\d+)/m', $output, $matches)) {
            // Skip the first match if it's the DNS server itself (8.8.8.8)
            preg_match_all('/Address:\s*(\d+\.\d+\.\d+\.\d+)/m', $output, $allMatches);
            foreach ($allMatches[1] as $foundIp) {
                if ($foundIp !== '8.8.8.8') {
                    debugLog("DNS resolved via nslookup: {$host} → {$foundIp}");
                    return $foundIp;
                }
            }
        }
    }

    debugLog("All DNS resolution methods failed for: {$host}", 'ERROR');
    return null;
}

function fetchUrl($url)
{
    global $authUser, $authPass, $authCookieJar;
    debugLog("Fetching URL: {$url}");

    // Realistic browser User-Agent (avoids blocks from Wikipedia and other sites)
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // Pre-resolve DNS for the host (fixes XAMPP/Apache DNS issues on Windows & macOS)
    $parsed = parse_url($url);
    $host = isset($parsed['host']) ? $parsed['host'] : '';
    $resolvedIp = null;
    $resolveDirective = null;

    if ($host) {
        $resolvedIp = resolveHost($host);
        if ($resolvedIp) {
            $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
            $port = $scheme === 'https' ? 443 : 80;
            if (isset($parsed['port'])) {
                $port = (int) $parsed['port'];
            }
            $resolveDirective = ["{$host}:{$port}:{$resolvedIp}"];
            debugLog("Will use CURLOPT_RESOLVE: {$host}:{$port}:{$resolvedIp}");
        }
    }

    // Try cURL first if available
    if (function_exists('curl_init')) {
        debugLog("Using cURL for fetch");

        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Connection: keep-alive',
            ],
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ];

        // Set explicit DNS servers if the cURL build supports it (requires c-ares)
        if (defined('CURLOPT_DNS_SERVERS')) {
            $curlOpts[CURLOPT_DNS_SERVERS] = '8.8.8.8,8.8.4.4';
            debugLog("Set explicit DNS servers: 8.8.8.8, 8.8.4.4");
        }

        // Pin the pre-resolved IP so cURL doesn't need to do DNS at all
        if ($resolveDirective) {
            $curlOpts[CURLOPT_RESOLVE] = $resolveDirective;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $curlOpts);

        // === AUTHENTICATION ===
        if (!empty($authUser)) {
            debugLog("Auth enabled for user: {$authUser}");

            // Set credentials for Basic/Digest/NTLM auth (try any)
            curl_setopt($ch, CURLOPT_USERPWD, "{$authUser}:{$authPass}");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);

            // Use cookie jar for session persistence across requests
            if (!empty($authCookieJar)) {
                curl_setopt($ch, CURLOPT_COOKIEJAR, $authCookieJar);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $authCookieJar);
            }
        }

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // If DNS failed and we didn't have a pre-resolved IP, try resolving now and retry
        if ($curlErrno === 6 && !$resolvedIp && $host) {
            debugLog("cURL DNS failed, attempting manual DNS resolution and retry…", 'WARN');
            $resolvedIp = resolveHost($host);
            if ($resolvedIp) {
                $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
                $port = $scheme === 'https' ? 443 : 80;
                if (isset($parsed['port'])) {
                    $port = (int) $parsed['port'];
                }
                $curlOpts[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$resolvedIp}"];
                debugLog("Retrying with resolved IP: {$host} → {$resolvedIp}");

                $ch = curl_init();
                curl_setopt_array($ch, $curlOpts);

                // Re-apply auth for retry
                if (!empty($authUser)) {
                    curl_setopt($ch, CURLOPT_USERPWD, "{$authUser}:{$authPass}");
                    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                    if (!empty($authCookieJar)) {
                        curl_setopt($ch, CURLOPT_COOKIEJAR, $authCookieJar);
                        curl_setopt($ch, CURLOPT_COOKIEFILE, $authCookieJar);
                    }
                }
                $html = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                curl_close($ch);
            }
        }

        if ($html === false || $curlErrno !== 0) {
            $errMsg = "cURL error #{$curlErrno}: {$curlError}";
            debugLog($errMsg, 'ERROR');
            sendDebug("Fetch failed — {$errMsg}");
            return ['html' => null, 'error' => $errMsg];
        }

        if ($httpCode >= 400) {
            $errMsg = "HTTP {$httpCode}";
            debugLog("HTTP {$httpCode} for {$url}", 'ERROR');
            sendDebug("Fetch returned HTTP {$httpCode}");
            return ['html' => null, 'error' => $errMsg];
        }

        debugLog("Fetched OK — HTTP {$httpCode}, " . strlen($html) . " bytes");
        return ['html' => $html, 'error' => null];
    }

    // Fallback to file_get_contents
    debugLog("cURL not available, using file_get_contents");
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => $userAgent,
            'follow_location' => true,
            'max_redirects' => 5,
            'header' => "Accept: text/html,application/xhtml+xml\r\nAccept-Language: en-US,en;q=0.5\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);

    $html = @file_get_contents($url, false, $ctx);
    if ($html === false) {
        $err = error_get_last();
        $errMsg = $err ? $err['message'] : 'Unknown error';
        debugLog("file_get_contents failed: {$errMsg}", 'ERROR');
        sendDebug("Fetch failed — {$errMsg}");
        return ['html' => null, 'error' => $errMsg];
    }

    debugLog("Fetched OK — " . strlen($html) . " bytes");
    return ['html' => $html, 'error' => null];
}

// Legacy wrapper for single-mode compatibility
function fetchUrlLegacy($url)
{
    $result = fetchUrl($url);
    return $result['html'];
}

function sanitizeFilename($name)
{
    $name = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = trim($name, '_ ');
    if (empty($name))
        $name = 'page';
    return substr($name, 0, 120);
}

function resolveUrl($base, $relative)
{
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }
    $parsed = parse_url($base);
    $scheme = $parsed['scheme'] ?? 'https';
    $host = $parsed['host'] ?? '';
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

    if (str_starts_with($relative, '//')) {
        return $scheme . ':' . $relative;
    }
    if (str_starts_with($relative, '/')) {
        return $scheme . '://' . $host . $port . $relative;
    }
    $path = $parsed['path'] ?? '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    return $scheme . '://' . $host . $port . $dir . $relative;
}

function getBaseUrl($url)
{
    $parsed = parse_url($url);
    $scheme = $parsed['scheme'] ?? 'https';
    $host = $parsed['host'] ?? '';
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    return $scheme . '://' . $host . $port;
}

function detectWikiPathPrefix($url)
{
    $parsed = parse_url($url);
    $path = $parsed['path'] ?? '/';

    if (preg_match('#^(/wiki/)#', $path, $m))
        return '/wiki/';
    if (preg_match('#^(/w/index\.php)#', $path, $m))
        return '/w/index.php';

    $dir = substr($path, 0, strrpos($path, '/') + 1);
    return $dir;
}

function extractWikiLinks($html, $baseUrl, $wikiPrefix)
{
    $links = [];

    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($doc);

    $anchors = $xpath->query('//a[@href]');
    foreach ($anchors as $a) {
        $href = $a->getAttribute('href');

        if (empty($href) || $href[0] === '#')
            continue;
        if (preg_match('#(Special:|Talk:|User:|Template:|Category:|File:|Help:|Wikipedia:|Portal:|MediaWiki:)#i', $href))
            continue;
        if (preg_match('#\.(jpg|jpeg|png|gif|svg|pdf|css|js)$#i', $href))
            continue;
        if (str_contains($href, 'action='))
            continue;
        if (str_contains($href, '?'))
            continue;

        $fullUrl = resolveUrl($baseUrl . $wikiPrefix, $href);

        $parsedFull = parse_url($fullUrl);
        $parsedBase = parse_url($baseUrl);

        if (($parsedFull['host'] ?? '') !== ($parsedBase['host'] ?? ''))
            continue;

        $path = $parsedFull['path'] ?? '';
        if (!empty($wikiPrefix) && $wikiPrefix !== '/' && !str_starts_with($path, $wikiPrefix))
            continue;

        $title = $a->textContent;
        $title = trim($title);

        if (strlen($title) < 2)
            continue;

        $links[$fullUrl] = $title;
    }

    return $links;
}

function extractPageContent($html, $sourceUrl = '')
{
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($doc);

    // Determine base URL for resolving relative paths
    $baseUrl = '';
    if ($sourceUrl) {
        $parsedSource = parse_url($sourceUrl);
        $scheme = isset($parsedSource['scheme']) ? $parsedSource['scheme'] : 'https';
        $host = isset($parsedSource['host']) ? $parsedSource['host'] : '';
        $port = isset($parsedSource['port']) ? ':' . $parsedSource['port'] : '';
        $baseUrl = $scheme . '://' . $host . $port;
    }

    // Try to find page title
    $title = '';
    $titleNodes = $xpath->query('//h1[@id="firstHeading"] | //h1[contains(@class,"firstHeading")] | //h1');
    if ($titleNodes->length > 0) {
        $title = trim($titleNodes->item(0)->textContent);
    }

    // Fallback: try <title> tag
    if (empty($title)) {
        $titleTags = $xpath->query('//title');
        if ($titleTags->length > 0) {
            $title = trim($titleTags->item(0)->textContent);
            // Clean up common suffixes like " - Wikipedia"
            $title = preg_replace('/\s*[-–—|]\s*(Wikipedia|Wiki).*$/i', '', $title);
        }
    }

    // Try to find main content area (MediaWiki standard selectors)
    $contentSelectors = [
        '//*[@id="mw-content-text"]',
        '//*[@id="bodyContent"]',
        '//*[@id="content"]',
        '//*[contains(@class,"mw-parser-output")]',
        '//main',
        '//article',
        '//body'
    ];

    $contentHtml = '';
    foreach ($contentSelectors as $sel) {
        $nodes = $xpath->query($sel);
        if ($nodes->length > 0) {
            $node = $nodes->item(0);

            $removeSelectors = [
                './/nav',
                './/footer',
                './/header',
                './/*[contains(@class,"navbox")]',
                './/*[contains(@class,"sidebar")]',
                './/*[contains(@class,"mw-editsection")]',
                './/*[contains(@class,"noprint")]',
                './/*[contains(@class,"mw-jump-link")]',
                './/*[contains(@class,"toc")]',
                './/*[contains(@id,"siteSub")]',
                './/*[contains(@id,"contentSub")]',
                './/*[contains(@class,"mw-indicators")]',
                './/script',
                './/style',
                './/*[contains(@class,"catlinks")]',
                './/*[contains(@class,"printfooter")]',
            ];

            foreach ($removeSelectors as $rSel) {
                $toRemove = $xpath->query($rSel, $node);
                foreach ($toRemove as $r) {
                    if ($r->parentNode) {
                        $r->parentNode->removeChild($r);
                    }
                }
            }

            // ===== FIX IMAGE & MEDIA URLs =====
            if ($baseUrl) {
                // Fix <img> tags — src, data-src, srcset
                $images = $xpath->query('.//img', $node);
                foreach ($images as $img) {
                    // Handle lazy-loaded images: data-src → src
                    $dataSrc = $img->getAttribute('data-src');
                    $src = $img->getAttribute('src');

                    // If src is a placeholder/tiny and data-src has the real image, use data-src
                    if ($dataSrc && (!$src || strpos($src, 'data:') === 0 || strpos($src, '1x1') !== false || strlen($src) < 10)) {
                        $img->setAttribute('src', $dataSrc);
                        $src = $dataSrc;
                    }

                    // Convert relative src to absolute
                    if ($src) {
                        $img->setAttribute('src', makeAbsoluteUrl($src, $baseUrl));
                    }

                    // Convert srcset URLs to absolute
                    $srcset = $img->getAttribute('srcset');
                    if ($srcset) {
                        $img->setAttribute('srcset', fixSrcset($srcset, $baseUrl));
                    }

                    // Also fix data-srcset
                    $dataSrcset = $img->getAttribute('data-srcset');
                    if ($dataSrcset) {
                        $img->setAttribute('srcset', fixSrcset($dataSrcset, $baseUrl));
                        $img->removeAttribute('data-srcset');
                    }

                    // Remove lazy-load classes that might hide images
                    $class = $img->getAttribute('class');
                    if ($class) {
                        $class = preg_replace('/\b(lazy|lazyload|lazyloaded)\b/i', '', $class);
                        $img->setAttribute('class', trim($class));
                    }

                    // Ensure loading is eager (not lazy) for standalone files
                    $img->setAttribute('loading', 'eager');
                    $img->removeAttribute('data-src');
                }

                // Fix <source> tags (inside <picture> or <video>)
                $sources = $xpath->query('.//source', $node);
                foreach ($sources as $source) {
                    $srcAttr = $source->getAttribute('src');
                    if ($srcAttr) {
                        $source->setAttribute('src', makeAbsoluteUrl($srcAttr, $baseUrl));
                    }
                    $srcsetAttr = $source->getAttribute('srcset');
                    if ($srcsetAttr) {
                        $source->setAttribute('srcset', fixSrcset($srcsetAttr, $baseUrl));
                    }
                }

                // Fix <video> and <audio> poster/src
                $mediaElems = $xpath->query('.//video | .//audio', $node);
                foreach ($mediaElems as $media) {
                    $poster = $media->getAttribute('poster');
                    if ($poster) {
                        $media->setAttribute('poster', makeAbsoluteUrl($poster, $baseUrl));
                    }
                    $mediaSrc = $media->getAttribute('src');
                    if ($mediaSrc) {
                        $media->setAttribute('src', makeAbsoluteUrl($mediaSrc, $baseUrl));
                    }
                }

                // Fix <a> hrefs that link to images/files
                $links = $xpath->query('.//a[@href]', $node);
                foreach ($links as $link) {
                    $href = $link->getAttribute('href');
                    if ($href && $href[0] !== '#') {
                        $link->setAttribute('href', makeAbsoluteUrl($href, $baseUrl));
                    }
                }
            }

            $contentHtml = $doc->saveHTML($node);
            break;
        }
    }

    if (empty($contentHtml)) {
        $contentHtml = '<p>Content could not be extracted.</p>';
    }

    // ===== EXTRACT STYLESHEETS from original page =====
    $stylesheets = [];
    if ($baseUrl) {
        // Extract <link rel="stylesheet"> tags
        $linkNodes = $xpath->query('//link[@rel="stylesheet"]');
        foreach ($linkNodes as $linkNode) {
            $href = $linkNode->getAttribute('href');
            if ($href) {
                $absHref = makeAbsoluteUrl($href, $baseUrl);
                $media = $linkNode->getAttribute('media');
                $stylesheets[] = ['type' => 'link', 'href' => $absHref, 'media' => $media ?: 'all'];
            }
        }

        // Extract inline <style> blocks from <head>
        $styleNodes = $xpath->query('//head//style');
        foreach ($styleNodes as $styleNode) {
            $cssText = $styleNode->textContent;
            if (trim($cssText)) {
                // Fix any relative URLs inside inline CSS (url(...))
                $cssText = preg_replace_callback('/url\(\s*["\']?([^)"\'\']+?)["\']?\s*\)/', function($m) use ($baseUrl) {
                    $url = trim($m[1]);
                    if (strpos($url, 'data:') === 0) return $m[0];
                    return 'url("' . makeAbsoluteUrl($url, $baseUrl) . '")';
                }, $cssText);
                $stylesheets[] = ['type' => 'inline', 'css' => $cssText];
            }
        }
    }

    return ['title' => $title, 'content' => $contentHtml, 'stylesheets' => $stylesheets];
}

/**
 * Convert a relative URL to absolute.
 */
function makeAbsoluteUrl($url, $baseUrl)
{
    $url = trim($url);
    if (empty($url)) return $url;

    // Already absolute
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    // Protocol-relative
    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }
    // Data URIs — leave as-is
    if (strpos($url, 'data:') === 0) {
        return $url;
    }
    // Absolute path
    if ($url[0] === '/') {
        return $baseUrl . $url;
    }
    // Relative path
    return $baseUrl . '/' . $url;
}

/**
 * Fix all URLs inside a srcset attribute value.
 * srcset format: "url1 1x, url2 2x" or "url1 300w, url2 600w"
 */
function fixSrcset($srcset, $baseUrl)
{
    $parts = explode(',', $srcset);
    $fixed = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        // Split into URL and descriptor (e.g., "1x", "300w")
        $pieces = preg_split('/\s+/', $part, 2);
        $url = $pieces[0];
        $descriptor = isset($pieces[1]) ? $pieces[1] : '';
        $url = makeAbsoluteUrl($url, $baseUrl);
        $fixed[] = $descriptor ? ($url . ' ' . $descriptor) : $url;
    }
    return implode(', ', $fixed);
}

/**
 * Extract a folder name from a URL based on its last path segment.
 * e.g. https://en.wikipedia.org/wiki/PHP → "PHP"
 */
function extractFolderName($url)
{
    $parsed = parse_url($url);
    $path = isset($parsed['path']) ? $parsed['path'] : '';
    $path = rtrim($path, '/');

    if (empty($path) || $path === '/') {
        // Use hostname as fallback
        $host = isset($parsed['host']) ? $parsed['host'] : 'export';
        return sanitizeFilename($host);
    }

    $lastSegment = basename(urldecode($path));
    $folderName = sanitizeFilename($lastSegment);

    if (empty($folderName)) {
        $folderName = 'export_' . substr(md5($url), 0, 8);
    }

    return $folderName;
}

/**
 * Download a single image via cURL and save it to the target directory.
 * Returns the local filename on success, or null on failure.
 */
function downloadImage($imageUrl, $targetDir)
{
    global $authUser, $authPass, $authCookieJar;
    if (empty($imageUrl) || strpos($imageUrl, 'data:') === 0) {
        return null;
    }

    // Generate a clean filename from the URL
    $parsed = parse_url($imageUrl);
    $pathPart = isset($parsed['path']) ? $parsed['path'] : '';
    $baseName = basename($pathPart);

    // Clean up the filename
    $baseName = urldecode($baseName);
    $baseName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $baseName);
    $baseName = preg_replace('/_+/', '_', $baseName);

    if (empty($baseName) || $baseName === '.' || strlen($baseName) < 3) {
        $baseName = 'img_' . substr(md5($imageUrl), 0, 10) . '.png';
    }

    // Ensure the filename isn't too long
    if (strlen($baseName) > 120) {
        $ext = pathinfo($baseName, PATHINFO_EXTENSION);
        $baseName = substr(pathinfo($baseName, PATHINFO_FILENAME), 0, 100) . '.' . $ext;
    }

    // Avoid collisions
    $savePath = $targetDir . '/' . $baseName;
    if (file_exists($savePath)) {
        // File already downloaded (same name), reuse it
        return $baseName;
    }

    // Download via cURL
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $imageUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);

    // Use DNS resolution helper
    $host = isset($parsed['host']) ? $parsed['host'] : '';
    if ($host) {
        $ip = resolveHost($host);
        if ($ip) {
            $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
            $port = $scheme === 'https' ? 443 : 80;
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}"]);
        }
    }

    // Apply auth if available
    if (!empty($authUser)) {
        curl_setopt($ch, CURLOPT_USERPWD, "{$authUser}:{$authPass}");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        if (!empty($authCookieJar)) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $authCookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $authCookieJar);
        }
    }

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($data === false || $httpCode >= 400 || strlen($data) < 100) {
        debugLog("Failed to download image: {$imageUrl} (HTTP {$httpCode}, err: {$err})", 'WARN');
        return null;
    }

    // Save the file
    $written = @file_put_contents($savePath, $data);
    if ($written === false) {
        debugLog("Failed to write image: {$savePath}", 'WARN');
        return null;
    }

    return $baseName;
}

/**
 * Download all images/media in the HTML content and replace URLs with local filenames.
 * Returns the modified HTML with local image paths.
 */
function downloadAllImages($contentHtml, $targetDir, $sourceUrl = '')
{
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8" ?>' . '<div id="__wrapper__">' . $contentHtml . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($doc);

    $wrapper = $xpath->query('//*[@id="__wrapper__"]')->item(0);
    if (!$wrapper) {
        return $contentHtml; // fallback, no changes
    }

    $downloaded = []; // cache: originalUrl => localFilename
    $imgCount = 0;

    // Process <img> tags
    $images = $xpath->query('.//img', $wrapper);
    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        if (empty($src) || strpos($src, 'data:') === 0) continue;

        if (!isset($downloaded[$src])) {
            $localName = downloadImage($src, $targetDir);
            $downloaded[$src] = $localName;
            if ($localName) $imgCount++;
        }

        if ($downloaded[$src]) {
            $img->setAttribute('src', $downloaded[$src]);
        }

        // Also fix srcset to local files
        $srcset = $img->getAttribute('srcset');
        if ($srcset) {
            $newSrcset = fixSrcsetLocal($srcset, $targetDir, $downloaded);
            $img->setAttribute('srcset', $newSrcset);
        }
    }

    // Process <source> tags (inside <picture>/<video>)
    $sources = $xpath->query('.//source', $wrapper);
    foreach ($sources as $source) {
        $srcAttr = $source->getAttribute('src');
        if ($srcAttr && strpos($srcAttr, 'data:') !== 0) {
            if (!isset($downloaded[$srcAttr])) {
                $downloaded[$srcAttr] = downloadImage($srcAttr, $targetDir);
            }
            if ($downloaded[$srcAttr]) {
                $source->setAttribute('src', $downloaded[$srcAttr]);
            }
        }
        $srcsetAttr = $source->getAttribute('srcset');
        if ($srcsetAttr) {
            $newSrcset = fixSrcsetLocal($srcsetAttr, $targetDir, $downloaded);
            $source->setAttribute('srcset', $newSrcset);
        }
    }

    // Process <video>/<audio> poster and src
    $mediaElems = $xpath->query('.//video | .//audio', $wrapper);
    foreach ($mediaElems as $media) {
        $poster = $media->getAttribute('poster');
        if ($poster && strpos($poster, 'data:') !== 0) {
            if (!isset($downloaded[$poster])) {
                $downloaded[$poster] = downloadImage($poster, $targetDir);
            }
            if ($downloaded[$poster]) {
                $media->setAttribute('poster', $downloaded[$poster]);
            }
        }
    }

    debugLog("Downloaded {$imgCount} images to local directory");

    // Extract the modified HTML from wrapper
    $modifiedHtml = '';
    foreach ($wrapper->childNodes as $child) {
        $modifiedHtml .= $doc->saveHTML($child);
    }

    return $modifiedHtml;
}

/**
 * Fix srcset attribute by downloading each image and replacing with local filenames.
 */
function fixSrcsetLocal($srcset, $targetDir, &$downloaded)
{
    $parts = explode(',', $srcset);
    $fixed = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        $pieces = preg_split('/\s+/', $part, 2);
        $url = $pieces[0];
        $descriptor = isset($pieces[1]) ? $pieces[1] : '';

        if (!empty($url) && strpos($url, 'data:') !== 0) {
            if (!isset($downloaded[$url])) {
                $downloaded[$url] = downloadImage($url, $targetDir);
            }
            if ($downloaded[$url]) {
                $url = $downloaded[$url];
            }
        }
        $fixed[] = $descriptor ? ($url . ' ' . $descriptor) : $url;
    }
    return implode(', ', $fixed);
}

function buildStandaloneHtml($title, $contentHtml, $sourceUrl, $stylesheets = [])
{
    $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $date = date('Y-m-d H:i:s');

    // Build stylesheet includes from original page
    $stylesheetHtml = '';
    if (!empty($stylesheets)) {
        foreach ($stylesheets as $ss) {
            if ($ss['type'] === 'link') {
                $media = htmlspecialchars($ss['media'], ENT_QUOTES, 'UTF-8');
                $href = htmlspecialchars($ss['href'], ENT_QUOTES, 'UTF-8');
                $stylesheetHtml .= "  <link rel=\"stylesheet\" href=\"{$href}\" media=\"{$media}\">\n";
            } elseif ($ss['type'] === 'inline') {
                $stylesheetHtml .= "  <style>{$ss['css']}</style>\n";
            }
        }
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$escapedTitle}</title>
  <!-- Exported by WikiExporter on {$date} -->
  <!-- Source: {$sourceUrl} -->

  <!-- Original page stylesheets -->
{$stylesheetHtml}
  <!-- WikiExporter overrides -->
  <style>
    body {
      background: #f8fafc;
      padding: 20px;
    }
    .wiki-export-wrapper {
      max-width: 960px;
      margin: 0 auto;
      background: #fff;
      padding: 40px 48px;
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    }
    .wiki-export-wrapper img {
      max-width: 100%;
      height: auto;
    }
    .source-info {
      margin-top: 40px;
      padding-top: 20px;
      border-top: 1px solid #e2e8f0;
      font-size: 0.8rem;
      color: #94a3b8;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    .source-info a { color: #6366f1; text-decoration: none; }
    .source-info a:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="wiki-export-wrapper">
    {$contentHtml}
    <div class="source-info">
      Exported from <a href="{$sourceUrl}" target="_blank">{$sourceUrl}</a><br>
      Generated by WikiExporter on {$date}
    </div>
  </div>
</body>
</html>
HTML;
}

/**
 * Export a single URL directly (no crawling).
 * Returns ['status' => 'success'|'failed', 'reason' => '...', 'title' => '...', 'filename' => '...', 'size' => '...']
 */
function exportSingleUrl($url, $exportsDir)
{
    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['status' => 'failed', 'reason' => 'Invalid URL format', 'title' => '', 'filename' => '', 'size' => ''];
    }

    // Fetch page
    $result = fetchUrl($url);
    if ($result['html'] === null) {
        return ['status' => 'failed', 'reason' => $result['error'] ?: 'Could not fetch URL', 'title' => '', 'filename' => '', 'size' => ''];
    }

    $html = $result['html'];

    // Extract content
    $extracted = extractPageContent($html, $url);
    $pageTitle = !empty($extracted['title']) ? $extracted['title'] : basename(parse_url($url, PHP_URL_PATH));
    $contentHtml = $extracted['content'];

    if (empty($pageTitle) || $pageTitle === '/') {
        $pageTitle = 'Untitled_Page';
    }

    // Create subdirectory named after the last URL segment
    $folderName = extractFolderName($url);
    $pageDir = $exportsDir . '/' . $folderName;

    // Avoid folder name collisions
    $baseFolderName = $folderName;
    $counter = 1;
    while (is_dir($pageDir)) {
        $folderName = $baseFolderName . '_' . $counter;
        $pageDir = $exportsDir . '/' . $folderName;
        $counter++;
    }

    if (!@mkdir($pageDir, 0777, true)) {
        $err = error_get_last();
        $errMsg = $err ? $err['message'] : 'Unknown error';
        debugLog("Failed to create directory: {$pageDir} — {$errMsg}", 'ERROR');
        return ['status' => 'failed', 'reason' => "Cannot create directory: {$errMsg}", 'title' => $pageTitle, 'filename' => '', 'size' => ''];
    }

    // Download all images to local directory and update HTML
    debugLog("Downloading images for: {$pageTitle}");
    $contentHtml = downloadAllImages($contentHtml, $pageDir, $url);

    // Build standalone HTML
    $stylesheets = isset($extracted['stylesheets']) ? $extracted['stylesheets'] : [];
    $standaloneHtml = buildStandaloneHtml($pageTitle, $contentHtml, $url, $stylesheets);

    // Save HTML file inside the subdirectory
    $htmlFilename = sanitizeFilename($pageTitle) . '.html';
    $filePath = $pageDir . '/' . $htmlFilename;
    debugLog("Writing file: {$filePath} (" . strlen($standaloneHtml) . " bytes)");

    $written = @file_put_contents($filePath, $standaloneHtml);

    if ($written === false) {
        $err = error_get_last();
        $errMsg = $err ? $err['message'] : 'Unknown write error';
        debugLog("WRITE FAILED: {$filePath} — {$errMsg}", 'ERROR');
        return ['status' => 'failed', 'reason' => "Write failed: {$errMsg}", 'title' => $pageTitle, 'filename' => '', 'size' => ''];
    }

    if (!file_exists($filePath)) {
        debugLog("VERIFY FAILED: file does not exist after write: {$filePath}", 'ERROR');
        return ['status' => 'failed', 'reason' => 'File not found after write', 'title' => $pageTitle, 'filename' => '', 'size' => ''];
    }

    $fileSize = filesize($filePath);
    $fileSizeStr = $fileSize > 1024 ? round($fileSize / 1024, 1) . ' KB' : $fileSize . ' B';
    debugLog("File saved OK: {$folderName}/{$htmlFilename} — {$fileSizeStr}");

    // Return the relative path as folder/file so frontend can link to it
    return [
        'status' => 'success',
        'reason' => '',
        'title' => $pageTitle,
        'filename' => $folderName . '/' . $htmlFilename,
        'size' => $fileSizeStr,
    ];
}


// ===== MAIN =====

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    sendError('Invalid request. Please provide valid JSON input.');
}

// Global authentication credentials
$authUser = isset($input['auth_user']) ? trim($input['auth_user']) : '';
$authPass = isset($input['auth_pass']) ? $input['auth_pass'] : '';
$authCookieJar = '';

if (!empty($authUser)) {
    // Create a temp cookie file for session persistence
    $authCookieJar = sys_get_temp_dir() . '/wikiexporter_cookies_' . md5($authUser) . '.txt';
    debugLog("Authentication enabled for user: {$authUser}");
}

// Ensure exports directory exists and is writable
$exportsDir = __DIR__ . '/exports';
if (!is_dir($exportsDir)) {
    $mkResult = @mkdir($exportsDir, 0777, true);
    debugLog("mkdir result: " . ($mkResult ? 'success' : 'FAILED'));
}
if (!is_writable($exportsDir)) {
    @chmod($exportsDir, 0777);
    if (!is_writable($exportsDir)) {
        sendError("The exports directory is not writable. Please run: chmod 777 " . $exportsDir);
    }
}

// Clear old debug log
@file_put_contents($debugLogFile, "=== WikiExporter Debug Log ===\n" . date('Y-m-d H:i:s') . "\n\n");

// -- Send environment diagnostics --
$diagInfo = [
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
    'script_path' => __FILE__,
    'exports_dir' => $exportsDir,
    'exports_exists' => is_dir($exportsDir) ? 'yes' : 'NO',
    'exports_writable' => is_writable($exportsDir) ? 'yes' : 'NO',
    'exports_perms' => is_dir($exportsDir) ? substr(sprintf('%o', fileperms($exportsDir)), -4) : 'N/A',
    'process_user' => (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : (get_current_user()),
    'curl_available' => function_exists('curl_init') ? 'yes' : 'NO',
    'allow_url_fopen' => ini_get('allow_url_fopen') ? 'yes' : 'NO',
    'open_basedir' => ini_get('open_basedir') ?: '(none)',
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
];

sendMsg(['type' => 'diagnostics', 'data' => $diagInfo]);
debugLog("Diagnostics: " . json_encode($diagInfo));


// ===== DETERMINE MODE =====

$mode = $input['mode'] ?? 'single';

if ($mode === 'batch') {
    // ======================== BATCH MODE ========================

    $urls = $input['urls'] ?? [];

    if (!is_array($urls) || count($urls) === 0) {
        sendError('No URLs provided. Please upload a CSV with at least one URL.');
    }

    // Deduplicate but preserve order
    $urls = array_values(array_unique($urls));
    $totalUrls = count($urls);

    sendLog("Batch export started: {$totalUrls} URLs", 'info');
    sendProgress(0, $totalUrls, 'Starting batch export…');

    $allResults = [];

    foreach ($urls as $idx => $url) {
        $pageNum = $idx + 1;
        $url = trim($url);

        sendProgress($pageNum, $totalUrls, "Exporting ({$pageNum}/{$totalUrls}): " . (strlen($url) > 60 ? substr($url, 0, 60) . '…' : $url));
        sendLog("Processing: {$url}", 'info');

        if (empty($url)) {
            $result = ['status' => 'failed', 'reason' => 'Empty URL', 'title' => '', 'filename' => '', 'size' => ''];
        } else {
            $result = exportSingleUrl($url, $exportsDir);
        }

        $result['url'] = $url;

        // Send per-URL status
        sendMsg([
            'type' => 'url_status',
            'url' => $url,
            'status' => $result['status'],
            'reason' => $result['reason'],
            'title' => $result['title'],
            'filename' => $result['filename'],
            'size' => $result['size'],
        ]);

        if ($result['status'] === 'success') {
            sendLog("✅ Saved: {$result['filename']} ({$result['size']})", 'success');
        } else {
            sendLog("❌ Failed: {$url} — {$result['reason']}", 'error');
        }

        $allResults[] = $result;

        // Small delay between requests to avoid hammering servers
        if ($idx < count($urls) - 1) {
            usleep(300000); // 300ms
        }
    }

    $successCount = count(array_filter($allResults, fn($r) => $r['status'] === 'success'));
    $failedCount = count(array_filter($allResults, fn($r) => $r['status'] === 'failed'));

    sendMsg([
        'type' => 'batch_complete',
        'total' => $totalUrls,
        'success' => $successCount,
        'failed' => $failedCount,
        'results' => $allResults,
    ]);

    sendLog("Batch export complete! {$successCount} succeeded, {$failedCount} failed out of {$totalUrls} URLs.", 'success');

} else {
    // ======================== SINGLE URL MODE (Legacy) ========================

    if (empty($input['url'])) {
        sendError('Please provide a valid wiki URL.');
    }

    $rootUrl = rtrim($input['url'], '/');
    $maxPages = min(max(intval($input['count'] ?? 5), 1), 100);

    if (!filter_var($rootUrl, FILTER_VALIDATE_URL)) {
        sendError('Invalid URL format. Please provide a valid HTTP/HTTPS URL.');
    }

    sendLog("Starting export from: {$rootUrl}", 'info');
    sendLog("Target: {$maxPages} pages", 'info');

    // Step 1: Fetch the root page
    sendProgress(0, $maxPages, 'Fetching root page…');
    $rootHtml = fetchUrlLegacy($rootUrl);

    if (!$rootHtml) {
        sendError("Could not fetch the root URL. Please check the URL and try again.");
    }

    sendLog("Root page fetched successfully", 'success');

    $baseUrl = getBaseUrl($rootUrl);
    $wikiPrefix = detectWikiPathPrefix($rootUrl);
    sendLog("Detected wiki prefix: {$wikiPrefix}", 'info');

    // Step 2: Extract links from root page
    $discoveredLinks = extractWikiLinks($rootHtml, $baseUrl, $wikiPrefix);
    sendLog("Discovered " . count($discoveredLinks) . " internal links", 'info');

    if (count($discoveredLinks) === 0) {
        $discoveredLinks[$rootUrl] = 'Main Page';
        sendLog("No sub-links found. Will export the root page itself.", 'warning');
    }

    // Step 3: Include the root page as the first page to export
    $pagesToExport = [];
    $rootContent = extractPageContent($rootHtml, $rootUrl);
    $pagesToExport[] = [
        'url' => $rootUrl,
        'title' => $rootContent['title'] ?: 'Main Page',
        'html' => $rootHtml,
    ];

    // Select pages to export (up to maxPages including root)
    $remaining = $maxPages - 1;
    $linkUrls = array_keys($discoveredLinks);

    $visited = [$rootUrl => true];
    $queue = $linkUrls;
    $queueIdx = 0;

    while ($remaining > 0 && $queueIdx < count($queue)) {
        $pageUrl = $queue[$queueIdx];
        $queueIdx++;

        if (isset($visited[$pageUrl]))
            continue;
        $visited[$pageUrl] = true;

        $pageTitle = $discoveredLinks[$pageUrl] ?? basename(parse_url($pageUrl, PHP_URL_PATH));

        $pagesToExport[] = [
            'url' => $pageUrl,
            'title' => $pageTitle,
        ];
        $remaining--;
    }

    $totalPages = count($pagesToExport);
    sendLog("Will export {$totalPages} pages", 'info');
    sendProgress(0, $totalPages, 'Starting page export…');

    // Step 4: Export each page
    $exportedFiles = [];

    foreach ($pagesToExport as $idx => $page) {
        $pageNum = $idx + 1;
        $title = $page['title'];

        sendProgress($pageNum, $totalPages, "Exporting: {$title}");
        sendLog("Fetching: {$page['url']}", 'info');

        if ($idx === 0) {
            $html = $page['html'];
        } else {
            $html = fetchUrlLegacy($page['url']);
            if (!$html) {
                sendLog("Failed to fetch: {$title}", 'error');
                continue;
            }
        }

        $extracted = extractPageContent($html, $page['url']);
        $pageTitle = !empty($extracted['title']) ? $extracted['title'] : $title;
        $contentHtml = $extracted['content'];

        // Create subdirectory for this page
        $folderName = extractFolderName($page['url']);
        $pageDir = $exportsDir . '/' . $folderName;

        // Avoid folder collisions
        $baseFolderName = $folderName;
        $fc = 1;
        while (is_dir($pageDir)) {
            $folderName = $baseFolderName . '_' . $fc;
            $pageDir = $exportsDir . '/' . $folderName;
            $fc++;
        }

        if (!@mkdir($pageDir, 0777, true)) {
            sendLog("Failed to create directory for: {$title}", 'error');
            continue;
        }

        // Download images locally
        sendLog("Downloading images for: {$pageTitle}", 'info');
        $contentHtml = downloadAllImages($contentHtml, $pageDir, $page['url']);

        $stylesheets = isset($extracted['stylesheets']) ? $extracted['stylesheets'] : [];
        $standaloneHtml = buildStandaloneHtml($pageTitle, $contentHtml, $page['url'], $stylesheets);

        $htmlFilename = sanitizeFilename($pageTitle) . '.html';
        $filePath = $pageDir . '/' . $htmlFilename;
        debugLog("Writing file: {$filePath} (" . strlen($standaloneHtml) . " bytes)");

        $written = @file_put_contents($filePath, $standaloneHtml);

        if ($written === false) {
            $err = error_get_last();
            $errMsg = $err ? $err['message'] : 'Unknown write error';
            debugLog("WRITE FAILED: {$filePath} — {$errMsg}", 'ERROR');
            sendLog("Failed to save: {$htmlFilename} — {$errMsg}", 'error');
            sendDebug("Write error detail: {$errMsg} | Path: {$filePath}");
            continue;
        }

        if (!file_exists($filePath)) {
            debugLog("VERIFY FAILED: file does not exist after write: {$filePath}", 'ERROR');
            sendLog("File written but not found on disk: {$htmlFilename}", 'error');
            sendDebug("File verification failed — file not found after write");
            continue;
        }

        $fileSize = filesize($filePath);
        $fileSizeStr = $fileSize > 1024 ? round($fileSize / 1024, 1) . ' KB' : $fileSize . ' B';
        $relPath = $folderName . '/' . $htmlFilename;
        debugLog("File saved OK: {$relPath} — {$fileSizeStr}");

        sendLog("Saved: {$relPath} ({$fileSizeStr})", 'success');

        $exportedFiles[] = [
            'title' => $pageTitle,
            'filename' => $relPath,
            'size' => $fileSizeStr,
            'url' => $page['url'],
        ];

        sendMsg([
            'type' => 'file',
            'title' => $pageTitle,
            'filename' => $relPath,
            'size' => $fileSizeStr,
            'url' => $page['url'],
        ]);

        if ($idx < count($pagesToExport) - 1) {
            usleep(300000);
        }
    }

    // Step 5: Complete
    sendMsg([
        'type' => 'complete',
        'files' => $exportedFiles,
        'total' => count($exportedFiles),
    ]);

    sendLog("Export complete! {$totalPages} pages exported.", 'success');
}
