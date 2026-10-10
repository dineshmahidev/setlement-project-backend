<?php

namespace App\Http\Controllers;

use App\Models\ChargeType;
use App\Models\CompanySetting;
use App\Models\FieldConfiguration;
use App\Models\SettlementBill;
use App\Services\SettlementBillService;
use App\Services\SettlementCalculationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class SettlementBillController extends Controller
{
    public function __construct(
        private SettlementBillService $service,
        private SettlementCalculationService $calculator,
    ) {}

    public function index(Request $request)
    {
        $query = SettlementBill::with(['vendor', 'branch', 'item', 'vehicle'])
            ->orderByDesc('bill_date')
            ->orderByDesc('id');

        $this->scopeBranches($query);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q
                ->where('bill_number', 'like', "%$s%")
                ->orWhere('lot_number', 'like', "%$s%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('bill_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('bill_date', '<=', $request->date_to);
        }

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['vendor_snapshot'] = $this->vendorSnapshot($request);
        $bill = $this->service->create($data, $request->user()->id);
        return response()->json($bill, 201);
    }

    public function show(SettlementBill $settlementBill)
    {
        return response()->json($settlementBill->load([
            'vendor', 'branch', 'item', 'vehicle', 'qc', 'charges', 'taxes', 'creator', 'approver',
        ]));
    }

    public function update(Request $request, SettlementBill $settlementBill)
    {
        $data = $this->validated($request, true);
        if ($request->has('vendor_id') || $request->has('vendor_snapshot')) {
            $data['vendor_snapshot'] = $this->vendorSnapshot($request);
        }
        $bill = $this->service->update($settlementBill, $data, $request->user()->id);
        return response()->json($bill);
    }

    public function submit(Request $request, SettlementBill $settlementBill)
    {
        if (! in_array($settlementBill->status, ['DRAFT'], true)) {
            return response()->json(['message' => 'Only Draft bills can be submitted'], 422);
        }
        $settlementBill->update(['status' => 'SUBMITTED']);
        return response()->json($settlementBill->fresh());
    }

    public function approve(Request $request, SettlementBill $settlementBill)
    {
        try {
            $bill = $this->service->approve($settlementBill, $request->user()->id);
            return response()->json($bill);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }

    public function cancel(Request $request, SettlementBill $settlementBill)
    {
        try {
            $bill = $this->service->cancel($settlementBill, $request->user()->id, $request->input('reason'));
            return response()->json($bill);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }

    public function calculate(Request $request)
    {
        $data = $request->validate([
            'net_weight' => ['nullable', 'numeric', 'min:0'],
            'final_weight' => ['nullable', 'numeric', 'min:0'],
            'manual_quantity' => ['nullable', 'numeric', 'min:0'],
            'jute_bag_quantity' => ['nullable', 'numeric', 'min:0'],
            'container_unit_rate' => ['nullable', 'numeric', 'min:0'],
            'final_unit_rate' => ['nullable', 'numeric', 'min:0'],
            'billing_quantity_source' => ['nullable', 'in:FINAL_WEIGHT,NET_WEIGHT,MANUAL_QUANTITY'],
            'rate_mode' => ['nullable', 'in:MANUAL,AUTOMATIC'],
            'rate_rule' => ['nullable', 'string', 'max:200'],
            'gst_type' => ['nullable', 'in:CGST_SGST,IGST,NONE'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'qc' => ['nullable', 'array'],
            'charges' => ['nullable', 'array'],
            'charges.*.name' => ['nullable', 'string', 'max:100'],
            'charges.*.code' => ['nullable', 'string', 'max:50'],
            'charges.*.charge_type_id' => ['nullable', 'integer'],
            'charges.*.mode' => ['nullable', 'in:MANUAL,AUTOMATIC'],
            'charges.*.direction' => ['nullable', 'in:ADD,DEDUCT'],
            'charges.*.amount' => ['nullable', 'numeric'],
            'charges.*.calculation_rule' => ['nullable', 'string', 'max:200'],
            'charges.*.active' => ['nullable', 'boolean'],
            'charges.*.sort_order' => ['nullable', 'integer'],
        ]);

        $qc = $request->input('qc', []);
        $qcValues = is_array($qc) ? collect($qc)->pluck('value', 'key')->all() : [];

        return response()->json($this->calculator->calculate($data, $qcValues));
    }

    public function pdf(SettlementBill $settlementBill)
    {
        $bill = $settlementBill->load([
            'vendor', 'branch', 'item', 'vehicle', 'qc', 'charges', 'taxes', 'creator', 'approver',
        ]);

        $settings = \App\Models\CompanySetting::pluck('value', 'key')->all();
        $visibleQc = FieldConfiguration::where('module', 'QC')->where('visible', true)->pluck('field_key')->all();
        $hasQcConfig = FieldConfiguration::where('module', 'QC')->exists();
        if ($hasQcConfig) {
            $bill->setRelation('qc', $bill->qc->filter(fn ($q) => in_array($q->key, $visibleQc, true))->values());
        }
        $bill->setRelation('charges', $bill->charges->filter(fn ($c) => $c->active)->values());

        $pdf = Pdf::loadView('pdf.settlement-bill', ['bill' => $bill, 'settings' => $settings])
            ->setPaper('a4', 'portrait');

        return $pdf->stream('settlement-'.str_replace(['/', '\\'], '-', $bill->bill_number).'.pdf');
    }

    private function validated(Request $request, bool $partial = false, bool $skipBillNumber = false): array
    {
        $uniqueBill = $request->settlementBill ? ','.$request->settlementBill->id : '';
        $rules = [
            'bill_number' => [$partial ? 'sometimes' : 'nullable', 'string', 'max:50', 'unique:settlement_bills,bill_number'.$uniqueBill],
            'bill_date' => ['required', 'date'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['required', 'exists:branches,id'],
            'vendor_id' => ['required', 'exists:vendors,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'item_id' => ['nullable', 'exists:items,id'],
            'po_number' => ['nullable', 'string', 'max:50'],
            'net_weight' => ['nullable', 'numeric', 'min:0'],
            'final_weight' => ['nullable', 'numeric', 'min:0'],
            'jute_bag_quantity' => ['nullable', 'numeric', 'min:0'],
            'container_unit_rate' => ['nullable', 'numeric', 'min:0'],
            'final_unit_rate' => ['nullable', 'numeric', 'min:0'],
            'manual_quantity' => ['nullable', 'numeric', 'min:0'],
            'billing_quantity_source' => ['nullable', 'in:FINAL_WEIGHT,NET_WEIGHT,MANUAL_QUANTITY'],
            'rate_mode' => ['nullable', 'in:MANUAL,AUTOMATIC'],
            'rate_rule' => ['nullable', 'string', 'max:200'],
            'gst_type' => ['nullable', 'in:CGST_SGST,IGST,NONE'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'post_to_inventory' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string'],
            'qc' => ['nullable', 'array'],
            'qc.*.name' => ['required_with:qc', 'string', 'max:100'],
            'qc.*.key' => ['nullable', 'string', 'max:100'],
            'qc.*.value' => ['nullable', 'string', 'max:200'],
            'qc.*.unit' => ['nullable', 'string', 'max:50'],
            'qc.*.remarks' => ['nullable', 'string', 'max:500'],
            'qc.*.active' => ['nullable', 'boolean'],
            'charges' => ['nullable', 'array'],
            'charges.*.name' => ['required_with:charges', 'string', 'max:100'],
            'charges.*.code' => ['nullable', 'string', 'max:50'],
            'charges.*.charge_type_id' => ['nullable', 'exists:charge_types,id'],
            'charges.*.mode' => ['nullable', 'in:MANUAL,AUTOMATIC'],
            'charges.*.direction' => ['nullable', 'in:ADD,DEDUCT'],
            'charges.*.amount' => ['nullable', 'numeric'],
            'charges.*.calculation_rule' => ['nullable', 'string', 'max:200'],
            'charges.*.active' => ['nullable', 'boolean'],
            'charges.*.sort_order' => ['nullable', 'integer'],
        ];

        if ($skipBillNumber) {
            unset($rules['bill_number']);
        }

        return $request->validate($rules);
    }

    private function vendorSnapshot(Request $request): ?string
    {
        if ($request->has('vendor_snapshot') && is_array($request->vendor_snapshot)) {
            return json_encode($request->vendor_snapshot);
        }
        if ($request->filled('vendor_id')) {
            $v = \App\Models\Vendor::find($request->vendor_id);
            if ($v) {
                return json_encode($v->only([
                    'code', 'name', 'address', 'city', 'district', 'state',
                    'pincode', 'gst_number', 'phone', 'email', 'pan', 'po_number',
                ]));
            }
        }
        return null;
    }
}
