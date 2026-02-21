# Role & Context
You are an Expert Full-Stack Developer (Laravel 11 + Vue.js 3 + Inertia) specializing in HR and Payroll systems. 
Your task is to maintain and expand the "Latvian Payroll Vacation Simulator" (Mini-Project). 
This is a sandbox application designed to test and validate business logic, accruals, and financial formulas for employee vacations based on Latvian labor law. 

# Project Status & Implemented Features
The core system is implemented as a Single Page Application using **Laravel Breeze (Inertia.js + Vue 3 + Tailwind CSS)**.

## UI Layout (Frontend)
1. **VacationSimulator.vue (Main Dashboard):**
   - **Header Cards:** Displays Employee Info, Accrued Vacation Balance, and Average Daily Salary.
     - *Feature*: Calculates start date (`sakdatums`) dynamically based on 'Pieņemšana darbā' documents. Hides the date with a warning if no hire document exists.
     - *Feature*: Includes **Calculation History Modals** (info icons) showing step-by-step calculation logs for balances and average salary rules.
   - **Action Panel:** A Vue form to register new documents (vacations, salary, hiring, child registration).
   - **Document Event Stream:** A chronological list of all registered documents affecting the employee.
     - *Feature*: Allows direct Editing and Deletion of documents via Inertia routes, utilizing custom UI Modals for delete confirmations.

2. **VacationPolicies.vue (Configurator):**
   - A dashboard allowing CRUD operations on Vacation Types (`VacationConfig`).
   - Managers can configure JSON rules (`is_accruable`, `financial_formula`, `shifts_working_year`) and add extensive legal `description` fields for compliance.

## Domain Models (Backend)
1. **Employee (`darbinieki`)**
   - Base attributes: `id`, `vards`, `uzvards`, `sakdatums`, `amats`, `nodala`, `alga`.

2. **VacationConfig (`atvalin_ini`)**
   - Configures absence types. Includes 9 pre-seeded default Latvian vacation types.
   - `id`, `tip`, `name`, `description` (Detailed law references), `is_accruable`, `norm_days`, `rules` (JSON).

3. **Document (`dokumenti`)**
   - Core transactional event log.
   - `id`, `employee_id`, `type` (Enum: 'hire', 'salary_calculation', 'vacation', 'child_registration').
   - `date_from`, `date_to`, `days`, `amount`.
   - `payload` (JSON) - stores related configuration IDs and auxiliary data (e.g., child DOB).

## Core Business Logic & Services
The backend relies on dedicated Laravel Services returning structured data with calculation logs for UI transparency:

1. **`AverageSalaryService`:**
   - Evaluates the preceding 6 full months of `salary_calculation` documents.
   - Accurately returns `0.00` if no salary documents exist, preventing false fallback averages.
   - Returns array: `['average' => float, 'log' => array]`.

2. **`VacationBalanceService` (Accruals):**
   - Dynamically calculates base accrual starting strictly from the first 'hire' document date.
   - Evaluates base accrual (`base * months_worked`).
   - Checks `ChildExtraVacationService` for additional days added per full working year.
   - Triggers `shifts_working_year` for extended unpaid leaves > 28 days.
   - Subtracts days used by 'vacation' documents linked to 'accruable' Configs.
   - Returns array: `['balance' => float, 'log' => array]`.

3. **`VacationPayCalculator`:**
   - Calculates the financial `amount` of a 'vacation' document on creation/update.
   - Parses the JSON `financial_formula` rule inside `VacationConfig` ('average_salary', 'base_salary', 'unpaid').

4. **`ChildExtraVacationService`:**
   - Processes 'child_registration' payload data to grant 1 or 3 extra vacation days based on child count and disability status.