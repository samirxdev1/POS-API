<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\Business;
use App\BusinessLocation;
use App\TaxRate;

class SettingController extends BaseController
{
    public function getBusiness(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $business = Business::with(['currency', 'locations'])->find($businessId);

        if (!$business) {
            return Response::notFound("Business with ID {$businessId} not found");
        }

        return Response::success($business, 'Business profile retrieved successfully');
    }

    public function updateBusiness(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $business = Business::find($businessId);

        if (!$business) {
            return Response::notFound("Business with ID {$businessId} not found");
        }

        $fields = ['name', 'time_zone', 'tax_number_1', 'tax_label_1', 'default_profit_percent'];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                $business->{$f} = $request->input($f);
            }
        }
        $business->save();

        return Response::success($business, 'Business settings updated successfully');
    }

    public function listLocations(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $locations = BusinessLocation::where('business_id', $businessId)->get();

        return Response::success($locations, 'Business locations retrieved successfully');
    }

    public function createLocation(Request $request): Response
    {
        $businessId = $request->getBusinessId();

        $errors = $this->validate($request, [
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:30',
        ]);
        if ($errors) {
            return Response::validationError($errors);
        }

        $location = BusinessLocation::create([
            'business_id' => $businessId,
            'name' => $request->input('name'),
            'city' => $request->input('city'),
            'state' => $request->input('state'),
            'country' => $request->input('country'),
            'zip_code' => $request->input('zip_code'),
            'mobile' => $request->input('mobile'),
            'is_active' => 1,
        ]);

        return Response::created($location, 'Business location created successfully');
    }

    public function listTaxRates(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $taxes = TaxRate::where('business_id', $businessId)->get();

        return Response::success($taxes, 'Tax rates retrieved successfully');
    }

    public function createTaxRate(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $userId = $request->getUserId();

        $errors = $this->validate($request, [
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'is_tax_group' => 'nullable|boolean',
        ]);
        if ($errors) {
            return Response::validationError($errors);
        }

        $tax = TaxRate::create([
            'business_id' => $businessId,
            'name' => $request->input('name'),
            'amount' => (float)$request->input('amount'),
            'is_tax_group' => !empty($request->input('is_tax_group')) ? 1 : 0,
            'created_by' => $userId,
        ]);

        return Response::created($tax, 'Tax rate created successfully');
    }
}

