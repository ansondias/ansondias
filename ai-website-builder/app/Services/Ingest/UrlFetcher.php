<?php

namespace App\Services\Ingest;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a public web page into plain text, keeping the bits that matter for a business:
 * title, meta/OpenGraph tags, schema.org JSON-LD, tel:/mailto: links and the visible text.
 *
 * Social networks usually answer bots with a login wall. We detect that and report the
 * source as "blocked" instead of pretending we read it, so the owner can paste the details.
 */
class UrlFetcher
{
    /** Hosts that are known to hide content behind a login for automated clients. */
    public const LOGIN_WALLED = ['instagram.com', 'facebook.com', 'fb.com', 'linkedin.com', 'x.com', 'twitter.com', 'tiktok.com', 'threads.net'];

    public const MAX_TEXT = 40000;

    public function fetch(string $url): FetchResult
    {
        if (! self::isSafePublicUrl($url)) {
            return new FetchResult('failed', error: 'Only public http(s) links can be read.');
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; AIWebsiteBuilder/1.0; +https://github.com/)',
                'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en;q=0.9',
            ])->timeout(20)->withOptions([
                'allow_redirects' => [
                    'max' => 5,
                    'track_redirects' => true,
                    'on_redirect' => function ($request, $response, $uri) {
                        if (! self::isSafePublicUrl((string) $uri)) {
                            throw new \RuntimeException('Redirected to a non-public address.');
                        }
                    },
                ],
            ])->get($url);
        } catch (ConnectionException $e) {
            return new FetchResult('failed', error: 'We could not connect to '.self::host($url).'.');
        } catch (Throwable $e) {
            return new FetchResult('failed', error: Str::limit($e->getMessage(), 150));
        }

        $history = $response->header('X-Guzzle-Redirect-History');
        $finalUrl = $history ? trim(last(explode(',', $history))) : $url;
        $status = $response->status();

        if (in_array($status, [401, 403, 429, 999], true)) {
            return new FetchResult('blocked', error: "The site refused automated access (HTTP $status).", meta: ['final_url' => $finalUrl]);
        }
        if (! $response->successful()) {
            return new FetchResult('failed', error: "The page returned HTTP $status.", meta: ['final_url' => $finalUrl]);
        }

        $contentType = (string) $response->header('Content-Type');
        $body = $response->body();
        if (str_contains($contentType, 'json')) {
            return new FetchResult('read', Str::limit($body, self::MAX_TEXT, ''), meta: ['final_url' => $finalUrl]);
        }
        if (! str_contains($contentType, 'html') && $contentType !== '') {
            return new FetchResult('failed', error: "Unsupported content type ($contentType).");
        }

        $parsed = $this->parseHtml($body);
        $text = $parsed['text'];
        $meta = ['final_url' => $finalUrl, 'title' => $parsed['title']];

        if ($this->looksLikeLoginWall($finalUrl, $url, $text)) {
            // Keep whatever the public preview tags said (often the bio), but flag it.
            return new FetchResult('blocked', $parsed['preview'], 'This site shows a login wall to automated readers, so only its public preview could be read.', $meta);
        }

        if (mb_strlen($text) < 80) {
            return new FetchResult('blocked', $text, 'The page had almost no readable text (it probably needs JavaScript).', $meta);
        }

        return new FetchResult('read', $text, meta: $meta);
    }

    /** @return array{title: string, text: string, preview: string} */
    public function parseHtml(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $title = trim($xpath->evaluate('string(//title)'));
        $lines = [];
        if ($title !== '') {
            $lines[] = "Page title: $title";
        }

        $preview = [];
        foreach ($xpath->query('//meta[@name or @property or @itemprop]') as $meta) {
            $name = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property') ?: $meta->getAttribute('itemprop'));
            $content = trim($meta->getAttribute('content'));
            if ($content === '') {
                continue;
            }
            if (in_array($name, ['description', 'og:title', 'og:description', 'twitter:title', 'twitter:description', 'name', 'og:site_name', 'business:contact_data:street_address', 'business:contact_data:locality', 'business:contact_data:phone_number', 'business:contact_data:email'], true)) {
                $preview[] = "$name: $content";
            }
        }
        $lines = array_merge($lines, $preview);

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $json = json_decode(trim($script->textContent), true);
            if ($json) {
                $lines[] = 'Structured data: '.json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        foreach ($xpath->query('//a[starts-with(@href, "tel:") or starts-with(@href, "mailto:") or contains(@href, "wa.me/")]') as $a) {
            $lines[] = 'Link: '.trim($a->getAttribute('href'));
        }

        foreach ($xpath->query('//script|//style|//noscript|//svg|//template|//iframe') as $node) {
            $node->parentNode?->removeChild($node);
        }
        // Content hidden with inline CSS is a common place for injected instructions; skip it.
        foreach ($xpath->query('//*[contains(translate(@style, "DISPLAYNOEVTBH: ", "displaynoevtbh"), "display:none") or contains(translate(@style, "VISIBLTYHDN: ", "visiblthdn"), "visibility:hidden")]') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $xpath->query('//body')->item(0);
        if ($body) {
            foreach ($xpath->query('.//br|.//p|.//div|.//li|.//h1|.//h2|.//h3|.//h4|.//tr|.//section', $body) as $block) {
                $block->appendChild($dom->createTextNode("\n"));
            }
            $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $body->textContent);
            $text = preg_replace("/\n\s*\n+/", "\n", $text);
            $lines[] = trim($text);
        }

        return [
            'title' => $title,
            'text' => Str::limit(trim(implode("\n", $lines)), self::MAX_TEXT, ''),
            'preview' => implode("\n", $preview),
        ];
    }

    private function looksLikeLoginWall(string $finalUrl, string $originalUrl, string $text): bool
    {
        if (preg_match('#/(login|signin|sign-in|accounts/login|checkpoint|authwall)#i', $finalUrl)) {
            return true;
        }
        $host = self::host($originalUrl);
        foreach (self::LOGIN_WALLED as $walled) {
            if ($host === $walled || str_ends_with($host, '.'.$walled)) {
                // These sites serve a bot a shell page; treat as blocked unless there is real text.
                return mb_strlen($text) < 1500 || preg_match('/\b(log in|sign up|create new account)\b/i', Str::limit($text, 3000)) === 1;
            }
        }

        return false;
    }

    public static function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^(www\.|m\.)/', '', $host);
    }

    /** Blocks file://, localhost and private network addresses (SSRF). */
    public static function isSafePublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        $host = trim($parts['host'], '[]');
        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true) || str_ends_with(strtolower($host), '.local') || str_ends_with(strtolower($host), '.internal')) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === [] && ! filter_var($host, FILTER_VALIDATE_IP)) {
            // Could not resolve here (e.g. sandboxed DNS); allow and let the HTTP call decide.
            return true;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /** Decide what kind of source a link is. */
    public static function classify(string $url): string
    {
        $host = self::host($url);
        if (preg_match('#(^|\.)google\.[a-z.]+$#', $host) && str_contains($url, '/maps')) {
            return 'google_business';
        }
        if (in_array($host, ['maps.app.goo.gl', 'g.page', 'g.co', 'business.google.com', 'maps.google.com'], true) || ($host === 'goo.gl' && str_contains($url, '/maps'))) {
            return 'google_business';
        }
        foreach (array_merge(self::LOGIN_WALLED, ['youtube.com', 'pinterest.com', 'wa.me', 'whatsapp.com', 'zomato.com', 'swiggy.com', 'justdial.com', 'yelp.com', 'tripadvisor.com', 'tripadvisor.in', 'indiamart.com']) as $social) {
            if ($host === $social || str_ends_with($host, '.'.$social)) {
                return 'social';
            }
        }

        return 'website';
    }
}
