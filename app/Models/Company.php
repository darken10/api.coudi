<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    /** @use HasFactory<\Database\Factories\CompanyFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'status',
        'address',
        'phone',
        'email',
        'website',
        'logo_uri',
    ];

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function diagnosticSetting()
    {
        return $this->hasOne(DiagnosticSetting::class);
    }
}
