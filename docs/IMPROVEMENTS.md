# Codebase Audit & Prioritized Improvement Roadmap

This document captures all security vulnerabilities, runtime bugs, architectural gaps, database discrepancies, frontend defects, and missing features identified during a full line-by-line audit of `ascending-pc` (`backend-API/` + `frontend-user/`), organized by priority (`P0` through `P3`) with concrete remediation steps.

---

## Executive Summary

| Priority | Tier Definition | Issue Count | Key Themes |
| :--- | :--- | :---: | :--- |
| **P0 — Critical** | Security vulnerabilities, data/money corruption, 500/404 hard blockers | **11 (All Resolved)** | Arbitrary file deletion, Midtrans secret logging & webhook replay stock inflation, overselling race conditions, missing `ProductController@destroy`, broken `/orders/{id}` binding, frontend build blocker (`.env`), `/web/login` 404s, cart ID corruption |
| **P1 — High** | Broken user flows, API contract mismatches, schema/migration failures | **14 (All Resolved)** | Vendor dashboard `Rp NaN` / `Rp 0`, split-brain `auth.ts`/`user.ts` stores, mobile UX dead-end, broken migration `down()`, `composer.lock` PHP 8.4 lock mismatch, hardcoded commissions & sandbox URLs |
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

## P1 — High Priority Issues (Resolved)

> [!NOTE]
> All 14 `P1` issues below have been implemented across `backend-API/` and `frontend-user/`.

### 12. [Resolved] [API Contract] Vendor Dashboard Displays `Rp 0` & `Rp NaN` on All Orders
- **Resolution**: Created `App\Http\Resources\ProductResource`, re-enabled `VendorOrderResource` in `VendorOrderController`, appended `total_price` on `VendorOrder`, and updated `dashboard/+page.svelte` to support both `total_price`/`order_total` and `user`/`parent_order` customer names.

### 13. [Resolved] [UX Blocker] Mobile Users Cannot Search, Log In, View Cart, or Checkout
- **Resolution**: Added a responsive mobile top bar with cart badge trigger, inline mobile search bar, and slide-over mobile navigation drawer (auth/profile/vendor/cart/wishlist/categories) inside `Header.svelte`, plus updated `/web/sidebar-mobile` and removed the dead `/web/contact` link.

### 14. [Resolved] [Frontend Auth] Landing Page "Area Pengguna" & `/pengguna` Always Redirect to `/login`
- **Resolution**: Replaced dead `localStorage.userLoggedIn` checks in `+page.svelte` and `pengguna/+page.svelte` with unified `$isLoggedIn` / `auth_token` checks, fixed `/vendor` (`404`) to route to `/web/dashboard` or `/registervendor`, and removed the global `body { overflow: hidden }` leak.

### 15. [Resolved] [State Architecture] Split-Brain `auth.ts` vs `user.ts` & Unguarded `JSON.parse` Crash
- **Resolution**: Consolidated user/auth state into `src/lib/stores/auth.ts` with guarded JSON parsing, `normalizeUser()`, `setAuth()`, and token-revoking `logout(apiUrl)`, re-exporting the same store instance from `user.ts`.

### 16. [Resolved] [Frontend Bug] Product Creation Masks Validation Errors as `"Error koneksi"`
- **Resolution**: Added `'Accept': 'application/json'` and `details[category]`/`details[description]` to `POST /product` in `dashboard/+page.svelte`, surfacing field-level Laravel validation messages and redirecting non-vendors (`404`) to `/registervendor`.

### 17. [Resolved] [Payment & Accounting] Midtrans `order_id` Collisions, Undocumented +Rp 4.000 Fee & Hardcoded Commission/Sandbox URL
- **Resolution**: Added `midtrans_order_id` (`ASC-{id}-{timestamp}`), `admin_fee` (`4000`), and `snap_redirect_url` (`redirect_url` accessor) on `ParentOrder`, recorded `admin_fee` in `total_amount`, computed vendor commissions dynamically from `vendors.commission_rate`, and updated `profile/+page.svelte` to open `order.redirect_url`.

### 18. [Resolved] [Fulfillment Security] Vendors Receive Unpaid Orders (with Buyer PII) & Can Ship Before Payment
- **Resolution**: Scoped `VendorOrderController@index` and `show` to paid parent orders (`payment_status = '2'`), enforced paid-order + forward-only shipping state transitions in `updateStatus`, and restored item stock inside `DB::transaction()` when a vendor cancels an order.

### 19. [Resolved] [Database Migrations] Broken `down()` Rollbacks & Column Length Mismatches
- **Resolution**: Fixed `down()` table names in `create_vendor_table.php` (`vendors`) and `create_vendors_orders_table.php` (`vendor_orders`), and added migration `2025_12_16_000001_align_schema_constraints_and_order_metadata.php` widening `users.address` (`TEXT`) and `vendors.store_name` (`VARCHAR(255)`), adding `UNIQUE(user_id)` on `vendors` and `UNIQUE(user_id, product_id)` on `cart`, and adding order snapshot columns.

### 20. [Resolved] [Backend Autoloading & Broken References] PSR-4 Violations & Missing Classes
- **Resolution**: Replaced `payout.php` with PSR-4 `App\Models\Payout`, removed broken `User::cartItems()`, added `User::cart()`/`wishlists()`, `Vendor::vendorOrders()`/`payouts()`, `Product::vendor()`, fixed `Product::getGalleryUrlsAttribute()` (`details.images.gallery`), and fixed `ParentOrderResource` (`phone_number`).

### 21. [Resolved] [Backend Dependencies] `composer.lock` Requires PHP >= 8.4 Despite `composer.json` Declaring `^8.2`
- **Resolution**: Aligned `"php": "^8.4"` in `backend-API/composer.json` with `composer.lock` and added `@php artisan storage:link` to `@setup`.

### 22. [Resolved] [Backend Seeder] `php artisan db:seed` Crashes in `productsSeeder.php`
- **Resolution**: Replaced lowercase `vendorSeeder`/`productsSeeder`/`cartSeeder` with PSR-4 `VendorSeeder`, `ProductSeeder` (12 realistic PC component & PC Ready fixtures with compatibility metadata), and `CartSeeder` (idempotent `updateOrInsert`).

### 23. [Resolved] [PC Builder UX] Lost Build State, Missing `LGA1200` / Cooler Socket Checks & 6-Brand Limit
- **Resolution**: Persisted `selectionMap` in `sessionStorage` across product detail visits, expanded brand recognition to 35+ hardware brands + title prefix fallback, and enforced bidirectional `AM4`/`AM5`/`LGA1700`/`LGA1200` socket checks across CPU, Motherboard, and Cooler plus `DDR4`/`DDR5` across Motherboard and RAM.

### 24. [Resolved] [Checkout UX] Page Reload Loses Selected Items & Shipping Address Is Not Confirmed
- **Resolution**: Persisted `selectedItemIds` in `sessionStorage`, hydrated `$cart` on `/web/co` reload, added required/editable `shipping_address` and `recipient_phone` fields on `/web/co`, and snapshotted both onto `parent_orders`.

### 25. [Resolved] [User Profile Bug] `PUT /api/user` Wipes Omitted `phone_number` / `address` to `null`
- **Resolution**: Updated `AuthController@update` to `sometimes` validation rules with `$user->fill($validated)->save()`.

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
