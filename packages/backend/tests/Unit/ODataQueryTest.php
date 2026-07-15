<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ODataQuery;
use PHPUnit\Framework\TestCase;

/** Mirror of packages/core/test/odata.test.js. */
class ODataQueryTest extends TestCase
{
    public function test_escape_doubles_single_quotes(): void
    {
        $this->assertSame("O''Brien", ODataQuery::escapeString("O'Brien"));
        $this->assertSame("'O''Brien'", ODataQuery::literal("O'Brien"));
    }

    public function test_contact_match_filter_lowercases_and_covers_default_fields(): void
    {
        $this->assertSame(
            "(tolower(email) eq 'foo@bar.com' or tolower(email_business) eq 'foo@bar.com')",
            ODataQuery::contactMatchFilter('Foo@Bar.COM'),
        );
    }

    public function test_contact_match_filter_respects_custom_fields(): void
    {
        $f = ODataQuery::contactMatchFilter('a@b.com', ['email', 'email_business', 'email_personal']);
        $this->assertStringContainsString("tolower(email_personal) eq 'a@b.com'", $f);
    }

    public function test_contact_match_params(): void
    {
        $p = ODataQuery::contactMatchParams('a@b.com');
        $this->assertSame(1, $p['$top']);
        $this->assertSame('id', $p['$select']);
        $this->assertStringContainsString("tolower(email) eq 'a@b.com'", $p['$filter']);
    }

    public function test_timeline_filter_and_params(): void
    {
        $this->assertSame(
            "regarding_id eq 11111111-2222-3333-4444-555555555555 and regarding_type eq 'CRM.Email'",
            ODataQuery::timelineFilter('11111111-2222-3333-4444-555555555555'),
        );
        $p = ODataQuery::timelineParams('guid', 25);
        $this->assertSame('sent_at desc', $p['$orderby']);
        $this->assertSame(25, $p['$top']);
    }

    public function test_parse_entity_id_header(): void
    {
        $this->assertSame(
            '9f8e7d6c-0000-1111-2222-333344445555',
            ODataQuery::parseEntityIdHeader('https://x/odata/CRM.Email(9f8e7d6c-0000-1111-2222-333344445555)'),
        );
        $this->assertSame('abc-123', ODataQuery::parseEntityIdHeader("https://x/odata/EmailSet('abc-123')"));
        $this->assertNull(ODataQuery::parseEntityIdHeader(''));
        $this->assertNull(ODataQuery::parseEntityIdHeader(null));
        $this->assertNull(ODataQuery::parseEntityIdHeader('no-parens-here'));
    }
}
