<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Analysis;
use App\Http\Traits\Bboxable;
use Illuminate\Support\Facades\Log;

class RecalculateCoveragePercentage extends Command
{
    use Bboxable;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:recalculate-coverage-percentage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculates the weed coverage percentage for all existing analyses based on merged bounding boxes.';

  
    public function handle()
    {
        $this->info('Starting recalculation of weed coverage percentage for all analyses...');

        $analyses = Analysis::all();
        $progressBar = $this->output->createProgressBar(count($analyses));

        foreach ($analyses as $analysis) {
            $detectionResults = $analysis->detection_results;
            if (is_string($detectionResults)) {
                $detectionResults = json_decode($detectionResults, true);
            }

            $detectionRaws = $detectionResults['detecciones_raw'] ?? [];

            if (empty($detectionRaws)) {
                $progressBar->advance();
                continue;
            }

            $unifiedDetections = $this->mergeOverlappingBboxes($detectionRaws);

            $totalBboxArea = 0;
            foreach ($unifiedDetections as $detection) {
                $bbox = $detection['bbox'];
                $totalBboxArea += ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
            }

            $imageArea = 640 * 640; 

            $newCoveragePercentage = 0;
            if ($imageArea > 0) {
                $newCoveragePercentage = min(100, ($totalBboxArea / $imageArea) * 100);
            }

            $analysis->weed_coverage_percentage = $newCoveragePercentage;
            $analysis->save();

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\nRecalculation completed successfully.");

        return 0;
    }
}
