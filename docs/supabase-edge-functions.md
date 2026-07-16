# ShopWave — Supabase Edge Functions (API spec)

Use the **anon** key in the Flutter/web client. **Never** ship the **service role** key; Edge Functions hold it as a secret and call Stripe / PayMongo / Xendit with server-side keys.

Base URL pattern: `https://<project-ref>.supabase.co/functions/v1/<function-name>`

Send `Authorization: Bearer <user JWT>` when the function should act on behalf of the logged-in user (validate with `supabase.auth.getUser(jwt)` inside the function). For webhooks, verify provider signatures only.

---

## 1. `create-payment-intent`

**POST** — Creates a provider payment session or PaymentIntent (Stripe) for an order.

**Headers:** `Authorization: Bearer <user JWT>`, `Content-Type: application/json`

**Body (JSON):**

```json
{
  "order_id": "uuid",
  "provider": "stripe",
  "success_url": "https://app.example/orders/{id}/paid",
  "cancel_url": "https://app.example/checkout"
}
```

**Response (200):**

```json
{
  "client_secret": "pi_xxx_secret_xxx",
  "provider_payment_id": "pi_xxx",
  "amount": 1299.5,
  "currency": "PHP"
}
```

**Behavior:** Load `orders` with **service role**, assert `buyer_id` matches JWT user, assert `payment_status` allows payment, create Stripe PaymentIntent (or PayMongo intent), insert row into `payments` with status `created` / `requires_action`.

**Errors:** `401` invalid JWT, `403` not buyer, `404` order, `409` already paid.

---

## 2. `stripe-webhook` (or `payment-webhook`)

**POST** — Provider webhook; **no** user JWT. Verify `Stripe-Signature` (or provider equivalent).

**Body:** Raw JSON payload from provider.

**Behavior:** On `payment_intent.succeeded`, set `payments.status = paid`, `orders.payment_status = paid`, append `order_events`, optionally create `deliveries` row (`unassigned`). On failure/refund, update rows accordingly.

**Response:** `200` with `{ "received": true }` after idempotent processing.

---

## 3. `create-order`

**POST** — Optional consolidated checkout: validates cart lines, creates `orders` + `order_items` in one transaction (service role), then returns order id. Alternative: client inserts via RLS (current schema supports buyer insert).

**Body:**

```json
{
  "store_id": "uuid",
  "items": [
    { "product_id": "uuid", "qty": 2 }
  ],
  "payment_method": "cod",
  "delivery_fee": 50
}
```

**Response:** `{ "order_id": "uuid", "total": 500 }`

---

## 4. `assign-rider`

**POST** — Admin or dispatch logic. **Service role** or admin JWT.

**Body:** `{ "delivery_id": "uuid", "rider_id": "uuid" }`

**Behavior:** Update `deliveries` (`rider_id`, `status = assigned`, `assigned_at`), notify rider (push/email via your provider).

---

## 5. `update-delivery-status`

**POST** — Rider or seller transitions.

**Body:** `{ "delivery_id": "uuid", "status": "picked_up" }`

**Behavior:** Validate caller is assigned `rider_id` or store owner for the order; update `deliveries` and mirror `orders.status` when appropriate; insert `order_events`.

---

## 6. `broadcast-rider-location` (optional thin wrapper)

**POST** — If you prefer not to expose direct table writes: accepts `{ "lat", "lng", "delivery_id" }`, validates JWT is rider, upserts `rider_locations`. Otherwise the app can upsert `rider_locations` directly with RLS.

---

## Realtime (dashboard)

Enable **`orders`**, **`deliveries`**, and **`rider_locations`** on the `supabase_realtime` publication (see commented SQL in `20250326120001_rls_policies.sql`). Subscribe from Flutter with `supabase_flutter` channels filtered by `order_id` or `delivery_id`.

---

## Secrets (Edge Function env)

| Name | Purpose |
|------|---------|
| `STRIPE_SECRET_KEY` | Stripe API |
| `STRIPE_WEBHOOK_SECRET` | Verify webhooks |
| `SUPABASE_SERVICE_ROLE_KEY` | Server-side DB (only in functions) |
| `PAYMONGO_SECRET_KEY` | If using PayMongo |

---

## Idempotency

Webhook handlers must use `provider_payment_id` as idempotency key so duplicate events do not double-credit or double-update `orders`.
