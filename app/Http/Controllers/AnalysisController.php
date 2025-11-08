<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Analysis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Http\Traits\Bboxable;

class AnalysisController extends Controller
{
    use Bboxable;
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = $user->analyses()->orderBy('created_at', 'desc');

        if ($request->has('date')) {
            $query->whereDate('created_at', $request->date);
        }

        if ($request->has('name')) {
            $query->where('title', 'like', '%' . $request->name . '%');
        }

        $analyses = $query->get();

        $analyses->each(function ($analysis) {
            $analysis->processed_image_url = Storage::disk('public')->url($analysis->processed_image_path);
            $analysis->original_image_url = Storage::disk('public')->url($analysis->original_image_path);
            if ($analysis->original_image_path_after) {
                $analysis->original_image_url_after = Storage::disk('public')->url($analysis->original_image_path_after);
            }
            if ($analysis->processed_image_path_after) {
                $analysis->processed_image_url_after = Storage::disk('public')->url($analysis->processed_image_path_after);
            }
        });

        return response()->json(['analyses' => $analyses]);
    }


    public function calculateCost(Request $request, $analysisId)
    {
        Log::info("Calculando el costo para el análisis ID: {$analysisId}");

        $analysis = Analysis::findOrFail($analysisId);
        
        $detectionResults = $analysis->detection_results;

        if (is_string($detectionResults)) {
            $detectionResults = json_decode($detectionResults, true);
        }

        $detectionRaws = $detectionResults['detecciones_raw'] ?? [];

        // Lógica para fusionar Bounding Boxes superpuestos
        $unifiedDetections = $this->mergeOverlappingBboxes($detectionRaws);

        Log::info("Detecciones encontradas: " . count($detectionRaws));
        Log::info("Detecciones después de la fusión: " . count($unifiedDetections));

        $totalCost = 0;
        $imageArea = 640 * 640;

        $costs = [

            'maleza'        => ['base' => 1.5, 'area_factor' => 0.5],
        ];

        foreach ($unifiedDetections as $detection) {
            $bbox = $detection['bbox'];
            $bboxArea = ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
            $className = strtolower($detection['class']);

            Log::info("Detección: class={$className}, bboxArea={$bboxArea}");

            if (isset($costs[$className])) {
                $costConfig = $costs[$className];
                // costo base + (proporción de área * factor de área)
                $calculatedCost = $costConfig['base'] + ($bboxArea / $imageArea) * $costConfig['area_factor'];
                $totalCost += $calculatedCost;
                Log::info("Costo calculado para esta detección: {$calculatedCost}");
            } else {
                Log::warning("Clase no encontrada en la lista de costos: {$className}");
            }
        }

        Log::info("Costo total calculado: {$totalCost}");

        return response()->json(['total_cost' => $totalCost]);
    }

    public function getIdentificationsByZone(Request $request)
    {
        $user = Auth::user();
        $identificationsByZone = $user->analyses()->select('zone')
            ->selectRaw('count(*) as total')
            ->groupBy('zone')
            ->get();

        return response()->json($identificationsByZone);
    }

    public function getAnalysisTitles(Request $request)
    {
        $user = Auth::user();
        $titles = $user->analyses()->select('title')->distinct()->orderBy('title', 'asc')->get();
        return response()->json($titles);
    }

    public function getAnalysisByTitle(Request $request, $title)
    {
        $user = Auth::user();
        $analysis = $user->analyses()->where('title', $title)->firstOrFail();

        // Costos en el Manejo de Maleza
        $detectionResults = $analysis->detection_results;
        if (is_string($detectionResults)) {
            $detectionResults = json_decode($detectionResults, true);
        }
        $detectionRaws = $detectionResults['detecciones_raw'] ?? [];

        $unifiedDetections = $this->mergeOverlappingBboxes($detectionRaws);

        $totalCost = 0;
        $imageArea = 640 * 640;
        $costs = [

            'maleza'        => ['base' => 1.5, 'area_factor' => 0.5],
        ];

        foreach ($unifiedDetections as $detection) {
            $bbox = $detection['bbox'];
            $bboxArea = ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
            $className = strtolower($detection['class']);
            if (isset($costs[$className])) {
                $costConfig = $costs[$className];
                $calculatedCost = $costConfig['base'] + ($bboxArea / $imageArea) * $costConfig['area_factor'];
                $totalCost += $calculatedCost;
            }
        }

        $average_identification_time = $detectionResults['tiempo_promedio_identificacion_segundos'] ?? 0;

        // Calcular el área total de los bboxes unificados
        $totalBboxArea = 0;
        foreach ($unifiedDetections as $detection) {
            $bbox = $detection['bbox'];
            $totalBboxArea += ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
        }

        // Calcular el porcentaje de cobertura
        $imageArea = 640 * 640;
        $coveragePercentage = ($imageArea > 0) ? ($totalBboxArea / $imageArea) * 100 : 0;
        
        $coveragePercentage = min(100, $coveragePercentage);

        $data = [
            'average_identification_time' => $average_identification_time,
            'identifications_made' => count($unifiedDetections),
            'weed_management_costs' => $totalCost,
            'coverage_percentage' => $coveragePercentage,
        ];

        return response()->json($data);
    }

    public function getAnalysisByDate(Request $request, $date)
    {
        $user = Auth::user();
        $analyses = $user->analyses()->whereDate('created_at', $date)->get();

        if ($analyses->isEmpty()) {
            return response()->json([
                'average_identification_time' => 0,
                'identifications_made' => 0,
                'weed_management_costs' => 0,
                'coverage_percentage' => 0,
            ]);
        }

        $total_average_identification_time = 0;
        $total_identifications_made = 0;
        $total_weed_management_costs = 0;
        $total_coverage_percentage = 0;
        $analysis_count = $analyses->count();

        foreach ($analyses as $analysis) {
            $detectionResults = $analysis->detection_results;
            if (is_string($detectionResults)) {
                $detectionResults = json_decode($detectionResults, true);
            }

            $total_average_identification_time += $detectionResults['tiempo_promedio_identificacion_segundos'] ?? 0;

            $detectionRaws = $detectionResults['detecciones_raw'] ?? [];
            $unifiedDetections = $this->mergeOverlappingBboxes($detectionRaws);
            $total_identifications_made += count($unifiedDetections);

            $totalCost = 0;
            $imageArea = 640 * 640;
            $costs = [
                'maleza'        => ['base' => 1.5, 'area_factor' => 0.5],
            ];
            foreach ($unifiedDetections as $detection) {
                $bbox = $detection['bbox'];
                $bboxArea = ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
                $className = strtolower($detection['class']);
                if (isset($costs[$className])) {
                    $costConfig = $costs[$className];
                    $calculatedCost = $costConfig['base'] + ($bboxArea / $imageArea) * $costConfig['area_factor'];
                    $totalCost += $calculatedCost;
                }
            }
            $total_weed_management_costs += $totalCost;

            // Calcular el área total de los bboxes unificados para el análisis actual
            $totalBboxArea = 0;
            foreach ($unifiedDetections as $detection) {
                $bbox = $detection['bbox'];
                $totalBboxArea += ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
            }

            // Calcular el porcentaje de cobertura para el análisis actual
            $coveragePercentage = ($imageArea > 0) ? ($totalBboxArea / $imageArea) * 100 : 0;
            
            // Asegurarse de que el porcentaje no exceda el 100% y sumarlo al total
            $total_coverage_percentage += min(100, $coveragePercentage);
        }

        $data = [
            'average_identification_time' => $analysis_count > 0 ? $total_average_identification_time / $analysis_count : 0,
            'identifications_made' => $total_identifications_made,
            'weed_management_costs' => $total_weed_management_costs,
            'coverage_percentage' => $analysis_count > 0 ? $total_coverage_percentage / $analysis_count : 0,
        ];

        return response()->json($data);
    }

    public function getAnalysisByDateRange(Request $request)
    {
        $user = Auth::user();
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if ($startDate === $endDate) {
            $analyses = $user->analyses()->whereDate('created_at', $startDate)->get();
        } else {
            $analyses = $user->analyses()->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])->get();
        }

        if ($analyses->isEmpty()) {
            return response()->json([
                'average_identification_time' => 0,
                'identifications_made' => 0,
                'weed_management_costs' => 0,
                'coverage_percentage' => 0,
            ]);
        }

        $total_average_identification_time = 0;
        $total_identifications_made = 0;
        $total_weed_management_costs = 0;
        $total_coverage_percentage = 0;
        $analysis_count = $analyses->count();

        foreach ($analyses as $analysis) {
            $detectionResults = $analysis->detection_results;
            if (is_string($detectionResults)) {
                $detectionResults = json_decode($detectionResults, true);
            }

            $total_average_identification_time += $detectionResults['tiempo_promedio_identificacion_segundos'] ?? 0;

            $detectionRaws = $detectionResults['detecciones_raw'] ?? [];
            $unifiedDetections = $this->mergeOverlappingBboxes($detectionRaws);
            $total_identifications_made += count($unifiedDetections);

            $totalCost = 0;
            $imageArea = 640 * 640;
            $costs = [
                'maleza'        => ['base' => 1.5, 'area_factor' => 0.5],
            ];
            foreach ($unifiedDetections as $detection) {
                $bbox = $detection['bbox'];
                $bboxArea = ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
                $className = strtolower($detection['class']);
                if (isset($costs[$className])) {
                    $costConfig = $costs[$className];
                    $calculatedCost = $costConfig['base'] + ($bboxArea / $imageArea) * $costConfig['area_factor'];
                    $totalCost += $calculatedCost;
                }
            }
            $total_weed_management_costs += $totalCost;

            // Calcular el área total de los bboxes unificados para el análisis actual
            $totalBboxArea = 0;
            foreach ($unifiedDetections as $detection) {
                $bbox = $detection['bbox'];
                $totalBboxArea += ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
            }

            // Calcular el porcentaje de cobertura para el análisis actual
            $coveragePercentage = ($imageArea > 0) ? ($totalBboxArea / $imageArea) * 100 : 0;

            // Asegurarse de que el porcentaje no exceda el 100% y sumarlo al total
            $total_coverage_percentage += min(100, $coveragePercentage);
        }

        $data = [
            'average_identification_time' => $analysis_count > 0 ? $total_average_identification_time / $analysis_count : 0,
            'identifications_made' => $total_identifications_made,
            'weed_management_costs' => $total_weed_management_costs,
            'coverage_percentage' => $analysis_count > 0 ? $total_coverage_percentage / $analysis_count : 0,
        ];

        return response()->json($data);
    }
}
