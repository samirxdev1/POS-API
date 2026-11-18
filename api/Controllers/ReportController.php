<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\Product;
use App\Transaction;
use App\VariationLocationDetails;
use Illuminate\Support\Facades\DB;

class ReportController extends BaseController
{
    public function sales(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $startDate = $request->get('start_date', date('Y-m-01'));
        $endDate = $request->get('end_date', date('Y-m-d'));
        $groupBy = $request->get('group_by', 'day'); // 'day' or 'month'
        $locationId = $request->get('location_id');

        $dateFormat = $groupBy === 'month' ? '%Y-%m' : '%Y-%m-%d';

        $query = Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereDate('transaction_date', '>=', $startDate)
            ->whereDate('transaction_date', '<=', $endDate);

        if (!empty($locationId)) {
            $query->where('location_id', $locationId);
        }

        $summary = (clone $query)->select(
            DB::raw('COUNT(id) as total_invoices'),
            DB::raw('COALESCE(SUM(total_before_tax), 0) as total_before_tax'),
            DB::raw('COALESCE(SUM(tax_amount), 0) as total_tax'),
            DB::raw('COALESCE(SUM(discount_amount), 0) as total_discount'),
            DB::raw('COALESCE(SUM(shipping_charges), 0) as total_shipping'),
            DB::raw('COALESCE(SUM(final_total), 0) as total_sales')
        )->first();

        $periods = $query->select(
            DB::raw("DATE_FORMAT(transaction_date, '{$dateFormat}') as period"),
            DB::raw('COUNT(id) as invoice_count'),
            DB::raw('COALESCE(SUM(total_before_tax), 0) as subtotal'),
            DB::raw('COALESCE(SUM(tax_amount), 0) as tax'),
            DB::raw('COALESCE(SUM(final_total), 0) as total')
        )
        ->groupBy('period')
        ->orderBy('period', 'asc')
        ->get();

        return Response::success([
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'group_by' => $groupBy,
                'location_id' => $locationId,
            ],
            'summary' => $summary,
            'breakdown' => $periods,
        ], 'Sales report generated successfully');
    }

    public function profitLoss(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $startDate = $request->get('start_date', date('Y-m-01'));
        $endDate = $request->get('end_date', date('Y-m-d'));
        $locationId = $request->get('location_id');

        // Total Sales
        $salesQuery = Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereDate('transaction_date', '>=', $startDate)
            ->whereDate('transaction_date', '<=', $endDate);

        if (!empty($locationId)) {
            $salesQuery->where('location_id', $locationId);
        }
        $totalSales = (float)$salesQuery->sum('final_total');

        // Total Purchases
        $purchasesQuery = Transaction::where('business_id', $businessId)
            ->where('type', 'purchase')
            ->where('status', 'received')
            ->whereDate('transaction_date', '>=', $startDate)
            ->whereDate('transaction_date', '<=', $endDate);

        if (!empty($locationId)) {
            $purchasesQuery->where('location_id', $locationId);
        }
        $totalPurchases = (float)$purchasesQuery->sum('final_total');

        // Total Expenses
        $expensesQuery = Transaction::where('business_id', $businessId)
            ->where('type', 'expense')
            ->whereDate('transaction_date', '>=', $startDate)
            ->whereDate('transaction_date', '<=', $endDate);

        if (!empty($locationId)) {
            $expensesQuery->where('location_id', $locationId);
        }
        $totalExpenses = (float)$expensesQuery->sum('final_total');

        // Approximate COGS from Sell Lines * Purchase Price
        $cogsQuery = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $startDate)
            ->whereDate('t.transaction_date', '<=', $endDate);

        if (!empty($locationId)) {
            $cogsQuery->where('t.location_id', $locationId);
        }
        $totalCogs = (float)$cogsQuery->select(DB::raw('COALESCE(SUM(tsl.quantity * v.default_purchase_price), 0) as cogs'))->value('cogs');

        $grossProfit = $totalSales - $totalCogs;
        $netProfit = $grossProfit - $totalExpenses;

        return Response::success([
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'location_id' => $locationId,
            ],
            'total_sales' => $totalSales,
            'total_cogs' => $totalCogs,
            'total_purchases' => $totalPurchases,
            'total_expenses' => $totalExpenses,
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
        ], 'Profit and loss summary retrieved');
    }

    public function topProducts(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $startDate = $request->get('start_date', date('Y-m-01'));
        $endDate = $request->get('end_date', date('Y-m-d'));
        $limit = (int)$request->get('limit', 10);
        $locationId = $request->get('location_id');

        $query = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $startDate)
            ->whereDate('t.transaction_date', '<=', $endDate);

        if (!empty($locationId)) {
            $query->where('t.location_id', $locationId);
        }

        $top = $query->select(
            'p.id as product_id',
            'p.name as product_name',
            'p.sku',
            'v.name as variation_name',
            'u.short_name as unit',
            DB::raw('SUM(tsl.quantity) as total_quantity_sold'),
            DB::raw('SUM(tsl.quantity * tsl.unit_price_inc_tax) as total_revenue')
        )
        ->groupBy('p.id', 'p.name', 'p.sku', 'v.name', 'u.short_name')
        ->orderBy('total_quantity_sold', 'desc')
        ->limit($limit)
        ->get();

        return Response::success($top, 'Top selling products retrieved', [
            'count' => count($top),
        ]);
    }

    public function stockValuation(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $locationId = $request->get('location_id');

        $query = VariationLocationDetails::join('products as p', 'p.id', '=', 'variation_location_details.product_id')
            ->join('variations as v', 'v.id', '=', 'variation_location_details.variation_id')
            ->where('p.business_id', $businessId)
            ->where('p.enable_stock', 1);

        if (!empty($locationId)) {
            $query->where('variation_location_details.location_id', $locationId);
        }

        $valuation = $query->select(
            DB::raw('COUNT(DISTINCT p.id) as total_products'),
            DB::raw('COALESCE(SUM(variation_location_details.qty_available), 0) as total_stock_units'),
            DB::raw('COALESCE(SUM(variation_location_details.qty_available * v.default_purchase_price), 0) as valuation_by_purchase_price'),
            DB::raw('COALESCE(SUM(variation_location_details.qty_available * v.default_sell_price), 0) as valuation_by_selling_price')
        )->first();

        $potentialProfit = (float)$valuation->valuation_by_selling_price - (float)$valuation->valuation_by_purchase_price;

        return Response::success([
            'location_id' => $locationId,
            'total_products' => (int)$valuation->total_products,
            'total_stock_units' => (float)$valuation->total_stock_units,
            'valuation_by_purchase_price' => (float)$valuation->valuation_by_purchase_price,
            'valuation_by_selling_price' => (float)$valuation->valuation_by_selling_price,
            'potential_profit' => $potentialProfit,
        ], 'Stock valuation calculated successfully');
    }

    public function taxSummary(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $startDate = $request->get('start_date', date('Y-m-01'));
        $endDate = $request->get('end_date', date('Y-m-d'));

        // Output Tax (collected on sales)
        $outputTax = (float)Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereDate('transaction_date', '>=', $startDate)
            ->whereDate('transaction_date', '<=', $endDate)
            ->sum('tax_amount');

        // Input Tax (paid on purchases)
        $inputTax = (float)Transaction::where('business_id', $businessId)
            ->where('type', 'purchase')
            ->where('status', 'received')
            ->whereDate('transaction_date', '>=', $startDate)
            ->whereDate('transaction_date', '<=', $endDate)
            ->sum('tax_amount');

        $netTaxPayable = $outputTax - $inputTax;

        return Response::success([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'output_tax' => $outputTax,
            'input_tax' => $inputTax,
            'net_tax_payable' => $netTaxPayable,
        ], 'Tax summary calculated successfully');
    }
}

