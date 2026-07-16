<?php

declare(strict_types=1);

namespace App\Models\MockSmoh;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** DEV mock SMOH: a CRM.Lead record. Not tenant-scoped. */
class MockLead extends Model
{
    use HasUuids;

    protected $table = 'mock_leads';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;
}
