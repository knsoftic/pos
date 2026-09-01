<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use App\Services\ExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The headings a shop files its spending under (#43).
 *
 * One screen: the list is the editor. There is no separate create page because
 * a category is a name and a sort order, and sending someone to another page to
 * type one word is a page too many.
 */
class ExpenseCategoryController extends Controller
{
    public function __construct(protected ExpenseService $expenses) {}

    public function index(): View
    {
        return view('app.expenses.categories', [
            'categories' => ExpenseCategory::query()
                ->withCount('expenses')
                ->withSum('expenses', 'amount')
                ->ordered()
                ->get(),
        ]);
    }

    public function store(ExpenseCategoryRequest $request): RedirectResponse
    {
        $category = $this->expenses->createCategory($request->categoryAttributes());

        return back()->with('success', "\"{$category->name}\" added.");
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        $this->expenses->updateCategory($category, $request->categoryAttributes());

        return back()->with('success', "\"{$category->name}\" updated.");
    }

    public function destroy(ExpenseCategory $category): RedirectResponse
    {
        $name = $category->name;

        $this->expenses->deleteCategory($category);

        return back()->with('success', "\"{$name}\" deleted.");
    }
}
