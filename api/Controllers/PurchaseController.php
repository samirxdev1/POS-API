<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\PurchaseLine;
use App\TaxRate;
use App\Transaction;
use Illuminate\Support\Facades\DB;

class PurchaseController extends BaseController
{
    public function index(Request $request): Response
    {
        $businessId = $request->getBusinessId();

        $query = Transaction::where('business_id', $businessId)
            ->whereIn('type', ['purchase', 'purchase_order'])
            ->with([
                'contact:id,name,supplier_business_name,mobile',
                'location:id,name',
                'payment_lines',
            ]);

        if ($contactId = $request->get('contact_id')) {
            $query->where('contact_id', $contactId);
        }
        if ($locationId = $request->get('location_id')) {
            $query->where('location_id', $locationId);
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($paymentStatus = $request->get('payment_status')) {
            $query->where('payment_status', $paymentStatus);
        }
        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }
        if ($startDate = $request->get('start_date')) {
            $query->whereDate('transaction_date', '>=', $startDate);
        }
        if ($endDate = $request->get('end_date')) {
            $query->whereDate('transaction_date', '<=', $endDate);
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('ref_no', 'LIKE', "%{$search}%")
                  ->orWhereHas('contact', function ($cq) use ($search) {
                      $cq->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('supplier_business_name', 'LIKE', "%{$search}%");
                  });
            });
        }

        $sortBy = $request->get('sort_by', 'transaction_date');
        $sortOrder = strtolower($request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'ref_no', 'transaction_date', 'final_total'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Purchases retrieved successfully', $result['meta']);
    }

    public function show(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $purchase = Transaction::where('business_id', $businessId)
            ->where('id', $id)
            ->with([
                'contact',
                'location',
                'purchase_lines.product',
                'purchase_lines.variations',
                'payment_lines',
            ])
            ->first();

        if (!$purchase) {
            return Response::notFound("Purchase with ID {$id} not found");
        }

        return Response::success($purchase, 'Purchase details retrieved successfully');
    }

    public function store(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'contact_id' => 'required|integer',
            'location_id' => 'required|integer',
            'status' => 'required|in:received,pending,ordered',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.variation_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.purchase_price' => 'required|numeric|min:0',
            'payments' => 'nullable|array',
            'payments.*.amount' => 'required_with:payments|numeric|min:0',
            'payments.*.method' => 'required_with:payments|string|in:cash,card,cheque,bank_transfer,other',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $input = $request->all();

        return $this->dbTransaction(function () use ($businessId, $userId, $input) {
            $locationId = (int)$input['location_id'];
            $contactId = (int)$input['contact_id'];
            $status = $input['status'];
            $type = $input['type'] ?? ($status === 'ordered' ? 'purchase_order' : 'purchase');

            // Calculate total
            $totalBeforeTax = 0;
            $purchaseLines = [];

            foreach ($input['items'] as $item) {
                $qty = (float)$item['quantity'];
                $price = (float)$item['purchase_price'];
                $priceIncTax = (float)($item['purchase_price_inc_tax'] ?? $price);
                $lineTotal = $priceIncTax * $qty;
                $totalBeforeTax += $lineTotal;

                $purchaseLines[] = [
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'],
                    'quantity' => $qty,
                    'pp_without_discount' => $price,
                    'discount_percent' => (float)($item['discount_percent'] ?? 0),
                    'purchase_price' => $price,
                    'purchase_price_inc_tax' => $priceIncTax,
                    'item_tax' => (float)($item['item_tax'] ?? 0),
                    'tax_id' => $item['tax_id'] ?? null,
                ];
            }

            $discountAmount = (float)($input['discount_amount'] ?? 0);
            $taxAmount = (float)($input['tax_amount'] ?? 0);
            $shippingCharges = (float)($input['shipping_charges'] ?? 0);
            $finalTotal = $totalBeforeTax - $discountAmount + $taxAmount + $shippingCharges;

            $refCount = $this->productUtil->setAndGetReferenceCount($type, $businessId);
            $refNo = !empty($input['ref_no']) ? $input['ref_no'] : $this->productUtil->generateReferenceNumber($type, $refCount, $businessId);

            $transaction = Transaction::create([
                'business_id' => $businessId,
                'location_id' => $locationId,
                'type' => $type,
                'status' => $status,
                'contact_id' => $contactId,
                'ref_no' => $refNo,
                'total_before_tax' => $totalBeforeTax,
                'transaction_date' => $input['transaction_date'] ?? date('Y-m-d H:i:s'),
                'discount_type' => $input['discount_type'] ?? 'fixed',
                'discount_amount' => $discountAmount,
                'tax_id' => $input['tax_rate_id'] ?? null,
                'tax_amount' => $taxAmount,
                'shipping_charges' => $shippingCharges,
                'final_total' => $finalTotal,
                'additional_notes' => $input['additional_notes'] ?? null,
                'created_by' => $userId,
                'payment_status' => 'due',
            ]);

            $createdLines = $transaction->purchase_lines()->createMany($purchaseLines);

            // If received, update stock
            if ($status === 'received') {
                foreach ($purchaseLines as $line) {
                    $this->productUtil->updateProductQuantity(
                        $locationId,
                        $line['product_id'],
                        $line['variation_id'],
                        $line['quantity']
                    );
                }
            }

            // Create payments if provided
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
                $this->transactionUtil->createOrUpdatePaymentLines($transaction, $payments, $businessId, $userId, false);
            }

            $this->transactionUtil->updatePaymentStatus($transaction->id, $finalTotal);
            $transaction->load(['purchase_lines.product', 'purchase_lines.variations', 'payment_lines', 'contact', 'location']);

            return Response::created($transaction, 'Purchase recorded successfully');
        });
    }

    public function receive(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $purchase = Transaction::where('business_id', $businessId)
            ->where('id', $id)
            ->with('purchase_lines')
            ->first();

        if (!$purchase) {
            return Response::notFound("Purchase with ID {$id} not found");
        }

        if ($purchase->status === 'received') {
            return Response::error('Purchase has already been marked as received', 400);
        }

        return $this->dbTransaction(function () use ($purchase) {
            foreach ($purchase->purchase_lines as $line) {
                $this->productUtil->updateProductQuantity(
                    $purchase->location_id,
                    $line->product_id,
                    $line->variation_id,
                    $line->quantity
                );
            }

            $purchase->status = 'received';
            $purchase->save();

            return Response::success($purchase, 'Purchase status updated to received and stock added to inventory');
        });
    }
}

