<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'smoh_base_url' => 'https://'.fake()->domainWord().'.smoh.test',
            'smoh_auth_username' => 'svc-'.fake()->userName(),
            'smoh_auth_password' => Str::random(24),
            'smoh_email_activity_set' => null,
            'is_active' => true,
        ];
    }
}
