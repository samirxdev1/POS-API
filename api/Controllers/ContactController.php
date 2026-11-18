<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\Contact;
use Illuminate\Support\Facades\DB;

class ContactController extends BaseController
{
    public function index(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $query = Contact::where('business_id', $businessId);

        if ($type = $request->get('type')) {
            if ($type === 'customer') {
                $query->whereIn('type', ['customer', 'both']);
            } elseif ($type === 'supplier') {
                $query->whereIn('type', ['supplier', 'both']);
            } else {
                $query->where('type', $type);
            }
        }

        if ($status = $request->get('contact_status')) {
            $query->where('contact_status', $status);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('supplier_business_name', 'LIKE', "%{$search}%")
                  ->orWhere('mobile', 'LIKE', "%{$search}%")
                  ->orWhere('contact_id', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = strtolower($request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'name', 'created_at', 'mobile'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $result = $this->paginate($query, $request);

        return Response::success($result['data'], 'Contacts retrieved successfully', $result['meta']);
    }

    public function show(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $contact = Contact::where('business_id', $businessId)->where('id', $id)->first();
        if (!$contact) {
            return Response::notFound("Contact with ID {$id} not found");
        }

        // Calculate current total balance due
        $balance = $this->contactUtil->getContactInfo($businessId, $id);

        return Response::success([
            'contact' => $contact,
            'financial_summary' => $balance,
        ], 'Contact details retrieved successfully');
    }

    public function search(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $term = trim((string)$request->get('term', $request->get('query', '')));

        if (empty($term)) {
            return Response::validationError(['term' => 'Search term is required']);
        }

        $type = $request->get('type');

        $query = Contact::where('business_id', $businessId)
            ->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', "%{$term}%")
                  ->orWhere('supplier_business_name', 'LIKE', "%{$term}%")
                  ->orWhere('mobile', 'LIKE', "%{$term}%")
                  ->orWhere('contact_id', 'LIKE', "%{$term}%");
            });

        if ($type === 'customer') {
            $query->whereIn('type', ['customer', 'both']);
        } elseif ($type === 'supplier') {
            $query->whereIn('type', ['supplier', 'both']);
        }

        $results = $query->limit(30)->get();

        return Response::success($results, 'Contact search results', [
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
            'type' => 'required|in:customer,supplier,both',
            'mobile' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:100',
            'tax_number' => 'nullable|string|max:100',
            'opening_balance' => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric|min:0',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $input = $request->all();

        return $this->dbTransaction(function () use ($businessId, $userId, $input) {
            $contactId = !empty($input['contact_id']) ? $input['contact_id'] : null;
            if (empty($contactId)) {
                $refCount = $this->commonUtil->setAndGetReferenceCount('contacts', $businessId);
                $contactId = $this->commonUtil->generateReferenceNumber('contacts', $refCount, $businessId);
            }

            $contactData = [
                'business_id' => $businessId,
                'type' => $input['type'],
                'name' => $input['name'],
                'prefix' => $input['prefix'] ?? null,
                'first_name' => $input['first_name'] ?? $input['name'],
                'last_name' => $input['last_name'] ?? null,
                'supplier_business_name' => $input['supplier_business_name'] ?? null,
                'contact_id' => $contactId,
                'tax_number' => $input['tax_number'] ?? null,
                'city' => $input['city'] ?? null,
                'state' => $input['state'] ?? null,
                'country' => $input['country'] ?? null,
                'address_line_1' => $input['address_line_1'] ?? null,
                'address_line_2' => $input['address_line_2'] ?? null,
                'zip_code' => $input['zip_code'] ?? null,
                'mobile' => $input['mobile'] ?? null,
                'email' => $input['email'] ?? null,
                'credit_limit' => $input['credit_limit'] ?? null,
                'created_by' => $userId,
                'contact_status' => 'active',
            ];

            $contact = Contact::create($contactData);

            // Create opening balance transaction if specified
            $openingBalance = (float)($input['opening_balance'] ?? 0);
            if ($openingBalance != 0) {
                $this->transactionUtil->createOpeningBalanceTransaction($businessId, $contact->id, $openingBalance, $userId, false);
            }

            return Response::created($contact, 'Contact created successfully');
        });
    }

    public function update(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $contact = Contact::where('business_id', $businessId)->where('id', $id)->first();
        if (!$contact) {
            return Response::notFound("Contact with ID {$id} not found");
        }

        $errors = $this->validate($request, [
            'name' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:100',
            'credit_limit' => 'nullable|numeric|min:0',
            'contact_status' => 'nullable|in:active,inactive',
        ]);

        if ($errors) {
            return Response::validationError($errors);
        }

        $fields = [
            'name', 'first_name', 'last_name', 'supplier_business_name', 'mobile',
            'email', 'tax_number', 'city', 'state', 'country', 'address_line_1',
            'address_line_2', 'zip_code', 'credit_limit', 'contact_status',
        ];

        foreach ($fields as $field) {
            if ($request->input($field) !== null) {
                $contact->{$field} = $request->input($field);
            }
        }
        $contact->save();

        return Response::success($contact, 'Contact updated successfully');
    }

    public function destroy(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $contact = Contact::where('business_id', $businessId)->where('id', $id)->first();
        if (!$contact) {
            return Response::notFound("Contact with ID {$id} not found");
        }

        // Check if transactions exist
        $hasTransactions = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('contact_id', $id)
            ->exists();

        if ($hasTransactions) {
            return Response::error('Cannot delete contact because transactions (sales, purchases, or payments) are associated with this contact. Mark contact as inactive instead.', 409);
        }

        $contact->delete();
        return Response::success(null, 'Contact deleted successfully');
    }

    public function ledger(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $contact = Contact::where('business_id', $businessId)->where('id', $id)->first();
        if (!$contact) {
            return Response::notFound("Contact with ID {$id} not found");
        }

        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $locationId = $request->get('location_id');

        try {
            $ledgerDetails = $this->transactionUtil->getLedgerDetails($id, $startDate, $endDate, 'format_1', $locationId, false);
            return Response::success([
                'contact' => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'type' => $contact->type,
                    'mobile' => $contact->mobile,
                    'email' => $contact->email,
                ],
                'ledger' => $ledgerDetails,
            ], 'Contact ledger retrieved successfully');
        } catch (\Throwable $e) {
            return Response::serverError('Failed to calculate ledger: ' . $e->getMessage());
        }
    }
}

