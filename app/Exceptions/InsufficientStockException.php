<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a movement would take a shelf below zero and the business has not
 * allowed that (#142).
 *
 * The message names the actual numbers, because "not enough stock" is useless at
 * a till: the cashier needs to know how many there are so they can sell that
 * many instead.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly string $productName,
        public readonly float $available,
        public readonly float $requested,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'Not enough stock for "%s": %s available, %s needed.',
            $productName,
            $this->trim($available),
            $this->trim($requested),
        ));
    }

    protected function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
    }

    public function context(): array
    {
        return [
            'product' => $this->productName,
            'available' => $this->available,
            'requested' => $this->requested,
        ];
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'insufficient_stock',
                'context' => $this->context(),
            ], 422);
        }

        return back()
            ->withInput()
            ->with('insufficient_stock', $this->context())
            ->withErrors(['quantity' => $this->getMessage()]);
    }
}
