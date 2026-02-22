<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Employee;
use App\Models\Document;
use App\Models\VacationConfig;
use App\Services\LeaveAccrualService;

class SimulatorController extends Controller
{
    public function index(LeaveAccrualService $leaveAccrualService)
    {
        $employee = Employee::first();
        
        if (!$employee) {
            return response('No employee found. Please seed the database.', 404);
        }

        $documents = Document::where('employee_id', $employee->id)->orderBy('date_from', 'desc')->get();
        $vacationConfigs = VacationConfig::all();
        
        // Calculate all leave balances
        $leaveData = $leaveAccrualService->calculateAll($employee);

        // Apply FIFO for each type
        foreach ($leaveData as $configId => $data) {
            $leaveAccrualService->applyFifo($employee, $configId);
        }

        // Build balance table for frontend
        $balanceTable = [];
        foreach ($leaveData as $configId => $data) {
            $balanceTable[] = [
                'config_id' => $configId,
                'config_name' => $data['config']->name,
                'config_tip' => $data['config']->tip,
                'description' => $data['config']->description,
                'accrued' => $data['accrued'],
                'used' => $data['used'],
                'balance_dd' => $data['balance'],
                'balance_kd' => $data['balance_kd'],
                'transactions' => $data['transactions'],
                'algorithm' => $data['algorithm'],
                'rules' => is_string($data['config']->rules) 
                    ? json_decode($data['config']->rules, true) 
                    : ($data['config']->rules ?? []),
            ];
        }

        $hasHireDoc = Document::where('employee_id', $employee->id)->where('type', 'hire')->exists();

        return Inertia::render('VacationSimulator', [
            'employee' => $employee,
            'documents' => $documents,
            'vacationConfigs' => $vacationConfigs,
            'hasHireDocument' => $hasHireDoc,
            'balanceTable' => $balanceTable,
        ]);
    }

    public function storeDocument(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'type' => 'required|string',
            'date_from' => 'nullable|date|required_unless:type,child_registration',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'days' => 'nullable|numeric',
            'payload' => 'nullable|array',
            'vacation_config_id' => 'nullable|exists:vacation_configs,id'
        ]);

        $payload = $request->input('payload', []);
        if (!empty($validated['vacation_config_id'])) {
            $payload['vacation_config_id'] = $validated['vacation_config_id'];
        }
        $validated['payload'] = $payload;

        Document::create($validated);

        return redirect()->back();
    }

    public function updateDocument(Request $request, Document $document)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'type' => 'required|string',
            'date_from' => 'nullable|date|required_unless:type,child_registration',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'days' => 'nullable|numeric',
            'payload' => 'nullable|array',
            'vacation_config_id' => 'nullable|exists:vacation_configs,id'
        ]);

        $payload = $request->input('payload', []);
        if (!empty($validated['vacation_config_id'])) {
            $payload['vacation_config_id'] = $validated['vacation_config_id'];
        }
        $validated['payload'] = $payload;

        $document->update($validated);

        return redirect()->back();
    }

    public function destroyDocument(Document $document)
    {
        $document->delete();
        return redirect()->back();
    }
}
