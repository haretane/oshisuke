<?php

declare(strict_types=1);

function publicUrlTarget(string $url): array
{
    if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        throw new RuntimeException('URL_INVALID');
    }
    $parts = parse_url($url);
    if (!is_array($parts)) throw new RuntimeException('URL_INVALID');
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('URL_INVALID');
    }
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || preg_match('/[^\x21-\x7e]/', $host) === 1) {
        throw new RuntimeException('URL_BLOCKED');
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
        throw new RuntimeException('PORT_BLOCKED');
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips[] = $host;
    } else {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) throw new RuntimeException('DNS_FAILED');
        foreach ($records as $record) {
            if (isset($record['ip'])) $ips[] = $record['ip'];
            if (isset($record['ipv6'])) $ips[] = $record['ipv6'];
        }
    }
    $ips = array_values(array_unique($ips));
    if ($ips === []) throw new RuntimeException('DNS_FAILED');
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('IP_BLOCKED');
        }
    }
    return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
}

function resolveRedirectUrl(string $baseUrl, string $location): string
{
    $location = trim($location);
    if ($location === '') throw new RuntimeException('REDIRECT_INVALID');
    if (preg_match('#^https?://#i', $location) === 1) return $location;
    $base = parse_url($baseUrl);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) throw new RuntimeException('REDIRECT_INVALID');
    if (str_starts_with($location, '//')) return $base['scheme'] . ':' . $location;
    $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
    $path = str_starts_with($location, '/') ? $location : preg_replace('#/[^/]*$#', '/', (string) ($base['path'] ?? '/')) . $location;
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') array_pop($segments); else $segments[] = $segment;
    }
    return $origin . '/' . implode('/', $segments);
}

function safeHttpGet(string $initialUrl, int $maxBytes, array $allowedContentPrefixes, int $maxRedirects = 3): array
{
    $url = $initialUrl;
    for ($requestNumber = 0; $requestNumber <= $maxRedirects; $requestNumber++) {
        $target = publicUrlTarget($url);
        $body = '';
        $overflow = false;
        $location = null;
        $contentType = '';
        $curl = curl_init();
        $address = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:$address"],
            CURLOPT_USERAGENT => 'OSHISUKE-LinkPreview/1.0',
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location, &$contentType): int {
                if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
                if (stripos($header, 'Content-Type:') === 0) $contentType = strtolower(trim(explode(';', substr($header, 13), 2)[0]));
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $overflow = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        if ($overflow) throw new RuntimeException('RESPONSE_TOO_LARGE');
        if ($success === false) throw new RuntimeException('FETCH_FAILED:' . $curlError);
        if ($status >= 300 && $status < 400 && $location !== null) {
            if ($requestNumber === $maxRedirects) throw new RuntimeException('TOO_MANY_REDIRECTS');
            $url = resolveRedirectUrl($url, $location);
            continue;
        }
        if ($status < 200 || $status >= 300) throw new RuntimeException('HTTP_STATUS');
        if (!array_any($allowedContentPrefixes, static fn (string $prefix): bool => str_starts_with($contentType, $prefix))) {
            throw new RuntimeException('CONTENT_TYPE_BLOCKED');
        }
        return ['body' => $body, 'content_type' => $contentType, 'final_url' => $url];
    }
    throw new RuntimeException('TOO_MANY_REDIRECTS');
}

function htmlAttribute(string $tag, string $name): ?string
{
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $matches) !== 1) return null;
    return html_entity_decode(trim($matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function unicodeLimit(string $value, int $limit): string
{
    if (preg_match('/\A(.{0,' . $limit . '})/us', $value, $matches) === 1) return $matches[1];
    return '';
}

function extractPagePreview(string $html, string $finalUrl): array
{
    $ogTitle = null;
    $ogImage = null;
    if (preg_match_all('/<meta\b[^>]*>/is', $html, $tags) !== false) {
        foreach ($tags[0] as $tag) {
            $property = strtolower((string) (htmlAttribute($tag, 'property') ?? htmlAttribute($tag, 'name')));
            $content = htmlAttribute($tag, 'content');
            if ($property === 'og:title' && $content !== null) $ogTitle = $content;
            if ($property === 'og:image' && $content !== null) $ogImage = $content;
        }
    }
    $htmlTitle = null;
    if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $matches) === 1) {
        $htmlTitle = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $title = preg_replace('/\s+/u', ' ', trim((string) ($htmlTitle ?: $ogTitle))) ?? '';
    return [
        'title' => $title === '' ? null : unicodeLimit($title, 300),
        'image_url' => $ogImage === null ? null : resolveRedirectUrl($finalUrl, $ogImage),
    ];
}

function fetchSafeUrlPreview(string $url): array
{
    $page = safeHttpGet($url, 1048576, ['text/html', 'application/xhtml+xml']);
    $metadata = extractPagePreview($page['body'], $page['final_url']);
    $imageMime = null;
    $imageData = null;
    if ($metadata['image_url'] !== null) {
        try {
            $image = safeHttpGet($metadata['image_url'], 2097152, ['image/'], 2);
            $size = getimagesizefromstring($image['body']);
            $detectedMime = is_array($size) ? ($size['mime'] ?? null) : null;
            if (in_array($detectedMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                $imageMime = $detectedMime;
                $imageData = $image['body'];
            }
        } catch (Throwable) {
            // A thumbnail failure must not discard the safely fetched page title.
        }
    }
    return ['resolved_url' => $page['final_url'], 'page_title' => $metadata['title'], 'image_mime' => $imageMime, 'image_data' => $imageData];
}
