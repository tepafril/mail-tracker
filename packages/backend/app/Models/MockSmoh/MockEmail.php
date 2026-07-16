<?php

declare(strict_types=1);

namespace App\Models\MockSmoh;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** DEV mock SMOH: a CRM.Email activity record. Not tenant-scoped. */
class MockEmail extends Model
{
    use HasUuids;

    protected $table = 'mock_emails';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;
}
