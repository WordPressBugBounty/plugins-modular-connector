<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Helpers;

use Modular\ConnectorDependencies\Illuminate\Support\Str;

class LinkExtractor
{
    private const TYPE_LINK = 'link';
    private const TYPE_IMAGE = 'image';
    private const TYPE_SCRIPT = 'script';
    private const TYPE_STYLESHEET = 'stylesheet';

    private const SKIPPED_SCHEMES = ['mailto:', 'tel:', 'javascript:', 'data:'];

    private string $homeHost;
    private string $homeScheme;

    private bool $checkInternal = true;
    private bool $checkExternal = true;
    private bool $checkMixedContent = true;

    /**
     * @param string|null $homeUrl Base URL for internal-vs-external classification.
     *                             Defaults to WordPress home_url('/'); accepts an
     *                             explicit value for testing or non-WP callers.
     * @param array{internal?:bool,external?:bool,mixed_content?:bool} $check
     *        Per-scan flags from `check.*` in the request payload. `internal` /
     *        `external` filter the output by link internality. When
     *        `mixed_content` is true, an `is_mixed_content` boolean is added
     *        to each emitted link (true iff the source page is https and the
     *        link is http). All default to true (no-op vs. legacy behavior).
     */
    public function __construct(?string $homeUrl = null, array $check = [])
    {
        $home = $homeUrl ?? \home_url('/');
        $this->homeHost = $this->normalizeHost((string)parse_url($home, PHP_URL_HOST));
        $this->homeScheme = (string)parse_url($home, PHP_URL_SCHEME) ?: 'https';

        $this->checkInternal = (bool)($check['internal'] ?? true);
        $this->checkExternal = (bool)($check['external'] ?? true);
        $this->checkMixedContent = (bool)($check['mixed_content'] ?? true);
    }

    /**
     * @param string[] $resourceTypes subset of BrokenLinkPart::SUPPORTED_RESOURCE_TYPES
     *                                 (currently {link, image, script, stylesheet}).
     *                                 Already filtered upstream — unknown values
     *                                 are silently dropped before reaching here.
     * @param string[] $excludedUrlPatterns delimited PCRE patterns
     * @return array<int, array{url:string,text:string,rel:string,is_internal:bool,type:string,is_mixed_content?:bool}>
     */
    public function extract(string $html, string $permalink, array $resourceTypes, array $excludedUrlPatterns): array
    {
        if (empty($resourceTypes) || Str::of($html)->trim()->isEmpty()) {
            return [];
        }

        // Both internal/external checks disabled → nothing to emit; skip DOM pass.
        if (!$this->checkInternal && !$this->checkExternal) {
            return [];
        }

        $html = $this->stripDataUris($html);

        $doc = $this->loadHtml($html);
        $base = $this->resolveBase($doc, $permalink);
        $wanted = array_fill_keys($resourceTypes, true);
        $seen = [];
        $out = [];

        if (isset($wanted[self::TYPE_LINK])) {
            foreach ($doc->getElementsByTagName('a') as $el) {
                /** @var \DOMElement $el */
                $this->collect((string)$el->getAttribute('href'), self::TYPE_LINK, $this->extractText($el), trim((string)$el->getAttribute('rel')), $base, $excludedUrlPatterns, $seen, $out);
            }

            $this->extractPlainTextUrls($doc, $base, $excludedUrlPatterns, $seen, $out);
        }

        if (isset($wanted[self::TYPE_IMAGE])) {
            foreach ($doc->getElementsByTagName('img') as $el) {
                /** @var \DOMElement $el */
                $alt = trim((string)$el->getAttribute('alt'));
                $this->collect((string)$el->getAttribute('src'), self::TYPE_IMAGE, $alt, '', $base, $excludedUrlPatterns, $seen, $out);
                foreach ($this->parseSrcset((string)$el->getAttribute('srcset')) as $u) {
                    $this->collect($u, self::TYPE_IMAGE, $alt, '', $base, $excludedUrlPatterns, $seen, $out);
                }
            }

            foreach ($doc->getElementsByTagName('source') as $el) {
                /** @var \DOMElement $el */
                $this->collect((string)$el->getAttribute('src'), self::TYPE_IMAGE, '', '', $base, $excludedUrlPatterns, $seen, $out);
                foreach ($this->parseSrcset((string)$el->getAttribute('srcset')) as $u) {
                    $this->collect($u, self::TYPE_IMAGE, '', '', $base, $excludedUrlPatterns, $seen, $out);
                }
            }

            foreach ($this->elementsWithBackgroundStyle($doc) as $el) {
                foreach ($this->extractBackgroundUrls((string)$el->getAttribute('style')) as $u) {
                    $this->collect($u, self::TYPE_IMAGE, '', '', $base, $excludedUrlPatterns, $seen, $out);
                }
            }
        }

        if (isset($wanted[self::TYPE_SCRIPT])) {
            foreach ($doc->getElementsByTagName('script') as $el) {
                /** @var \DOMElement $el */
                $this->collect((string)$el->getAttribute('src'), self::TYPE_SCRIPT, '', '', $base, $excludedUrlPatterns, $seen, $out);
            }
        }

        if (isset($wanted[self::TYPE_STYLESHEET])) {
            foreach ($doc->getElementsByTagName('link') as $el) {
                /** @var \DOMElement $el */
                $relAttr = trim((string)$el->getAttribute('rel'));
                $rels = preg_split('/\s+/', Str::lower($relAttr)) ?: [];
                if (!in_array('stylesheet', $rels, true)) {
                    continue;
                }
                $this->collect((string)$el->getAttribute('href'), self::TYPE_STYLESHEET, '', $relAttr, $base, $excludedUrlPatterns, $seen, $out);
            }
        }

        return $out;
    }

    /**
     * Builder-rendered pages can carry many large inline data: URIs — base64
     * images (data:image/png;base64,...) and URL-encoded SVG (data:image/svg+xml,
     * %3Csvg...%3E). DOMDocument copies every attribute verbatim, so these
     * payloads multiply the document's memory footprint without contributing any
     * extractable URL — data: is in SKIPPED_SCHEMES anyway. Neutering the payload
     * (preserving the attribute structure) keeps the DOM tree light.
     *
     * Captures everything from `data:` up to the first HTML attribute boundary
     * (quote, whitespace or `>`), which covers both `;base64,...` and inline
     * `,<encoded-body>` variants without needing two separate patterns.
     */
    private function stripDataUris(string $html): string
    {
        return (string)preg_replace('/data:[^"\'\s>]+/', 'data:', $html);
    }

    /**
     * Capture bare URLs sitting in text nodes (typed as plaintext by the author
     * and not wrapped in <a>). DOMDocument's getElementsByTagName('a') misses
     * these and AIOSEO BLC's regex captures them — closing a real coverage gap.
     *
     * Excluded ancestors: <a> (already collected), <script>/<style> (not visible
     * content), <code>/<pre>/<textarea>/<noscript> (literal text, not links).
     *
     * @param array{0:string,1:string,2:string} $base
     * @param string[] $excludedUrlPatterns
     * @param array<string,bool> $seen
     * @param array<int, array{url:string,text:string,rel:string,is_internal:bool,type:string}> $out
     */
    private function extractPlainTextUrls(\DOMDocument $doc, array $base, array $excludedUrlPatterns, array &$seen, array &$out): void
    {
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query(
            '//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)'
            . ' and not(ancestor::code) and not(ancestor::pre) and not(ancestor::textarea)'
            . ' and not(ancestor::noscript)]'
        );
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            $text = (string)$node->nodeValue;
            if ($text === '' || stripos($text, '://') === false) {
                continue;
            }

            if (!preg_match_all('#(?:https?|ftp)://[^\s<>"\'()\[\]]+#i', $text, $matches)) {
                continue;
            }

            foreach ($matches[0] as $url) {
                // Trim trailing punctuation that's almost never part of a URL
                // and is usually sentence punctuation (e.g. "see https://x.com.").
                $url = rtrim($url, ".,;:!?'\"");
                if ($url === '') {
                    continue;
                }
                $this->collect($url, self::TYPE_LINK, $url, '', $base, $excludedUrlPatterns, $seen, $out);
            }
        }
    }

    private function loadHtml(string $html): \DOMDocument
    {
        // Force libxml's HTML parser to honour UTF-8 (it otherwise assumes
        // ISO-8859-1 and mojibakes multibyte content).
        $utf8 = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<body>' . $utf8 . '</body>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc;
    }

    /**
     * @return array{0:string,1:string,2:string} [scheme, host, path-with-trailing-slash]
     */
    private function resolveBase(\DOMDocument $doc, string $permalink): array
    {
        // <base href> overrides the permalink-derived base — themes/builders rely on this.
        foreach ($doc->getElementsByTagName('base') as $baseEl) {
            /** @var \DOMElement $baseEl */
            $href = trim((string)$baseEl->getAttribute('href'));
            if ($href !== '') {
                return $this->baseFromUrl($href);
            }
        }

        return $this->baseFromUrl($permalink);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function baseFromUrl(string $url): array
    {
        $scheme = (string)parse_url($url, PHP_URL_SCHEME) ?: $this->homeScheme;
        $host = $this->normalizeHost((string)parse_url($url, PHP_URL_HOST)) ?: $this->homeHost;
        $path = (string)parse_url($url, PHP_URL_PATH) ?: '/';
        if (!Str::endsWith($path, '/')) {
            $path = dirname($path) . '/';
        }
        return [$scheme, $host, $path];
    }

    /**
     * @param array{0:string,1:string,2:string} $base
     * @param string[] $excludedUrlPatterns
     * @param array<string,bool> $seen
     * @param array<int, array{url:string,text:string,rel:string,is_internal:bool,type:string,is_mixed_content?:bool}> $out
     */
    private function collect(string $raw, string $type, string $text, string $rel, array $base, array $excludedUrlPatterns, array &$seen, array &$out): void
    {
        $absolute = $this->absolutize($raw, $base);
        if ($absolute === null) {
            return;
        }

        // Strip fragment — irrelevant for HTTP status (/page#a and /page#b
        // hit the same resource) and makes the wire payload smaller. Aligns
        // with dedupKey() which already ignored fragments.
        $hashPos = strpos($absolute, '#');
        if ($hashPos !== false) {
            $absolute = substr($absolute, 0, $hashPos);
        }

        $key = $this->dedupKey($absolute);
        if (isset($seen[$key]) || $this->isExcluded($absolute, $excludedUrlPatterns)) {
            return;
        }

        $isInternal = $this->isInternal($absolute);

        // Internality filters from `check.internal` / `check.external`. Either
        // flag set to false skips the corresponding bucket of links.
        if ($isInternal && !$this->checkInternal) {
            return;
        }

        if (!$isInternal && !$this->checkExternal) {
            return;
        }

        $seen[$key] = true;

        $row = [
            'url' => $absolute,
            'text' => $text,
            'rel' => $rel,
            'is_internal' => $isInternal,
            'type' => $type,
        ];

        if ($this->checkMixedContent) {
            // Mixed content: an https page embedding an http resource. Computed
            // against the resolved base scheme so a `<base href>` override is
            // honoured. The field is only present when the API asked for it.
            $linkScheme = strtolower((string)parse_url($absolute, PHP_URL_SCHEME));
            $row['is_mixed_content'] = $base[0] === 'https' && $linkScheme === 'http';
        }

        $out[] = $row;
    }

    /** @return string[] */
    private function parseSrcset(string $srcset): array
    {
        $srcset = trim($srcset);
        if ($srcset === '') {
            return [];
        }

        // Split on commas that are NOT inside data: URIs. A simple state machine is
        // safer than explode(',') because data:image/svg+xml,... contains commas.
        $candidates = [];
        $depth = 0;
        $buf = '';
        $len = strlen($srcset);
        for ($i = 0; $i < $len; $i++) {
            $ch = $srcset[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($ch === ',' && $depth === 0 && !$this->insideDataUri($buf)) {
                $candidates[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '') {
            $candidates[] = $buf;
        }

        $urls = [];
        foreach ($candidates as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate), 2) ?: [];
            $url = trim($parts[0] ?? '');
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    private function insideDataUri(string $buf): bool
    {
        // Cheap detector: the active candidate started with `data:` and we have not yet
        // hit whitespace (which would terminate the URL part of the srcset candidate).
        $trimmed = ltrim($buf);
        if (!Str::startsWith(Str::lower($trimmed), 'data:')) {
            return false;
        }
        return !preg_match('/\s/', $trimmed);
    }

    /**
     * @return iterable<\DOMElement>
     */
    private function elementsWithBackgroundStyle(\DOMDocument $doc): iterable
    {
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query("//*[contains(@style, 'background')]");
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                yield $node;
            }
        }
    }

    /** @return string[] */
    private function extractBackgroundUrls(string $style): array
    {
        if ($style === '' || stripos($style, 'background') === false) {
            return [];
        }

        $urls = [];
        if (preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $style, $m)) {
            foreach ($m[2] as $u) {
                $u = trim((string)$u);
                if ($u !== '') {
                    $urls[] = $u;
                }
            }
        }
        return $urls;
    }

    /**
     * @param string[] $excludedUrlPatterns
     */
    private function isExcluded(string $absolute, array $excludedUrlPatterns): bool
    {
        foreach ($excludedUrlPatterns as $pattern) {
            if (preg_match($pattern, $absolute) === 1) {
                return true;
            }
        }

        return false;
    }

    private function extractText(\DOMElement $a): string
    {
        $inner = trim((string)$a->textContent);
        if ($inner !== '') {
            return $inner;
        }

        foreach (['aria-label', 'title'] as $attr) {
            $v = trim((string)$a->getAttribute($attr));
            if ($v !== '') {
                return $v;
            }
        }

        $imgs = $a->getElementsByTagName('img');
        if ($imgs->length > 0) {
            $alt = trim((string)$imgs->item(0)->getAttribute('alt'));
            if ($alt !== '') {
                return $alt;
            }
        }

        return '';
    }

    /**
     * @param array{0:string,1:string,2:string} $base
     */
    private function absolutize(string $href, array $base): ?string
    {
        $href = trim($href);
        if ($href === '' || $this->isSkipped($href)) {
            return null;
        }

        [$scheme, $host, $path] = $base;

        // Already absolute (any scheme — http(s), ftp, custom). Pass through verbatim.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $href)) {
            return $href;
        }

        // Protocol-relative.
        if (Str::startsWith($href, '//')) {
            return $scheme . ':' . $href;
        }

        // Decompose href into its path / query / fragment so the path resolution below
        // does not corrupt query strings or fragments.
        $hrefPath = (string)parse_url($href, PHP_URL_PATH);
        $hrefQuery = parse_url($href, PHP_URL_QUERY);
        $hrefFragment = parse_url($href, PHP_URL_FRAGMENT);
        $tail = ($hrefQuery !== null ? '?' . $hrefQuery : '') . ($hrefFragment !== null ? '#' . $hrefFragment : '');

        // Empty href path with query/fragment only: stay on current path.
        if ($hrefPath === '') {
            return $scheme . '://' . $host . $path . $tail;
        }

        // Root-relative.
        if ($hrefPath[0] === '/') {
            return $scheme . '://' . $host . $hrefPath . $tail;
        }

        // Document-relative — resolve against $path.
        $combined = $path . $hrefPath;
        $segments = [];
        foreach (explode('/', $combined) as $part) {
            if ($part === '..') {
                array_pop($segments);
            } elseif ($part !== '' && $part !== '.') {
                $segments[] = $part;
            }
        }

        return $scheme . '://' . $host . '/' . implode('/', $segments) . $tail;
    }

    private function isSkipped(string $href): bool
    {
        if ($href === '') {
            return true;
        }
        if ($href[0] === '#') {
            return true;
        }
        return Str::startsWith(Str::lower($href), self::SKIPPED_SCHEMES);
    }

    private function isInternal(string $absolute): bool
    {
        $host = $this->normalizeHost((string)parse_url($absolute, PHP_URL_HOST));
        return $host !== '' && $host === $this->homeHost;
    }

    private function dedupKey(string $absolute): string
    {
        // Lowercase scheme + host, strip fragment so /a, /a#section dedupe as one.
        $parts = parse_url($absolute);
        if ($parts === false) {
            return $absolute;
        }
        $scheme = isset($parts['scheme']) ? Str::lower($parts['scheme']) . '://' : '';
        $host = isset($parts['host']) ? $this->normalizeHost($parts['host']) : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $scheme . $host . $port . $path . $query;
    }

    private function normalizeHost(string $host): string
    {
        $host = Str::lower($host);
        return Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
    }
}
