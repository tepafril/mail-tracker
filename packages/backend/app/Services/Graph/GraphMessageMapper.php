<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\DataObjects\EmailMessageData;
use App\DataObjects\MailAddressData;
use App\Enums\EmailDirection;
use App\Enums\MailProvider;
use App\Models\User;
use App\Support\DedupKey;

/**
 * Maps a Microsoft Graph `message` resource to our canonical {@see EmailMessageData}.
 * Direction is inferred from the sender vs. the mailbox owner (a message sent BY the
 * owner is outbound). The synthetic key is recomputed so a synced sent-item reconciles
 * against any send-time row the add-in already created (MASTER-PLAN §7.2).
 */
final class GraphMessageMapper
{
    /**
     * @param  array<string, mixed>  $msg
     */
    public static function toEmailMessage(array $msg, User $user): EmailMessageData
    {
        $from = self::address($msg['from'] ?? null) ?? new MailAddressData('');
        $to = self::addresses($msg['toRecipients'] ?? []);
        $cc = self::addresses($msg['ccRecipients'] ?? []);
        $bcc = self::addresses($msg['bccRecipients'] ?? []);

        $outbound = $from->address !== '' && $from->address === DedupKey::normalizeAddress($user->email);
        $sentAt = (string) ($msg['sentDateTime'] ?? $msg['receivedDateTime'] ?? now()->toIso8601String());
        $sentAtMs = strtotime($sentAt) !== false ? strtotime($sentAt) * 1000 : (int) (microtime(true) * 1000);

        $recipients = array_map(static fn (MailAddressData $a) => $a->address, [...$to, ...$cc, ...$bcc]);

        return new EmailMessageData(
            internetMessageId: DedupKey::normalizeMessageId(isset($msg['internetMessageId']) ? (string) $msg['internetMessageId'] : null),
            syntheticKey: DedupKey::synthetic((string) $user->tenant_id, (string) $user->id, $recipients, (string) ($msg['subject'] ?? ''), $sentAtMs),
            subject: (string) ($msg['subject'] ?? ''),
            body: (string) (data_get($msg, 'body.content', '')),
            bodyType: data_get($msg, 'body.contentType') === 'html' ? 'html' : 'text',
            from: $from,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            sentAt: $sentAt,
            direction: $outbound ? EmailDirection::Outbound : EmailDirection::Inbound,
            provider: MailProvider::Outlook,
        );
    }

    /**
     * @param  array<int, mixed>  $recipients
     * @return list<MailAddressData>
     */
    private static function addresses(array $recipients): array
    {
        $out = [];
        foreach ($recipients as $r) {
            $addr = self::address($r);
            if ($addr !== null && $addr->address !== '') {
                $out[] = $addr;
            }
        }

        return $out;
    }

    private static function address(mixed $node): ?MailAddressData
    {
        $email = data_get($node, 'emailAddress.address');
        if (! is_string($email) || $email === '') {
            return null;
        }
        $name = data_get($node, 'emailAddress.name');

        return new MailAddressData(DedupKey::normalizeAddress($email), is_string($name) && $name !== '' ? $name : null);
    }
}
