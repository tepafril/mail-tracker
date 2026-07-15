<?php

declare(strict_types=1);

namespace App\Services\Graph;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft Graph client for one client organization (Entra tenant id). App-only
 * (client-credentials) auth — the app's Mail.Read application permission must be
 * admin-consented in that org and scoped via RBAC for Applications (MASTER-PLAN §7.3).
 * Construct via {@see GraphClientFactory}.
 */
class GraphClient
{
    public function __construct(protected readonly string $orgTenantId) {}

    /** App-only access token for this org, cached until shortly before expiry. */
    public function token(): string
    {
        $cacheKey = 'graph:token:'.sha1($this->orgTenantId);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->post(
            rtrim((string) config('services.graph.login_url'), '/')."/{$this->orgTenantId}/oauth2/v2.0/token",
            [
                'grant_type' => 'client_credentials',
                'client_id' => (string) config('services.graph.client_id'),
                'client_secret' => (string) config('services.graph.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
            ],
        );

        if (! $response->successful()) {
            throw new GraphException('Graph token request failed', $response->status(), $response->body());
        }

        $token = (string) $response->json('access_token');
        $ttl = (int) ($response->json('expires_in') ?? 3600) - 60;
        Cache::put($cacheKey, $token, $ttl > 60 ? $ttl : (int) config('services.graph.token_ttl', 3000));

        return $token;
    }

    /**
     * Create a change-notification subscription.
     *
     * @return array{id: string, expirationDateTime: string}
     */
    public function createSubscription(string $resource, string $clientState, string $expiration): array
    {
        $response = $this->request()->post($this->base().'/subscriptions', array_filter([
            'changeType' => 'created',
            'notificationUrl' => (string) config('services.graph.notification_url'),
            'lifecycleNotificationUrl' => config('services.graph.lifecycle_url') ?: null,
            'resource' => $resource,
            'clientState' => $clientState,
            'expirationDateTime' => $expiration,
        ]));

        if (! $response->successful()) {
            throw new GraphException('Graph subscription create failed', $response->status(), $response->body());
        }

        return [
            'id' => (string) $response->json('id'),
            'expirationDateTime' => (string) $response->json('expirationDateTime'),
        ];
    }

    /** Extend a subscription's expiry. Returns the new expiration. */
    public function renewSubscription(string $subscriptionId, string $expiration): string
    {
        $response = $this->request()->patch($this->base().'/subscriptions/'.$subscriptionId, [
            'expirationDateTime' => $expiration,
        ]);

        if (! $response->successful()) {
            throw new GraphException('Graph subscription renew failed', $response->status(), $response->body());
        }

        return (string) $response->json('expirationDateTime');
    }

    public function deleteSubscription(string $subscriptionId): void
    {
        $response = $this->request()->delete($this->base().'/subscriptions/'.$subscriptionId);

        // 404 = already gone; treat as success.
        if (! $response->successful() && $response->status() !== 404) {
            throw new GraphException('Graph subscription delete failed', $response->status(), $response->body());
        }
    }

    /**
     * Fetch a single message from a user's mailbox.
     *
     * @return array<string, mixed>
     */
    public function getMessage(string $userId, string $messageId): array
    {
        $response = $this->request()->get($this->base()."/users/{$userId}/messages/{$messageId}", [
            '$select' => 'internetMessageId,subject,body,from,toRecipients,ccRecipients,bccRecipients,sentDateTime,receivedDateTime,parentFolderId',
        ]);

        if (! $response->successful()) {
            throw new GraphException('Graph message fetch failed', $response->status(), $response->body());
        }

        return (array) $response->json();
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson();
    }

    protected function base(): string
    {
        return rtrim((string) config('services.graph.base_url'), '/');
    }
}
