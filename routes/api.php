<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelemetryController;
use App\Http\Controllers\OtelDebugController;
use App\Http\Controllers\MetricsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
})->name('user.show');

// OpenTelemetry test endpoints
Route::get('/health', [TelemetryController::class, 'health'])->name('health');
Route::get('/test', [TelemetryController::class, 'test'])->name('test');
Route::post('/test-log', [TelemetryController::class, 'testLog'])->name('test-log.post');
Route::get('/test-log', [TelemetryController::class, 'testLog'])->name('test-log.get');

// OTEL Debug endpoints
Route::get('/otel/check', [OtelDebugController::class, 'checkCollector'])->name('otel.check');
Route::get('/otel/test-trace', [OtelDebugController::class, 'testTrace'])->name('otel.test-trace');
Route::get('/otel/config', [OtelDebugController::class, 'showConfig'])->name('otel.config');

// Prometheus metrics endpoints
Route::get('/metrics', [MetricsController::class, 'index'])->name('metrics.index');
Route::post('/metrics/reset', [MetricsController::class, 'reset'])->name('metrics.reset');
