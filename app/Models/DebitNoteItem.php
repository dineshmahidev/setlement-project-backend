<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DebitNoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'debit_note_id',
        'particulars',
        'amount',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function debitNote()
    {
        return $this->belongsTo(DebitNote::class);
    }
}
