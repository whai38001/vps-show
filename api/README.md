# API Endpoints

All responses are JSON with `code` and `data` fields unless noted.

## GET /api/vendors.php

Returns a list of vendors.

Query params: none

Response:
```json
{
  "code": 0,
  "data": [
    {"id": 1, "name": "RackNerd", "website": "https://...", "logo_url": "...", "description": "..."}
  ]
}
```

Caching: recommend CDN edge caching for 60s.

## GET /api/plans.php

List VPS plans with filters and pagination.

Query params:
- `q`: keyword search in title/subtitle/vendor
- `vendor`: vendor id
- `billing`: one of `per month` | `per year` | `one-time`
- `min_price`, `max_price`: numeric filters
- `location`: substring match on location
- `sort`: `default` | `price_asc` | `price_desc` | `newest` | `cpu_asc` | `cpu_desc` | `ram_asc` | `ram_desc` | `storage_asc` | `storage_desc`
- `page`: page number (default 1)
- `page_size`: per page (1-100, default 20)
- `recent`: optional, if >0 returns the most recently updated N items (ignores page)
- `min_cpu`: minimum CPU cores (float, e.g., `2` or `2.5`)
- `min_ram_gb`: minimum RAM in GB (float)
- `min_storage_gb`: minimum storage in GB (integer)

Response:
```json
{
  "code": 0,
  "data": {
    "items": [
      {
        "id": 123,
        "vendor_id": 1,
        "vendor_name": "RackNerd",
        "title": "2.5GB",
        "subtitle": "KVM VPS",
        "price": 18.93,
        "price_duration": "per year",
        "order_url": "https://...",
        "location": "Multiple Locations",
        "features": ["2 vCPU Cores", "40 GB SSD", "3000 GB Monthly Transfer"],
        "cpu": "2 vCPU Cores",
        "ram": "2.5 GB RAM",
        "storage": "40 GB SSD Storage",
        "cpu_cores": 2,
        "ram_mb": 2560,
        "storage_gb": 40,
        "highlights": "Hot",
        "updated_at": "2024-11-30 12:30:00"
      }
    ],
    "pagination": {"page": 1, "page_size": 20, "total": 123}
  }
}
```

Notes:
- `cpu_cores`, `ram_mb`, `storage_gb` may be `null` when not available.

Headers:
- `Cache-Control: public, max-age=30, stale-while-revalidate=60`
- `ETag`: weak ETag, you can send `If-None-Match` for conditional GET (304)
- `Last-Modified`: send `If-Modified-Since` for conditional GET (304)

Rate limiting:
- IP-based limiter by simple file storage (default 120 req/min). On exceed returns `429` with `Retry-After` header.

Notes:
- `features` may be stored as JSON and returned as an array.
- For Chinese UI, free-text fields are translated at render time on the HTML site; API returns raw values.
