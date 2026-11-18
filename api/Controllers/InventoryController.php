<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\Product;
use App\StockAdjustmentLine;
use App\Transaction;
use App\VariationLocationDetails;
use Illuminate\Support\Facades\DB;

class InventoryController extends BaseController
{
    public function getStock(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $locationId = $request->get('location_id');
        $productId = $request->get('product_id');

        $query = VariationLocationDetails::join('products as p', 'p.id', '=', 'variation_location_details.product_id')
            ->join('variations as v', 'v.id', '=', 'variation_location_details.variation_id')
            ->leftJoin('business_locations as l', 'l.id', '=', 'variation_location_details.location_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.business_id', $businessId)
            ->select(
                'variation_location_details.id as id',
                'variation_location_details.location_id',
                'l.name as location_name',
                'p.id as product_id',
                'p.name as product_name',
                'p.type as product_type',
                'p.sku as product_sku',
                'p.alert_quantity',
                'u.short_name as unit',
                'v.id as variation_id',
                'v.name as variation_name',
                'v.sub_sku',
                'v.default_sell_price as selling_price',
                'v.default_purchase_price as purchase_price',
                'variation_location_details.qty_available'
            );

        if (!empty($locationId)) {
            $query->where('variation_location_details.location_id', $locationId);
        }
        if (!empty($productId)) {
            $query->where('p.id', $productId);
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'LIKE', "%{$search}%")
                  ->orWhere('p.sku', 'LIKE', "%{$search}%")
                  ->orWhere('v.sub_sku', 'LIKE', "%{$search}%");
            });
        }

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Inventory stock retrieved successfully', $result['meta']);
    }

    public function adjustStock(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'location_id' => 'required|integer',
            'adjustment_type' => 'required|in:normal,abnormal',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.variation_id' => 'required|integer',
            'products.*.quantity' => 'required|numeric',
            'products.*.unit_price' => 'required|numeric|min:0',
            'total_amount_recovered' => 'nullable|numeric',
            'additional_notes' => 'nullable|string',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $input = $request->all();

        return $this->dbTransaction(function () use ($businessId, $userId, $input) {
            $refCount = $this->productUtil->setAndGetReferenceCount('stock_adjustment', $businessId);
            $refNo = $input['ref_no'] ?? $this->productUtil->generateReferenceNumber('stock_adjustment', $refCount, $businessId);

            $totalAmount = 0;
            foreach ($input['products'] as $p) {
                $totalAmount += ((float)$p['quantity'] * (float)$p['unit_price']);
            }

            $transaction = Transaction::create([
                'type' => 'stock_adjustment',
                'status' => 'received',
                'business_id' => $businessId,
                'location_id' => $input['location_id'],
                'ref_no' => $refNo,
                'transaction_date' => $input['transaction_date'] ?? date('Y-m-d H:i:s'),
                'total_amount_recovered' => (float)($input['total_amount_recovered'] ?? 0),
                'final_total' => $totalAmount,
                'adjustment_type' => $input['adjustment_type'],
                'additional_notes' => $input['additional_notes'] ?? null,
                'created_by' => $userId,
            ]);

            $lines = [];
            foreach ($input['products'] as $p) {
                $qty = (float)$p['quantity'];
                $lines[] = [
                    'product_id' => $p['product_id'],
                    'variation_id' => $p['variation_id'],
                    'quantity' => $qty,
                    'unit_price' => (float)$p['unit_price'],
                ];

                // Decrease or increase stock depending on adjustment sign/type
                $this->productUtil->decreaseProductQuantity(
                    $p['product_id'],
                    $p['variation_id'],
                    $input['location_id'],
                    $qty
                );
            }

            $transaction->stock_adjustment_lines()->createMany($lines);
            $transaction->load('stock_adjustment_lines');

            return Response::created($transaction, 'Stock adjustment recorded successfully');
        });
    }

    public function getLowStock(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $locationId = $request->get('location_id');

        $query = VariationLocationDetails::join('products as p', 'p.id', '=', 'variation_location_details.product_id')
            ->join('variations as v', 'v.id', '=', 'variation_location_details.variation_id')
            ->leftJoin('business_locations as l', 'l.id', '=', 'variation_location_details.location_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.business_id', $businessId)
            ->where('p.enable_stock', 1)
            ->whereRaw('variation_location_details.qty_available <= p.alert_quantity')
            ->select(
                'p.id as product_id',
                'p.name as product_name',
                'p.sku as product_sku',
                'p.alert_quantity',
                'u.short_name as unit',
                'v.id as variation_id',
                'v.name as variation_name',
                'v.sub_sku',
                'variation_location_details.location_id',
                'l.name as location_name',
                'variation_location_details.qty_available'
            );

        if (!empty($locationId)) {
            $query->where('variation_location_details.location_id', $locationId);
        }

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Low-stock items retrieved successfully', $result['meta']);
    }

    public function getHistory(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $productId = $request->get('product_id');
        $variationId = $request->get('variation_id');
        $locationId = $request->get('location_id');

        // Combined history from sales, purchases, stock adjustments
        $salesQuery = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->select(
                't.id as transaction_id',
                't.ref_no',
                't.invoice_no',
                't.type',
                't.transaction_date',
                't.location_id',
                'tsl.product_id',
                'tsl.variation_id',
                DB::raw('(-1 * tsl.quantity) as quantity_change'),
                'tsl.unit_price_inc_tax as unit_price',
                DB::raw("'sale' as movement_type")
            );

        if ($productId) {
            $salesQuery->where('tsl.product_id', $productId);
        }
        if ($variationId) {
            $salesQuery->where('tsl.variation_id', $variationId);
        }
        if ($locationId) {
            $salesQuery->where('t.location_id', $locationId);
        }

        $purchasesQuery = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'purchase')
            ->select(
                't.id as transaction_id',
                't.ref_no',
                DB::raw('NULL as invoice_no'),
                't.type',
                't.transaction_date',
                't.location_id',
                'pl.product_id',
                'pl.variation_id',
                'pl.quantity as quantity_change',
                'pl.purchase_price_inc_tax as unit_price',
                DB::raw("'purchase' as movement_type")
            );

        if ($productId) {
            $purchasesQuery->where('pl.product_id', $productId);
        }
        if ($variationId) {
            $purchasesQuery->where('pl.variation_id', $variationId);
        }
        if ($locationId) {
            $purchasesQuery->where('t.location_id', $locationId);
        }

        $unionQuery = $salesQuery->unionAll($purchasesQuery)->orderBy('transaction_date', 'desc');

        $result = $this->paginate($unionQuery, $request);
        return Response::success($result['data'], 'Stock movement history retrieved', $result['meta']);
    }
}

