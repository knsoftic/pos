<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The top-bar search (#75).
 *
 * JSON, because the results appear under the box as somebody types — a full
 * page load per keystroke would make the box slower than the sidebar it
 * replaces. Everything about what may be returned is decided in
 * {@see GlobalSearchService}.
 */
class SearchController extends Controller
{
    public function __construct(protected GlobalSearchService $search) {}

    public function __invoke(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        $results = $this->search->search($term);

        return response()->json([
            'term' => $term,
            'count' => $results->count(),
            // Grouped for the dropdown, which shows headings rather than one
            // undifferentiated list — "INV-2026-0001" and a customer called
            // "Inv…" are not the same kind of answer.
            'groups' => $results->groupBy('group')->map->values(),
        ]);
    }
}
