<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MailProvider;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** Default: an Outlook (Entra) user. */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => MailProvider::Outlook,
            'entra_oid' => (string) Str::uuid(),
            'entra_tid' => (string) Str::uuid(),
            'google_sub' => null,
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
        ];
    }

    /** A Gmail (Google) user instead. */
    public function gmail(): static
    {
        return $this->state(fn () => [
            'provider' => MailProvider::Gmail,
            'entra_oid' => null,
            'entra_tid' => null,
            'google_sub' => (string) Str::uuid(),
        ]);
    }
}
