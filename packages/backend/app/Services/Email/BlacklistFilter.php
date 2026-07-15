<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * Domain blacklist for the zero-touch sync engine (MASTER-PLAN §4.7/§7.3): background
 * ingestion drops internal mail so we never log colleague-to-colleague email as CRM
 * activity. The user-driven one-click path does NOT apply this — if a rep clicks
 * "Log to CRM", their intent overrides the blacklist.
 */
final class BlacklistFilter
{
    public function isBlacklisted(string $email): bool
    {
        $domain = self::domainOf($email);
        if ($domain === null) {
            return false;
        }

        foreach ($this->domains() as $entry) {
            $entry = ltrim(mb_strtolower(trim($entry)), '@');
            if ($entry === '') {
                continue;
            }
            // Support wildcard suffixes like "*.corp.example".
            if (str_starts_with($entry, '*.')) {
                $suffix = substr($entry, 1); // ".corp.example"
                if (str_ends_with($domain, $suffix)) {
                    return true;
                }
            } elseif ($domain === $entry) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function domains(): array
    {
        /** @var list<string> $configured */
        $configured = (array) config('mail_tracker.blacklist_domains', []);

        return $configured;
    }

    private static function domainOf(string $email): ?string
    {
        $at = strrpos($email, '@');

        return $at === false ? null : mb_strtolower(substr($email, $at + 1));
    }
}
