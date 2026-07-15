<?php

declare(strict_types=1);

namespace App\Services\Graph;

use Illuminate\Support\Str;

/**
 * DEV/DEMO ONLY. In-process stand-in for {@see GraphClient} — no real Graph, no app
 * registration, no network. Enabled by `services.graph.fake` (GRAPH_FAKE=true).
 *
 * Subscriptions are fake ids; getMessage() returns a canned inbound message so the
 * zero-touch pipeline (notification → fetch → ingest) can be exercised locally.
 */
final class FakeGraphClient extends GraphClient
{
    public function token(): string
    {
        return 'fake-graph-token';
    }

    public function createSubscription(string $resource, string $clientState, string $expiration): array
    {
        return ['id' => 'fake-sub-'.Str::random(8), 'expirationDateTime' => $expiration];
    }

    public function renewSubscription(string $subscriptionId, string $expiration): string
    {
        return $expiration;
    }

    public function deleteSubscription(string $subscriptionId): void
    {
        // no-op
    }

    /**
     * A deterministic canned INBOUND message from an external contact to $userId.
     *
     * @return array<string, mixed>
     */
    public function getMessage(string $userId, string $messageId): array
    {
        return [
            'internetMessageId' => "<{$messageId}@partner.example>",
            'subject' => 'Re: Project kickoff',
            'body' => ['contentType' => 'html', 'content' => '<p>Thanks — looks good. Let\'s proceed.</p>'],
            'from' => ['emailAddress' => ['address' => 'contact@partner.example', 'name' => 'External Contact']],
            'toRecipients' => [['emailAddress' => ['address' => $userId, 'name' => 'Mailbox Owner']]],
            'ccRecipients' => [],
            'bccRecipients' => [],
            'sentDateTime' => now()->subMinutes(2)->toIso8601String(),
            'receivedDateTime' => now()->subMinutes(1)->toIso8601String(),
            'parentFolderId' => 'inbox',
        ];
    }
}
