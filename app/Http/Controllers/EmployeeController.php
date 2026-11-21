<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Data Karyawan + akun login (hanya Admin Master). */
class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status', 'aktif');
        $q = Employee::query()->with('user')
            ->when($status === 'dihapus', fn ($q) => $q->onlyTrashed())
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = '%'.trim($request->input('q')).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('code', 'like', $s)->orWhere('id_card', 'like', $s)
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', $s)));
            })
            ->when($request->filled('role'), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('role', $request->input('role'))))
            ->when($status === 'aktif', fn ($q) => $q->where(fn ($w) => $w->whereDoesntHave('user')->orWhereHas('user', fn ($u) => $u->where('is_active', true))))
            ->when($status === 'nonaktif', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('is_active', false)))
            ->orderByRaw('code IS NULL')->orderBy('code')->orderBy('name');

        return view('employees.index', [
            'employees' => $q->paginate(30)->withQueryString(),
            'status' => $status,
            'counts' => User::query()->selectRaw('role, COUNT(*) c')->where('is_active', true)->groupBy('role')->pluck('c', 'role'),
        ]);
    }

    public function create()
    {
        return view('employees.form', ['employee' => new Employee, 'user' => null]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        DB::transaction(function () use ($data, $request) {
            $employee = Employee::create($data['employee']);
            if ($request->boolean('create_account')) {
                User::create($data['account'] + [
                    'name' => $employee->name, 'employee_id' => $employee->id, 'email' => $employee->email,
                    'is_active' => true, 'must_change_password' => true,
                ]);
            }
        });

        return redirect()->route('employees.index')->with('success', 'Karyawan '.$data['employee']['name'].' ditambahkan.');
    }

    public function edit(Employee $employee)
    {
        $employee->load('user');
        $logs = ActivityLog::query()
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', 'employee')->where('subject_id', $employee->id))
                ->when($employee->user, fn ($w) => $w->orWhere(fn ($x) => $x->where('subject_type', 'user')->where('subject_id', $employee->user->id))))
            ->latest('id')->limit(30)->get();

        return view('employees.form', ['employee' => $employee, 'user' => $employee->user, 'logs' => $logs]);
    }

    public function update(Request $request, Employee $employee)
    {
        $user = $employee->user;
        $data = $this->validated($request, $employee);
        $me = $request->user();

        if ($user && $user->id === $me->id && (($data['account']['role'] ?? 'master') !== 'master' || ! $request->boolean('is_active'))) {
            return back()->withInput()->with('error', 'Anda tidak bisa menurunkan role atau menonaktifkan akun Anda sendiri.');
        }

        DB::transaction(function () use ($employee, $user, $data, $request) {
            $employee->update($data['employee']);

            if ($user) {
                $attrs = [
                    'name' => $employee->name,
                    'username' => $data['account']['username'],
                    'role' => $data['account']['role'],
                    'is_active' => $request->boolean('is_active'),
                ];
                if (! empty($data['account']['password'])) {
                    $attrs['password'] = $data['account']['password'];
                    $attrs['must_change_password'] = true;
                }
                $user->update($attrs);
            } elseif ($request->boolean('create_account')) {
                User::create($data['account'] + [
                    'name' => $employee->name, 'employee_id' => $employee->id, 'email' => $employee->email,
                    'is_active' => true, 'must_change_password' => true,
                ]);
            }
        });

        return redirect()->route('employees.index')->with('success', 'Data '.$employee->name.' disimpan.');
    }

    /** Soft delete: karyawan disembunyikan & akunnya dinonaktifkan, riwayatnya tetap ada. */
    public function destroy(Request $request, Employee $employee)
    {
        if ($employee->user?->id === $request->user()->id) {
            return back()->with('error', 'Anda tidak bisa menghapus akun Anda sendiri.');
        }
        DB::transaction(function () use ($employee) {
            $employee->user?->update(['is_active' => false]);
            $employee->delete();
        });

        return redirect()->route('employees.index')->with('success', $employee->name.' dihapus (masih bisa dipulihkan dari filter "Dihapus").');
    }

    public function restore(Employee $employee)
    {
        $employee->restore();

        return redirect()->route('employees.edit', $employee)->with('success', $employee->name.' dipulihkan. Aktifkan akunnya jika perlu.');
    }

    /** Kartu ringkas karyawan (muncul saat nama pengguna diklik). */
    public function card(Employee $employee)
    {
        $user = $employee->user;

        return response()->json([
            'name' => $employee->name,
            'code' => $employee->code,
            'id_card' => $employee->id_card,
            'gender' => $employee->genderLabel(),
            'position' => $employee->position,
            'email' => $employee->email,
            'deleted' => $employee->trashed(),
            'username' => $user?->username,
            'role' => $user?->roleLabel(),
            'active' => (bool) $user?->is_active,
            'last_login' => $user?->last_login_at?->format('d/m/Y H:i'),
            'stats' => $user ? [
                'Form Produksi' => DB::table('production_forms')->where('user_id', $user->id)->whereNull('deleted_at')->count(),
                'Form Evaluasi' => DB::table('evaluation_forms')->where('user_id', $user->id)->whereNull('deleted_at')->count(),
                'Import data' => DB::table('import_batches')->where('user_id', $user->id)->where('status', 'done')->count(),
                'Aktivitas tercatat' => DB::table('activity_logs')->where('user_id', $user->id)->count(),
            ] : [],
            'edit_url' => request()->user()->isMaster() ? route('employees.edit', $employee) : null,
        ]);
    }

    private function validated(Request $request, ?Employee $employee): array
    {
        $userId = $employee?->user?->id;
        $needsAccount = $userId || $request->boolean('create_account');

        $v = $request->validate([
            'code' => ['nullable', 'string', 'max:20', Rule::unique('employees', 'code')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:100'],
            'id_card' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'id_card')->ignore($employee?->id)],
            'gender' => ['nullable', Rule::in(['L', 'P'])],
            'position' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:150'],
            'username' => [$needsAccount ? 'required' : 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($userId)],
            'role' => [$needsAccount ? 'required' : 'nullable', Rule::in(array_keys(User::ROLES))],
            'password' => [$userId ? 'nullable' : ($needsAccount ? 'required' : 'nullable'), 'string', 'min:8'],
        ], [
            'username.regex' => 'Username hanya huruf, angka, titik, strip, atau garis bawah (tanpa spasi).',
            'code.unique' => 'Kode sudah dipakai karyawan lain.',
            'id_card.unique' => 'ID card sudah dipakai karyawan lain.',
            'username.unique' => 'Username sudah dipakai.',
        ]);

        $code = ! empty($v['code']) ? strtoupper(preg_replace('/\s+/', ' ', trim($v['code']))) : null;

        return [
            'employee' => [
                'code' => $code, 'name' => trim($v['name']), 'id_card' => ($v['id_card'] ?? null) ?: null, 'gender' => ($v['gender'] ?? null) ?: null,
                'position' => ($v['position'] ?? null) ?: null, 'email' => ($v['email'] ?? null) ?: null,
            ],
            'account' => array_filter([
                'username' => $v['username'] ?? null, 'role' => $v['role'] ?? null, 'password' => $v['password'] ?? null,
            ], fn ($x) => $x !== null && $x !== ''),
        ];
    }
}
