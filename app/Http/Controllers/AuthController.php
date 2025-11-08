<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Http\Response;
use App\Models\Analysis; // Importar el modelo Analysis
use App\Models\DetectionRaw;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Http\Traits\Bboxable;

class AuthController extends Controller
{
    use Bboxable;
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'User successfully registered',
            'user' => $user
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        \Log::info('Attempting login with credentials:', $credentials);

        if (Auth::attempt($credentials)) {
            \Log::info('Login successful for user:', ['email' => $credentials['email']]);
            $user = Auth::user();
   
            return response()->json([
                'message' => 'Login successful',
                'user' => $user,
            ], 200);
        } else {
            \Log::warning('Login failed for user:', ['email' => $credentials['email']]);
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }
    }

    public function uploadAnalysis(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'required_without:analysis_id|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'image_after' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'analysis_id' => 'nullable|exists:analyses,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            if ($request->has('analysis_id')) {
                $analysis = Analysis::where('id', $request->analysis_id)->where('user_id', $user->id)->first();
                if (!$analysis) {
                    return response()->json(['message' => 'Analysis not found or unauthorized.'], 404);
                }

                if ($request->hasFile('image_after')) {
                    $image = $request->file('image_after');
                    $paths = $this->processAndStoreImage($image);

                    $analysis->update([
                        'original_image_path_after' => $paths['original_image_path'],
                        'processed_image_path_after' => $paths['processed_image_path'],
                        'detection_results_after' => $paths['detection_results'],
                    ]);
                }

                return response()->json([
                    'message' => 'Analysis updated successfully',
                    'analysis' => $analysis,
                ], 200);

            } else {
                $image = $request->file('image');
                
                // Obtener dimensiones de la imagen original
                list($original_width, $original_height) = getimagesize($image->getRealPath());
                $image_area = $original_width * $original_height;

                $paths = $this->processAndStoreImage($image);

                \Log::info('YOLO Detection Results:', $paths);

                // Calcular el porcentaje de cobertura de la maleza usando la lógica de fusión
                $weed_coverage_percentage = 0;
                if (isset($paths['detection_results']['detecciones_raw']) && is_array($paths['detection_results']['detecciones_raw'])) {
                    $detectionRaws = $paths['detection_results']['detecciones_raw'];
                    
                    // Fusionar bboxes superpuestos
                    $unifiedDetections = $this->mergeOverlappingBboxes($detectionRaws);
                    \Log::info('Detecciones después de la fusión:', $unifiedDetections);

                    // Calcular el área total de los bboxes unificados
                    $totalBboxArea = 0;
                    foreach ($unifiedDetections as $detection) {
                        $bbox = $detection['bbox'];
                        $totalBboxArea += ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
                    }

                    if ($image_area > 0) {
                        // Calcular el porcentaje y asegurarse de que no exceda el 100%
                        $weed_coverage_percentage = min(100, ($totalBboxArea / $image_area) * 100);
                    }
                }
                
                \Log::info('Cálculo de cobertura:', [
                    'total_area' => $totalBboxArea,
                    'image_area' => $image_area,
                    'original_width' => $original_width,
                    'original_height' => $original_height,
                    'weed_coverage_percentage' => $weed_coverage_percentage
                ]);

                $analysis = Analysis::create([
                    'user_id' => $user->id,
                    'title' => $request->title,
                    'description' => $request->description,
                    'original_image_path' => $paths['original_image_path'],
                    'processed_image_path' => $paths['processed_image_path'],
                    'detection_results' => $paths['detection_results'],
                    'weed_coverage_percentage' => $weed_coverage_percentage,
                ]);

                if (isset($paths['detection_results']['detecciones_raw'])) {
                    foreach ($paths['detection_results']['detecciones_raw'] as $detection) {
                        DetectionRaw::create([
                            'analysis_id' => $analysis->id,
                            'class' => $detection['class'],
                            'confidence' => $detection['confidence'],
                            'bbox' => json_encode($detection['bbox']),
                        ]);
                    }
                }

                return response()->json([
                    'message' => 'Analysis created successfully',
                    'analysis' => $analysis,
                ], 201);
            }
        } catch (\Exception $e) {
            \Log::error('Error processing analysis:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error processing analysis.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function processAndStoreImage($image)
    {
        $originalImageName = time() . '_original.' . $image->getClientOriginalExtension();
        $originalImagePath = 'uploads/' . $originalImageName;

        Storage::disk('public')->put($originalImagePath, file_get_contents($image->getRealPath()));

        $fullOriginalImagePath = Storage::disk('public')->path($originalImagePath);

        $processedImageName = time() . '_processed.' . $image->getClientOriginalExtension();
        $processedImagePath = 'processed_uploads/' . $processedImageName;
        $fullProcessedImagePath = Storage::disk('public')->path($processedImagePath);

        Storage::disk('public')->makeDirectory('processed_uploads');

        $pythonExecutable = 'c:/xampp/htdocs/venv/Scripts/python.exe';
        $pythonScript = 'c:/xampp/htdocs/venv/main.py';

        $command = [
            $pythonExecutable,
            $pythonScript,
            '--input', $fullOriginalImagePath,
            '--output', $fullProcessedImagePath,
        ];

        $process = new Process($command);
        $process->setEnv(['YOLO_CONFIG_DIR' => 'c:/xampp/htdocs/venv/yolo_config']);
        $process->setTimeout(3600);

        Storage::disk('public')->makeDirectory('yolo_config');
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $resultsPath = $fullProcessedImagePath . ".json";
        $detectionResults = [];
        if (file_exists($resultsPath)) {
            $jsonContent = file_get_contents($resultsPath);
            $detectionResults = json_decode($jsonContent, true);
            unlink($resultsPath);
        }

        return [
            'original_image_path' => $originalImagePath,
            'processed_image_path' => $processedImagePath,
            'detection_results' => $detectionResults,
        ];
    }



    public function deleteAnalysis(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $analysis = Analysis::where('id', $id)->where('user_id', $user->id)->first();

        if (!$analysis) {
            return response()->json(['message' => 'Analysis not found or unauthorized.'], 404);
        }

        try {
            Storage::disk('public')->delete($analysis->original_image_path);
            Storage::disk('public')->delete($analysis->processed_image_path);
            if ($analysis->original_image_path_after) {
                Storage::disk('public')->delete($analysis->original_image_path_after);
            }
            if ($analysis->processed_image_path_after) {
                Storage::disk('public')->delete($analysis->processed_image_path_after);
            }


            $analysis->delete();

            return response()->json(['message' => 'Analysis deleted successfully.'], 200);
        } catch (\Exception $e) {
            \Log::error('Error deleting analysis:', ['analysis_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error deleting analysis.', 'error' => $e->getMessage()], 500);
        }
    }
}
