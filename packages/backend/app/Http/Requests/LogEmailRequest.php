<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EmailDirection;
use App\Enums\MailProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/activities/email`. `internetMessageId` is nullable (empty at Outlook
 * OnMessageSend time); `syntheticKey` is always required as the send-time idempotency
 * fallback (MASTER-PLAN §7.2).
 */
class LogEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'internetMessageId' => ['nullable', 'string', 'max:998'],
            'syntheticKey' => ['required', 'string', 'max:128'],
            'subject' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string'],
            'bodyType' => ['nullable', Rule::in(['html', 'text'])],
            'from' => ['required', 'array'],
            'from.address' => ['required', 'string', 'email:rfc', 'max:320'],
            'from.name' => ['nullable', 'string', 'max:255'],
            // At least one recipient must exist across to/cc/bcc (see withValidator);
            // a Cc-only or Bcc-only send is valid and must still be loggable.
            'to' => ['nullable', 'array'],
            'to.*.address' => ['required', 'string', 'email:rfc', 'max:320'],
            'to.*.name' => ['nullable', 'string', 'max:255'],
            'cc' => ['nullable', 'array'],
            'cc.*.address' => ['required', 'string', 'email:rfc', 'max:320'],
            'cc.*.name' => ['nullable', 'string', 'max:255'],
            'bcc' => ['nullable', 'array'],
            'bcc.*.address' => ['required', 'string', 'email:rfc', 'max:320'],
            'bcc.*.name' => ['nullable', 'string', 'max:255'],
            'sentAt' => ['required', 'date'],
            'direction' => ['required', Rule::enum(EmailDirection::class)],
            'provider' => ['required', Rule::enum(MailProvider::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $count = count((array) $this->input('to', []))
                + count((array) $this->input('cc', []))
                + count((array) $this->input('bcc', []));

            if ($count < 1) {
                $validator->errors()->add('to', 'At least one recipient (to, cc, or bcc) is required.');
            }
        });
    }
}
