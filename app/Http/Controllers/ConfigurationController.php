<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\VacationConfig;

class ConfigurationController extends Controller
{
    public function index()
    {
        $configs = VacationConfig::all();

        return Inertia::render('VacationPolicies', [
            'configs' => $configs
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tip' => 'required|integer',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_accruable' => 'required|boolean',
            'norm_days' => 'nullable|numeric',
            'rules' => 'required|array',
        ]);

        VacationConfig::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, VacationConfig $vacationConfig)
    {
        $validated = $request->validate([
            'tip' => 'required|integer',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_accruable' => 'required|boolean',
            'norm_days' => 'nullable|numeric',
            'rules' => 'required|array',
        ]);

        $vacationConfig->update($validated);

        return redirect()->back();
    }
}
