<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\EmployeeRequest;
use App\Models\Branch;
use App\Models\PosCounter;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\PlanLimitService;
use App\Support\LimitRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Staff accounts (#50). Viewing needs `employees.view`; every write needs
 * `employees.manage` — both enforced on the routes and re-asserted in the form
 * request, so neither layer is load-bearing on its own.
 *
 * The screen shows the employee quota meter because adding staff is one of the
 * quotas tenants actually hit (#78).
 */
class EmployeeController extends Controller
{
    public function __construct(
        protected EmployeeService $employees,
        protected PlanLimitService $limits,
    ) {}

    public function index(): View
    {
        return view('app.employees.index', [
            'employees' => User::query()
                ->with(['role', 'branch', 'posCounter'])
                ->orderByDesc('is_business_owner')
                ->orderBy('name')
                ->get(),
            'meter' => $this->limits->meter(LimitRegistry::EMPLOYEES),
        ]);
    }

    public function create(): View
    {
        return view('app.employees.create', $this->formData(new User));
    }

    public function store(EmployeeRequest $request): RedirectResponse
    {
        $employee = $this->employees->create($request->employeeAttributes());

        return redirect()
            ->route('app.employees.index')
            ->with('success', "\"{$employee->name}\" added.");
    }

    public function edit(User $employee): View
    {
        return view('app.employees.edit', $this->formData($employee));
    }

    public function update(EmployeeRequest $request, User $employee): RedirectResponse
    {
        $this->employees->update($employee, $request->employeeAttributes(), $request->user());

        return redirect()
            ->route('app.employees.index')
            ->with('success', "\"{$employee->name}\" updated.");
    }

    public function toggle(Request $request, User $employee): RedirectResponse
    {
        if ($employee->isOwner() || $request->user()->id === $employee->id) {
            return back()->with('error', $employee->isOwner()
                ? 'The business owner cannot be deactivated.'
                : 'You cannot deactivate your own account.');
        }

        $this->employees->setActive($employee, ! $employee->is_active, $request->user());

        return back()->with('success', "\"{$employee->name}\" ".($employee->is_active ? 'reactivated' : 'deactivated').'.');
    }

    /**
     * Operator-style password reset for a member of staff: a new password is
     * generated and shown once. No email is involved — the manager is standing
     * next to them.
     */
    public function resetPassword(Request $request, User $employee): RedirectResponse
    {
        if ($employee->isOwner()) {
            return back()->with('error', 'Use "Forgot password" for the owner account.');
        }

        $password = Str::password(14);
        $this->employees->resetPassword($employee, $password);

        return back()
            ->with('success', "New password for {$employee->name}: {$password}")
            ->with('warning', 'Copy it now — it is not stored anywhere in readable form.');
    }

    public function destroy(Request $request, User $employee): RedirectResponse
    {
        if (! $this->employees->delete($employee, $request->user())) {
            return back()->with('error', $employee->isOwner()
                ? 'The business owner cannot be removed.'
                : 'You cannot remove your own account.');
        }

        return redirect()
            ->route('app.employees.index')
            ->with('success', 'Employee removed.');
    }

    /** @return array<string, mixed> */
    protected function formData(User $employee): array
    {
        return [
            'employee' => $employee,
            'roles' => Role::query()->ordered()->get(['id', 'name', 'is_system']),
            'branches' => Branch::query()->accessible()->active()->ordered()->get(['id', 'name', 'code']),
            // Counters are already branch-scoped by BelongsToBranch, so a manager
            // is only ever offered tills they could actually staff (#48).
            'counters' => PosCounter::query()->active()->with('branch')->orderBy('name')->get(),
        ];
    }
}
