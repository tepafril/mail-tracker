<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MockSmoh\MockContact;
use App\Models\MockSmoh\MockEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dev-only mock SMOH CRM (OData v4 + /auth/login) that stands in for a real SMOH.
 */
class MockSmohTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mail_tracker.dev_auth', true); // un-gate the mock
    }

    public function test_login_returns_a_bearer_token(): void
    {
        $this->postJson('/api/mock-smoh/auth/login', ['username' => 'u', 'password' => 'p'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    }

    public function test_metadata_lists_entity_sets(): void
    {
        $res = $this->withToken('mock-x')->get('/api/mock-smoh/odata/$metadata');

        $res->assertOk();
        $this->assertStringContainsString('EntitySet Name="Contacts" EntityType="CRM.Contact"', $res->getContent());
        $this->assertStringContainsString('EntitySet Name="Emails" EntityType="CRM.Email"', $res->getContent());
        $this->assertStringContainsString('EntitySet Name="Leads" EntityType="CRM.Lead"', $res->getContent());
        $this->assertStringContainsString('EntitySet Name="Accounts" EntityType="CRM.Account"', $res->getContent());
    }

    public function test_odata_requires_a_bearer_token(): void
    {
        $this->getJson('/api/mock-smoh/odata/Contacts')->assertStatus(401);
    }

    public function test_contact_match_by_email(): void
    {
        $contact = MockContact::create(['first_name' => 'A', 'email' => 'a@b.com']);

        $url = '/api/mock-smoh/odata/Contacts?'.http_build_query([
            '$filter' => "(tolower(email) eq 'a@b.com')",
            '$select' => 'id',
            '$top' => 1,
        ]);

        $this->withToken('mock-x')->getJson($url)
            ->assertOk()
            ->assertJsonPath('value.0.id', $contact->id);
    }

    public function test_contact_match_returns_empty_for_unknown(): void
    {
        $url = '/api/mock-smoh/odata/Contacts?'.http_build_query([
            '$filter' => "(tolower(email) eq 'nobody@nowhere.test')",
            '$top' => 1,
        ]);

        $this->withToken('mock-x')->getJson($url)->assertOk()->assertJsonCount(0, 'value');
    }

    public function test_create_email_activity_returns_odata_entity_id(): void
    {
        $res = $this->withToken('mock-x')->postJson('/api/mock-smoh/odata/Emails', [
            'regarding_id' => 'contact-1',
            'regarding_type' => 'CRM.Contact',
            'subject' => 'Hi',
            'direction' => 'outbound',
            'from' => 'x@y.com',
            'to' => 'a@b.com',
        ]);

        $res->assertCreated();
        $this->assertMatchesRegularExpression('/\([^)]+\)$/', (string) $res->headers->get('OData-EntityId'));
        $this->assertSame(1, MockEmail::count());
    }

    public function test_gated_off_when_not_dev(): void
    {
        config()->set('mail_tracker.dev_auth', false); // and env is 'testing', not 'local'

        $this->postJson('/api/mock-smoh/auth/login', [])->assertNotFound();
    }
}
