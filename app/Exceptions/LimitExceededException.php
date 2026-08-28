<?php

namespace App\Exceptions;

use App\Support\LimitRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Thrown when a create would push a business past its plan quota (#78).
 *
 * BACKEND enforcement, not a UI hint. The button may be hidden and the form may
 * be disabled, but this is what actually stops the row from being written — a
 * hand-crafted POST hits the same guard. #187
 */
class LimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $limitCode,
        public readonly int $limit,
        public readonly int $usage,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $this->defaultMessage());
    }

    protected function defaultMessage(): string
    {
        return sprintf(
            'Your plan allows %d %s and you are using %d. Upgrade your plan to add more.',
            $this->limit,
            LimitRegistry::unit($this->limitCode),
            $this->usage,
        );
    }

    /** What the UI needs to render a helpful message plus an upgrade link. */
    public function context(): array
    {
        return [
            'limit_code' => $this->limitCode,
            'limit_name' => LimitRegistry::name($this->limitCode),
            'limit' => $this->limit,
            'usage' => $this->usage,
        ];
    }

    /**
     * Rendered as 403 with the quota detail — a validation-style redirect for
     * normal form posts, JSON for API/XHR callers.
     */
    public function render(Request $request): Response|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'limit_exceeded',
                'context' => $this->context(),
            ], 403);
        }

        return back()
            ->withInput()
            ->with('limit_exceeded', $this->context())
            ->withErrors(['limit' => $this->getMessage()]);
    }
}
