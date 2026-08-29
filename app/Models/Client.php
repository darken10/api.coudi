<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    /** @use HasFactory<\Database\Factories\ClientFactory> */
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'notes',
        'photo_uri',
        'birth_date',
        'event_date',
        'event_label',
        'is_vip',
        'is_active',
    ];

    protected $casts = [
        'is_vip' => 'boolean',
        'is_active' => 'boolean',
        'birth_date' => 'date',
        'event_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

}
