<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionBatch extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'batch_number', 'company_id', 'party_id', 'source_branch_id', 'dest_branch_id',
        'date', 'output_item_id', 'output_grade_id', 'output_lot_number',
        'output_oil_pct', 'input_total', 'output_total', 'wastage',
        'status', 'notes', 'created_by', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'output_oil_pct' => 'decimal:2',
            'input_total' => 'decimal:3',
            'output_total' => 'decimal:3',
            'wastage' => 'decimal:3',
            'confirmed_at' => 'datetime',
        ];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function party() { return $this->belongsTo(Vendor::class, 'party_id'); }
    public function sourceBranch() { return $this->belongsTo(Branch::class, 'source_branch_id'); }
    public function destBranch() { return $this->belongsTo(Branch::class, 'dest_branch_id'); }
    public function outputItem() { return $this->belongsTo(Item::class, 'output_item_id'); }
    public function outputGrade() { return $this->belongsTo(Grade::class, 'output_grade_id'); }
    public function inputs() { return $this->hasMany(ProductionInput::class, 'batch_id'); }
    public function outputs() { return $this->hasMany(ProductionOutput::class, 'batch_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function confirmer() { return $this->belongsTo(User::class, 'confirmed_by'); }
}
