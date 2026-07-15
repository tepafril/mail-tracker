<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SMOH CRM
    |--------------------------------------------------------------------------
    | Defaults for the per-tenant SMOH OData client. Per-tenant base URL and
    | credentials live on the tenant row (encrypted); these are cross-tenant
    | defaults for entity type names and behavior. See MASTER-PLAN §2, §4.4.
    */
    'smoh' => [
        // DEV/DEMO: use the in-process FakeSmohClient (no real SMOH instance needed).
        'fake' => filter_var(env('SMOH_FAKE', false), FILTER_VALIDATE_BOOL),
        // Cached-login TTL fallback (seconds) when the JWT `exp` can't be read.
        'token_ttl' => (int) env('SMOH_TOKEN_TTL', 3000),
        // Fully-qualified OData types (entity-SET names are resolved from $metadata).
        'email_type' => env('SMOH_EMAIL_TYPE', 'CRM.Email'),
        'contact_type' => env('SMOH_CONTACT_TYPE', 'CRM.Contact'),
        // Contact fields searched when matching by email.
        'contact_email_fields' => array_values(array_filter(
            explode(',', (string) env('SMOH_CONTACT_EMAIL_FIELDS', 'email,email_business'))
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider OIDC (client -> backend auth)
    |--------------------------------------------------------------------------
    | Used by App\Services\Auth\TokenVerifier to validate the access/id tokens the
    | thin clients present at /api/v1/auth/exchange. See MASTER-PLAN §4.2, §5.1, §6.3.
    */
    'entra' => [
        // Expected audience: your custom API app-id URI, api://<app-id>. NOT Graph.
        'audience' => env('ENTRA_API_AUDIENCE'),
        'jwks_uri' => env('ENTRA_JWKS_URI', 'https://login.microsoftonline.com/common/discovery/v2.0/keys'),
        // v2.0 issuer template; {tid} is replaced with the token's tenant id.
        'issuer_template' => env('ENTRA_ISSUER_TEMPLATE', 'https://login.microsoftonline.com/{tid}/v2.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft Graph (Phase 2 zero-touch sync for Outlook)
    |--------------------------------------------------------------------------
    | App-only (client-credentials) access, consented per client org (Entra tid),
    | scoped by RBAC for Applications in Exchange Online. See MASTER-PLAN §4.7/§7.3.
    */
    'graph' => [
        // DEV/DEMO: use the in-process FakeGraphClient (no real Graph / app registration).
        'fake' => filter_var(env('GRAPH_FAKE', false), FILTER_VALIDATE_BOOL),
        'client_id' => env('GRAPH_CLIENT_ID'),
        'client_secret' => env('GRAPH_CLIENT_SECRET'),
        'base_url' => env('GRAPH_BASE_URL', 'https://graph.microsoft.com/v1.0'),
        'login_url' => env('GRAPH_LOGIN_URL', 'https://login.microsoftonline.com'),
        'token_ttl' => (int) env('GRAPH_TOKEN_TTL', 3000),
        // Public webhook URLs Graph posts to (on your server, e.g. https://odad.asia/...).
        'notification_url' => env('GRAPH_NOTIFICATION_URL'),
        'lifecycle_url' => env('GRAPH_LIFECYCLE_URL'),
        // Subscription lifetime (minutes). Message subscriptions cap at 10080 (~7 days);
        // we create shorter and renew daily. Clamped in code.
        'subscription_minutes' => (int) env('GRAPH_SUBSCRIPTION_MINUTES', 4320),
    ],

    'google' => [
        // OAuth client id the add-on's userIdToken is issued for (the `aud`).
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'jwks_uri' => env('GOOGLE_JWKS_URI', 'https://www.googleapis.com/oauth2/v3/certs'),
        'issuers' => array_values(array_filter(
            explode(',', (string) env('GOOGLE_ISSUERS', 'https://accounts.google.com,accounts.google.com'))
        )),
        // Service account email that signs Gmail Pub/Sub push OIDC tokens (Phase 2).
        'pubsub_service_account' => env('GOOGLE_PUBSUB_SERVICE_ACCOUNT'),
        // Expected audience of the Pub/Sub push OIDC token (the push endpoint URL).
        // Set explicitly rather than trusting the request URL behind a proxy.
        'pubsub_audience' => env('GOOGLE_PUBSUB_AUDIENCE'),
    ],

];
