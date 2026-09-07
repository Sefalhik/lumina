<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Experience;
use App\Services\CvService;
use Illuminate\View\View;

class CvController extends Controller
{
    public function index(CvService $cv): View
    {
        return view('cv', [
            'timeline' => $cv->timeline(Experience::mostRecentFirst()->get()),
        ]);
    }
}
