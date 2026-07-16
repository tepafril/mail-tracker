<?php

declare(strict_types=1);

namespace App\Services\Smoh;

use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;

/**
 * DEV/DEMO ONLY. An in-process stand-in for {@see SmohClient} that needs no real SMOH
 * instance and makes no network calls. Enabled by `services.smoh.fake` (SMOH_FAKE=true).
 *
 * - Every email "matches" a deterministic contact GUID (so logging always has a target).
 * - Logged activities are stored in the cache, so the timeline reflects what you log.
 *
 * It extends SmohClient purely to satisfy the factory's return type; every method that
 * would touch the network is overridden.
 */
final class FakeSmohClient extends SmohClient
{
    public function __construct(private readonly SmohConfig $cfg)
    {
        parent::__construct($cfg);
    }

    public function login(): string
    {
        return 'fake-token';
    }

    public function token(): string
    {
        return 'fake-token';
    }

    public function emailActivitySet(): string
    {
        return 'Emails';
    }

    public function contactSet(): string
    {
        return 'Contacts';
    }

    public function resolveEntitySet(string $type): string
    {
        return str_contains($type, 'Contact') ? 'Contacts' : 'Emails';
    }

    /** Any address maps to a stable fake contact GUID (v5 of the address). */
    public function findContactByEmail(string $email): ?string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'smoh-contact:'.mb_strtolower(trim($email)))->toString();
    }

    /** The fake resolves every address to its stable contact. */
    public function resolveRecipient(string $email): ?RecipientMatch
    {
        $id = $this->findContactByEmail($email);

        return $id === null ? null : new RecipientMatch($id, 'CRM.Contact');
    }

    /** Deterministic fake: one contact candidate derived from the query. */
    public function searchRecords(string $query, int $perType = 5): array
    {
        $id = $this->findContactByEmail($query);

        return $id === null ? [] : [['id' => $id, 'type' => 'CRM.Contact', 'label' => "Contact for {$query}"]];
    }

    /** Update the stored fake activity's regarding. */
    public function setActivityRegarding(string $activityId, string $regardingId, string $regardingType): void
    {
        $key = $this->cacheKey();
        $activities = Cache::get($key, []);
        foreach ($activities as $i => $activity) {
            if (($activity['id'] ?? null) === $activityId) {
                $activities[$i]['regarding_id'] = $regardingId;
                $activities[$i]['regarding_type'] = $regardingType;
            }
        }
        Cache::put($key, $activities, now()->addDays(7));
    }

    /** Store the activity in the cache and return a fresh GUID. */
    public function logEmailActivity(array $payload): string
    {
        $id = Uuid::uuid4()->toString();

        $key = $this->cacheKey();
        $activities = Cache::get($key, []);
        $activities[] = [
            'id' => $id,
            'regarding_id' => (string) ($payload['regarding_id'] ?? ''),
            'regarding_type' => (string) ($payload['regarding_type'] ?? 'CRM.Email'),
            'subject' => (string) ($payload['subject'] ?? ''),
            'direction' => (string) ($payload['direction'] ?? ''),
            'sent_at' => (string) ($payload['sent_at'] ?? now()->toIso8601String()),
        ];
        Cache::put($key, $activities, now()->addDays(7));

        return $id;
    }

    /** Return stored activities for a contact, newest first. */
    public function timeline(string $contactId, int $top = 50): array
    {
        $activities = array_values(array_filter(
            Cache::get($this->cacheKey(), []),
            static fn (array $a) => ($a['regarding_id'] ?? null) === $contactId,
        ));

        usort($activities, static fn ($a, $b) => strcmp((string) $b['sent_at'], (string) $a['sent_at']));

        return array_slice($activities, 0, $top);
    }

    private function cacheKey(): string
    {
        return 'fake-smoh:activities:'.$this->cfg->tenantId;
    }
}
