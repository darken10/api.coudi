<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    /** @use HasFactory<\Database\Factories\CompanyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
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
}
