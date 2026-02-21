<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

use App\Http\Controllers\SimulatorController;
use App\Http\Controllers\ConfigurationController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [SimulatorController::class, 'index'])->name('dashboard');
    Route::post('/documents', [SimulatorController::class, 'storeDocument'])->name('documents.store');
    Route::put('/documents/{document}', [SimulatorController::class, 'updateDocument'])->name('documents.update');
    Route::delete('/documents/{document}', [SimulatorController::class, 'destroyDocument'])->name('documents.destroy');
    
    Route::get('/policies', [ConfigurationController::class, 'index'])->name('policies.index');
    Route::post('/policies', [ConfigurationController::class, 'store'])->name('policies.store');
    Route::put('/policies/{vacationConfig}', [ConfigurationController::class, 'update'])->name('policies.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
