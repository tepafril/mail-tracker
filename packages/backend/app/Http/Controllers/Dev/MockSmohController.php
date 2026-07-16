<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\MockSmoh\MockAccount;
use App\Models\MockSmoh\MockContact;
use App\Models\MockSmoh\MockEmail;
use App\Models\MockSmoh\MockLead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * DEV/DEMO ONLY. An in-backend mock of a SMOH CRM: implements just enough of the
 * `/auth/login` + OData v4 contract that {@see \App\Services\Smoh\SmohClient} calls, backed
 * by the mock_* dummy tables. It lets the CRM-depth features be built against the real wire
 * protocol before a real SMOH exists, and doubles as the API spec for it.
 *
 * Point a tenant's smoh_base_url at {APP_URL}/api/mock-smoh and set SMOH_FAKE=false.
 * Gated to dev (MAIL_TRACKER_DEV_AUTH / local); returns 404 in production.
 */
class MockSmohController extends Controller
{
    /** OData entity-set name => backing model. */
    private const SETS = [
        'Accounts' => MockAccount::class,
        'Contacts' => MockContact::class,
        'Leads' => MockLead::class,
        'Emails' => MockEmail::class,
    ];

    /** POST /auth/login — accepts any credentials (it's a mock), returns a bearer token. */
    public function login(Request $request): Response
    {
        $this->ensureDev();

        return response()->json([
            'access_token' => 'mock-'.Str::random(48),
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    /** GET /odata/{set} — the $metadata document, or a filtered entity query. */
    public function query(Request $request, string $set): Response
    {
        $this->ensureDev();
        $this->ensureAuthorized($request);

        if ($set === '$metadata') {
            return response($this->metadataXml())->header('Content-Type', 'application/xml');
        }

        $query = $this->rawQuery($request);
        $top = $this->intParam($query, 'top', 50);

        $rows = $set === 'Emails'
            ? $this->queryEmails($query, $top)
            : $this->queryByEmail($this->modelFor($set), $set, $query, $top);

        return response()->json(['value' => $rows]);
    }

    /** POST /odata/{set} — create a record (only Emails/activities are used today). */
    public function create(Request $request, string $set): Response
    {
        $this->ensureDev();
        $this->ensureAuthorized($request);

        if ($set !== 'Emails') {
            abort(400, "Mock SMOH only supports creating Emails, not {$set}.");
        }

        $payload = $request->json()->all();
        $email = MockEmail::create([
            'regarding_id' => $payload['regarding_id'] ?? null,
            'regarding_type' => $payload['regarding_type'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'body' => $payload['body'] ?? null,
            'direction' => $payload['direction'] ?? null,
            'sent_at' => $payload['sent_at'] ?? null,
            'from_address' => $payload['from'] ?? null,
            'to_recipients' => $payload['to'] ?? null,
        ]);

        // Mirror OData's create response: 201 + OData-EntityId carrying the new key.
        return response()->json(['id' => $email->id], 201)
            ->header('OData-EntityId', url("/api/mock-smoh/odata/Emails({$email->id})"));
    }

    // ---- internals --------------------------------------------------------------

    private function ensureDev(): void
    {
        abort_unless((bool) config('mail_tracker.dev_auth') || app()->environment('local'), 404);
    }

    private function ensureAuthorized(Request $request): void
    {
        abort_if(($request->bearerToken() ?? '') === '', 401, 'Missing bearer token.');
    }

    /** @return class-string<Model> */
    private function modelFor(string $set): string
    {
        return self::SETS[$set] ?? abort(404, "Unknown entity set {$set}.");
    }

    /** Raw, URL-decoded query string — avoids PHP's $_GET key mangling of `$filter`/`$top`. */
    private function rawQuery(Request $request): string
    {
        return urldecode((string) $request->server('QUERY_STRING', ''));
    }

    private function intParam(string $query, string $name, int $default): int
    {
        return preg_match('/\$'.$name.'=(\d+)/', $query, $m) === 1 ? (int) $m[1] : $default;
    }

    /**
     * Match Contacts/Accounts/Leads by the email literal in the $filter. The SmohClient
     * sends `(tolower(<field>) eq '<email>' or …)`; match that email against this set's
     * email-ish columns.
     *
     * @param  class-string<Model>  $model
     * @return list<array<string,mixed>>
     */
    private function queryByEmail(string $model, string $set, string $query, int $top): array
    {
        if (preg_match("/eq '([^']+)'/", $query, $m) !== 1) {
            return [];
        }
        $email = mb_strtolower($m[1]);

        $columns = match ($set) {
            'Contacts' => ['email', 'email_business'],
            'Leads' => ['email'],
            'Accounts' => ['primary_email'],
            default => ['email'],
        };

        $builder = $model::query()->where(function ($q) use ($columns, $email) {
            foreach ($columns as $col) {
                $q->orWhereRaw('lower('.$col.') = ?', [$email]);
            }
        });

        // An account also matches on the email's domain, so an unknown person at a known
        // company still resolves to the account.
        if ($set === 'Accounts' && str_contains($email, '@')) {
            $builder->orWhereRaw('lower(domain) = ?', [substr(strrchr($email, '@'), 1)]);
        }

        return $builder->limit($top)->get()->map(fn (Model $r) => $r->toArray())->values()->all();
    }

    /**
     * Timeline query for Emails: filter by `regarding_id eq <id>`, newest first.
     *
     * @return list<array<string,mixed>>
     */
    private function queryEmails(string $query, int $top): array
    {
        $builder = MockEmail::query();
        if (preg_match('/regarding_id eq ([^ &]+)/', $query, $m) === 1) {
            $builder->where('regarding_id', trim($m[1], "'"));
        }

        return $builder->orderByDesc('sent_at')->limit($top)->get()->map(fn (Model $r) => $r->toArray())->values()->all();
    }

    private function metadataXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<edmx:Edmx Version="4.0" xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx">
  <edmx:DataServices>
    <Schema Namespace="CRM" xmlns="http://docs.oasis-open.org/odata/ns/edm">
      <EntityType Name="Account">
        <Key><PropertyRef Name="id"/></Key>
        <Property Name="id" Type="Edm.String"/>
        <Property Name="name" Type="Edm.String"/>
        <Property Name="domain" Type="Edm.String"/>
        <Property Name="primary_email" Type="Edm.String"/>
      </EntityType>
      <EntityType Name="Contact">
        <Key><PropertyRef Name="id"/></Key>
        <Property Name="id" Type="Edm.String"/>
        <Property Name="first_name" Type="Edm.String"/>
        <Property Name="last_name" Type="Edm.String"/>
        <Property Name="email" Type="Edm.String"/>
        <Property Name="email_business" Type="Edm.String"/>
        <Property Name="account_id" Type="Edm.String"/>
      </EntityType>
      <EntityType Name="Lead">
        <Key><PropertyRef Name="id"/></Key>
        <Property Name="id" Type="Edm.String"/>
        <Property Name="name" Type="Edm.String"/>
        <Property Name="email" Type="Edm.String"/>
        <Property Name="status" Type="Edm.String"/>
      </EntityType>
      <EntityType Name="Email">
        <Key><PropertyRef Name="id"/></Key>
        <Property Name="id" Type="Edm.String"/>
        <Property Name="regarding_id" Type="Edm.String"/>
        <Property Name="regarding_type" Type="Edm.String"/>
        <Property Name="subject" Type="Edm.String"/>
        <Property Name="body" Type="Edm.String"/>
        <Property Name="direction" Type="Edm.String"/>
        <Property Name="sent_at" Type="Edm.String"/>
      </EntityType>
      <EntityContainer Name="Container">
        <EntitySet Name="Accounts" EntityType="CRM.Account"/>
        <EntitySet Name="Contacts" EntityType="CRM.Contact"/>
        <EntitySet Name="Leads" EntityType="CRM.Lead"/>
        <EntitySet Name="Emails" EntityType="CRM.Email"/>
      </EntityContainer>
    </Schema>
  </edmx:DataServices>
</edmx:Edmx>
XML;
    }
}
