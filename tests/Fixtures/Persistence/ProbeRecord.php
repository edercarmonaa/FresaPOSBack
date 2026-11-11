<?php

namespace Tests\Fixtures\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ProbeRecord extends Model
{
    use HasUlids;

    protected $connection = 'sqlsrv_testing';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'occurred_at' => 'immutable_datetime'];
    }
}
