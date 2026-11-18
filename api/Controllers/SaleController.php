<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\Product;
use App\TaxRate;
use App\Transaction;
use App\TransactionPayment;
use App\TransactionSellLine;
use App\Variation;
use Illuminate\Support\Facades\DB;

class SaleController extends BaseController
{
    public function index(Request $request): Response
    {
        $businessId = $request->getBusinessId();

        $query = Transaction::where('business_id', $businessId)
            ->whereIn('type', ['sell', 'sales_order'])
            ->with([
                'contact:id,name,mobile,email',
                'location:id,name',
                'payment_lines',
            ]);

        // Filters
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
        if ($startDate = $request->get('start_date')) {
            $query->whereDate('transaction_date', '>=', $startDate);
        }
        if ($endDate = $request->get('end_date')) {
            $query->whereDate('transaction_date', '<=', $endDate);
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_no', 'LIKE', "%{$search}%")
                  ->orWhereHas('contact', function ($cq) use ($search) {
                      $cq->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('mobile', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'transaction_date');
        $sortOrder = strtolower($request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'invoice_no', 'transaction_date', 'final_total'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $result = $this->paginate($query, $request);

        return Response::success($result['data'], 'Sales retrieved successfully', $result['meta']);
    }

    public function show(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $sale = Transaction::where('business_id', $businessId)
            ->where('id', $id)
            ->with([
                'contact',
                'location',
                'sell_lines.product',
                'sell_lines.variations',
                'payment_lines',
            ])
            ->first();

        if (!$sale) {
            return Response::notFound("Sale with ID {$id} not found");
        }

        return Response::success($sale, 'Sale details retrieved successfully');
    }

    public function store(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'location_id' => 'required|integer',
            'contact_id' => 'required|integer',
            'status' => 'required|in:final,draft,quotation',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.variation_id' => 'required|integer',
            'products.*.quantity' => 'required|numeric|min:0.01',
            'products.*.unit_price' => 'required|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_rate_id' => 'nullable|integer',
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

            // Calculate item subtotal
            $totalBeforeTax = 0;
            $sellProducts = [];

            foreach ($input['products'] as $item) {
                $unitPrice = (float)$item['unit_price'];
                $qty = (float)$item['quantity'];
                $lineTotal = $unitPrice * $qty;
                $totalBeforeTax += $lineTotal;

                $sellProducts[] = [
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'unit_price_inc_tax' => (float)($item['unit_price_inc_tax'] ?? $unitPrice),
                    'item_tax' => (float)($item['item_tax'] ?? 0),
                    'tax_id' => $item['tax_id'] ?? null,
                    'line_discount_type' => $item['discount_type'] ?? 'fixed',
                    'line_discount_amount' => (float)($item['discount_amount'] ?? 0),
                ];
            }

            // Calculate invoice discount
            $discountAmount = (float)($input['discount_amount'] ?? 0);
            $discountType = $input['discount_type'] ?? 'fixed';
            $calculatedDiscount = $discountType === 'percentage'
                ? ($totalBeforeTax * $discountAmount / 100)
                : $discountAmount;

            $taxableAmount = max(0, $totalBeforeTax - $calculatedDiscount);

            // Calculate tax
            $taxAmount = 0;
            if (!empty($input['tax_rate_id'])) {
                $taxRate = TaxRate::where('business_id', $businessId)->find($input['tax_rate_id']);
                if ($taxRate) {
                    $taxAmount = ($taxableAmount * $taxRate->amount) / 100;
                }
            }

            $shippingCharges = (float)($input['shipping_charges'] ?? 0);
            $finalTotal = $taxableAmount + $taxAmount + $shippingCharges;

            // Invoice number
            $invoiceNo = !empty($input['invoice_no'])
                ? $input['invoice_no']
                : $this->transactionUtil->getInvoiceNumber($businessId, $status, $locationId);

            $transactionData = [
                'business_id' => $businessId,
                'location_id' => $locationId,
                'type' => 'sell',
                'status' => $status,
                'contact_id' => $contactId,
                'invoice_no' => $invoiceNo,
                'ref_no' => '',
                'total_before_tax' => $totalBeforeTax,
                'transaction_date' => $input['transaction_date'] ?? date('Y-m-d H:i:s'),
                'tax_id' => $input['tax_rate_id'] ?? null,
                'discount_type' => $discountType,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'shipping_charges' => $shippingCharges,
                'final_total' => $finalTotal,
                'additional_notes' => $input['additional_notes'] ?? null,
                'staff_note' => $input['staff_note'] ?? null,
                'created_by' => $userId,
                'is_direct_sale' => 1,
            ];

            $transaction = Transaction::create($transactionData);

            // Create Sell Lines
            $this->transactionUtil->createOrUpdateSellLines(
                $transaction,
                $sellProducts,
                $locationId,
                false,
                null,
                [],
                false
            );

            // Deduct stock if final sale
            if ($status === 'final') {
                foreach ($sellProducts as $p) {
                    $this->productUtil->decreaseProductQuantity(
                        $p['product_id'],
                        $p['variation_id'],
                        $locationId,
                        $p['quantity']
                    );
                }
            }

            // Create Payments
            if (!empty($input['payments']) && is_array($input['payments'])) {
                $payments = [];
                foreach ($input['payments'] as $pay) {
                    $payments[] = [
                        'amount' => (float)$pay['amount'],
                        'method' => $pay['method'],
                        'note' => $pay['note'] ?? null,
                        'card_number' => $pay['card_number'] ?? null,
                        'card_holder_name' => $pay['card_holder_name'] ?? null,
                        'card_transaction_number' => $pay['card_transaction_number'] ?? null,
                        'card_type' => $pay['card_type'] ?? null,
                        'card_month' => $pay['card_month'] ?? null,
                        'card_year' => $pay['card_year'] ?? null,
                        'card_security' => $pay['card_security'] ?? null,
                        'cheque_number' => $pay['cheque_number'] ?? null,
                        'bank_account_number' => $pay['bank_account_number'] ?? null,
                        'paid_on' => $pay['paid_on'] ?? date('Y-m-d H:i:s'),
                    ];
                }

                $this->transactionUtil->createOrUpdatePaymentLines($transaction, $payments, $businessId, $userId, false);
            }

            // Update Payment Status
            $this->transactionUtil->updatePaymentStatus($transaction->id, $finalTotal);

            $transaction->load(['sell_lines.product', 'sell_lines.variations', 'payment_lines', 'contact', 'location']);

            return Response::created($transaction, 'Sale created successfully');
        });
    }

    public function void(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $sale = Transaction::where('business_id', $businessId)->where('id', $id)->first();
        if (!$sale) {
            return Response::notFound("Sale with ID {$id} not found");
        }

        if ($sale->status === 'cancelled') {
            return Response::error('Sale is already cancelled / voided', 400);
        }

        return $this->dbTransaction(function () use ($sale) {
            // Restore product stock if it was a final sale
            if ($sale->status === 'final') {
                $sellLines = TransactionSellLine::where('transaction_id', $sale->id)->get();
                foreach ($sellLines as $line) {
                    $this->productUtil->updateProductQuantity(
                        $sale->location_id,
                        $line->product_id,
                        $line->variation_id,
                        $line->quantity
                    );
                }
            }

            $sale->status = 'cancelled';
            $sale->additional_notes .= "\n[Voided by API on " . date('Y-m-d H:i:s') . ']';
            $sale->save();

            return Response::success($sale, 'Sale has been voided and stock restored successfully');
        });
    }

    public function hold(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $sale = Transaction::where('business_id', $businessId)->where('id', $id)->first();
        if (!$sale) {
            return Response::notFound("Sale with ID {$id} not found");
        }

        $sale->is_suspend = 1;
        $sale->status = 'draft';
        $sale->save();

        return Response::success($sale, 'Sale has been placed on hold (draft)');
    }

    public function resume(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $sale = Transaction::where('business_id', $businessId)->where('id', $id)->first();
        if (!$sale) {
            return Response::notFound("Sale with ID {$id} not found");
        }

        if ($sale->status === 'final') {
            return Response::error('Sale is already finalized', 400);
        }

        return $this->dbTransaction(function () use ($sale) {
            // Deduct stock for sell lines
            $sellLines = TransactionSellLine::where('transaction_id', $sale->id)->get();
            foreach ($sellLines as $line) {
                $this->productUtil->decreaseProductQuantity(
                    $line->product_id,
                    $line->variation_id,
                    $sale->location_id,
                    $line->quantity
                );
            }

            $sale->is_suspend = 0;
            $sale->status = 'final';
            $sale->save();

            $this->transactionUtil->updatePaymentStatus($sale->id, $sale->final_total);

            return Response::success($sale, 'Held sale resumed and finalized successfully');
        });
    }

    public function invoice(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $sale = Transaction::where('business_id', $businessId)
            ->where('id', $id)
            ->with([
                'contact',
                'location',
                'sell_lines.product',
                'sell_lines.variations',
                'payment_lines',
                'tax',
            ])
            ->first();

        if (!$sale) {
            return Response::notFound("Sale with ID {$id} not found");
        }

        $business = Business::find($businessId);
        $totalPaid = $sale->payment_lines->sum('amount');
        $due = max(0, $sale->final_total - $totalPaid);

        $lineItems = [];
        foreach ($sale->sell_lines as $line) {
            $lineItems[] = [
                'product_name' => $line->product->name ?? 'Item',
                'variation_name' => $line->variations->name ?? '',
                'sku' => $line->variations->sub_sku ?? ($line->product->sku ?? ''),
                'quantity' => (float)$line->quantity,
                'unit_price' => (float)$line->unit_price,
                'unit_price_inc_tax' => (float)$line->unit_price_inc_tax,
                'line_total' => (float)($line->unit_price_inc_tax * $line->quantity),
            ];
        }

        $invoiceData = [
            'business' => [
                'name' => $business->name ?? '',
                'tax_number' => $business->tax_number_1 ?? '',
            ],
            'location' => [
                'name' => $sale->location->name ?? '',
                'city' => $sale->location->city ?? '',
                'state' => $sale->location->state ?? '',
                'country' => $sale->location->country ?? '',
                'mobile' => $sale->location->mobile ?? '',
            ],
            'customer' => [
                'name' => $sale->contact->name ?? 'Walk-In Customer',
                'mobile' => $sale->contact->mobile ?? '',
                'tax_number' => $sale->contact->tax_number ?? '',
                'address' => $sale->contact->address_line_1 ?? '',
            ],
            'invoice_no' => $sale->invoice_no,
            'date' => $sale->transaction_date,
            'status' => $sale->status,
            'payment_status' => $sale->payment_status,
            'lines' => $lineItems,
            'summary' => [
                'total_before_tax' => (float)$sale->total_before_tax,
                'discount_type' => $sale->discount_type,
                'discount_amount' => (float)$sale->discount_amount,
                'tax_name' => $sale->tax->name ?? null,
                'tax_amount' => (float)$sale->tax_amount,
                'shipping_charges' => (float)$sale->shipping_charges,
                'final_total' => (float)$sale->final_total,
                'total_paid' => (float)$totalPaid,
                'amount_due' => (float)$due,
            ],
            'payments' => $sale->payment_lines->map(function ($p) {
                return [
                    'method' => $p->method,
                    'amount' => (float)$p->amount,
                    'paid_on' => $p->paid_on,
                ];
            }),
        ];

        return Response::success($invoiceData, 'Invoice data formatted successfully');
    }
}

