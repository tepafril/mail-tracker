<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataObjects\EmailMessageData;
use App\DataObjects\MailAddressData;
use App\Enums\EmailDirection;
use App\Enums\MailProvider;
use App\Jobs\LogEmailActivityJob;
use Tests\TestCase;

/**
 * Guards the throttle-handling fix: the job must NOT use $tries (a 429 release()
 * increments attempts and would exhaust it), and must bound its lifetime via
 * retryUntil() + maxExceptions, with a finite uniqueFor lock.
 */
class LogEmailActivityJobConfigTest extends TestCase
{
    private function job(): LogEmailActivityJob
    {
        $message = new EmailMessageData(
            internetMessageId: null,
            syntheticKey: 'k',
            subject: 's',
            body: '',
            bodyType: 'text',
            from: new MailAddressData('a@b.com'),
            to: [new MailAddressData('c@d.com')],
            cc: [],
            bcc: [],
            sentAt: '2026-07-14T00:00:00Z',
            direction: EmailDirection::Outbound,
            provider: MailProvider::Outlook,
        );

        return new LogEmailActivityJob(1, 'tenant-1', $message);
    }

    public function test_does_not_use_tries_and_bounds_via_retry_until(): void
    {
        $job = $this->job();

        // $tries must be absent (or null) so throttle releases can't exhaust attempts.
        $this->assertTrue(! isset($job->tries) || $job->tries === null);

        $this->assertSame(8, $job->maxExceptions);
        $this->assertGreaterThan(0, $job->uniqueFor);

        $retryUntil = $job->retryUntil();
        $this->assertInstanceOf(\DateTimeInterface::class, $retryUntil);
        $this->assertGreaterThan(now()->getTimestamp(), $retryUntil->getTimestamp());
    }
}
