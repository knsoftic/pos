<?php

namespace App\Exceptions;

use App\Support\PermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a signed-in employee tries something their role does not allow
 * (#51, #188) — layer 2 of the three-layer check.
 *
 * Deliberately distinct from {@see FeatureUnavailableException}: "your plan does
 * not include this" sends the owner to billing, while "you are not allowed to do
 * this" must NOT — nudging a cashier towards the upgrade page for a permission
 * their manager withheld would be nonsense. This one simply refuses, in place.
 */
class PermissionDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $permissionCode,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'You do not have permission to %s. Ask the business owner if you need it.',
            lcfirst(PermissionRegistry::name($permissionCode)),
        ));
    }

    public function context(): array
    {
        return [
            'permission_code' => $this->permissionCode,
            'permission_name' => PermissionRegistry::name($this->permissionCode),
            'sensitive' => PermissionRegistry::isSensitive($this->permissionCode),
        ];
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'permission_denied',
                'context' => $this->context(),
            ], 403);
        }

        // Back to where they were, not to a dead end: the screen they came from
        // is still theirs, it was this one action that was not.
        return back()
            ->with('permission_denied', $this->context())
            ->with('error', $this->getMessage());
    }
}
