<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MailProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/auth/exchange` — a client presents its provider token. Public endpoint
 * (it mints the first-party token), so it does no auth of its own beyond validation.
 */
class ExchangeTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(MailProvider::class)],
            // Accept any of these field names; `token` is canonical.
            'token' => ['required_without_all:id_token,access_token', 'string'],
            'id_token' => ['required_without_all:token,access_token', 'string'],
            'access_token' => ['required_without_all:token,id_token', 'string'],
        ];
    }

    public function provider(): MailProvider
    {
        return MailProvider::from($this->string('provider')->value());
    }

    public function token(): string
    {
        return (string) ($this->input('token') ?? $this->input('access_token') ?? $this->input('id_token'));
    }
}
