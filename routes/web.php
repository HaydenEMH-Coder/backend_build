<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AnalysisController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/login', [App\Http\Controllers\AuthController::class, 'login']);
Route::post('/api/upload-analysis', [App\Http\Controllers\AuthController::class, 'uploadAnalysis']);
Route::get('/api/analyses', [AnalysisController::class, 'index']);
Route::delete('/api/analyses/{id}', [App\Http\Controllers\AuthController::class, 'deleteAnalysis']);
Route::get('/api/weed-types', [App\Http\Controllers\WeedTypeController::class, 'index']);
Route::post('/api/register', [App\Http\Controllers\AuthController::class, 'register']);
Route::get('/api/analyses/{id}/cost', [AnalysisController::class, 'calculateCost']);
Route::get('/api/identifications-by-zone', [AnalysisController::class, 'getIdentificationsByZone']);
Route::get('/api/analysis-titles', [AnalysisController::class, 'getAnalysisTitles']);
Route::get('/api/analysis-by-title/{title}', [AnalysisController::class, 'getAnalysisByTitle']);
Route::get('/api/analysis-by-date/{date}', [AnalysisController::class, 'getAnalysisByDate']);
Route::get('/api/analysis-by-date-range', [AnalysisController::class, 'getAnalysisByDateRange']);
