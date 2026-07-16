<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Enums\EmailDirection;
use App\Enums\MailProvider;
use App\Support\DedupKey;

/**
 * The canonical, provider-agnostic shape of one captured email. Mirrors
 * `EmailMessage` in packages/core. Constructed from a client request (or, in Phase 2,
 * from a Graph/Gmail sync payload) and carried through the ingestion pipeline and job.
 */
final readonly class EmailMessageData
{
    /**
     * @param  list<MailAddressData>  $to
     * @param  list<MailAddressData>  $cc
     * @param  list<MailAddressData>  $bcc
     */
    public function __construct(
        public ?string $internetMessageId,
        public string $syntheticKey,
        public string $subject,
        public string $body,
        public string $bodyType, // 'html' | 'text'
        public MailAddressData $from,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $sentAt, // ISO-8601
        public EmailDirection $direction,
        public MailProvider $provider,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $addresses = static function ($list): array {
            if (! is_array($list)) {
                return [];
            }

            return array_values(array_map(
                static fn ($a) => MailAddressData::fromArray(is_array($a) ? $a : ['address' => (string) $a]),
                $list,
            ));
        };

        return new self(
            internetMessageId: DedupKey::normalizeMessageId(isset($data['internetMessageId']) ? (string) $data['internetMessageId'] : null),
            syntheticKey: (string) ($data['syntheticKey'] ?? ''),
            subject: (string) ($data['subject'] ?? ''),
            body: (string) ($data['body'] ?? ''),
            bodyType: ($data['bodyType'] ?? 'text') === 'html' ? 'html' : 'text',
            from: MailAddressData::fromArray(is_array($data['from'] ?? null) ? $data['from'] : ['address' => (string) ($data['from'] ?? '')]),
            to: $addresses($data['to'] ?? []),
            cc: $addresses($data['cc'] ?? []),
            bcc: $addresses($data['bcc'] ?? []),
            sentAt: (string) ($data['sentAt'] ?? ''),
            direction: EmailDirection::from((string) ($data['direction'] ?? 'outbound')),
            provider: MailProvider::from((string) ($data['provider'] ?? 'outlook')),
        );
    }

    /**
     * The address to match against SMOH contacts: for outbound mail the primary
     * recipient; for inbound mail the sender. Returns null if unavailable.
     */
    public function counterpartyEmail(): ?string
    {
        if ($this->direction === EmailDirection::Outbound) {
            // Prefer To, then Cc, then Bcc — a Cc/Bcc-only send still has a counterparty.
            $recipient = ($this->to[0] ?? $this->cc[0] ?? $this->bcc[0] ?? null);

            return $recipient?->address ?? null;
        }

        return $this->from->address !== '' ? $this->from->address : null;
    }

    /**
     * The canonical CRM.Email activity payload for SMOH (MASTER-PLAN §7.2). When a CRM
     * record was matched, the scalar `regarding_id` + `regarding_type` pair links the
     * activity to it; with no match (the `all` track rule) the regarding is omitted.
     *
     * NOTE: the non-`regarding_*` property names below (subject/body/direction/sent_at/
     * from/to) are the best-guess mapping; confirm them against SMOH's CRM.Email
     * `$metadata` and adjust here — this is the single place they are defined
     * (open decision, MASTER-PLAN §10).
     *
     * @return array<string, mixed>
     */
    public function toSmohActivity(?string $regardingId, ?string $regardingType, string $body): array
    {
        $payload = [
            'subject' => mb_substr($this->subject, 0, 255),
            'body' => $body,
            'direction' => $this->direction->value,
            'sent_at' => $this->sentAt,
            'from' => $this->from->address,
            'to' => implode(',', array_map(static fn (MailAddressData $a) => $a->address, $this->to)),
        ];

        // Link to the matched CRM record (contact/lead/account). No match => no regarding
        // (the `all` track rule may log unmatched mail).
        if ($regardingId !== null && $regardingId !== '') {
            $payload['regarding_id'] = $regardingId;
            $payload['regarding_type'] = $regardingType ?? \App\Support\ODataQuery::EMAIL_REGARDING_TYPE;
        }

        return $payload;
    }

    /**
     * All external participant addresses (from + to + cc), de-duplicated, normalized.
     *
     * @return list<string>
     */
    public function allParticipants(): array
    {
        $addresses = [$this->from->address];
        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $addr) {
            $addresses[] = $addr->address;
        }

        return array_values(array_unique(array_filter($addresses, static fn (string $a) => $a !== '')));
    }
}
