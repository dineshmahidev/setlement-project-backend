<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'fy_code',
        'start_date',
        'end_date',
        'is_current',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public static function getFinancialYearForDate($date = null): string
    {
        $d = $date ? Carbon::parse($date) : Carbon::now();
        $year = $d->year;
        // Indian FY: April 1 to March 31
        if ($d->month >= 4) {
            $nextYearShort = substr((string)($year + 1), -2);
            return "{$year}-{$nextYearShort}";
        } else {
            $prevYear = $year - 1;
            $yearShort = substr((string)$year, -2);
            return "{$prevYear}-{$yearShort}";
        }
    }
}
