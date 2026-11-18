<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\PurchaseLine;
use App\Transaction;
use App\TransactionSellLine;
use Illuminate\Support\Facades\DB;

class ReturnController extends BaseController
{
    // ================= SALE RETURNS =================

    public function listSaleReturns(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $query = Transaction::where('business_id', $businessId)
            ->where('type', 'sell_return')
            ->with(['contact:id,name,mobile', 'location:id,name', 'return_parent:id,invoice_no']);

        if ($contactId = $request->get('contact_id')) {
            $query->where('contact_id', $contactId);
        }
        if ($locationId = $request->get('location_id')) {
            $query->where('location_id', $locationId);
        }
        if ($startDate = $request->get('start_date')) {
            $query->whereDate('transaction_date', '>=', $startDate);
        }
        if ($endDate = $request->get('end_date')) {
            $query->whereDate('transaction_date', '<=', $endDate);
        }

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Sale returns retrieved successfully', $result['meta']);
    }

    public function createSaleReturn(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'sale_id' => 'required|integer',
            'returns' => 'required|array|min:1',
            'returns.*.sell_line_id' => 'required|integer',
            'returns.*.quantity' => 'required|numeric|min:0.01',
            'refund_amount' => 'nullable|numeric|min:0',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $saleId = (int)$request->input('sale_id');
        $sale = Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('id', $saleId)
            ->with('sell_lines')
            ->first();

        if (!$sale) {
            return Response::notFound("Sale with ID {$saleId} not found");
        }

        return $this->dbTransaction(function () use ($businessId, $userId, $sale, $request) {
            $returns = $request->input('returns');
            $totalRefund = 0;

            foreach ($returns as $item) {
                $line = $sale->sell_lines->where('id', $item['sell_line_id'])->first();
                if (!$line) {
                    return Response::error("Sell line ID {$item['sell_line_id']} does not belong to this sale", 422);
                }

                $qty = (float)$item['quantity'];
                $unitPrice = (float)$line->unit_price_inc_tax;
                $totalRefund += ($unitPrice * $qty);

                // Update quantity returned on the sell line
                $line->quantity_returned = ((float)$line->quantity_returned) + $qty;
                $line->save();

                // Increment inventory back
                $this->productUtil->updateProductQuantity(
                    $sale->location_id,
                    $line->product_id,
                    $line->variation_id,
                    $qty
                );
            }

            $refundAmount = $request->input('refund_amount') !== null
                ? (float)$request->input('refund_amount')
                : $totalRefund;

            $refCount = $this->productUtil->setAndGetReferenceCount('sell_return', $businessId);
            $invoiceNo = $this->productUtil->generateReferenceNumber('sell_return', $refCount, $businessId);

            $returnTransaction = Transaction::create([
                'business_id' => $businessId,
                'location_id' => $sale->location_id,
                'type' => 'sell_return',
                'status' => 'final',
                'contact_id' => $sale->contact_id,
                'invoice_no' => $invoiceNo,
                'ref_no' => $invoiceNo,
                'total_before_tax' => $refundAmount,
                'final_total' => $refundAmount,
                'transaction_date' => $request->input('transaction_date', date('Y-m-d H:i:s')),
                'created_by' => $userId,
                'return_parent_id' => $sale->id,
            ]);

            return Response::created($returnTransaction, 'Sale return processed and inventory restocked successfully');
        });
    }

    // ================= PURCHASE RETURNS =================

    public function listPurchaseReturns(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $query = Transaction::where('business_id', $businessId)
            ->where('type', 'purchase_return')
            ->with(['contact:id,name,supplier_business_name', 'location:id,name', 'return_parent:id,ref_no']);

        if ($contactId = $request->get('contact_id')) {
            $query->where('contact_id', $contactId);
        }
        if ($locationId = $request->get('location_id')) {
            $query->where('location_id', $locationId);
        }

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Purchase returns retrieved successfully', $result['meta']);
    }

    public function createPurchaseReturn(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'purchase_id' => 'required|integer',
            'returns' => 'required|array|min:1',
            'returns.*.purchase_line_id' => 'required|integer',
            'returns.*.quantity' => 'required|numeric|min:0.01',
            'refund_amount' => 'nullable|numeric|min:0',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $purchaseId = (int)$request->input('purchase_id');
        $purchase = Transaction::where('business_id', $businessId)
            ->where('type', 'purchase')
            ->where('id', $purchaseId)
            ->with('purchase_lines')
            ->first();

        if (!$purchase) {
            return Response::notFound("Purchase with ID {$purchaseId} not found");
        }

        return $this->dbTransaction(function () use ($businessId, $userId, $purchase, $request) {
            $returns = $request->input('returns');
            $totalRefund = 0;

            foreach ($returns as $item) {
                $line = $purchase->purchase_lines->where('id', $item['purchase_line_id'])->first();
                if (!$line) {
                    return Response::error("Purchase line ID {$item['purchase_line_id']} does not belong to this purchase", 422);
                }

                $qty = (float)$item['quantity'];
                $unitPrice = (float)$line->purchase_price_inc_tax;
                $totalRefund += ($unitPrice * $qty);

                $line->quantity_returned = ((float)$line->quantity_returned) + $qty;
                $line->save();

                // Deduct inventory back
                $this->productUtil->decreaseProductQuantity(
                    $line->product_id,
                    $line->variation_id,
                    $purchase->location_id,
                    $qty
                );
            }

            $refundAmount = $request->input('refund_amount') !== null
                ? (float)$request->input('refund_amount')
                : $totalRefund;

            $refCount = $this->productUtil->setAndGetReferenceCount('purchase_return', $businessId);
            $refNo = $this->productUtil->generateReferenceNumber('purchase_return', $refCount, $businessId);

            $returnTransaction = Transaction::create([
                'business_id' => $businessId,
                'location_id' => $purchase->location_id,
                'type' => 'purchase_return',
                'status' => 'final',
                'contact_id' => $purchase->contact_id,
                'ref_no' => $refNo,
                'total_before_tax' => $refundAmount,
                'final_total' => $refundAmount,
                'transaction_date' => $request->input('transaction_date', date('Y-m-d H:i:s')),
                'created_by' => $userId,
                'return_parent_id' => $purchase->id,
            ]);

            return Response::created($returnTransaction, 'Purchase return processed successfully');
        });
    }
}

