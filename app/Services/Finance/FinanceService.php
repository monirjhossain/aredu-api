<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    public function __construct(private readonly ActivityLogService $activity) {}

    /**
     * @param  array{date_from?: string|null, date_to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        [$from, $to] = $this->dateRange($filters);

        $incomePaid = (float) Payment::query()
            ->where('status', Payment::PAID)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $incomeRefunded = (float) Payment::query()
            ->where('status', Payment::REFUNDED)
            ->whereBetween('updated_at', [$from, $to])
            ->sum('amount');

        $expenseQuery = Expense::query()
            ->whereDate('spent_on', '>=', $from->toDateString())
            ->whereDate('spent_on', '<=', $to->toDateString());

        $expenseTotal = (float) (clone $expenseQuery)->sum('amount');

        $netIncome = $incomePaid - $incomeRefunded;
        $profit = $netIncome - $expenseTotal;

        $expensesByCategory = (clone $expenseQuery)
            ->select('category')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'total' => number_format((float) $row->total, 2, '.', ''),
            ])
            ->values()
            ->all();

        $incomeByGateway = Payment::query()
            ->where('status', Payment::PAID)
            ->whereBetween('created_at', [$from, $to])
            ->select('gateway')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('gateway')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'gateway' => $row->gateway,
                'total' => number_format((float) $row->total, 2, '.', ''),
            ])
            ->values()
            ->all();

        $monthly = $this->monthlyBreakdown($from, $to);

        return [
            'currency' => 'BDT',
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'income_gross' => number_format($incomePaid, 2, '.', ''),
            'income_refunded' => number_format($incomeRefunded, 2, '.', ''),
            'income_net' => number_format($netIncome, 2, '.', ''),
            'expense_total' => number_format($expenseTotal, 2, '.', ''),
            'profit' => number_format($profit, 2, '.', ''),
            'income_by_gateway' => $incomeByGateway,
            'expenses_by_category' => $expensesByCategory,
            'monthly' => $monthly,
        ];
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null, gateway?: string|null, search?: string|null, per_page?: int|null}  $filters
     */
    public function paginateIncome(array $filters = []): LengthAwarePaginator
    {
        [$from, $to] = $this->dateRange($filters);
        $gateway = isset($filters['gateway']) && is_string($filters['gateway']) ? trim($filters['gateway']) : '';
        $search = isset($filters['search']) && is_string($filters['search']) ? trim($filters['search']) : '';
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $perPage = max(5, min($perPage, 50));

        return Payment::query()
            ->with(['order:id,order_number,user_id,status', 'order.user:id,name,email,phone'])
            ->whereIn('status', [Payment::PAID, Payment::REFUNDED])
            ->where(function ($query) use ($from, $to) {
                $query->where(function ($paid) use ($from, $to) {
                    $paid->where('status', Payment::PAID)
                        ->whereBetween('created_at', [$from, $to]);
                })->orWhere(function ($refunded) use ($from, $to) {
                    $refunded->where('status', Payment::REFUNDED)
                        ->whereBetween('updated_at', [$from, $to]);
                });
            })
            ->when($gateway !== '', fn ($query) => $query->where('gateway', $gateway))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('gateway_reference', 'like', $like)
                        ->orWhere('gateway_transaction_id', 'like', $like)
                        ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', $like)
                            ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like)
                                ->orWhere('phone', 'like', $like)));
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null, category?: string|null, search?: string|null, per_page?: int|null}  $filters
     */
    public function paginateExpenses(array $filters = []): LengthAwarePaginator
    {
        [$from, $to] = $this->dateRange($filters);
        $category = isset($filters['category']) && is_string($filters['category']) ? trim($filters['category']) : '';
        $search = isset($filters['search']) && is_string($filters['search']) ? trim($filters['search']) : '';
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $perPage = max(5, min($perPage, 50));

        return Expense::query()
            ->with(['creator:id,name'])
            ->whereDate('spent_on', '>=', $from->toDateString())
            ->whereDate('spent_on', '<=', $to->toDateString())
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('title', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhere('payment_method', 'like', $like);
                });
            })
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{title: string, category: string, amount: numeric, currency?: string, spent_on: string, payment_method?: string|null, notes?: string|null}  $data
     */
    public function createExpense(User $actor, array $data): Expense
    {
        $expense = Expense::query()->create([
            'title' => $data['title'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'BDT',
            'spent_on' => $data['spent_on'],
            'payment_method' => $data['payment_method'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->activity->record(
            $actor,
            'expense.created',
            $expense,
            'Expense recorded: '.$expense->title.' ('.$expense->amount.' '.$expense->currency.').',
        );

        return $expense->load('creator:id,name');
    }

    /**
     * @param  array{title: string, category: string, amount: numeric, currency?: string, spent_on: string, payment_method?: string|null, notes?: string|null}  $data
     */
    public function updateExpense(User $actor, Expense $expense, array $data): Expense
    {
        $expense->fill([
            'title' => $data['title'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? $expense->currency,
            'spent_on' => $data['spent_on'],
            'payment_method' => $data['payment_method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->save();

        $this->activity->record(
            $actor,
            'expense.updated',
            $expense,
            'Expense updated: #'.$expense->id.' '.$expense->title.'.',
        );

        return $expense->refresh()->load('creator:id,name');
    }

    public function deleteExpense(User $actor, Expense $expense): void
    {
        $id = $expense->id;
        $title = $expense->title;
        $expense->delete();

        $this->activity->record(
            $actor,
            'expense.deleted',
            null,
            'Expense deleted: #'.$id.' '.$title.'.',
        );
    }

    public function findExpense(int $id): Expense
    {
        $expense = Expense::query()->with('creator:id,name')->whereKey($id)->first();

        if ($expense === null) {
            throw new AuthorizationException('This expense is not available.');
        }

        return $expense;
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null}  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(array $filters): array
    {
        $fromRaw = isset($filters['date_from']) && is_string($filters['date_from']) ? trim($filters['date_from']) : '';
        $toRaw = isset($filters['date_to']) && is_string($filters['date_to']) ? trim($filters['date_to']) : '';

        try {
            $from = $fromRaw !== ''
                ? Carbon::createFromFormat('Y-m-d', $fromRaw)->startOfDay()
                : now()->startOfMonth()->startOfDay();
            $to = $toRaw !== ''
                ? Carbon::createFromFormat('Y-m-d', $toRaw)->endOfDay()
                : now()->endOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'date_from' => ['Use a valid date (YYYY-MM-DD).'],
            ]);
        }

        if ($from->gt($to)) {
            throw ValidationException::withMessages([
                'date_from' => ['Start date must be before end date.'],
            ]);
        }

        return [$from, $to];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monthlyBreakdown(Carbon $from, Carbon $to): array
    {
        $incomeRows = Payment::query()
            ->where('status', Payment::PAID)
            ->whereBetween('created_at', [$from, $to])
            ->get(['amount', 'created_at'])
            ->groupBy(fn (Payment $payment) => optional($payment->created_at)?->format('Y-m') ?: 'unknown')
            ->map(fn ($group) => (float) $group->sum(fn (Payment $payment) => (float) $payment->amount));

        $expenseRows = Expense::query()
            ->whereDate('spent_on', '>=', $from->toDateString())
            ->whereDate('spent_on', '<=', $to->toDateString())
            ->get(['amount', 'spent_on'])
            ->groupBy(fn (Expense $expense) => optional($expense->spent_on)?->format('Y-m') ?: 'unknown')
            ->map(fn ($group) => (float) $group->sum(fn (Expense $expense) => (float) $expense->amount));

        $months = $incomeRows->keys()
            ->merge($expenseRows->keys())
            ->unique()
            ->filter(fn ($month) => $month !== 'unknown')
            ->sort()
            ->values();

        return $months->map(function (string $month) use ($incomeRows, $expenseRows) {
            $income = (float) ($incomeRows[$month] ?? 0);
            $expense = (float) ($expenseRows[$month] ?? 0);

            return [
                'month' => $month,
                'income' => number_format($income, 2, '.', ''),
                'expense' => number_format($expense, 2, '.', ''),
                'profit' => number_format($income - $expense, 2, '.', ''),
            ];
        })->all();
    }
}
