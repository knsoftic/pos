<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Thrown when something tries to delete a posted financial record (#133, #198).
 *
 * This is not a permission problem and no role can grant it. An invoice that
 * existed has to keep existing, because somebody has the paper copy and a
 * figure somewhere already counted it. The remedy is always a second document
 * — a void, a return, an opposite entry — never an erasure.
 *
 * Reaching this exception means a service tried to do something the design
 * forbids, so it is a bug rather than a user error. It still renders politely,
 * because the one place a user could plausibly meet it is a delete button on a
 * record whose status changed in another tab a second ago.
 */
class ImmutableRecordException extends RuntimeException
{
    public function __construct(
        public readonly Model $record,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            '%s cannot be deleted. Financial records are reversed, never erased.',
            Str::headline(class_basename($record)),
        ));
    }

    public function context(): array
    {
        return [
            'model' => $this->record::class,
            'id' => $this->record->getKey(),
        ];
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'immutable_record',
            ], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
