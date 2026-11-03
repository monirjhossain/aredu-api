<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\MockTest\BandConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BandConversionController extends Controller
{
    public function __construct(private readonly BandConversionService $conversions) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => ['required', 'in:reading,listening'],
            'exam_type' => ['required', 'in:academic,general'],
        ]);

        return ApiResponse::success(
            [
                'module' => $data['module'],
                'exam_type' => $data['exam_type'],
                'rows' => $this->conversions->list($data['module'], $data['exam_type']),
            ],
            'Band conversions retrieved.',
        );
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => ['required', 'in:reading,listening'],
            'exam_type' => ['required', 'in:academic,general'],
            'rows' => ['required', 'array'],
            'rows.*.raw_score' => ['required', 'integer', 'min:0', 'max:100'],
            'rows.*.band_score' => ['required', 'numeric', 'min:0', 'max:9'],
        ]);

        return ApiResponse::success(
            [
                'module' => $data['module'],
                'exam_type' => $data['exam_type'],
                'rows' => $this->conversions->sync($data['module'], $data['exam_type'], $data['rows']),
            ],
            'Band conversions saved.',
        );
    }
}
