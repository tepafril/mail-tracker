<?php

declare(strict_types=1);

namespace App\Models\MockSmoh;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** DEV mock SMOH: a CRM.Account record. Not tenant-scoped. */
class MockAccount extends Model
{
    use HasUuids;

    protected $table = 'mock_accounts';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;
}
