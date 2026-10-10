<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebitNoteTax extends Model
{
    protected $fillable = ['debit_note_id', 'type', 'rate', 'amount'];

    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(DebitNote::class);
    }
}
