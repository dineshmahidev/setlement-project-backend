<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'company_name',
        'phone',
        'email',
        'requirement',
        'message',
        'source',
        'status',
        'notes',
        'contacted_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'contacted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
