<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\Product;
use App\ProductVariation;
use App\Variation;
use App\VariationLocationDetails;
use Illuminate\Support\Facades\DB;

class ProductController extends BaseController
{
    public function index(Request $request): Response
    {
        $businessId = $request->getBusinessId();

        $query = Product::where('business_id', $businessId)
            ->with(['unit:id,actual_name,short_name', 'brand:id,name', 'category:id,name', 'product_variations.variations']);

        // Filtering
        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }
        if ($catId = $request->get('category_id')) {
            $query->where('category_id', $catId);
        }
        if ($brandId = $request->get('brand_id')) {
            $query->where('brand_id', $brandId);
        }
        if ($unitId = $request->get('unit_id')) {
            $query->where('unit_id', $unitId);
        }
        if ($request->get('not_for_selling') !== null) {
            $query->where('not_for_selling', (int)$request->get('not_for_selling'));
        }

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('sku', 'LIKE', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = strtolower($request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'name', 'sku', 'created_at', 'type'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $result = $this->paginate($query, $request);

        return Response::success($result['data'], 'Products retrieved successfully', $result['meta']);
    }

    public function show(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $product = Product::where('business_id', $businessId)
            ->where('id', $id)
            ->with([
                'unit',
                'brand',
                'category',
                'sub_category',
                'product_tax',
                'product_variations.variations.variation_location_details',
            ])
            ->first();

        if (!$product) {
            return Response::notFound("Product with ID {$id} not found");
        }

        return Response::success($product, 'Product details retrieved successfully');
    }

    public function search(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $term = trim((string)$request->get('term', $request->get('query', '')));

        if (empty($term)) {
            return Response::validationError(['term' => 'Search term is required']);
        }

        $locationId = $request->get('location_id');

        $query = Variation::join('products as p', 'p.id', '=', 'variations.product_id')
            ->where('p.business_id', $businessId)
            ->where('p.not_for_selling', 0)
            ->where(function ($q) use ($term) {
                $q->where('p.name', 'LIKE', "%{$term}%")
                  ->orWhere('p.sku', 'LIKE', "%{$term}%")
                  ->orWhere('variations.sub_sku', 'LIKE', "%{$term}%")
                  ->orWhere('variations.name', 'LIKE', "%{$term}%");
            })
            ->select(
                'p.id as product_id',
                'p.name as product_name',
                'p.type as product_type',
                'p.enable_stock',
                'variations.id as variation_id',
                'variations.name as variation_name',
                'variations.sub_sku',
                'variations.default_sell_price as selling_price',
                'variations.sell_price_inc_tax as selling_price_inc_tax',
                'variations.default_purchase_price as purchase_price'
            );

        if (!empty($locationId)) {
            $query->leftJoin('variation_location_details as vld', function ($join) use ($locationId) {
                $join->on('vld.variation_id', '=', 'variations.id')
                     ->where('vld.location_id', '=', $locationId);
            })->addSelect(DB::raw('COALESCE(vld.qty_available, 0) as qty_available'));
        }

        $results = $query->limit(30)->get();

        return Response::success($results, 'Product search results', [
            'count' => count($results),
            'term' => $term,
        ]);
    }

    public function store(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'name' => 'required|string|max:255',
            'unit_id' => 'required|integer',
            'type' => 'required|in:single,variable',
            'barcode_type' => 'nullable|string',
            'sku' => 'nullable|string|max:100',
            'enable_stock' => 'nullable|boolean',
            'alert_quantity' => 'nullable|numeric',
            'purchase_price' => 'required_if:type,single|numeric|min:0',
            'selling_price' => 'required_if:type,single|numeric|min:0',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $input = $request->all();

        return $this->dbTransaction(function () use ($businessId, $userId, $input) {
            $sku = !empty($input['sku']) ? trim($input['sku']) : ' ';

            // Check SKU uniqueness within business
            if (!empty($input['sku'])) {
                $exists = Product::where('business_id', $businessId)->where('sku', $input['sku'])->exists();
                if ($exists) {
                    return Response::validationError(['sku' => 'The SKU has already been taken for this business.']);
                }
            }

            $productData = [
                'name' => $input['name'],
                'business_id' => $businessId,
                'unit_id' => $input['unit_id'],
                'brand_id' => $input['brand_id'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'sub_category_id' => $input['sub_category_id'] ?? null,
                'tax' => $input['tax_rate_id'] ?? null,
                'tax_type' => $input['tax_type'] ?? 'exclusive',
                'type' => $input['type'],
                'enable_stock' => !empty($input['enable_stock']) ? 1 : 0,
                'alert_quantity' => $input['alert_quantity'] ?? 0,
                'sku' => $sku,
                'barcode_type' => $input['barcode_type'] ?? 'C128',
                'created_by' => $userId,
                'not_for_selling' => !empty($input['not_for_selling']) ? 1 : 0,
                'product_description' => $input['product_description'] ?? null,
            ];

            $product = Product::create($productData);

            if (empty(trim($sku))) {
                $sku = $this->productUtil->generateProductSku($product->id);
                $product->sku = $sku;
                $product->save();
            }

            // Sync locations if provided
            if (!empty($input['product_locations']) && is_array($input['product_locations'])) {
                $product->product_locations()->sync($input['product_locations']);
            }

            if ($product->type === 'single') {
                $purchasePrice = (float)($input['purchase_price'] ?? 0);
                $purchasePriceIncTax = (float)($input['purchase_price_inc_tax'] ?? $purchasePrice);
                $profitPercent = (float)($input['profit_percent'] ?? 0);
                $sellingPrice = (float)($input['selling_price'] ?? 0);
                $sellingPriceIncTax = (float)($input['selling_price_inc_tax'] ?? $sellingPrice);

                $this->productUtil->createSingleProductVariation(
                    $product->id,
                    $product->sku,
                    $purchasePrice,
                    $purchasePriceIncTax,
                    $profitPercent,
                    $sellingPrice,
                    $sellingPriceIncTax
                );
            } elseif ($product->type === 'variable' && !empty($input['variations']) && is_array($input['variations'])) {
                $this->productUtil->createVariableProductVariations($product->id, $input['variations']);
            }

            $product->load(['product_variations.variations']);

            return Response::created($product, 'Product created successfully');
        });
    }

    public function update(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $product = Product::where('business_id', $businessId)->where('id', $id)->first();
        if (!$product) {
            return Response::notFound("Product with ID {$id} not found");
        }

        $errors = $this->validate($request, [
            'name' => 'nullable|string|max:255',
            'unit_id' => 'nullable|integer',
            'alert_quantity' => 'nullable|numeric',
            'purchase_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $input = $request->all();

        return $this->dbTransaction(function () use ($product, $input) {
            $fillable = [
                'name', 'unit_id', 'brand_id', 'category_id', 'sub_category_id',
                'tax', 'tax_type', 'enable_stock', 'alert_quantity', 'barcode_type',
                'not_for_selling', 'product_description'
            ];

            foreach ($fillable as $field) {
                if (array_key_exists($field, $input)) {
                    $product->{$field} = $input[$field];
                }
            }
            $product->save();

            // Update single variation pricing if provided
            if ($product->type === 'single' && (isset($input['selling_price']) || isset($input['purchase_price']))) {
                $variation = Variation::where('product_id', $product->id)->first();
                if ($variation) {
                    if (isset($input['purchase_price'])) {
                        $variation->default_purchase_price = (float)$input['purchase_price'];
                        $variation->dpp_inc_tax = (float)($input['purchase_price_inc_tax'] ?? $input['purchase_price']);
                    }
                    if (isset($input['selling_price'])) {
                        $variation->default_sell_price = (float)$input['selling_price'];
                        $variation->sell_price_inc_tax = (float)($input['selling_price_inc_tax'] ?? $input['selling_price']);
                    }
                    $variation->save();
                }
            }

            $product->load(['unit', 'category', 'brand', 'product_variations.variations']);

            return Response::success($product, 'Product updated successfully');
        });
    }

    public function destroy(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $product = Product::where('business_id', $businessId)->where('id', $id)->first();
        if (!$product) {
            return Response::notFound("Product with ID {$id} not found");
        }

        // Check if product is referenced in transactions
        $hasTransactions = DB::table('transaction_sell_lines')
            ->where('product_id', $id)
            ->exists()
            || DB::table('purchase_lines')
            ->where('product_id', $id)
            ->exists();

        if ($hasTransactions) {
            return Response::error('Cannot delete product because transactions (sales or purchases) are linked to it. Mark as not for selling instead.', 409);
        }

        return $this->dbTransaction(function () use ($product) {
            VariationLocationDetails::where('product_id', $product->id)->delete();
            Variation::where('product_id', $product->id)->delete();
            ProductVariation::where('product_id', $product->id)->delete();
            $product->delete();

            return Response::success(null, 'Product deleted successfully');
        });
    }

    public function bulkImport(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $products = $request->input('products');
        if (!is_array($products) || empty($products)) {
            return Response::validationError(['products' => 'The products list must be a non-empty array']);
        }

        $imported = [];
        $failed = [];

        $this->dbTransaction(function () use ($businessId, $userId, $products, &$imported, &$failed) {
            foreach ($products as $index => $item) {
                if (empty($item['name']) || empty($item['unit_id'])) {
                    $failed[] = ['index' => $index, 'reason' => 'Missing name or unit_id', 'item' => $item];
                    continue;
                }

                $sku = !empty($item['sku']) ? trim($item['sku']) : ' ';

                $product = Product::create([
                    'name' => $item['name'],
                    'business_id' => $businessId,
                    'unit_id' => (int)$item['unit_id'],
                    'brand_id' => $item['brand_id'] ?? null,
                    'category_id' => $item['category_id'] ?? null,
                    'type' => 'single',
                    'enable_stock' => !empty($item['enable_stock']) ? 1 : 0,
                    'alert_quantity' => $item['alert_quantity'] ?? 0,
                    'sku' => $sku,
                    'created_by' => $userId,
                ]);

                if (empty(trim($sku))) {
                    $sku = $this->productUtil->generateProductSku($product->id);
                    $product->sku = $sku;
                    $product->save();
                }

                $purchasePrice = (float)($item['purchase_price'] ?? 0);
                $sellingPrice = (float)($item['selling_price'] ?? 0);

                $this->productUtil->createSingleProductVariation(
                    $product->id,
                    $product->sku,
                    $purchasePrice,
                    $purchasePrice,
                    0,
                    $sellingPrice,
                    $sellingPrice
                );

                $imported[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ];
            }
        });

        return Response::success([
            'imported_count' => count($imported),
            'failed_count' => count($failed),
            'imported' => $imported,
            'failed' => $failed,
        ], 'Bulk import completed');
    }
}

