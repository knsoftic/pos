<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Messages from the operator to every shop (#77).
 *
 * One screen: the list is the editor, because an announcement is a title, a
 * paragraph and two dates, and sending somebody to another page to type that is
 * a page too many.
 */
class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::query()
                ->with('author:id,name')
                ->withCount('dismissedBy')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        Announcement::query()->create(
            $request->announcementAttributes() + ['created_by' => auth('admin')->id()]
        );

        return back()->with('success', 'Announcement published.');
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($request->announcementAttributes());

        return back()->with('success', 'Announcement updated.');
    }

    /**
     * Switching one off is the normal way to end it early; deleting is for a
     * mistake. Dismissals go with it, which is what cascadeOnDelete is for.
     */
    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return back()->with('success', 'Announcement deleted.');
    }
}
