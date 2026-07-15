<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ParseGraphNotificationJob;
use App\Models\GraphSubscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_graph_validation_handshake_echoes_token(): void
    {
        $response = $this->post('/api/webhooks/graph/notifications?validationToken=hello%20world')
            ->assertOk()
            ->assertSee('hello world');

        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_graph_notification_with_valid_client_state_is_enqueued(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        GraphSubscription::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'subscription_id' => 'sub-123',
            'resource' => "users/{$user->email}/messages",
            'client_state' => 'super-secret-state',
            'expiration' => now()->addDays(6),
        ]);

        $this->postJson('/api/webhooks/graph/notifications', [
            'value' => [[
                'subscriptionId' => 'sub-123',
                'clientState' => 'super-secret-state',
                'changeType' => 'created',
                'resource' => "users/{$user->email}/messages/AAA",
                'resourceData' => ['id' => 'AAA'],
            ]],
        ])->assertStatus(202);

        Bus::assertDispatched(ParseGraphNotificationJob::class);
    }

    public function test_graph_notification_with_spoofed_client_state_is_dropped(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        GraphSubscription::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'subscription_id' => 'sub-123',
            'resource' => 'messages',
            'client_state' => 'super-secret-state',
            'expiration' => now()->addDays(6),
        ]);

        $this->postJson('/api/webhooks/graph/notifications', [
            'value' => [[
                'subscriptionId' => 'sub-123',
                'clientState' => 'WRONG',
                'changeType' => 'created',
            ]],
        ])->assertStatus(202);

        Bus::assertNotDispatched(ParseGraphNotificationJob::class);
    }
}
