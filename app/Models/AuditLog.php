<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_name',
        'user_role',
        'action',
        'entity_type',
        'entity_id',
        'document_number',
        'old_values',
        'new_values',
        'remarks',
        'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function logAction(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $documentNumber = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $remarks = null
    ): self {
        $user = auth()->user();

        return self::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'System',
            'user_role' => $user?->role?->label ?? 'System',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'document_number' => $documentNumber,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'remarks' => $remarks,
            'ip_address' => request()?->ip(),
        ]);
    }
}
