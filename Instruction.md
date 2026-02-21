# Role & Context
You are an Expert Full-Stack Developer (Laravel + Vue.js) specializing in HR and Payroll systems. 
Your task is to build a "Latvian Payroll Vacation Simulator" (Mini-Project). 
This is a sandbox application to test and validate business logic, accruals, and financial formulas for employee vacations based on Latvian labor law. You have access to the legacy Firebird DB structure (`ALGA_db_structure.md`) and documentation — use them for domain naming, but design a clean, modern Laravel architecture.

# Project Scope & UI Layout
Create a Single Page Application (Vue frontend, Laravel API backend) with the following layout:
1. **Header (Employee Dashboard):** Displays the current state for one hardcoded/selected employee:
   - Name / Surname (Vārds, Uzvārds).
   - Accrued Vacation Balance (Atlikums) in Working Days (DD).
   - Current Calculated Average Daily Salary (Vidējā dienas izpeļņa).
2. **Document Event Stream (Main Body):** A chronological list of documents (events) that affect the employee. One row = one document.
3. **Action Panel:** Buttons to add new documents to the stream.

# Domain Models & Database (Keep it simple, focus on logic)
Create the following Eloquent Models and Migrations:

1. **Employee (`darbinieki`)**
   - `id`, `vards`, `uzvards`, `sakdatums` (Hire date).

2. **VacationConfig (`atvalin_ini`)**
   - `id`, `tip` (Code: 1 = Annual, 2 = Child Care, 3 = Unpaid/BK), `name`, `is_accruable` (boolean), `norm_days` (e.g., 20 DD or 28 KD).

3. **Document (`dokumenti`)**
   - This is the core transactional table.
   - `id`, `employee_id`, `type` (Enum: 'hire', 'salary_calculation', 'vacation', 'child_registration').
   - `date_from`, `date_to` (for vacations).
   - `days` (calculated duration).
   - `amount` (calculated money).
   - `payload` (JSON - for flexible data like gross salary amount, or number of children).

# Core Business Logic & Services (Latvian Formulas to Implement)
Please generate dedicated Laravel Services for these calculations:

1. **`AverageSalaryService`:**
   - Formula: Sum of Gross Salary (`amount` in 'salary_calculation' docs) for the **last 6 months** divided by the sum of worked days in those 6 months.

2. **`VacationBalanceService` (Accruals):**
   - Calculates earned days: From `sakdatums` (or last unpaid leave shift) to today. Norm is 20 working days (DD) per full year (or 1.666 days per month).
   - Subtracts used days: Sum of `days` from 'vacation' documents where `type` is Annual.

3. **`VacationPayCalculator` (Financials):**
   - When a 'vacation' document is added, automatically calculate the `amount`.
   - Formula: `Requested Working Days * Average Daily Salary` (from AverageSalaryService).

4. **`ChildExtraVacationService`:**
   - Evaluates 'child_registration' documents.
   - Rule: 1 extra paid day for 1-2 children under 14 years old. 3 extra paid days for 3+ children or a disabled child. 

# Task Requirements
1. Scaffold the Laravel Models, Migrations, and Services described above.
2. Provide the Vue component code (`VacationSimulator.vue`) that fetches the employee state and renders the Document list.
3. Include a form/modal in Vue to create new Documents (e.g., "Add Salary for March", "Request Annual Leave for 5 days").
4. Ensure when a new document is added, the API recalculates the Header stats (Balance and Average Salary) and returns them.
5. Use dummy data seeders for the Initial Employee and VacationConfigs.

# Vacation Policies (Configurator UI & Logic)
The user will provide visual references in the `Screens/` folder. Your task is to build a "Vacation Policies" module inspired by those screenshots. This module must allow dynamic configuration of rules for any absence type, directly replacing hardcoded logic.


# Other
1. **Policy Data Model Update (`atvalin_ini`)**
   Expand the configuration model to act as a Rule Engine. Add a `rules` JSON column (or create a related `atvalin_ini_detail` table) to store the policy flags:
   - `accrual_enabled` (boolean): Does this leave type accumulate a balance over time?
   - `accrual_rate` (float): E.g., 20 (days per year) or 1.666 (days per month).
   - `measure_unit` (enum): 'DD' (Darba dienas / Working days) or 'KD' (Kalendārās dienas / Calendar days).
   - `financial_formula` (enum): 
      - 'average_salary' (Vidējā izpeļņa - standard for annual leave).
      - 'base_salary' (Saglabāta mēnešalga - keeps the fixed monthly wage).
      - 'unpaid' (Bez atalgojuma - 0.00 amount).
   - `shifts_working_year` (boolean): If true (like for Unpaid Leave > 4 weeks), taking this leave postpones the employee's `sakdatums` (base date) for future annual leave calculations.

2. **Policy Configurator UI (Vue)**
   - Create a dedicated Vue route and component (e.g., `VacationPolicies.vue`).
   - Implement a form/dashboard where an admin can create a new Leave Type (e.g., "Study Leave", "Donor Day") and toggle the rules mentioned above.
   - Use UI elements (toggles, dropdowns for formulas, input fields for days) similar to the provided screenshots in the `Screens/` directory.

3. **Dynamic Service Integration**
   - Refactor the `VacationBalanceService` and `VacationPayCalculator` to read from the dynamic Policy rules instead of using hardcoded `if/else` statements. 
   - Example: When a user adds an event for "Study Leave", the system should check its policy: if `financial_formula === 'average_salary'`, it calls `AverageSalaryService`; if `unpaid`, the amount is automatically 0.