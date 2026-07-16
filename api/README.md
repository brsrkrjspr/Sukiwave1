# ShopWave API (PHP + MySQL on Railway)

This API reads database credentials from environment variables so it can run locally (XAMPP) and on Railway.

## Railway variables

Set these in Railway for your service:

- `MYSQLHOST`
- `MYSQLPORT`
- `MYSQLDATABASE`
- `MYSQLUSER`
- `MYSQLPASSWORD`

Optional URL-based variables also supported:

- `MYSQL_URL`
- `DATABASE_URL`
- `RAILWAY_DATABASE_URL`

## Local development

1. Copy `api/.env.example` values into your local Apache/PHP env setup.
2. Ensure MySQL has a database matching `MYSQLDATABASE`.
3. Import schema:

```sql
SOURCE api/sql/schema.mysql.sql;
```

4. PayMongo (card / e-wallet checkout): after schema, run:

```sql
SOURCE api/sql/schema.v12.order_payments.mysql.sql;
```

Set Railway (or local) env:

- `PAYMONGO_SECRET_KEY` — secret key from PayMongo dashboard
- `PAYMONGO_WEBHOOK_SECRET` — signing secret for the webhook you create in PayMongo (listen for `checkout_session.payment.paid`)
- Optional: `PAYMONGO_PAYMENT_METHOD_TYPES` — JSON array of method codes, e.g. `["card","gcash","paymaya"]`

Endpoints:

- `POST /payments/paymongo/create-checkout.php` — buyer Bearer; body `{ "order_id", "success_url", "cancel_url" }` (must be `https://`, or `http://` only for `localhost`, `127.0.0.1`, or `10.0.2.2`). Order must have `payment_method` = `paymongo` and not yet `paid`.
- `POST /webhooks/paymongo.php` — configure this URL in PayMongo webhooks; no auth header; verifies `Paymongo-Signature`.

Hosted return pages (for use as `success_url` / `cancel_url` from the app): `GET /payments/paymongo/return-success.php`, `return-cancel.php`.

5. (Optional) Add demo data:

```sql
SOURCE api/sql/seed.mysql.sql;
```

## Role-ready migration (buyer, seller, rider)

If your database was created before role-specific tables were added, run:

```sql
SOURCE api/sql/schema.v2.roles.mysql.sql;
```

This migration adds:

- `buyer_addresses`
- `rider_profiles`
- `deliveries`

## Rider endpoints

- `GET /rider/available-orders.php`
- `POST /rider/accept-delivery.php`
- `POST /rider/mark-picked-up.php`
- `POST /rider/mark-delivered.php`

## Buyer endpoints

- `GET /buyer/default-address.php?buyer_user_id=<id>`

## Admin endpoints (web dashboard)

- `GET /admin/users.php?role=<optional>&q=<optional>&page=<n>&limit=<n>&sort_by=<field>&sort_dir=asc|desc`
- `POST /admin/users.php` with actions: `create`, `update`, `delete`
- `GET /admin/stores.php?q=<optional>&page=<n>&limit=<n>&sort_by=<field>&sort_dir=asc|desc`
- `POST /admin/stores.php` with actions: `update`, `delete`
- `GET /admin/flash-deals.php?page=<n>&limit=<n>&sort_by=<field>&sort_dir=asc|desc`
- `POST /admin/flash-deals.php` with actions: `create`, `update`, `delete`

Admin endpoints now require header auth:

- `Authorization: Bearer <admin_api_token>`

`admin_api_token` is returned by `POST /auth/login.php` when logging in as role `admin`.

Run migration for admin auth/audit tables:

```sql
SOURCE api/sql/schema.v7.admin_auth_audit.mysql.sql;
```

Example payload for rider actions:

```json
{
  "order_id": 1,
  "rider_user_id": 3,
  "rider_note": "On the way"
}
```

## Notes

- `api/config/db.php` automatically prefers Railway env vars.
- If no env vars are present, it falls back to local defaults for development.
