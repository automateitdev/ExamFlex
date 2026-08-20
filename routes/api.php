<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MarkEntryController;

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

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });



Route::post('/mark-entry/config', [MarkEntryController::class, 'storeConfig']);
Route::post('/mark-entry/process', [MarkEntryController::class, 'processStudents']);
Route::post('result-process', [MarkEntryController::class, 'resultProcess']);
Route::post('merit-process', [MarkEntryController::class, 'meritProcess']);
Route::post('combined-result-process', [MarkEntryController::class, 'combinedResultProcess']);
