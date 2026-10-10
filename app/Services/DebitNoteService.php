<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\DebitNote;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class DebitNoteService
{
    public function __construct(
        private SettlementCalculationService $calculator,
        private AuditService $audit,
    ) {}

    public function create(array $data, int $userId): DebitNote
    {
        return DB::transaction(function () use ($data, $userId) {
            $rebate = (float) ($data['rebate'] ?? 0);
            $invoiceValue = (float) ($data['invoice_value'] ?? 0);
            $base = round($invoiceValue - $rebate, 2);

            $gstType = strtoupper($data['gst_type'] ?? 'NONE');
            if (! in_array($gstType, ['CGST_SGST', 'IGST', 'NONE'], true)) {
                $gstType = 'NONE';
            }
            $gstRate = (float) ($data['gst_rate'] ?? 0);

            // Spec §15: GST follows mutually exclusive modes; base = invoice - rebate
            $gst = $this->calculator->calculateGst($gstType, $base, $gstRate);
            $total = round($base + $gst['cgst_amount'] + $gst['sgst_amount'] + $gst['igst_amount'], 2);

            $note = DebitNote::create(array_merge($data, [
                'number' => $data['number'] ?? $this->nextNumber(),
                'gst_type' => $gstType,
                'gst_rate' => $gstRate,
                'cgst_amount' => $gst['cgst_amount'],
                'sgst_amount' => $gst['sgst_amount'],
                'igst_amount' => $gst['igst_amount'],
                'total' => $total,
                'status' => 'DRAFT',
                'created_by' => $userId,
            ]));

            foreach ($gst['taxes'] as $t) {
                $note->taxes()->create($t);
            }

            $this->audit->log('CREATE', 'DebitNote', $note->id, null, $note->only(['number', 'total', 'status']));

            return $note->load('taxes');
        });
    }

    public function approve(DebitNote $note, int $userId): DebitNote
    {
        if ($note->status === 'APPROVED') return $note;
        if (! in_array($note->status, ['DRAFT'], true)) {
            throw new \RuntimeException('Only Draft debit notes can be approved.');
        }
        $note->update(['status' => 'APPROVED', 'approved_by' => $userId, 'approved_at' => now()]);
        $this->audit->log('APPROVE', 'DebitNote', $note->id, ['status' => 'DRAFT'], ['status' => 'APPROVED']);
        return $note->fresh();
    }

    public function cancel(DebitNote $note, int $userId): DebitNote
    {
        $old = ['status' => $note->status];
        $note->update(['status' => 'CANCELLED']);
        $this->audit->log('CANCEL', 'DebitNote', $note->id, $old, ['status' => 'CANCELLED']);
        return $note->fresh();
    }

    public function nextNumber(): string
    {
        $prefix = CompanySetting::getValue('debit_note_prefix', 'DN');
        $fy = $this->financialYear();
        $last = DebitNote::where('number', 'LIKE', $prefix.'/'.$fy.'/%')->orderByDesc('id')->value('number');
        $seq = $last ? ((int) substr($last, strrpos($last, '/') + 1)) + 1 : 1;
        return sprintf('%s/%s/%04d', $prefix, $fy, $seq);
    }

    private function financialYear(): string
    {
        $y = (int) date('Y');
        return date('m') >= '04' ? $y.'-'.($y + 1) : ($y - 1).'-'.$y;
    }
}
