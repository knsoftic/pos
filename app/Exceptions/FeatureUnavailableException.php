<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a business touches something its plan does not include (#13).
 *
 * The navigation hides unavailable features, but hiding is cosmetic — this is
 * the guard that makes the route actually inaccessible. #187
 */
class FeatureUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $featureCode,
        public readonly ?string $featureName = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            '"%s" is not included in your current plan. Upgrade to unlock it.',
            $featureName ?? $featureCode,
        ));
    }

    public function context(): array
    {
        return [
            'feature_code' => $this->featureCode,
            'feature_name' => $this->featureName ?? $this->featureCode,
        ];
    }

    public function render(Request $request): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'feature_unavailable',
                'context' => $this->context(),
            ], 403);
        }

        return redirect()
            ->route('app.billing.index')
            ->with('feature_unavailable', $this->context())
            ->with('error', $this->getMessage());
    }
}
