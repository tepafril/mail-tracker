@php
    /** Format a recipients array [{address,name}] into "Name <addr>, ..." */
    $fmt = function (?array $list): string {
        if (empty($list)) {
            return '—';
        }
        return collect($list)->map(function ($r) {
            $addr = $r['address'] ?? '';
            $name = $r['name'] ?? null;
            return $name ? "{$name} <{$addr}>" : $addr;
        })->implode(', ');
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logged Emails — SMOH Mail Tracker (dev)</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: -apple-system, 'Segoe UI', system-ui, sans-serif; margin: 0; background: #f4f5f7; color: #1a1a1a; }
        header { background: #0f6cbd; color: #fff; padding: 16px 24px; }
        header h1 { margin: 0; font-size: 18px; }
        header p { margin: 4px 0 0; opacity: .85; font-size: 13px; }
        .wrap { max-width: 860px; margin: 24px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e2e4e8; border-radius: 8px; margin-bottom: 18px; overflow: hidden; }
        .card .head { padding: 14px 18px; border-bottom: 1px solid #eee; }
        .subject { font-size: 16px; font-weight: 600; margin: 0 0 6px; }
        .meta { font-size: 13px; color: #555; line-height: 1.6; }
        .meta b { color: #333; font-weight: 600; }
        .badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; letter-spacing: .03em; }
        .out { background: #e6f2fb; color: #0f6cbd; }
        .in { background: #eaf6ec; color: #1a7f37; }
        .status { font-size: 11px; padding: 2px 8px; border-radius: 10px; background: #eee; color: #444; }
        .body { padding: 16px 18px; font-size: 14px; line-height: 1.55; }
        .body pre { white-space: pre-wrap; word-break: break-word; font-family: inherit; margin: 0; }
        .purged { color: #b00020; font-style: italic; }
        .empty { text-align: center; color: #777; padding: 40px; }
        .warn { background: #fff8e1; border: 1px solid #ffe08a; color: #7a5900; padding: 10px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; }
        code { background: #f0f0f0; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
    </style>
</head>
<body>
    <header>
        <h1>Logged Emails</h1>
        <p>SMOH Mail Tracker — {{ $emails->count() }} record(s), newest first. Content decrypted for viewing.</p>
    </header>

    <div class="wrap">
        <div class="warn">⚠️ Dev-only view — this page shows decrypted email content (PII). It is disabled outside local / demo mode.</div>

        @forelse ($emails as $email)
            @php $outbound = $email->direction?->value === 'outbound'; @endphp
            <div class="card">
                <div class="head">
                    <p class="subject">{{ $email->subject ?: '(no subject)' }}</p>
                    <div class="meta">
                        <span class="badge {{ $outbound ? 'out' : 'in' }}">{{ $outbound ? 'Sent' : 'Received' }}</span>
                        <span class="status">{{ str_replace('_', ' ', $email->status?->value ?? 'unknown') }}</span>
                        &nbsp;·&nbsp; {{ ucfirst($email->provider?->value ?? '?') }}
                        &nbsp;·&nbsp; {{ optional($email->email_sent_at)->format('M j, Y g:i A') ?? '—' }}
                        <br>
                        <b>From:</b> {{ $email->from_address ?: '—' }}<br>
                        <b>To:</b> {{ $fmt($email->to_recipients) }}<br>
                        @if (!empty($email->cc_recipients))<b>Cc:</b> {{ $fmt($email->cc_recipients) }}<br>@endif
                        @if (!empty($email->bcc_recipients))<b>Bcc:</b> {{ $fmt($email->bcc_recipients) }}<br>@endif
                        <b>CRM contact:</b> {{ $email->contact_id ? "linked ($email->contact_id)" : 'not matched' }}
                        &nbsp;·&nbsp; <b>tenant:</b> <code>{{ $email->tenant_id }}</code>
                        @if ($email->internet_message_id)&nbsp;·&nbsp; <b>msg-id:</b> <code>{{ $email->internet_message_id }}</code>@endif
                    </div>
                </div>
                <div class="body">
                    @if ($email->content_purged_at)
                        <span class="purged">🔒 Content purged on {{ $email->content_purged_at->format('M j, Y') }} (retention / erasure).</span>
                    @elseif ($email->body_type === 'html')
                        {!! $email->body ?: '<em>(empty body)</em>' !!}
                    @else
                        <pre>{{ $email->body ?: '(empty body)' }}</pre>
                    @endif
                </div>
            </div>
        @empty
            <div class="empty">No emails logged yet. Log one from the add-in (or the demo flow) and refresh.</div>
        @endforelse
    </div>
</body>
</html>
