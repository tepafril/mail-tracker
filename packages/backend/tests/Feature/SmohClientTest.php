<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Smoh\SmohClient;
use App\Services\Smoh\SmohConfig;
use App\Services\Smoh\SmohThrottleException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmohClientTest extends TestCase
{
    private const BASE = 'https://tenant.smoh.test';

    private function config(): SmohConfig
    {
        return new SmohConfig(
            tenantId: 'tenant-1',
            baseUrl: self::BASE,
            authUsername: 'svc',
            authPassword: 'secret',
        );
    }

    /** A structurally valid (unsigned) JWT with a future exp, for TTL parsing. */
    private function fakeJwt(): string
    {
        $b64 = fn (array $x) => rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');

        return $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$b64(['exp' => time() + 3600]).'.sig';
    }

    private function edmx(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx" Version="4.0">
          <edmx:DataServices>
            <Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="CRM">
              <EntityContainer Name="Container">
                <EntitySet Name="Contacts" EntityType="CRM.Contact"/>
                <EntitySet Name="Emails" EntityType="CRM.Email"/>
              </EntityContainer>
            </Schema>
          </edmx:DataServices>
        </edmx:Edmx>
        XML;
    }

    private function fakeHappyPath(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['access_token' => $this->fakeJwt()], 200),
                str_contains($url, '/odata/$metadata') => Http::response($this->edmx(), 200, ['Content-Type' => 'application/xml']),
                str_contains($url, '/odata/Contacts') => Http::response(['value' => [['id' => 'contact-guid-1']]], 200),
                str_contains($url, '/odata/Emails') && $method === 'POST' => Http::response(null, 201, [
                    'OData-EntityId' => self::BASE.'/odata/Emails(activity-guid-9)',
                ]),
                str_contains($url, '/odata/Emails') => Http::response(['value' => [
                    ['id' => 'a1', 'subject' => 'Hi', 'direction' => 'outbound', 'sent_at' => '2026-01-01T00:00:00Z'],
                ]], 200),
                default => Http::response('unexpected: '.$method.' '.$url, 500),
            };
        });
    }

    public function test_resolves_entity_sets_from_metadata(): void
    {
        $this->fakeHappyPath();
        $client = new SmohClient($this->config());

        $this->assertSame('Contacts', $client->contactSet());
        $this->assertSame('Emails', $client->emailActivitySet());
    }

    public function test_find_contact_by_email_returns_id(): void
    {
        $this->fakeHappyPath();
        $client = new SmohClient($this->config());

        $this->assertSame('contact-guid-1', $client->findContactByEmail('jane@acme.com'));

        // The lookup was issued with a $top=1 lower-cased tolower() filter.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/odata/Contacts')
            && str_contains(urldecode($r->url()), "tolower(email) eq 'jane@acme.com'")
            && str_contains(urldecode($r->url()), '$top=1'));
    }

    public function test_find_contact_returns_null_when_no_match(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['access_token' => $this->fakeJwt()], 200),
                str_contains($url, '/odata/$metadata') => Http::response($this->edmx(), 200),
                default => Http::response(['value' => []], 200),
            };
        });

        $this->assertNull((new SmohClient($this->config()))->findContactByEmail('nobody@acme.com'));
    }

    public function test_log_email_activity_parses_odata_entity_id_header(): void
    {
        $this->fakeHappyPath();
        $client = new SmohClient($this->config());

        $id = $client->logEmailActivity([
            'regarding_id' => 'contact-guid-1',
            'regarding_type' => 'CRM.Email',
            'subject' => 'Deal update',
            'body' => 'Hello',
            'direction' => 'outbound',
            'sent_at' => '2026-01-01T00:00:00Z',
        ]);

        $this->assertSame('activity-guid-9', $id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/odata/Emails')
            && $r->method() === 'POST'
            && $r['regarding_type'] === 'CRM.Email');
    }

    public function test_timeline_returns_rows(): void
    {
        $this->fakeHappyPath();
        $rows = (new SmohClient($this->config()))->timeline('contact-guid-1', 10);

        $this->assertCount(1, $rows);
        $this->assertSame('Hi', $rows[0]['subject']);
    }

    public function test_throttling_raises_typed_exception_with_retry_after(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['access_token' => $this->fakeJwt()], 200),
                str_contains($url, '/odata/$metadata') => Http::response($this->edmx(), 200),
                default => Http::response('slow down', 429, ['Retry-After' => '120']),
            };
        });

        try {
            (new SmohClient($this->config()))->findContactByEmail('x@y.com');
            $this->fail('Expected SmohThrottleException');
        } catch (SmohThrottleException $e) {
            $this->assertSame(120, $e->retryAfter);
            $this->assertSame(429, $e->status);
        }
    }
}
