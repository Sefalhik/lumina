<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\GeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoController extends Controller
{
    public function __construct(private readonly GeoService $geo) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $this->geo->locate($request->ip());

        return response()->json($data ?? ['error' => true]);
    }
}
