#!/usr/bin/env bash
# UltimatePOS REST API - cURL Test Suite

BASE_URL="http://localhost/api/v1"
API_TOKEN="pos_test_token_2026_antigravity"

echo "=== 1. Health Check (Public) ==="
curl -s -X GET "${BASE_URL}/ping" | jq .

echo "=== 2. Discover Endpoints ==="
curl -s -X GET "${BASE_URL}/meta/endpoints" | jq .

echo "=== 3. Unauthorized Test (Should be 401) ==="
curl -s -X GET "${BASE_URL}/products" | jq .

echo "=== 4. List Products (Authenticated) ==="
curl -s -X GET "${BASE_URL}/products" \
  -H "Authorization: Bearer ${API_TOKEN}" | jq .

echo "=== 5. Create Product ==="
curl -s -X POST "${BASE_URL}/products" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Mechanical Keyboard",
    "unit_id": 1,
    "type": "single",
    "sku": "KB-M01",
    "enable_stock": true,
    "alert_quantity": 5,
    "purchase_price": 40.00,
    "selling_price": 75.00
  }' | jq .

echo "=== 6. Query Inventory Stock ==="
curl -s -X GET "${BASE_URL}/inventory/stock" \
  -H "Authorization: Bearer ${API_TOKEN}" | jq .

echo "=== 7. Create Customer ==="
curl -s -X POST "${BASE_URL}/contacts" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Alice Smith",
    "type": "customer",
    "mobile": "+1555987654",
    "email": "alice@example.com"
  }' | jq .

echo "=== 8. Create Sale (POS Billing) ==="
curl -s -X POST "${BASE_URL}/sales" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "location_id": 1,
    "contact_id": 1,
    "status": "final",
    "products": [
      {
        "product_id": 1,
        "variation_id": 1,
        "quantity": 1,
        "unit_price": 75.00
      }
    ],
    "payments": [
      {
        "amount": 75.00,
        "method": "cash"
      }
    ]
  }' | jq .

echo "=== 9. Sales Report ==="
curl -s -X GET "${BASE_URL}/reports/sales?group_by=day" \
  -H "Authorization: Bearer ${API_TOKEN}" | jq .

