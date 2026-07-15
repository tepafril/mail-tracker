<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * Conservative HTML body sanitization (MASTER-PLAN §4/§7.4: strip scripts and tracking
 * pixels before the body leaves the client's mailbox for SMOH).
 *
 * NOTE: this is a defensive regex pass, adequate for MVP. For production hardening,
 * swap in a real allow-list sanitizer (e.g. ezyang/htmlpurifier) — regex HTML parsing
 * is not a complete XSS defense on its own.
 */
final class BodySanitizer
{
    public static function sanitize(string $body, string $type): string
    {
        if ($type !== 'html') {
            return trim($body);
        }

        $b = $body;

        // Remove whole dangerous element blocks.
        $b = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $b) ?? $b;
        // Remove dangerous void/self-closing tags.
        $b = preg_replace('#<(script|iframe|object|embed|link|meta)\b[^>]*/?>#is', '', $b) ?? $b;
        // Strip inline event handlers (onload, onclick, ...).
        $b = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $b) ?? $b;
        // Neutralize javascript: URIs in href/src.
        $b = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1=$2#$2', $b) ?? $b;
        // Drop 1x1 tracking pixels (either attribute order).
        $b = preg_replace('#<img\b[^>]*\bwidth\s*=\s*["\']?1["\']?[^>]*\bheight\s*=\s*["\']?1["\']?[^>]*>#i', '', $b) ?? $b;
        $b = preg_replace('#<img\b[^>]*\bheight\s*=\s*["\']?1["\']?[^>]*\bwidth\s*=\s*["\']?1["\']?[^>]*>#i', '', $b) ?? $b;

        return trim($b);
    }
}
