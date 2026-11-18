# UltimatePOS REST API Specification (v1)

> **Purpose:** Secure, token-authenticated REST API layer designed for AI agents, background automations, and third-party integrations to interact with all core features of the UltimatePOS Point of Sale system.

---

## Table of Contents
1. [Overview](#1-overview)
2. [Authentication & Token Generation](#2-authentication--token-generation)
3. [Base URL & Request Headers](#3-base-url--request-headers)
4. [Standard Response Format](#4-standard-response-format)
5. [HTTP Status & Error Codes](#5-http-status--error-codes)
6. [Pagination, Filtering & Sorting](#6-pagination-filtering--sorting)
7. [Discovery & Health Check](#7-discovery--health-check)
8. [Module Endpoint Reference](#8-module-endpoint-reference)
   - [8.1 Products & Variations](#81-products--variations)
   - [8.2 Taxonomies (Categories, Brands, Units, Warranties)](#82-taxonomies)
   - [8.3 Inventory & Stock Management](#83-inventory--stock-management)
   - [8.4 Contacts (Customers & Suppliers)](#84-contacts)
   - [8.5 Sales & POS Billing](#85-sales--pos-billing)
   - [8.6 Returns & Refunds](#86-returns--refunds)
   - [8.7 Purchases & Receiving](#87-purchases--receiving)
   - [8.8 Payments & Balances](#88-payments--balances)
   - [8.9 Expenses](#89-expenses)
   - [8.10 Business Reports](#810-business-reports)
   - [8.11 Users & Roles](#811-users--roles)
   - [8.12 Settings & Locations](#812-settings--locations)
9. [OpenAPI 3.0 & Postman Testing](#9-openapi-30--postman-testing)
10. [Setup & Quick Start Guide](#10-setup--quick-start-guide)
11. [Changelog](#11-changelog)

---

## 1. Overview
The UltimatePOS REST API is an additive, non-breaking architectural layer located at `/api` with entry point `/api/index.php`. It initializes the underlying Laravel application kernel to reuse domain models, helper classes (`ProductUtil`, `TransactionUtil`, `ContactUtil`, `BusinessUtil`), database transactions, and business validation rules. All multi-step database mutations are wrapped inside ACID transactions.

---

## 2. Authentication & Token Generation

### Static Test Token (For Rapid Testing & Development)
To start testing immediately without running migrations or inserting database records, a **static test key** is supported directly via `.env` or code:

- **Default Static Token:** `pos_test_token_2026_antigravity`
- **Configurable via `.env`:**
  ```env
  API_STATIC_TEST_TOKEN=pos_test_token_2026_antigravity
  API_TEST_BUSINESS_ID=1
  API_TEST_USER_ID=1
  ```
- Any request passing `Authorization: Bearer pos_test_token_2026_antigravity` is authenticated as Super Admin with full wildcard `*` permissions.

### Production Tokens (Database Backed)
- In production, tokens are generated via the CLI and stored as SHA-256 hashes in `api_tokens`.
- The raw token is a **64-character hexadecimal string** generated cryptographically via `bin2hex(random_bytes(32))`.
- Only the **SHA-256 hash** of the token is stored in the `api_tokens` database table. Plain-text tokens are never stored.
- Hashes are verified using timing-attack safe `hash_equals()`.
- Access tokens can be associated with a `business_id` and `user_id` to scope data and audit actions.

### Token Generator CLI
Generate an agent token via the command line:

```bash
php api/tools/generate_token.php --name="AI Agent" --business-id=1 --user-id=1 --scopes="*"
```

Options:
- `--name`: Identifier label for the agent or client (default: `AI Agent`).
- `--business-id`: The business tenant ID (default: `1`).
- `--user-id`: Audited user ID for recorded actions (default: `1`).
- `--scopes`: Comma-separated list of scopes (default: `*` for full permissions).
- `--days`: Expiration in days (optional, defaults to never expires).

> **Important:** The CLI script displays the raw token **only once**. Save it in your `.env` or agent secret manager immediately.

---

## 3. Base URL & Request Headers

### Base URL
- **Local:** `http://localhost/api/v1`
- **Subdirectory:** `http://localhost/UltimatePOS-CodeBase-V7.4.1/api/v1`

### Standard Request Headers
```http
Authorization: Bearer <64_HEX_TOKEN>
Content-Type: application/json
Accept: application/json
X-Business-Id: 1 (optional, if overriding default tenant)
```

---

## 4. Standard Response Format

All API responses are formatted uniformly in JSON:

### Success Response
```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": { ... },
  "meta": {
    "total": 120,
    "page": 1,
    "per_page": 20,
    "last_page": 6,
    "has_more": true
  }
}
```

### Error Response
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "sku": "The SKU has already been taken for this business."
  }
}
```

---

## 5. HTTP Status & Error Codes

| Status | Meaning | Description |
|---|---|---|
| `200 OK` | Success | Request succeeded. |
| `201 Created` | Created | Resource successfully created. |
| `400 Bad Request` | Client Error | Malformed request or invalid parameters. |
| `401 Unauthorized` | Auth Error | Missing, expired, or invalid API token. |
| `403 Forbidden` | Forbidden | Token is deactivated or lacks requested scope. |
| `404 Not Found` | Not Found | Requested entity or route does not exist. |
| `409 Conflict` | Conflict | Cannot delete entity due to existing transaction constraints. |
| `422 Unprocessable`| Validation | Field validation failed. |
| `429 Too Many` | Rate Limited | Sliding window rate limit exceeded (120 req/min). |
| `500 Server Error` | Server Error | Internal unhandled server exception. |

---

## 6. Pagination, Filtering & Sorting

List endpoints accept standard pagination and search query parameters:

- `page`: Page number (default: `1`)
- `per_page`: Records per page (default: `20`, max: `100`)
- `search`: Search query string
- `sort_by`: Column to sort by (e.g. `id`, `created_at`, `final_total`)
- `sort_order`: `asc` or `desc` (default: `desc`)

---

## 7. Discovery & Health Check

### 7.1 Health Check
- **Endpoint:** `GET /api/v1/ping`
- **Auth:** Public
- **Description:** Verifies API availability, PHP version, and database connectivity.

**cURL Example:**
```bash
curl -X GET "http://localhost/api/v1/ping"
```

**Success Response (200 OK):**
```json
{
  "success": true,
  "message": "API is online and healthy",
  "data": {
    "status": "healthy",
    "api_version": "1.0.0",
    "app": "UltimatePOS REST API",
    "php_version": "8.2.12",
    "database": "connected",
    "timestamp": "2026-11-18T07:25:00+00:00"
  }
}
```

---

### 7.2 Endpoint Catalog Discovery
- **Endpoint:** `GET /api/v1/meta/endpoints`
- **Auth:** Public / Bearer
- **Description:** Returns JSON catalog of all API routes, methods, descriptions, required parameters, and permission scopes for automated tool selection by LLM agents.

**Success Response (200 OK):**
```json
{
  "success": true,
  "message": "Endpoint catalog retrieved",
  "data": [
    {
      "method": "GET",
      "path": "/api/v1/products",
      "description": "List products with filters, search, and pagination",
      "scopes": ["products:read"],
      "required_params": []
    }
  ]
}
```

---

## 8. Module Endpoint Reference

### 8.1 Products & Variations

#### List Products
- **`GET /api/v1/products`**
- **Query Params:** `page`, `per_page`, `search`, `category_id`, `brand_id`, `unit_id`, `type` (`single`|`variable`)
- **Headers:** `Authorization: Bearer <TOKEN>`

#### Search Products
- **`GET /api/v1/products/search?term=<keyword>&location_id=1`**
- **Description:** Searches products and sub-SKUs with available stock for POS quick-add.

#### Get Product by ID
- **`GET /api/v1/products/{id}`**

#### Create Product
- **`POST /api/v1/products`**
- **Body:**
```json
{
  "name": "Logitech Wireless Mouse M185",
  "unit_id": 1,
  "type": "single",
  "sku": "LOG-M185",
  "barcode_type": "C128",
  "enable_stock": true,
  "alert_quantity": 5,
  "purchase_price": 12.00,
  "selling_price": 19.99
}
```

#### Update Product
- **`PUT /api/v1/products/{id}`**

#### Delete Product
- **`DELETE /api/v1/products/{id}`**
- *Note:* If transactions are attached to the product, returns HTTP 409 Conflict.

#### Bulk Import Products
- **`POST /api/v1/products/bulk-import`**
- **Body:** `{ "products": [ { "name": "Item 1", "unit_id": 1, "purchase_price": 5, "selling_price": 10 } ] }`

---

### 8.2 Taxonomies
- **Categories:**
  - `GET /api/v1/categories`
  - `POST /api/v1/categories` `{ "name": "Electronics", "short_code": "ELEC" }`
  - `GET /api/v1/categories/{id}`
  - `PUT /api/v1/categories/{id}`
  - `DELETE /api/v1/categories/{id}`
- **Brands:**
  - `GET /api/v1/brands`, `POST /api/v1/brands`, `PUT /api/v1/brands/{id}`, `DELETE /api/v1/brands/{id}`
- **Units:**
  - `GET /api/v1/units`, `POST /api/v1/units` `{ "actual_name": "Pieces", "short_name": "Pc" }`, `DELETE /api/v1/units/{id}`
- **Warranties:**
  - `GET /api/v1/warranties`, `POST /api/v1/warranties` `{ "name": "1 Year", "duration": 1, "duration_type": "years" }`

---

### 8.3 Inventory & Stock Management

#### Get Real-time Stock
- **`GET /api/v1/inventory/stock`**
- **Query Params:** `location_id`, `product_id`, `search`

#### Stock Adjustment
- **`POST /api/v1/inventory/adjust`**
- **Description:** Deducts stock and records a `stock_adjustment` transaction.
- **Body:**
```json
{
  "location_id": 1,
  "adjustment_type": "normal",
  "additional_notes": "Breakage during transit",
  "products": [
    {
      "product_id": 1,
      "variation_id": 1,
      "quantity": 2,
      "unit_price": 12.00
    }
  ]
}
```

#### Low Stock Alert List
- **`GET /api/v1/inventory/low-stock?location_id=1`**
- Returns products where current quantity is less than or equal to `alert_quantity`.

#### Stock Movement Audit History
- **`GET /api/v1/inventory/history?product_id=1`**
- Returns unified ledger of sales, purchases, and adjustments affecting inventory.

---

### 8.4 Contacts

#### List Contacts
- **`GET /api/v1/contacts?type=customer`** (`customer`, `supplier`, or `both`)

#### Create Contact
- **`POST /api/v1/contacts`**
- **Body:**
```json
{
  "name": "Acme Industries",
  "type": "customer",
  "mobile": "+1555001122",
  "email": "info@acme.com",
  "tax_number": "TAX-12345",
  "opening_balance": 0.00,
  "credit_limit": 5000.00
}
```

#### Get Contact Financial Ledger
- **`GET /api/v1/contacts/{id}/ledger?start_date=2026-01-01&end_date=2026-11-18`**
- Calculates debit, credit, opening balance, payments, and balance due.

---

### 8.5 Sales & POS Billing

#### Create Sale
- **`POST /api/v1/sales`**
- **Description:** Automatically calculates totals, applies discounts and taxes, adds payment lines, updates payment status, and decrements stock if status is `final`.
- **Body:**
```json
{
  "location_id": 1,
  "contact_id": 1,
  "status": "final",
  "discount_type": "fixed",
  "discount_amount": 2.00,
  "products": [
    {
      "product_id": 1,
      "variation_id": 1,
      "quantity": 2,
      "unit_price": 19.99
    }
  ],
  "payments": [
    {
      "amount": 37.98,
      "method": "cash",
      "note": "Paid in cash"
    }
  ]
}
```

#### Void / Cancel Sale
- **`POST /api/v1/sales/{id}/void`**
- Cancels the transaction and automatically restores product stock.

#### Hold / Resume Sale
- **`POST /api/v1/sales/{id}/hold`**: Sets status to `draft` (suspended).
- **`POST /api/v1/sales/{id}/resume`**: Finalizes the draft and decrements stock.

#### Get Printable Invoice Data
- **`GET /api/v1/sales/{id}/invoice`**
- Formatted breakdown of company info, customer info, itemized lines, taxes, subtotal, final total, and payments.

---

### 8.6 Returns & Refunds

#### Create Sale Return
- **`POST /api/v1/returns/sales`**
- Restocks the returned items into inventory and records a `sell_return` transaction.
- **Body:**
```json
{
  "sale_id": 1,
  "returns": [
    { "sell_line_id": 1, "quantity": 1 }
  ],
  "refund_amount": 19.99
}
```

#### Create Purchase Return
- **`POST /api/v1/returns/purchases`**
- Deducts items returned to supplier from inventory and records a `purchase_return` transaction.

---

### 8.7 Purchases & Receiving

#### Create Purchase
- **`POST /api/v1/purchases`**
- **Body:**
```json
{
  "contact_id": 2,
  "location_id": 1,
  "status": "received",
  "items": [
    {
      "product_id": 1,
      "variation_id": 1,
      "quantity": 50,
      "purchase_price": 12.00
    }
  ],
  "payments": [
    {
      "amount": 600.00,
      "method": "bank_transfer"
    }
  ]
}
```

#### Receive Purchase Order
- **`POST /api/v1/purchases/{id}/receive`**
- Updates a pending/ordered purchase to `received` and adds the quantities into inventory stock.

---

### 8.8 Payments & Balances

#### Add Payment
- **`POST /api/v1/payments`**
- **Body:**
```json
{
  "transaction_id": 1,
  "amount": 50.00,
  "method": "cash",
  "note": "Partial settlement"
}
```

#### Outstanding Balances
- **`GET /api/v1/payments/due?type=sell`**
- Returns all transactions with unpaid or partially paid balances.

---

### 8.9 Expenses

- **`GET /api/v1/expenses/categories`**
- **`POST /api/v1/expenses/categories`** `{ "name": "Utilities" }`
- **`GET /api/v1/expenses`**
- **`POST /api/v1/expenses`**
  ```json
  {
    "location_id": 1,
    "expense_category_id": 1,
    "final_total": 85.00,
    "additional_notes": "Electricity bill",
    "payments": [{ "amount": 85.00, "method": "bank_transfer" }]
  }
  ```

---

### 8.10 Business Reports

#### Sales Summary
- **`GET /api/v1/reports/sales?start_date=2026-11-01&end_date=2026-11-30&group_by=day`**

#### Profit & Loss
- **`GET /api/v1/reports/profit-loss?start_date=2026-11-01&end_date=2026-11-30`**
- Computes Total Sales, Cost of Goods Sold (COGS), Gross Profit, Expenses, and Net Profit.

#### Top Selling Products
- **`GET /api/v1/reports/top-products?limit=10`**

#### Stock Valuation
- **`GET /api/v1/reports/stock-valuation?location_id=1`**
- Calculates stock value at purchase cost vs selling price, plus potential margin.

#### Tax Summary
- **`GET /api/v1/reports/tax-summary`**
- Reports output tax (sales) vs input tax (purchases) and net tax payable.

---

### 8.11 Users & Roles

- **`GET /api/v1/users`**
- **`POST /api/v1/users`** `{ "first_name": "Bob", "email": "bob@pos.com", "username": "bob", "password": "SecretPassword123!" }`
- **`GET /api/v1/users/{id}`**
- **`GET /api/v1/roles`**

---

### 8.12 Settings & Locations

- **`GET /api/v1/settings/business`**
- **`PUT /api/v1/settings/business`**
- **`GET /api/v1/settings/locations`**
- **`POST /api/v1/settings/locations`** `{ "name": "Downtown Branch", "city": "Metro City" }`
- **`GET /api/v1/settings/tax-rates`**
- **`POST /api/v1/settings/tax-rates`** `{ "name": "VAT 5%", "amount": 5.0 }`

---

## 9. OpenAPI 3.0 & Postman Testing

- **OpenAPI 3.0 Specification:** Stored at `/openapi.json` for AI tool schemas.
- **Postman Collection:** Stored at `/api/tests/UltimatePOS_API.postman_collection.json`.
- **PHP CLI Test Runner:** `php api/tests/test_flow.php`
- **cURL Bash Test Suite:** `bash api/tests/test_curl.sh`

---

## 10. Setup & Quick Start Guide

### Step 1: Run the Database Migration
Execute the migration to create `api_tokens` and `api_logs`:

```bash
php artisan migrate
```
*(Alternatively, import `/api/database/api_tables.sql` into MySQL)*

### Step 2: Generate an API Token
```bash
php api/tools/generate_token.php --name="AI Agent"
```
Copy the 64-hex string displayed on your screen.

### Step 3: Test Health Check
```bash
curl http://localhost/api/v1/ping
```

### Step 4: Test Authenticated Request
```bash
curl http://localhost/api/v1/products -H "Authorization: Bearer <YOUR_TOKEN>"
```

---

## 11. Changelog

### Version 1.0.0 (2026-11-18)
- Initial release of additive REST API layer for UltimatePOS.
- SHA-256 token authentication with scope support and rate limiting (120 req/min).
- Comprehensive CRUD and task endpoints for Products, Taxonomies, Inventory, Contacts, Sales, Returns, Purchases, Payments, Expenses, Reports, Users, and Settings.
- Full OpenAPI 3.0 file and Postman collection.

