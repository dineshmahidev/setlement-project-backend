<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebsiteVisit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'page_url',
        'page_title',
        'referrer',
        'ip_address',
        'user_agent',
        'device_type',
        'browser',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
