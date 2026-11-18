<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\Brands;
use App\Category;
use App\Unit;
use App\Warranty;

class TaxonomyController extends BaseController
{
    // ================= CATEGORIES =================

    public function listCategories(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $query = Category::where('business_id', $businessId);

        if ($type = $request->get('category_type')) {
            $query->where('category_type', $type);
        }
        if ($parentOnly = $request->get('parent_only')) {
            $query->where('parent_id', 0);
        }
        if ($search = $request->get('search')) {
            $query->where('name', 'LIKE', "%{$search}%");
        }

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Categories retrieved', $result['meta']);
    }

    public function getCategory(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');
        $cat = Category::where('business_id', $businessId)->where('id', $id)->first();
        if (!$cat) {
            return Response::notFound("Category with ID {$id} not found");
        }
        return Response::success($cat, 'Category retrieved');
    }

    public function createCategory(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'name' => 'required|string|max:255',
            'short_code' => 'nullable|string|max:50',
            'parent_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'category_type' => 'nullable|string',
        ]);
        if ($errors) {
            return Response::validationError($errors);
        }

        $cat = Category::create([
            'name' => $request->input('name'),
            'business_id' => $businessId,
            'short_code' => $request->input('short_code'),
            'parent_id' => (int)$request->input('parent_id', 0),
            'description' => $request->input('description'),
            'category_type' => $request->input('category_type', 'product'),
            'created_by' => $userId,
        ]);

        return Response::created($cat, 'Category created successfully');
    }

    public function updateCategory(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');
        $cat = Category::where('business_id', $businessId)->where('id', $id)->first();
        if (!$cat) {
            return Response::notFound("Category with ID {$id} not found");
        }

        $fields = ['name', 'short_code', 'parent_id', 'description', 'category_type'];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                $cat->{$f} = $request->input($f);
            }
        }
        $cat->save();

        return Response::success($cat, 'Category updated successfully');
    }

    public function deleteCategory(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');
        $cat = Category::where('business_id', $businessId)->where('id', $id)->first();
        if (!$cat) {
            return Response::notFound("Category with ID {$id} not found");
        }

        $cat->delete();
        return Response::success(null, 'Category deleted successfully');
    }

    // ================= BRANDS =================

    public function listBrands(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $query = Brands::where('business_id', $businessId);

        if ($search = $request->get('search')) {
            $query->where('name', 'LIKE', "%{$search}%");
        }

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Brands retrieved', $result['meta']);
    }

    public function getBrand(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');
        $brand = Brands::where('business_id', $businessId)->where('id', $id)->first();
        if (!$brand) {
            return Response::notFound("Brand with ID {$id} not found");
        }
        return Response::success($brand, 'Brand retrieved');
    }

    public function createBrand(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);
        if ($errors) {
            return Response::validationError($errors);
        }

        $brand = Brands::create([
            'name' => $request->input('name'),
            'business_id' => $businessId,
            'description' => $request->input('description'),
            'created_by' => $userId,
        ]);

        return Response::created($brand, 'Brand created successfully');
    }

    public function updateBrand(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');
        $brand = Brands::where('business_id', $businessId)->where('id', $id)->first();
        if (!$brand) {
            return Response::notFound("Brand with ID {$id} not found");
        }

        if ($request->input('name') !== null) {
            $brand->name = $request->input('name');
        }
        if ($request->input('description') !== null) {
            $brand->description = $request->input('description');
        }
        $brand->save();

        return Response::success($brand, 'Brand updated successfully');
    }

    public function deleteBrand(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');
        $brand = Brands::where('business_id', $businessId)->where('id', $id)->first();
        if (!$brand) {
            return Response::notFound("Brand with ID {$id} not found");
        }

        $brand->delete();
        return Response::success(null, 'Brand deleted successfully');
    }

    // ================= UNITS =================

    public function listUnits(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $query = Unit::where('business_id', $businessId);

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Units retrieved', $result['meta']);
    }

    public function getUnit(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');
        $unit = Unit::where('business_id', $businessId)->where('id', $id)->first();
        if (!$unit) {
            return Response::notFound("Unit with ID {$id} not found");
        }
        return Response::success($unit, 'Unit retrieved');
    }

    public function createUnit(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'actual_name' => 'required|string|max:255',
            'short_name' => 'required|string|max:50',
            'allow_decimal' => 'nullable|boolean',
        ]);
        if ($errors) {
            return Response::validationError($errors);
        }

        $unit = Unit::create([
            'business_id' => $businessId,
            'actual_name' => $request->input('actual_name'),
            'short_name' => $request->input('short_name'),
            'allow_decimal' => !empty($request->input('allow_decimal')) ? 1 : 0,
            'created_by' => $userId,
        ]);

        return Response::created($unit, 'Unit created successfully');
    }

    public function updateUnit(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');
        $unit = Unit::where('business_id', $businessId)->where('id', $id)->first();
        if (!$unit) {
            return Response::notFound("Unit with ID {$id} not found");
        }

        if ($request->input('actual_name') !== null) {
            $unit->actual_name = $request->input('actual_name');
        }
        if ($request->input('short_name') !== null) {
            $unit->short_name = $request->input('short_name');
        }
        if ($request->input('allow_decimal') !== null) {
            $unit->allow_decimal = (int)$request->input('allow_decimal');
        }
        $unit->save();

        return Response::success($unit, 'Unit updated successfully');
    }

    public function deleteUnit(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');
        $unit = Unit::where('business_id', $businessId)->where('id', $id)->first();
        if (!$unit) {
            return Response::notFound("Unit with ID {$id} not found");
        }

        $unit->delete();
        return Response::success(null, 'Unit deleted successfully');
    }

    // ================= WARRANTIES =================

    public function listWarranties(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $query = Warranty::where('business_id', $businessId);

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Warranties retrieved', $result['meta']);
    }

    public function createWarranty(Request $request): Response
    {
        $businessId = $request->getBusinessId();

        $errors = $this->validate($request, [
            'name' => 'required|string|max:255',
            'duration' => 'required|numeric',
            'duration_type' => 'required|in:days,months,years',
            'description' => 'nullable|string',
        ]);
        if ($errors) {
            return Response::validationError($errors);
        }

        $w = Warranty::create([
            'business_id' => $businessId,
            'name' => $request->input('name'),
            'duration' => $request->input('duration'),
            'duration_type' => $request->input('duration_type'),
            'description' => $request->input('description'),
        ]);

        return Response::created($w, 'Warranty created successfully');
    }
}

