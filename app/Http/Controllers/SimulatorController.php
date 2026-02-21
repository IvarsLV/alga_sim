<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Employee;
use App\Models\Document;
use App\Models\VacationConfig;
use App\Services\AverageSalaryService;
use App\Services\VacationBalanceService;
use App\Services\VacationPayCalculator;

class SimulatorController extends Controller
{
    public function index(
        AverageSalaryService $averageSalaryService, 
        VacationBalanceService $vacationBalanceService
    ) {
        $employee = Employee::first();
        
        if (!$employee) {
            return response('No employee found. Please seed the database.', 404);
        }

        $documents = Document::where('employee_id', $employee->id)->orderBy('date_from', 'desc')->get();
        $vacationConfigs = VacationConfig::all();
        
        $averageSalaryData = $averageSalaryService->calculateDailyAverageWithLog($employee);
        $vacationBalanceData = $vacationBalanceService->getBalanceWithLog($employee);
        
        $hasHireDoc = Document::where('employee_id', $employee->id)->where('type', 'hire')->exists();

        return Inertia::render('VacationSimulator', [
            'employee' => $employee,
            'documents' => $documents,
            'vacationConfigs' => $vacationConfigs,
            'hasHireDocument' => $hasHireDoc,
            'stats' => [
                'averageSalary' => $averageSalaryData['average'],
                'averageSalaryLog' => $averageSalaryData['log'],
                'vacationBalance' => $vacationBalanceData['balance'],
                'vacationBalanceDD' => $vacationBalanceData['balanceDD'] ?? 0,
                'vacationBalanceKD' => $vacationBalanceData['balanceKD'] ?? 0,
                'vacationBalanceLog' => $vacationBalanceData['log'],
                'vacationBalanceUnit' => $vacationBalanceData['unit'] ?? 'dienas',
            ]
        ]);
    }

    public function storeDocument(Request $request, VacationPayCalculator $vacationPayCalculator)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'type' => 'required|string',
            'date_from' => 'nullable|date|required_unless:type,child_registration',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'days' => 'nullable|numeric',
            'amount' => 'nullable|numeric',
            'payload' => 'nullable|array',
            'vacation_config_id' => 'nullable|exists:vacation_configs,id'
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        
        // Auto-calculate amount if it's a type of vacation
        if (!empty($validated['vacation_config_id'])) {
            $config = VacationConfig::find($validated['vacation_config_id']);
            $days = $validated['days'] ?: 1;
            
            $validated['amount'] = $vacationPayCalculator->calculateAmount(
                $employee, 
                $config, 
                $days,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null
            );
        }
        
        $payload = $request->input('payload', []);
        if (!empty($validated['vacation_config_id'])) {
            $payload['vacation_config_id'] = $validated['vacation_config_id'];
        }
        $validated['payload'] = $payload;

        Document::create($validated);

        return redirect()->back();
    }

    public function updateDocument(Request $request, Document $document, VacationPayCalculator $vacationPayCalculator)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'type' => 'required|string',
            'date_from' => 'nullable|date|required_unless:type,child_registration',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'days' => 'nullable|numeric',
            'amount' => 'nullable|numeric',
            'payload' => 'nullable|array',
            'vacation_config_id' => 'nullable|exists:vacation_configs,id'
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        
        // Auto-calculate amount if it's a type of vacation
        if (!empty($validated['vacation_config_id'])) {
            $config = VacationConfig::find($validated['vacation_config_id']);
            $days = $validated['days'] ?: 1;
            
            $validated['amount'] = $vacationPayCalculator->calculateAmount(
                $employee, 
                $config, 
                $days,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null
            );
        }
        
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
