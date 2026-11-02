<?php

use App\Http\Controllers\DispatchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('api.key')->group(function (): void {
    Route::post('dispatch-requests', [DispatchController::class, 'store']);
    Route::delete('assignments', [DispatchController::class, 'reset']);
    Route::delete('assignments/{bookingId}', [DispatchController::class, 'release'])->whereNumber('bookingId');
});
