<?php

namespace App\Http\Traits;

trait Bboxable {

    private function bboxesOverlap($bbox1, $bbox2)
    {
        // Define el umbral de IoU (Intersection over Union). 
        // Solo se considerará una superposición si el IoU es mayor o igual a este valor.
        $iouThreshold = 0.3;

        // Calcula las coordenadas (x, y) del rectángulo de intersección.
        $x_left = max($bbox1[0], $bbox2[0]);
        $y_top = max($bbox1[1], $bbox2[1]);
        $x_right = min($bbox1[2], $bbox2[2]);
        $y_bottom = min($bbox1[3], $bbox2[3]);
        // Si no hay intersección, devuelve false.
        if ($x_right < $x_left || $y_bottom < $y_top) {
            return false;
        }

        // Calcula el área del rectángulo de intersección.
        $intersection_area = ($x_right - $x_left) * ($y_bottom - $y_top);

        // Calcula el área de cada bounding box.
        $bbox1_area = ($bbox1[2] - $bbox1[0]) * ($bbox1[3] - $bbox1[1]);
        $bbox2_area = ($bbox2[2] - $bbox2[0]) * ($bbox2[3] - $bbox2[1]);

        // Calcula el área de la unión de los dos bboxes.
        // Fórmula: Union(A, B) = Area(A) + Area(B) - Area(Intersección(A, B))
        $union_area = $bbox1_area + $bbox2_area - $intersection_area;

        // Si el área de la unión es cero, evita la división por cero.
        if ($union_area == 0) {
            return false;
        }

        // Calcula el IoU.
        $iou = $intersection_area / $union_area;

        // Devuelve true solo si el IoU es mayor o igual al umbral definido.
        return $iou >= $iouThreshold;
    }

    private function mergeBboxes($bbox1, $bbox2)
    {
        return [
            min($bbox1[0], $bbox2[0]),
            min($bbox1[1], $bbox2[1]),
            max($bbox1[2], $bbox2[2]),
            max($bbox1[3], $bbox2[3]),
        ];
    }

    private function mergeOverlappingBboxes($detections)
    {
        $mergedInPass = true;
        while ($mergedInPass) {
            $mergedInPass = false;
            $newDetections = [];
            $count = count($detections);
            if ($count < 2) {
                return $detections;
            }
            
            $mergedIndices = [];

            for ($i = 0; $i < $count; $i++) {
                if (in_array($i, $mergedIndices)) {
                    continue;
                }

                $current = $detections[$i];

                for ($j = $i + 1; $j < $count; $j++) {
                    if (in_array($j, $mergedIndices)) {
                        continue;
                    }

                    $other = $detections[$j];

                    if ($current['class'] === $other['class'] && $this->bboxesOverlap($current['bbox'], $other['bbox'])) {
                        $current['bbox'] = $this->mergeBboxes($current['bbox'], $other['bbox']);
                        $mergedIndices[] = $j;
                        $mergedInPass = true;
                    }
                }
                $newDetections[] = $current;
            }
            $detections = $newDetections;
        }
        return $detections;
    }
}
