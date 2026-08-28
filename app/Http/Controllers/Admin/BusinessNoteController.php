<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessNote;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Internal support notes on a tenant (#159).
 *
 * Operator-only: {@see BusinessNote} carries no tenant trait precisely so these
 * remarks can never surface in a tenant-scoped query. Notes belong to whoever
 * wrote them — one operator does not edit or delete another's — and the author's
 * name is snapshotted so the note still reads correctly after staff changes.
 */
class BusinessNoteController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function store(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $admin = $request->user('admin');

        $note = new BusinessNote([
            'body' => $validated['body'],
            'is_pinned' => $request->boolean('is_pinned'),
        ]);
        $note->business_id = $business->id;
        $note->admin_id = $admin?->id;
        $note->admin_name = $admin?->name;
        $note->save();

        $this->audit->log(
            'business.note_added',
            $note,
            "Support note added to {$business->name}.",
            ['is_pinned' => $note->is_pinned],
            null,
            $business->id,
        );

        return back()->with('success', 'Note saved.');
    }

    public function update(Request $request, Business $business, BusinessNote $note): RedirectResponse
    {
        if (($response = $this->guard($request, $business, $note)) !== null) {
            return $response;
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $note->fill([
            'body' => $validated['body'],
            'is_pinned' => $request->boolean('is_pinned'),
        ])->save();

        return back()->with('success', 'Note updated.');
    }

    /** Pin/unpin from the list without opening the editor. */
    public function pin(Request $request, Business $business, BusinessNote $note): RedirectResponse
    {
        if (($response = $this->guard($request, $business, $note)) !== null) {
            return $response;
        }

        $note->is_pinned = ! $note->is_pinned;
        $note->save();

        return back()->with('success', $note->is_pinned ? 'Note pinned.' : 'Note unpinned.');
    }

    /**
     * Hard delete, unusually — a note is an internal scratchpad, not a financial
     * or audit record, so there is nothing to preserve (#104 covers records that
     * other rows reference). The audit entry keeps the fact of the deletion.
     */
    public function destroy(Request $request, Business $business, BusinessNote $note): RedirectResponse
    {
        if (($response = $this->guard($request, $business, $note)) !== null) {
            return $response;
        }

        $note->delete();

        $this->audit->log(
            'business.note_deleted',
            $business,
            "Support note deleted from {$business->name}.",
            [],
            null,
            $business->id,
        );

        return back()->with('success', 'Note deleted.');
    }

    /**
     * Two checks in one place: the note must belong to the business in the URL
     * (so ids cannot be swapped between tenants), and to the operator acting.
     */
    protected function guard(Request $request, Business $business, BusinessNote $note): ?RedirectResponse
    {
        if ($note->business_id !== $business->id) {
            return back()->with('error', 'That note does not belong to this business.');
        }

        $adminId = $request->user('admin')?->id;

        if ($note->admin_id !== null && $note->admin_id !== $adminId) {
            return back()->with('error', 'Only the operator who wrote a note can change it.');
        }

        return null;
    }
}
