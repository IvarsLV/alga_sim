<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Document;
use Carbon\Carbon;

class ChildExtraVacationService
{
    /**
     * Calculates extra paid vacation days based on registered children.
     * Rule: 1 extra paid day for 1-2 children under 14 years old.
     *       3 extra paid days for 3+ children or a disabled child.
     *
     * @param Employee $employee
     * @return int
     */
    public function getExtraDays(Employee $employee): int
    {
        $childDocs = Document::where('employee_id', $employee->id)
            ->where('type', 'child_registration')
            ->get();

        $validChildrenCount = 0;
        $hasDisabledChild = false;

        foreach ($childDocs as $doc) {
            $payload = is_string($doc->payload) ? json_decode($doc->payload, true) : $doc->payload;
            
            $dob = isset($payload['child_dob']) ? Carbon::parse($payload['child_dob']) : null;
            $isDisabled = $payload['is_disabled'] ?? false;
            
            if ($isDisabled) {
                $hasDisabledChild = true;
            }

            if ($dob && $dob->age < 14) {
                $validChildrenCount++;
            }
        }

        if ($hasDisabledChild || $validChildrenCount >= 3) {
            return 3;
        }

        if ($validChildrenCount >= 1) {
            return 1;
        }

        return 0;
    }
}
