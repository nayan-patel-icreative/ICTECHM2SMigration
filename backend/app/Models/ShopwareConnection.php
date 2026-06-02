<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopwareConnection extends Model
{
    protected $fillable = [
        'shop_id',
        'api_url',
        'client_id',
        'client_secret',
        'access_token',
        'token_expires_at',
        'language_config',
        'sales_channel_id',
        'sales_channel_name',
        'navigation_category_id',
    ];

    protected $casts = [
        'client_id' => 'encrypted',
        'client_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'language_config' => 'array',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
