<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Smoh\SmohClient;
use App\Services\Smoh\SmohConfig;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SmohClient::resolveRecipient — the contact -> lead -> account matching order (Phase C),
 * exercised against faked OData responses. Entity sets are provided in the config so no
 * $metadata round-trip is needed.
 */
class SmohClientTest extends TestCase
{
    private function client(): SmohClient
    {
        return new SmohClient(new SmohConfig(
            tenantId: 't',
            baseUrl: 'https://smoh.test',
            authUsername: 'u',
            authPassword: 'p',
            emailActivitySet: 'Emails',
            contactSet: 'Contacts',
            leadSet: 'Leads',
            accountSet: 'Accounts',
        ));
    }

    public function test_prefers_a_contact_match(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['access_token' => 't']),
            '*/odata/Contacts*' => Http::response(['value' => [['id' => 'contact-1']]]),
        ]);

        $match = $this->client()->resolveRecipient('a@b.com');

        $this->assertNotNull($match);
        $this->assertSame('contact-1', $match->id);
        $this->assertSame('CRM.Contact', $match->type);
    }

    public function test_falls_back_to_a_lead(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['access_token' => 't']),
            '*/odata/Contacts*' => Http::response(['value' => []]),
            '*/odata/Leads*' => Http::response(['value' => [['id' => 'lead-1']]]),
        ]);

        $match = $this->client()->resolveRecipient('a@b.com');

        $this->assertSame('lead-1', $match->id);
        $this->assertSame('CRM.Lead', $match->type);
    }

    public function test_falls_back_to_an_account(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['access_token' => 't']),
            '*/odata/Contacts*' => Http::response(['value' => []]),
            '*/odata/Leads*' => Http::response(['value' => []]),
            '*/odata/Accounts*' => Http::response(['value' => [['id' => 'acct-1']]]),
        ]);

        $match = $this->client()->resolveRecipient('a@b.com');

        $this->assertSame('acct-1', $match->id);
        $this->assertSame('CRM.Account', $match->type);
    }

    public function test_returns_null_when_nothing_matches(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['access_token' => 't']),
            '*/odata/Contacts*' => Http::response(['value' => []]),
            '*/odata/Leads*' => Http::response(['value' => []]),
            '*/odata/Accounts*' => Http::response(['value' => []]),
        ]);

        $this->assertNull($this->client()->resolveRecipient('a@b.com'));
    }

    public function test_search_records_across_types(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['access_token' => 't']),
            '*/odata/Contacts*' => Http::response(['value' => [['id' => 'c1', 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@x.com']]]),
            '*/odata/Leads*' => Http::response(['value' => [['id' => 'l1', 'name' => 'Lead Co', 'email' => 'lead@x.com']]]),
            '*/odata/Accounts*' => Http::response(['value' => []]),
        ]);

        $records = $this->client()->searchRecords('ja');

        $this->assertCount(2, $records);
        $this->assertSame('CRM.Contact', $records[0]['type']);
        $this->assertSame('c1', $records[0]['id']);
        $this->assertStringContainsString('Jane Doe', $records[0]['label']);
        $this->assertSame('CRM.Lead', $records[1]['type']);
    }

    public function test_set_activity_regarding_patches_the_activity(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['access_token' => 't']),
            '*/odata/Emails(*' => Http::response(null, 204),
        ]);

        $this->client()->setActivityRegarding('act-1', 'acct-9', 'CRM.Account');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/odata/Emails(act-1)')
            && $r['regarding_id'] === 'acct-9'
            && $r['regarding_type'] === 'CRM.Account');
    }
}
