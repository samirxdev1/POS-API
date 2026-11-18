<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\Contact;
use App\Transaction;
use App\TransactionPayment;
use Illuminate\Support\Facades\DB;

class PaymentController extends BaseController
{
    public function index(Request $request): Response
    {
        $businessId = $request->getBusinessId();

        $query = TransactionPayment::where('business_id', $businessId)
            ->with(['transaction:id,invoice_no,ref_no,type,contact_id,final_total,payment_status']);

        if ($transactionId = $request->get('transaction_id')) {
            $query->where('transaction_id', $transactionId);
        }
        if ($method = $request->get('method')) {
            $query->where('method', $method);
        }
        if ($startDate = $request->get('start_date')) {
            $query->whereDate('paid_on', '>=', $startDate);
        }
        if ($endDate = $request->get('end_date')) {
            $query->whereDate('paid_on', '<=', $endDate);
        }

        $query->orderBy('paid_on', 'desc');

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Payments retrieved successfully', $result['meta']);
    }

    public function store(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'transaction_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|in:cash,card,cheque,bank_transfer,other',
            'paid_on' => 'nullable|date',
            'note' => 'nullable|string',
            'account_id' => 'nullable|integer',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $transactionId = (int)$request->input('transaction_id');
        $transaction = Transaction::where('business_id', $businessId)->where('id', $transactionId)->first();
        if (!$transaction) {
            return Response::notFound("Transaction with ID {$transactionId} not found");
        }

        return $this->dbTransaction(function () use ($businessId, $userId, $transaction, $request) {
            $refCount = $this->commonUtil->setAndGetReferenceCount('purchase_payment', $businessId);
            $paymentRefNo = $this->commonUtil->generateReferenceNumber('purchase_payment', $refCount, $businessId);

            $payment = TransactionPayment::create([
                'transaction_id' => $transaction->id,
                'business_id' => $businessId,
                'amount' => (float)$request->input('amount'),
                'method' => $request->input('method'),
                'payment_ref_no' => $paymentRefNo,
                'paid_on' => $request->input('paid_on', date('Y-m-d H:i:s')),
                'created_by' => $userId,
                'note' => $request->input('note'),
                'account_id' => $request->input('account_id'),
                'card_number' => $request->input('card_number'),
                'card_holder_name' => $request->input('card_holder_name'),
                'card_transaction_number' => $request->input('card_transaction_number'),
                'card_type' => $request->input('card_type'),
                'card_month' => $request->input('card_month'),
                'card_year' => $request->input('card_year'),
                'card_security' => $request->input('card_security'),
                'cheque_number' => $request->input('cheque_number'),
                'bank_account_number' => $request->input('bank_account_number'),
            ]);

            // Update transaction payment status
            $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

            return Response::created($payment, 'Payment recorded successfully');
        });
    }

    public function due(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $type = $request->get('type', 'sell'); // 'sell' or 'purchase'

        $query = Transaction::where('business_id', $businessId)
            ->where('type', $type)
            ->whereIn('payment_status', ['due', 'partial'])
            ->with(['contact:id,name,mobile,supplier_business_name', 'location:id,name'])
            ->select(
                'id',
                'business_id',
                'location_id',
                'contact_id',
                'type',
                'invoice_no',
                'ref_no',
                'status',
                'payment_status',
                'final_total',
                'transaction_date',
                DB::raw('(SELECT COALESCE(SUM(tp.amount), 0) FROM transaction_payments as tp WHERE tp.transaction_id = transactions.id) as total_paid'),
                DB::raw('(transactions.final_total - (SELECT COALESCE(SUM(tp.amount), 0) FROM transaction_payments as tp WHERE tp.transaction_id = transactions.id)) as amount_due')
            );

        if ($contactId = $request->get('contact_id')) {
            $query->where('contact_id', $contactId);
        }
        if ($locationId = $request->get('location_id')) {
            $query->where('location_id', $locationId);
        }

        $query->orderBy('amount_due', 'desc');

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Outstanding due transactions retrieved', $result['meta']);
    }
}

