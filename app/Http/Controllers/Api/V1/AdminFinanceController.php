<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Expense;
use App\Models\Payment;
use App\Services\Finance\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFinanceController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        return ApiResponse::success(
            $this->finance->summary([
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
            ]),
            'Finance summary retrieved.',
        );
    }

    public function income(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $page = $this->finance->paginateIncome([
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'gateway' => $request->query('gateway'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page'),
        ]);

        return ApiResponse::success(
            PaymentResource::collection($page)->resolve(),
            'Income retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function expenses(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $page = $this->finance->paginateExpenses([
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'category' => $request->query('category'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page'),
        ]);

        return ApiResponse::success(
            ExpenseResource::collection($page)->resolve(),
            'Expenses retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function storeExpense(ExpenseRequest $request): JsonResponse
    {
        $this->authorize('create', Expense::class);

        $expense = $this->finance->createExpense($request->user(), $request->validated());

        return ApiResponse::success(
            ExpenseResource::make($expense)->resolve(),
            'Expense created.',
            201,
        );
    }

    public function updateExpense(ExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $updated = $this->finance->updateExpense($request->user(), $expense, $request->validated());

        return ApiResponse::success(
            ExpenseResource::make($updated)->resolve(),
            'Expense updated.',
        );
    }

    public function destroyExpense(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        $this->finance->deleteExpense($request->user(), $expense);

        return ApiResponse::success(null, 'Expense deleted.');
    }
}
