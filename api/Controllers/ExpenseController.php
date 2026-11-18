<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\ExpenseCategory;
use App\Transaction;
use Illuminate\Support\Facades\DB;

class ExpenseController extends BaseController
{
    // ================= EXPENSE CATEGORIES =================

    public function listCategories(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $query = ExpenseCategory::where('business_id', $businessId);

        if ($search = $request->get('search')) {
            $query->where('name', 'LIKE', "%{$search}%");
        }

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Expense categories retrieved', $result['meta']);
    }

    public function createCategory(Request $request): Response
    {
        $businessId = $request->getBusinessId();

        $errors = $this->validate($request, [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'parent_id' => 'nullable|integer',
        ]);
        if ($errors) {
            return Response::validationError($errors);
        }

        $cat = ExpenseCategory::create([
            'business_id' => $businessId,
            'name' => $request->input('name'),
            'code' => $request->input('code'),
            'parent_id' => $request->input('parent_id'),
        ]);

        return Response::created($cat, 'Expense category created successfully');
    }

    public function updateCategory(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $cat = ExpenseCategory::where('business_id', $businessId)->where('id', $id)->first();
        if (!$cat) {
            return Response::notFound("Expense category with ID {$id} not found");
        }

        if ($request->input('name') !== null) {
            $cat->name = $request->input('name');
        }
        if ($request->input('code') !== null) {
            $cat->code = $request->input('code');
        }
        if ($request->input('parent_id') !== null) {
            $cat->parent_id = $request->input('parent_id');
        }
        $cat->save();

        return Response::success($cat, 'Expense category updated successfully');
    }

    public function deleteCategory(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $cat = ExpenseCategory::where('business_id', $businessId)->where('id', $id)->first();
        if (!$cat) {
            return Response::notFound("Expense category with ID {$id} not found");
        }

        $cat->delete();
        return Response::success(null, 'Expense category deleted successfully');
    }

    // ================= EXPENSES =================

    public function index(Request $request): Response
    {
        $businessId = $request->getBusinessId();

        $query = Transaction::where('business_id', $businessId)
            ->where('type', 'expense')
            ->leftJoin('expense_categories as ec', 'ec.id', '=', 'transactions.expense_category_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 'transactions.location_id')
            ->select(
                'transactions.*',
                'ec.name as category_name',
                'bl.name as location_name'
            );

        if ($catId = $request->get('expense_category_id')) {
            $query->where('transactions.expense_category_id', $catId);
        }
        if ($locationId = $request->get('location_id')) {
            $query->where('transactions.location_id', $locationId);
        }
        if ($startDate = $request->get('start_date')) {
            $query->whereDate('transactions.transaction_date', '>=', $startDate);
        }
        if ($endDate = $request->get('end_date')) {
            $query->whereDate('transactions.transaction_date', '<=', $endDate);
        }

        $query->orderBy('transactions.transaction_date', 'desc');

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Expenses retrieved successfully', $result['meta']);
    }

    public function show(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $expense = Transaction::where('business_id', $businessId)
            ->where('type', 'expense')
            ->where('id', $id)
            ->with(['location', 'payment_lines'])
            ->first();

        if (!$expense) {
            return Response::notFound("Expense with ID {$id} not found");
        }

        return Response::success($expense, 'Expense details retrieved successfully');
    }

    public function store(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'location_id' => 'required|integer',
            'final_total' => 'required|numeric|min:0.01',
            'expense_category_id' => 'nullable|integer',
            'transaction_date' => 'nullable|date',
            'additional_notes' => 'nullable|string',
            'payments' => 'nullable|array',
            'payments.*.amount' => 'required_with:payments|numeric|min:0',
            'payments.*.method' => 'required_with:payments|string|in:cash,card,cheque,bank_transfer,other',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $input = $request->all();

        return $this->dbTransaction(function () use ($businessId, $userId, $input) {
            $refCount = $this->productUtil->setAndGetReferenceCount('expense', $businessId);
            $refNo = !empty($input['ref_no']) ? $input['ref_no'] : $this->productUtil->generateReferenceNumber('expense', $refCount, $businessId);

            $finalTotal = (float)$input['final_total'];

            $expense = Transaction::create([
                'business_id' => $businessId,
                'location_id' => (int)$input['location_id'],
                'type' => 'expense',
                'status' => 'final',
                'payment_status' => 'due',
                'ref_no' => $refNo,
                'total_before_tax' => $finalTotal,
                'final_total' => $finalTotal,
                'expense_category_id' => $input['expense_category_id'] ?? null,
                'transaction_date' => $input['transaction_date'] ?? date('Y-m-d H:i:s'),
                'additional_notes' => $input['additional_notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Add payment lines if provided
            if (!empty($input['payments']) && is_array($input['payments'])) {
                $payments = [];
                foreach ($input['payments'] as $pay) {
                    $payments[] = [
                        'amount' => (float)$pay['amount'],
                        'method' => $pay['method'],
                        'note' => $pay['note'] ?? null,
                        'paid_on' => $pay['paid_on'] ?? date('Y-m-d H:i:s'),
                    ];
                }
                $this->transactionUtil->createOrUpdatePaymentLines($expense, $payments, $businessId, $userId, false);
            }

            $this->transactionUtil->updatePaymentStatus($expense->id, $finalTotal);
            $expense->load('payment_lines');

            return Response::created($expense, 'Expense recorded successfully');
        });
    }

    public function update(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $expense = Transaction::where('business_id', $businessId)
            ->where('type', 'expense')
            ->where('id', $id)
            ->first();

        if (!$expense) {
            return Response::notFound("Expense with ID {$id} not found");
        }

        $fields = ['location_id', 'expense_category_id', 'final_total', 'transaction_date', 'additional_notes'];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                if ($f === 'final_total') {
                    $expense->total_before_tax = (float)$request->input($f);
                    $expense->final_total = (float)$request->input($f);
                } else {
                    $expense->{$f} = $request->input($f);
                }
            }
        }
        $expense->save();
        $this->transactionUtil->updatePaymentStatus($expense->id, $expense->final_total);

        return Response::success($expense, 'Expense updated successfully');
    }

    public function destroy(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $expense = Transaction::where('business_id', $businessId)
            ->where('type', 'expense')
            ->where('id', $id)
            ->first();

        if (!$expense) {
            return Response::notFound("Expense with ID {$id} not found");
        }

        return $this->dbTransaction(function () use ($expense) {
            $expense->payment_lines()->delete();
            $expense->delete();

            return Response::success(null, 'Expense deleted successfully');
        });
    }
}

