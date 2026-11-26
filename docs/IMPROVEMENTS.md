# Codebase Audit & Prioritized Improvement Roadmap

This document captures all security vulnerabilities, runtime bugs, architectural gaps, database discrepancies, frontend defects, and missing features identified during a full line-by-line audit of `ascending-pc` (`backend-API/` + `frontend-user/`), organized by priority (`P0` through `P3`) with concrete remediation steps.

---

## Executive Summary

| Priority | Tier Definition | Issue Count | Key Themes |
| :--- | :--- | :---: | :--- |
| **P0 — Critical** | Security vulnerabilities, data/money corruption, 500/404 hard blockers | **11 (All Resolved)** | Arbitrary file deletion, Midtrans secret logging & webhook replay stock inflation, overselling race conditions, missing `ProductController@destroy`, broken `/orders/{id}` binding, frontend build blocker (`.env`), `/web/login` 404s, cart ID corruption |
| **P1 — High** | Broken user flows, API contract mismatches, schema/migration failures | **14** | Vendor dashboard `Rp NaN` / `Rp 0`, split-brain `auth.ts`/`user.ts` stores, mobile UX dead-end, broken migration `down()`, `composer.lock` PHP 8.4 lock mismatch, hardcoded commissions & sandbox URLs |
| **P2 — Medium** | Architecture, performance (`N+1`), incomplete features, security hardening | **11** | Vendor balance & `Payout` system unimplemented, missing product edit/delete UI, Snap.js modal integration, `httpOnly` cookie auth / token revocation, CORS wildcard+credentials, rate limiting, `N+1` queries |
| **P3 — Low** | Code hygiene, dead boilerplate, Svelte 5 migration, automated tests & CI/CD | **8** | Remove `sverdle` & 11 dead category `.svelte` files, fix missing `/images/placeholder.*` & watermarked icon, root `.gitignore`, Pest & Playwright test suites, GitHub Actions CI |

---

## P0 — Critical Issues (Resolved)

> [!NOTE]
> All 11 `P0` issues below have been implemented and verified across `backend-API/` and `frontend-user/`.

### 1. [Resolved] [Security] Arbitrary File Deletion in `ProductController@update`
- **Location**: `backend-API/app/Http/Controllers/ProductController.php`
- **Resolution**: Intersected `remove_gallery_images` against `$updatedDetails['images']['gallery']` and excluded `http*` URLs before calling `Storage::disk('public')->delete()`.

### 2. [Resolved] [Security] Plaintext `MIDTRANS_SERVER_KEY` Logged & Raw Exceptions Returned
- **Location**: `backend-API/app/Http/Controllers/OrderController.php`
- **Resolution**: Removed debug `Log::info` calls leaking `MIDTRANS_SERVER_KEY` and gated `'error' => $e->getMessage()` behind `config('app.debug')`.

### 3. [Resolved] [Security & Integrity] Midtrans Webhook Replay Inflates Stock & Allows State Downgrade
- **Location**: `backend-API/app/Http/Controllers/OrderController.php` (`receive`)
- **Resolution**: Added non-empty `MIDTRANS_SERVER_KEY` guard, timing-safe `hash_equals()` signature check, `gross_amount` verification (`total_amount + 4000`), `DB::transaction()` + `lockForUpdate()`, terminal-state idempotency (`'2'`, `'3'`, `'4'`), `fraud_status` check on `capture`, and `vendor_orders.shipping_status = 'cancelled'` cascade on failed/expired payments.

### 4. [Resolved] [Data Integrity] Checkout Overselling Race Condition & Synchronous HTTP Inside DB Transaction
- **Location**: `backend-API/app/Http/Controllers/OrderController.php` (`checkout`)
- **Resolution**: Replaced invalid `new ValidationException([...])` with `ValidationException::withMessages(...)`, locked `Product` rows (`orderBy('id')->lockForUpdate()`), validated aggregated stock per `product_id`, committed the DB reservation before calling `Snap::getSnapToken()`, added compensating stock/order rollback if Midtrans fails, and made `redirect_url` respect `config('midtrans.is_production')`.

### 5. [Resolved] [Backend Crash] Unvalidated & Negative Quantities in `CartController@addToCart`
- **Location**: `backend-API/app/Http/Controllers/CartController.php`
- **Resolution**: Validated `quantity` (`sometimes|integer|min:1`, default `1`), enforced cumulative `newQuantity <= product.stock_quantity` inside `DB::transaction()` with row locks, assigned `$cartItem = Cart::create(...)` so `data` is always returned with its primary key, and fixed `$cartItem->product->stock_quantity` in `update()`.

### 6. [Resolved] [Backend Crash] Missing `ProductController@destroy` & Null Crash in `ProductController@update`
- **Location**: `backend-API/app/Http/Controllers/ProductController.php`
- **Resolution**: Added `404` check before reading `$product->vendor_id` in `update()`, enforced `mimes:jpeg,png,jpg,webp` on `update()` images, and implemented `destroy($id)` with vendor ownership check, `OrderItem` history guard (`409 Conflict`), and `'public'` disk image cleanup.

### 7. [Resolved] [Backend Bug] `GET /api/orders/{id}` Always Returns `403 Unauthorized`
- **Location**: `backend-API/app/Http/Controllers/OrderController.php` (`show`)
- **Resolution**: Changed `show($id)` to resolve `ParentOrder::find($id)` with explicit `404` and `403` ownership checks matching `/orders/{id}`.

### 8. [Resolved] [Frontend Build Blocker] Missing `.env` & `.env.example` Breaks Compilation
- **Location**: `frontend-user/.env.example`
- **Resolution**: Added `frontend-user/.env.example` (and local gitignored `.env`) defining `PUBLIC_API_URL` and `PUBLIC_STORAGE_URL`, plus safe fallback guards on `PUBLIC_STORAGE_URL`.

### 9. [Resolved] [Frontend 404 Blocker] 8 Redirects Point to Non-Existent `/web/login`
- **Location**: `ProductCard.svelte`, `web/co/+page.svelte`, `web/profile/+page.svelte`, `web/simulasi-pc/+page.svelte`, `web/wishlist/+page.svelte`
- **Resolution**: Updated all 8 unauthenticated redirects to `/login` and prevented `simulasi-pc` `handleDirectCheckout()` from overriding `/login` with `/web/cart`.

### 10. [Resolved] [Frontend Bug] Cart Item ID Corruption Breaks Cart Update, Delete & Checkout
- **Location**: `src/lib/stores/cart.ts`, `src/routes/+layout.svelte`, `ProductCard.svelte`, `web/product/[id]/+page.svelte`, `CartDrawer.svelte`, `web/cart/+page.svelte`, `web/co/+page.svelte`
- **Resolution**: Introduced canonical `mapApiCartItem()` in `cart.ts`, ensured `ProductCard` and `product/[id]` store `String(result.data.id)` (`cart.id`) rather than `product.id` or `Date.now()`, cast `selected_cart_ids` to integers on checkout, and pruned checked-out items from `$cart` on navigation.

### 11. [Resolved] [Backend Setup Blocker] Un-commented Note in `backend-API/.env.example`
- **Location**: `backend-API/.env.example:67`
- **Resolution**: Prefixed inline note on `MIDTRANS_SERVER_KEY` with `#` so `vlucas/phpdotenv` parses `.env` cleanly.

---

## P1 — High Priority Issues (Broken Features, Contract & Schema Mismatches)

### 12. [API Contract] Vendor Dashboard Displays `Rp 0` & `Rp NaN` on All Orders
- **Location**: `backend-API/app/Http/Controllers/VendorOrderController.php:8, 31`, `backend-API/app/Http/Resources/OrderItemResource.php:23`, `frontend-user/src/routes/web/dashboard/+page.svelte:342, 578`
- **Problem**: `OrderItemResource` references non-existent `App\Http\Resources\ProductResource`. To avoid the crash, `VendorOrderResource` was commented out in `VendorOrderController`, returning raw `VendorOrder` models with `order_total` instead of `total_price`. Consequently, `dashboard/+page.svelte` renders `Rp 0` in Recent Orders (line 342) and `Rp NaN` in All Orders (line 578).
- **Fix**: Create `ProductResource` (or serialize `$this->product` safely in `OrderItemResource`), re-enable `VendorOrderResource::collection($orders)` in `VendorOrderController`, or read `order.order_total ?? order.total_price` on the frontend.

### 13. [UX Blocker] Mobile Users Cannot Search, Log In, View Cart, or Checkout
- **Location**: `frontend-user/src/lib/components/Header.svelte:122, 408`, `frontend-user/src/routes/web/sidebar-mobile/+page.svelte`
- **Problem**: Below `768px`, `.desktop-only` hides the search bar, wishlist button, cart drawer trigger, and `<UserMenu />`. Meanwhile, `/web/sidebar-mobile` only lists static category links (plus a 404 `/web/contact` link) and has no search, cart, wishlist, login, or profile controls.
- **Fix**: Keep search, cart icon, and user/auth entry accessible on mobile viewports, and replace the separate `/web/sidebar-mobile` route with an accessible slide-over drawer component inside `Header.svelte`.

### 14. [Frontend Auth] Landing Page "Area Pengguna" & `/pengguna` Always Redirect to `/login`
- **Location**: `frontend-user/src/routes/+page.svelte:29-34`, `frontend-user/src/routes/pengguna/+page.svelte:6-11`
- **Problem**: Both check `localStorage.getItem("userLoggedIn") === "true"`, a legacy key never set by `/login` or `/register` (which write `auth_token` and `user_data`).
- **Fix**: Check `$isLoggedIn` / `auth_token` from unified auth store and route authenticated buyers to `/web/profile`.

### 15. [State Architecture] Split-Brain `auth.ts` vs `user.ts` & Unguarded `JSON.parse` Crash
- **Location**: `frontend-user/src/lib/stores/auth.ts`, `frontend-user/src/lib/stores/user.ts:16`
- **Problem**: Two separate stores manage user state with conflicting TypeScript interfaces (`phone_number` vs `phone`). Worse, `user.ts:16` runs `JSON.parse(storedUser)` without `try/catch` (crashes app if `"undefined"` was written). Additionally, `GET /user` is parsed 4 different ways across `+layout`, `UserMenu`, `profile`, and `co`.
- **Fix**: Consolidate into a single `auth.ts` store with a strict `User` type matching `App\Models\User`, guarded `JSON.parse`, and a single `logout()` helper that calls `POST /api/logout` and clears all client stores.

### 16. [Frontend Bug] Product Creation Masks Validation Errors as `"Error koneksi"`
- **Location**: `frontend-user/src/routes/web/dashboard/+page.svelte:200-215`
- **Problem**: `POST /product` omits `'Accept': 'application/json'`. When Laravel validation fails (e.g., image > 2 MB or unsupported MIME), Laravel returns a `302` HTML redirect, causing `await res.json()` to throw a `SyntaxError` caught as `"Error koneksi"`.
- **Fix**: Add `'Accept': 'application/json'` to headers and surface field-level `err.errors` messages in the form UI.

### 17. [Payment & Accounting] Midtrans `order_id` Collisions, Undocumented +Rp 4.000 Fee & Hardcoded Commission/Sandbox URL
- **Location**: `backend-API/app/Http/Controllers/OrderController.php:64, 82, 110-111, 142`, `frontend-user/src/routes/web/profile/+page.svelte:123`
- **Problems**:
  1. Sends raw integer `$parentOrder->id` (`1, 2, 3...`) as Midtrans `order_id` — collides in Midtrans whenever DB resets or user retries payment.
  2. Adds `+ 4000` to Midtrans `gross_amount` without storing `admin_fee` or including it in `parent_orders.total_amount` or `item_details`.
  3. Hardcodes `$commissionRate = 0.10` (10%), ignoring `vendors.commission_rate` (`5.00`%).
  4. Hardcodes `https://app.sandbox.midtrans.com/snap/v2/vtweb/` in both `OrderController.php:142` and `profile/+page.svelte:123` even in production.
- **Fix**: Add `order_number` (e.g., `ASC-20261125-XXXX`) + `admin_fee` columns to `parent_orders`, compute commission from `$vendor->commission_rate / 100`, and read `redirect_url` directly from Midtrans `Snap::createTransaction($params)->redirect_url` (or `config('midtrans.is_production')`).

### 18. [Fulfillment Security] Vendors Receive Unpaid Orders (with Buyer PII) & Can Ship Before Payment
- **Location**: `backend-API/app/Http/Controllers/VendorOrderController.php:26-31, 60-87`
- **Problem**: `VendorOrderController@index` returns all `VendorOrder` rows regardless of `parentOrder.payment_status`, exposing buyer phone/address before payment and allowing vendors to transition unpaid (`'1'`) or failed (`'3'`) orders to `'shipped'`/`'delivered'`. Also lacks forward-only status transition guards and stock restoration on `'cancelled'`.
- **Fix**: Filter vendor orders to paid parent orders (`whereHas('parentOrder', fn($q) => $q->where('payment_status', '2'))`) or clearly badge unpaid status while blocking `updateStatus` until `payment_status === '2'`.

### 19. [Database Migrations] Broken `down()` Rollbacks & Column Length Mismatches
- **Location**:
  - `backend-API/database/migrations/2025_11_25_040910_create_vendor_table.php:17, 31`
  - `backend-API/database/migrations/2025_11_25_042623_create_vendors_orders_table.php:31`
  - `backend-API/database/migrations/0001_01_01_000000_create_users_table.php:21`
  - `backend-API/database/migrations/2025_12_05_072358_create_cart_table.php`
- **Problems**:
  1. `down()` drops `'vendor'` instead of `'vendors'`, and `'vendors_orders'` instead of `'vendor_orders'`, breaking `php artisan migrate:rollback` / `migrate:fresh` rollbacks.
  2. `users.address` is `VARCHAR(255)` while `AuthController@update` allows `1000` chars (`TEXT` needed).
  3. `vendors.store_name` is `VARCHAR(100)` while `VendorController@register` validates `max:255`.
  4. `vendors.user_id` lacks `->unique()`, and `cart` lacks `$table->unique(['user_id', 'product_id'])`.
- **Fix**: Fix `down()` table names and add a corrective migration changing `users.address` to `text()`, adding `unique('user_id')` on `vendors`, and adding `unique(['user_id', 'product_id'])` on `cart`.

### 20. [Backend Autoloading & Broken References] PSR-4 Violations & Missing Classes
- **Location**: `backend-API/app/Models/payout.php`, `backend-API/app/Models/User.php:94`, `backend-API/app/Models/Product.php:52`, `backend-API/app/Http/Controllers/CartController.php:97`, `backend-API/app/Http/Resources/ParentOrderResource.php:32`
- **Problems**:
  - `app/Models/payout.php` (`class payout`) violates PSR-4 (`App\Models\Payout` fails to autoload on case-sensitive Linux).
  - `User::cartItems()` references non-existent `App\Models\CartItem`.
  - `Product::getGalleryUrlsAttribute()` reads `$this->details['gallery_paths']` instead of `$this->details['images']['gallery']`.
  - `CartController@update` (line 97) reads `$cartItem->product->stock` (undefined) instead of `stock_quantity`.
  - `ParentOrderResource` (line 32) reads `$this->user->phone` instead of `phone_number`.
- **Fix**: Rename `payout.php` -> `Payout.php` (`class Payout`), remove dead `User::cartItems()`, standardize `User::cart()` / `Product::vendor()` camelCase relation names, and fix attribute names (`details.images.gallery`, `stock_quantity`, `phone_number`).

### 21. [Backend Dependencies] `composer.lock` Requires PHP >= 8.4 Despite `composer.json` Declaring `^8.2`
- **Location**: `backend-API/composer.json:12`, `backend-API/composer.lock`
- **Problem**: `composer.lock` was generated on PHP 8.4 without `config.platform.php`, locking `symfony/*` `v8.0.0` (`php >=8.4`). Running `composer install` on PHP 8.2 or 8.3 fails immediately.
- **Fix**: Either update `"php": "^8.4"` in `composer.json` to match the lockfile, or set `"config": { "platform": { "php": "8.2.0" } }` and run `composer update` to lock PHP 8.2-compatible Symfony 7 packages.

### 22. [Backend Seeder] `php artisan db:seed` Crashes in `productsSeeder.php`
- **Location**: `backend-API/database/seeders/productsSeeder.php:21`
- **Problem**: Calls `$faker->productName`, which does not exist in `fakerphp/faker` without an extra provider package. Seeders also use non-PSR-4 lowercase class names (`vendorSeeder`, `productsSeeder`, `cartSeeder`).
- **Fix**: Replace `$faker->productName` with realistic PC hardware fixture arrays (with `details.category`, `socket`, `memory`) and rename seeder classes to PascalCase (`VendorSeeder`, `ProductSeeder`, `CartSeeder`).

### 23. [PC Builder UX] Lost Build State, Missing `LGA1200` / Cooler Socket Checks & 6-Brand Limit
- **Location**: `frontend-user/src/routes/web/simulasi-pc/+page.svelte:59-66, 154-167, 273-284, 425`
- **Problems**:
  - Clicking a product row navigates to `/web/product/{id}` and wipes the entire in-progress build (`selectionMap` is not persisted).
  - Vendor Dashboard allows `LGA1200` and Cooler sockets, but `simulasi-pc` only checks `AM5`/`LGA1700`/`AM4` between CPU & Motherboard and ignores Cooler socket compatibility.
  - Brand filter only recognizes 6 strings (`AMD`, `INTEL`, `NVIDIA`, `ASUS`, `MSI`, `GIGABYTE`), hiding Corsair, Kingston, Samsung, Seasonic, etc.
  - Only fetches `?per_page=100` once, hiding catalog items past 100.
- **Fix**: Persist `selectionMap` in `sessionStorage`/`localStorage`, store structured `details.brand`, `details.socket`, `details.memory`, and `details.tdp_watts`, validate CPU + Motherboard + Cooler sockets + PSU wattage headroom, and query per-category endpoints (`GET /api/product?category=processor`).

### 24. [Checkout UX] Page Reload Loses Selected Items & Shipping Address Is Not Confirmed
- **Location**: `frontend-user/src/routes/web/co/+page.svelte`
- **Problem**: `$selectedItemIds` lives only in memory — refreshing `/web/co` resets it to `[]` and kicks the user back to `/web/cart`. Furthermore, `/web/co` does not display or validate that the buyer has filled in `user.address` and `user.phone_number` before placing an order, nor are address snapshots saved on `parent_orders`.
- **Fix**: Persist `selectedItemIds` in `sessionStorage`, require/confirm shipping address + phone on `/web/co`, and store `shipping_address` + `recipient_phone` snapshots on `parent_orders`.

### 25. [User Profile Bug] `PUT /api/user` Wipes Omitted `phone_number` / `address` to `null`
- **Location**: `backend-API/app/Http/Controllers/AuthController.php:77-82`
- **Problem**: Uses `'phone_number' => $validated['phone_number'] ?? null` instead of partial `sometimes` updates.
- **Fix**: Use `sometimes|nullable|...` validation rules and `$user->fill($validated)->save()`.

---

## P2 — Medium Priority Improvements (Architecture, Security Hardening & Missing Features)

### 26. [Missing Feature] End-to-End Vendor Balance Ledger & Payout Workflow
- **Current State**: `vendors.balance` stays `0.00` forever, `app/Models/payout.php` is empty, no `PayoutController` exists, and *Tarik Tunai* in `/web/dashboard` (`line 524`) triggers `alert("Fitur penarikan belum tersedia saat ini.")`.
- **Improvement**:
  1. Credit `vendor.balance += vendorOrder.net_amount` atomically when `VendorOrder` reaches `delivered` (and `parentOrder.payment_status === '2'`), recorded in a `wallet_transactions` ledger table.
  2. Implement `App\Models\Payout` + `PayoutController` (`GET /api/vendor/payouts`, `POST /api/vendor/payouts` with bank details & atomic balance deduction) and wire `/web/dashboard` *Tarik Tunai*.

### 27. [Missing Feature] Vendor Product Edit, Delete & Stock Toggle UI
- **Current State**: `/web/dashboard` (`produk` tab) only supports viewing and creating products; vendors cannot edit prices, replenish stock, upload gallery photos, or delete discontinued listings.
- **Improvement**: Wire edit modal/form (`POST /api/product/{id}` with `_method=PUT`) and delete confirmation (`DELETE /api/product/{id}` once `ProductController@destroy` is implemented) into `/web/dashboard`.

### 28. [Payment UX] Embed Official Midtrans `snap.js` Modal Instead of Raw VTWeb Tabs
- **Current State**: Checkout opens raw `vtweb` URLs in a new tab while `/web/profile` polls `GET /api/orders` every 5 seconds.
- **Improvement**: Load Midtrans `snap.js` (`data-client-key`) and invoke `window.snap.pay(snapToken, { onSuccess, onPending, onError, onClose })` directly on `/web/co` and `/web/profile`.

### 29. [Security Hardening] Fix Invalid CORS Config, Add API Rate Limiting & Strengthen Auth Storage
- **Locations**: `backend-API/config/cors.php:22, 32`, `backend-API/routes/api.php`, `frontend-user/src/lib/stores/auth.ts`
- **Improvements**:
  1. **CORS**: Replace `'allowed_origins' => ['*']` + `'supports_credentials' => true` with `[env('FRONTEND_URL', 'http://localhost:5173')]`.
  2. **Rate Limiting**: Add `throttle:6,1` on `/api/login` and `/api/register`, and `throttle:60,1` on `/api/*`.
  3. **Auth Storage**: Move from XSS-readable `localStorage` bearer tokens to SvelteKit server hooks + `httpOnly; SameSite=Lax; Secure` cookies (or Sanctum SPA stateful cookie auth), and reduce Sanctum token TTL from 91 days (`131400`) to a shorter sliding window with proper revocation on all logout buttons.

### 30. [Performance] Eliminate `N+1` Queries & Unbounded Pagination
- **Locations**: `backend-API/app/Models/User.php:48, 78-82`, `ProductController.php:21, 68`, `VendorController.php:84`
- **Improvements**:
  1. In `User::getIsVendorAttribute()`, use `$this->relationLoaded('vendor') ? $this->vendor !== null : $this->vendor()->exists()` (or remove `'is_vendor'` from global `$appends` and eager-load `withExists('vendor')` where needed) so serializing `VendorOrder` lists doesn't fire N extra `SELECT * FROM vendors` queries.
  2. Eager-load `Product::with('vendor:id,store_name')` in `ProductController@index` and cap `$perPage = min(max((int) $request->query('per_page', 12), 1), 50)`.
  3. Paginate `VendorController@vendorProductsView` (`GET /api/vendor/{id}/products`) and add functional pagination controls on `/web` and `/web/categories/[category]`.
  4. On PostgreSQL, add a `GIN` or expression index on `(details->>'category')` (or promote `category` to a dedicated indexed `VARCHAR(50)` column on `products`).

### 31. [UX Polish] Fix Broken Wishlist Badge, Redundant Redirects, Global CSS Leaks & `confirm()` Loops
- **Locations**:
  - `Header.svelte:172` (hardcoded `0` wishlist counter; hydrate `$wishlist` on layout mount and render `{$wishlist.length}`).
  - `web/product/[id]/+page.svelte:75, 278` (stop redirecting away to `/web/wishlist` on heart toggle; implement the dead *Spesifikasi* tab).
  - `web/cart/+page.svelte:150-157` (add `DELETE /api/cart` bulk-clear endpoint instead of prompting `confirm()` inside a `for` loop for every item).
  - `src/routes/layout.css:6-108` (strip leftover `sv create` global element rules overriding `body`, `a`, and `h1` across Tailwind pages; unify modal routes with route groups `(app)` vs `(auth)` so Header/Footer don't render behind full-screen modals).

---

## P3 — Low Priority (Code Hygiene, Svelte 5 Modernization, Testing & CI/CD)

### 32. [Dead Code Cleanup] Remove Boilerplate & 11 Non-Routable Legacy Files
- Delete starter template files: `src/routes/sverdle/**` (including 129 KB `words.server.js`), `src/routes/about/**`, `src/lib/images/{github.svg,svelte-logo.svg,svelte-welcome.*}`, and unused packages `@neoconfetti/svelte` & `@fontsource/fira-mono`.
- Delete the **11 dead legacy category files** in `src/routes/web/categories/*.svelte` (keep only `src/routes/web/categories/[category]/+page.svelte`), plus dead `ProductGrid.svelte`, `CategoryBar.svelte`, `cartStore.ts`, `products.ts`, and orphaned `product-pcready/[id]` / `pc-ready-products.ts`.
- Either implement or remove unlinked placeholder routes (`/jual-pc`, `/pengaturan`, `/pengguna`).

### 33. [Assets & Repo Hygiene] Fix Missing Placeholder Images, Watermarked Icon, Spaces in Paths & Empty Root `.gitignore`
- Add `/static/images/placeholder.png` (referenced across 10 files, currently 404).
- Replace watermarked `frontend-user/static/images/icons/cpu.png` (`www.shutterstock.com`) with a clean licensed SVG/PNG icon.
- Move `src/routes/assets gambar/halaman utama.jpg` to `src/lib/assets/hero-landing.jpg` (avoid spaces in source paths).
- Populate root `.gitignore` (currently 0 bytes) with `.DS_Store`, `.env`, `.idea/`, `.vscode/`, `node_modules/`, and Move `@sveltejs/adapter-node` to `"dependencies"` in `frontend-user/package.json`.
- Clean up stale remote revert branches (`revert-19-dev/fwaz`, `revert-20-revert-19-dev/fwaz`).

### 34. [Modernization] Fix `svelte-check` Type Errors & Adopt Svelte 5 Runes + SvelteKit `load`
- Resolve all current `svelte-check` TypeScript errors so `npm run check` passes with 0 errors.
- Migrate deprecated `$app/stores` (`$page`) to `$app/state` (`page`) and gradually adopt Svelte 5 runes (`$props`, `$state`, `$derived`) and SSR-friendly `+page.ts` `load` functions.

### 35. [Quality Assurance] Build Automated Backend (Pest) & Frontend (`svelte-check` + Playwright) CI Pipeline
- **Backend (`backend-API/tests/`)**: Uncomment `RefreshDatabase` in `tests/Pest.php`, create model factories (`VendorFactory`, `ProductFactory`, `ParentOrderFactory`), and add feature tests covering Auth, Vendor RBAC, Product CRUD + gallery deletion security, Cart stock bounds, Multi-Vendor Checkout concurrency, and Midtrans webhook signature & idempotency.
- **CI/CD (`.github/workflows/ci.yml`)**: Run `composer install` + `php artisan test` + `./vendor/bin/pint --test` for `backend-API`, and `npm ci` + `npm run check` + `npm run lint` + `npm run build` for `frontend-user` on every push and pull request.
