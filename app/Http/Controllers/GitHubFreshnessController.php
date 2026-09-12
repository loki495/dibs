<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RequestGitHubReconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubFreshnessController extends Controller
{
    public function __invoke(Request $request, RequestGitHubReconciliation $reconciliation): JsonResponse
    {
        return response()->json($reconciliation->handle($request->boolean('active')))->header('Cache-Control', 'no-store');
    }
}
