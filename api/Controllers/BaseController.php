<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\Utils\BusinessUtil;
use App\Utils\ContactUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use App\Utils\Util;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

abstract class BaseController
{
    protected ProductUtil $productUtil;
    protected TransactionUtil $transactionUtil;
    protected ContactUtil $contactUtil;
    protected BusinessUtil $businessUtil;
    protected Util $commonUtil;

    public function __construct()
    {
        $this->productUtil = app(ProductUtil::class);
        $this->transactionUtil = app(TransactionUtil::class);
        $this->contactUtil = app(ContactUtil::class);
        $this->businessUtil = app(BusinessUtil::class);
        $this->commonUtil = app(Util::class);
    }

    protected function validate(Request $request, array $rules, array $messages = []): ?array
    {
        $validator = Validator::make($request->all(), $rules, $messages);
        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }
        return null;
    }

    protected function paginate($query, Request $request): array
    {
        $perPage = (int)$request->get('per_page', 20);
        if ($perPage < 1) {
            $perPage = 20;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $page = (int)$request->get('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        $total = $query->count();
        $items = $query->forPage($page, $perPage)->get();

        $lastPage = (int)ceil($total / $perPage);
        if ($lastPage < 1) {
            $lastPage = 1;
        }

        return [
            'data' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
            ],
        ];
    }

    protected function dbTransaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}

