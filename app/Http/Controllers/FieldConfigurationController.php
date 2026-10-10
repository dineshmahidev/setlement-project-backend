<?php

namespace App\Http\Controllers;

use App\Models\FieldConfiguration;
use App\Services\AuditService;
use Illuminate\Http\Request;

class FieldConfigurationController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $query = FieldConfiguration::orderBy('module')->orderBy('sort_order');
        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }
        return response()->json($query->get());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'fields' => ['required', 'array'],
            'fields.*.id' => ['required', 'exists:field_configurations,id'],
            'fields.*.visible' => ['sometimes', 'boolean'],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.mode' => ['sometimes', 'in:MANUAL,AUTOMATIC'],
            'fields.*.default_value' => ['nullable', 'string'],
            'fields.*.calculation_rule' => ['nullable', 'string'],
            'fields.*.sort_order' => ['sometimes', 'integer'],
        ]);

        $old = FieldConfiguration::whereIn('id', collect($data['fields'])->pluck('id'))
            ->get()->keyBy('id')->map(fn ($f) => $f->only(['visible', 'required', 'mode', 'calculation_rule']))->toArray();

        foreach ($data['fields'] as $row) {
            FieldConfiguration::where('id', $row['id'])->update(array_diff_key($row, ['id' => true]));
        }

        $new = FieldConfiguration::whereIn('id', collect($data['fields'])->pluck('id'))
            ->get()->keyBy('id')->map(fn ($f) => $f->only(['visible', 'required', 'mode', 'calculation_rule']))->toArray();

        $this->audit->log('UPDATE', 'FieldConfiguration', null, $old, $new);

        return response()->json(FieldConfiguration::orderBy('module')->orderBy('sort_order')->get());
    }
}
