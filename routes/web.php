<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EvaluationFormController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductionFormController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware(['auth', 'role'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Semua role
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');
    Route::get('/dashboard/downtime-detail', [DashboardController::class, 'downtimeDetail'])->name('dashboard.downtime-detail');
    Route::get('/dashboard/trend', [DashboardController::class, 'trend'])->name('dashboard.trend');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::get('/employees/{employee}/card', [EmployeeController::class, 'card'])->name('employees.card')->withTrashed();

    // Admin Produksi: import, export, tabel data
    Route::middleware('role:produksi')->group(function () {
        Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/template', [ImportController::class, 'template'])->name('imports.template');
        Route::post('/imports/delete-group', [ImportController::class, 'destroyGroup'])->name('imports.destroy-group');
        Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('/imports/{import}/commit', [ImportController::class, 'commit'])->name('imports.commit');
        Route::post('/imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
        Route::get('/orders/{orderNo}', [OrderController::class, 'show'])->name('orders.show');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    });

    // Monitoring Karu (pindahan dari dashboard): Admin Produksi & Admin Master
    Route::middleware('role:produksi')->group(function () {
        Route::get('/forms/monitoring', [DashboardController::class, 'karu'])->name('forms.monitoring');
        Route::get('/forms/monitoring/data', [DashboardController::class, 'karuData'])->name('forms.monitoring.data');
    });

    // Form Karu: Karu mengisi; Admin Produksi melihat
    Route::middleware('role:karu,produksi')->group(function () {
        Route::get('/forms/produksi', [ProductionFormController::class, 'index'])->name('forms.production.index');
        Route::get('/forms/evaluasi', [EvaluationFormController::class, 'index'])->name('forms.evaluation.index');
        Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    });
    Route::middleware('role:karu')->group(function () {
        Route::get('/forms/produksi/create', [ProductionFormController::class, 'create'])->name('forms.production.create');
        Route::post('/forms/produksi', [ProductionFormController::class, 'store'])->name('forms.production.store');
        Route::get('/forms/produksi/{form}/edit', [ProductionFormController::class, 'edit'])->name('forms.production.edit');
        Route::put('/forms/produksi/{form}', [ProductionFormController::class, 'update'])->name('forms.production.update');
        Route::delete('/forms/produksi/{form}', [ProductionFormController::class, 'destroy'])->name('forms.production.destroy');

        Route::get('/forms/evaluasi/create', [EvaluationFormController::class, 'create'])->name('forms.evaluation.create');
        Route::post('/forms/evaluasi', [EvaluationFormController::class, 'store'])->name('forms.evaluation.store');
        Route::get('/forms/evaluasi/{form}/edit', [EvaluationFormController::class, 'edit'])->name('forms.evaluation.edit');
        Route::put('/forms/evaluasi/{form}', [EvaluationFormController::class, 'update'])->name('forms.evaluation.update');
        Route::delete('/forms/evaluasi/{form}', [EvaluationFormController::class, 'destroy'])->name('forms.evaluation.destroy');

        Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    });
    Route::middleware('role:karu,produksi')->group(function () {
        Route::get('/forms/produksi/{form}', [ProductionFormController::class, 'show'])->name('forms.production.show')->withTrashed();
        Route::get('/forms/evaluasi/{form}/export', [EvaluationFormController::class, 'export'])->name('forms.evaluation.export')->withTrashed();
        Route::get('/forms/evaluasi/{form}', [EvaluationFormController::class, 'show'])->name('forms.evaluation.show')->withTrashed();
    });

    // Admin Master
    Route::middleware('role:master')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit')->withTrashed();
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
        Route::post('/employees/{employee}/restore', [EmployeeController::class, 'restore'])->name('employees.restore')->withTrashed();

        Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');

        Route::post('/imports/reset', [ImportController::class, 'reset'])->name('imports.reset');
    });
});
