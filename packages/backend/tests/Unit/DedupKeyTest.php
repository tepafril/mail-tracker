<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DedupKey;
use PHPUnit\Framework\TestCase;

/**
 * Mirror of packages/core/test/dedup.test.js. The GOLDEN vector below must match the
 * one asserted in the TypeScript suite — that is the cross-language parity guarantee.
 */
class DedupKeyTest extends TestCase
{
    public function test_normalize_message_id_strips_brackets_and_lowercases(): void
    {
        $this->assertSame('abc@example.com', DedupKey::normalizeMessageId('<ABC@Example.COM>'));
        $this->assertSame('abc@x', DedupKey::normalizeMessageId('  <abc@x>  '));
        $this->assertSame('abc@x', DedupKey::normalizeMessageId('abc@x'));
    }

    public function test_normalize_message_id_returns_null_for_empty(): void
    {
        $this->assertNull(DedupKey::normalizeMessageId(''));
        $this->assertNull(DedupKey::normalizeMessageId('   '));
        $this->assertNull(DedupKey::normalizeMessageId('<>'));
        $this->assertNull(DedupKey::normalizeMessageId(null));
    }

    public function test_synthetic_preimage_is_order_independent_and_deduplicated(): void
    {
        $a = DedupKey::syntheticPreimage('t1', 'u1', ['b@x.com', 'a@x.com'], 'Hello', 1_700_000_000_000);
        $b = DedupKey::syntheticPreimage('t1', 'u1', ['A@X.com', 'b@x.com', 'a@x.com'], 'Hello', 1_700_000_000_000);
        $this->assertSame($a, $b);
        $this->assertStringContainsString('a@x.com,b@x.com', $a);
    }

    public function test_synthetic_preimage_buckets_time_and_collapses_subject(): void
    {
        $pre = DedupKey::syntheticPreimage('t1', 'u1', ['a@x.com'], "  Re:   quarterly    report ", 90_000);
        $lines = explode("\n", $pre);
        $this->assertSame(['t1', 'u1', 'a@x.com', 'Re: quarterly report', '1'], $lines);
    }

    public function test_negative_epoch_uses_floor_division_like_js(): void
    {
        // Math.floor rounds toward -inf; intdiv would truncate toward zero and diverge.
        $this->assertSame('-1', explode("\n", DedupKey::syntheticPreimage('t', 'u', ['a@x.com'], 's', -1))[4]);
        $this->assertSame('-2', explode("\n", DedupKey::syntheticPreimage('t', 'u', ['a@x.com'], 's', -60001))[4]);
        $this->assertSame('0', explode("\n", DedupKey::syntheticPreimage('t', 'u', ['a@x.com'], 's', 59999))[4]);
    }

    public function test_bom_collapses_like_js_whitespace(): void
    {
        // U+FEFF is \s in JS but not in PCRE \s; DedupKey adds it explicitly for parity.
        $pre = DedupKey::syntheticPreimage('t', 'u', ['a@x.com'], "x\u{FEFF}y", 0);
        $this->assertSame('x y', explode("\n", $pre)[3]);
    }

    public function test_synthetic_matches_hash_of_preimage_and_golden_vector(): void
    {
        $args = ['tenant-guid', 'user-guid', ['a@x.com', 'b@x.com'], 'Deal update', 1_700_000_055_000];
        $pre = DedupKey::syntheticPreimage(...$args);
        $this->assertSame(hash('sha256', $pre), DedupKey::synthetic(...$args));

        // GOLDEN cross-language vector (see packages/core/test/dedup.test.js).
        $this->assertSame(
            'd28005bf72531008c3dc3ebba90db03177b81317896fc06baad9e5239b7faee6',
            DedupKey::synthetic(...$args),
        );

        // Within the same minute -> same key (intended collision for send-time capture).
        $this->assertSame(
            DedupKey::synthetic(...$args),
            DedupKey::synthetic('tenant-guid', 'user-guid', ['a@x.com', 'b@x.com'], 'Deal update', 1_700_000_058_000),
        );
    }
}
