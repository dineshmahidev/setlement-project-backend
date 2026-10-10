<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorContact extends Model
{
    protected $fillable = ['vendor_id', 'name', 'phone', 'email', 'designation'];
}
