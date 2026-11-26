# REST API Reference (`backend-API`)

All API endpoints are defined in `backend-API/routes/api.php` and mounted automatically under the `/api` prefix by `backend-API/bootstrap/app.php`.

---

## 1. Base Conventions

- **Default Local Base URL**: `http://127.0.0.1:8000/api`
- **Required Headers**:
  - `Accept: application/json` *(Always include so validation failures return `422` JSON instead of `302` HTML redirects)*
  - `Content-Type: application/json` *(or `multipart/form-data` when uploading product images)*
  - `Authorization: Bearer <access_token>` *(Required for all endpoints inside the `auth:sanctum` group)*
- **Validation Error Format (`422 Unprocessable Entity`)**:
  ```json
  {
    "message": "The email has already been taken.",
    "errors": {
      "email": ["The email has already been taken."]
    }
  }
  ```

---

## 2. Endpoint Summary Matrix

| Domain | Method | Endpoint | Auth | Controller Action | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **System** | `GET` | `/up` | Public | Laravel Health Check | Active |
| **Auth** | `POST` | `/api/register` | Public | `AuthController@register` | Active |
| **Auth** | `POST` | `/api/login` | Public | `AuthController@login` | Active |
| **Auth** | `POST` | `/api/logout` | Sanctum | `AuthController@logout` | Active |
| **User** | `GET` | `/api/user` | Sanctum | `AuthController@user` | Active |
| **User** | `PUT` | `/api/user` | Sanctum | `AuthController@update` | Active |
| **Vendor** | `POST` | `/api/vendor/register` | Sanctum | `VendorController@register` | Active |
| **Vendor** | `GET` | `/api/vendor/profile` | Sanctum | `VendorController@vendorProfile` | Active |
| **Vendor** | `GET` | `/api/vendor/{id}/products` | Public | `VendorController@vendorProductsView` | Active |
| **Products** | `GET` | `/api/product` | Public | `ProductController@index` | Active |
| **Products** | `GET` | `/api/product/{id}` | Public | `ProductController@show` | Active |
| **Products** | `POST` | `/api/product` | Sanctum + Vendor | `ProductController@store` | Active |
| **Products** | `PUT\|PATCH` | `/api/product/{id}` | Sanctum + Vendor | `ProductController@update` | Partial (bugs) |
| **Products** | `DELETE` | `/api/product/{id}` | Sanctum + Vendor | `ProductController@destroy` | **Broken (`500` missing method)** |
| **Cart** | `POST` | `/api/cart/{productId}` | Sanctum | `CartController@addToCart` | Active (unvalidated qty) |
| **Cart** | `GET` | `/api/cart` | Sanctum | `CartController@index` | Active |
| **Cart** | `PUT` | `/api/cart/update/{cartId}` | Sanctum | `CartController@update` | Active |
| **Cart** | `DELETE` | `/api/cart/delete/{cartId}` | Sanctum | `CartController@destroy` | Active |
| **Wishlist** | `POST` | `/api/wishlist/{productId}` | Sanctum | `WishListController@toggle` | Active |
| **Wishlist** | `GET` | `/api/wishlist` | Sanctum | `WishListController@view` | Active |
| **Wishlist** | `DELETE` | `/api/wishlist/{productId}` | Sanctum | `WishListController@destroy` | Active |
| **Orders** | `POST` | `/api/checkout` | Sanctum | `OrderController@checkout` | Active |
| **Orders** | `GET` | `/api/orders` | Sanctum | `OrderController@index` | Active |
| **Orders** | `GET` | `/api/orders/{id}` | Sanctum | `OrderController@show` | **Broken (`403` binding bug)** |
| **Vendor Orders** | `GET` | `/api/vendor/orders` | Sanctum + Vendor | `VendorOrderController@index` | Active |
| **Vendor Orders** | `GET` | `/api/vendor/orders/{id}` | Sanctum + Vendor | `VendorOrderController@show` | Active |
| **Vendor Orders** | `PATCH` | `/api/vendor/orders/{id}/status` | Sanctum + Vendor | `VendorOrderController@updateStatus` | Active |
| **Payments** | `POST` | `/api/midtrans-callback` | Public (SHA-512) | `OrderController@receive` | Active |

---

## 3. Authentication & User Profile

### `POST /api/register`
Registers a new user account and issues a Sanctum bearer token.
- **Auth**: Public
- **Body (`application/json`)**:
  - `name`: `required|string|max:255`
  - `email`: `required|string|email|max:255|unique:users`
  - `password`: `required|string|min:8|confirmed` *(requires matching `password_confirmation`)*
  - `phone_number`: `nullable|string|max:20`
  - `address`: `nullable|string` *(DB limit: 255 chars)*
- **Success (`201 Created`)**:
  ```json
  {
    "message": "User registered",
    "user": {
      "id": 1,
      "name": "Budi Santoso",
      "email": "budi@example.com",
      "phone_number": "081234567890",
      "address": "Jl. Sudirman No. 10, Jakarta",
      "is_vendor": false
    },
    "access_token": "1|laravel_sanctum_token...",
    "token_type": "Bearer"
  }
  ```

### `POST /api/login`
Authenticates an existing user and returns a new bearer token.
- **Auth**: Public
- **Body (`application/json`)**: `email` (`required|email`), `password` (`required`)
- **Success (`200 OK`)**: `{ "message": "Login succesful", "access_token": "...", "token_type": "Bearer", "user": { ... } }`
- **Failure (`401 Unauthorized`)**: `{ "message": "Login Failed" }`

### `POST /api/logout`
Revokes the caller's current personal access token.
- **Auth**: `Bearer`
- **Success (`200 OK`)**: `{ "message": "logged out" }`

### `GET /api/user`
Returns the authenticated user object (including computed `is_vendor`).
- **Auth**: `Bearer`
- **Success (`200 OK`)**: Raw `User` JSON object.

### `PUT /api/user`
Updates the authenticated user's profile fields.
- **Auth**: `Bearer`
- **Body (`application/json`)**:
  - `name`: `required|string|max:255`
  - `email`: `required|email|max:255|unique:users,email,{currentUserId}`
  - `phone_number`: `nullable|string|max:20` *(Warning: omitting resets column to `null`)*
  - `address`: `nullable|string|max:1000` *(Warning: omitting resets column to `null`; DB column max is 255)*
- **Success (`200 OK`)**: `{ "message": "Profil berhasil diperbarui", "user": { ... } }`

---

## 4. Vendor Onboarding & Storefront

### `POST /api/vendor/register`
Upgrades the authenticated user into a vendor and updates their contact details.
- **Auth**: `Bearer`
- **Body (`application/json`)**:
  - `store_name`: `required|string|max:255|unique:vendors` *(DB column max is 100)*
  - `store_description`: `nullable|string`
  - `phone_number`: `required|string|max:20`
  - `address`: `required|string`
  - `owner_name`: `nullable|string|max:255` *(updates `users.name` if provided)*
- **Responses**:
  - `201 Created`: `{ "message": "Toko berhasil dibuat!", "vendor": { ... }, "user": { ... } }`
  - `400 Bad Request`: `{ "message": "User sudah terdaftar sebagai vendor." }`

### `GET /api/vendor/profile`
Fetches the authenticated user's vendor store profile and balance.
- **Auth**: `Bearer`
- **Responses**:
  - `200 OK`: `{ "message": "Profil vendor ditemukan", "vendor": { "id": 1, "store_name": "...", "commission_rate": "5.00", "balance": "0.00", ... } }`
  - `404 Not Found`: `{ "message": "User is not a vendor." }`

### `GET /api/vendor/{id}/products`
Publicly lists a vendor record with all of their products eager-loaded.
- **Auth**: Public (`{id}` = `vendors.id`)
- **Responses**:
  - `200 OK`: `{ "message": "Vendor products retrieved successfully.", "data": { "id": 1, "store_name": "...", "products": [ ... ] } }`
  - `404 Not Found`: `{ "message": "Vendor not found." }`

---

## 5. Product Catalog & Management

### `GET /api/product`
Paginated product listing with search, filtering, and sorting.
- **Auth**: Public
- **Query Parameters**:
  - `search`: Case-insensitive substring match against `title`
  - `category`: Matches `details->category` (ignored when `'all'`)
  - `vendor_id`: Exact match on `vendor_id`
  - `min_price` / `max_price`: Numeric bounds on `price`
  - `sort_by`: One of `price` *(default)*, `title`, `created_at`, `stock_quantity`, `vendor_id`
  - `sort`: `asc` *(default)* or `desc`
  - `per_page`: Items per page *(default `10`)*
  - `page`: Page number *(default `1`)*
- **Success (`200 OK`)**: Standard Laravel `LengthAwarePaginator` JSON (`current_page`, `data`, `last_page`, `per_page`, `total`, `links`). Each item includes `image_url` and `category`.

### `GET /api/product/{id}`
Fetches a single product with its `vendor` relation loaded.
- **Auth**: Public
- **Responses**: `200 OK` (raw `Product` JSON with `vendor` object) or `404 Not Found`.

### `POST /api/product`
Creates a new product owned by the authenticated vendor.
- **Auth**: `Bearer` (requires registered vendor; otherwise `403`)
- **Body (`multipart/form-data` or `application/json`)**:
  - `title`: `required|string|max:255`
  - `price`: `required|numeric|min:0`
  - `stock_quantity`: `integer|min:0` *(defaults to `0`)*
  - `description`: `nullable|string` *(merged into `details.description`)*
  - `category`: `nullable|string|max:100` *(merged into `details.category`)*
  - `details`: `nullable|array` *(e.g. `details[socket]`, `details[memory]`, `details[specs]`)*
  - `image`: `nullable|image|mimes:jpeg,png,jpg,webp|max:2048` *(saved to `details.images.main`)*
  - `images[]`: `nullable|array` of `image|mimes:jpeg,png,jpg,webp|max:2048` *(saved to `details.images.gallery`)*
- **Success (`201 Created`)**: `{ "message": "Product created successfully", "data": { ... } }`

### `PUT|PATCH /api/product/{id}`
Updates an existing product owned by the authenticated vendor.
- **Auth**: `Bearer` (must own product)
- **Notes**: When uploading files (`image`, `images[]`), send a `POST` request with `_method=PUT` so PHP parses `multipart/form-data`. Supports `remove_gallery_images[]` to remove gallery paths.

### `DELETE /api/product/{id}`
> [!CAUTION]
> **Currently Broken (`500 Internal Server Error`)**: `routes/api.php` registers `[ProductController::class, 'destroy']`, but `ProductController` does not define a `destroy()` method.

---

## 6. Shopping Cart & Wishlist

> [!IMPORTANT]
> Notice the ID parameter difference in Cart routes:
> - `POST /api/cart/{id}` expects **`product_id`**.
> - `PUT /api/cart/update/{id}` and `DELETE /api/cart/delete/{id}` expect **`cart.id`** (primary key of the `cart` row).

### `POST /api/cart/{productId}`
Adds a product to the user's cart (or increments quantity if already present).
- **Auth**: `Bearer`
- **Body**: `{ "quantity": 1 }` *(Must be explicitly sent; controller does not fall back to `1` if omitted)*
- **Responses**: `200 OK`, `400 Bad Request` (`Stok habis`), `404 Not Found`.

### `GET /api/cart`
Lists all items in the authenticated user's cart with `product` eager-loaded.
- **Auth**: `Bearer`
- **Success (`200 OK`)**:
  ```json
  {
    "status": "success",
    "data": [
      {
        "id": 12,
        "user_id": 1,
        "product_id": 5,
        "quantity": 2,
        "product": { "id": 5, "title": "AMD Ryzen 7 7800X3D", "price": "6500000.00", "stock_quantity": 10, "image_url": "..." }
      }
    ],
    "grand_total": 13000000
  }
  ```

### `PUT /api/cart/update/{cartId}`
Sets a cart row's quantity (or deletes the row if `quantity <= 0`).
- **Auth**: `Bearer`
- **Body**: `{ "quantity": 3 }` (`required|integer|min:0`)
- **Responses**: `200 OK`, `400 Bad Request` (insufficient stock), `404 Not Found`.

### `DELETE /api/cart/delete/{cartId}`
Removes a specific cart row.
- **Auth**: `Bearer`
- **Responses**: `200 OK` or `404 Not Found`.

### `POST /api/wishlist/{productId}`
Toggles a product in the authenticated user's wishlist.
- **Auth**: `Bearer`
- **Success (`200 OK`)**: `{ "status": "added" | "removed", "message": "..." }`

### `GET /api/wishlist`
Returns all wishlist entries for the user with `product` eager-loaded.
- **Auth**: `Bearer`
- **Success (`200 OK`)**: `{ "status": "success", "data": [ ... ] }`

### `DELETE /api/wishlist/{productId}`
Explicitly deletes a product from the user's wishlist by `product_id`.
- **Auth**: `Bearer`
- **Responses**: `200 OK` or `404 Not Found`.

---

## 7. Checkout, Buyer Orders & Vendor Fulfillment

### `POST /api/checkout`
Creates a `ParentOrder`, splits items by seller into `VendorOrder`s and `OrderItem`s, decrements `stock_quantity`, requests a Midtrans Snap token (`gross_amount = subtotal + 4000`), and deletes the checked-out cart rows.
- **Auth**: `Bearer`
- **Body (`application/json`)**:
  ```json
  {
    "selected_cart_ids": [12, 15]
  }
  ```
- **Success (`201 Created`)**:
  ```json
  {
    "status": "success",
    "message": "Order created, waiting for payment",
    "snap_token": "66e4fa55-...",
    "order_id": 42,
    "redirect_url": "https://app.sandbox.midtrans.com/snap/v2/vtweb/66e4fa55-..."
  }
  ```

### `GET /api/orders`
Paginates (`15` per page) the authenticated buyer's `ParentOrder` records with `vendorOrders` eager-loaded, newest first.
- **Auth**: `Bearer`

### `GET /api/orders/{id}`
> [!CAUTION]
> **Currently Broken (`403 Unauthorized`)**: Route parameter `{id}` does not match method signature `show(ParentOrder $parentOrder)`, so Laravel injects an empty `ParentOrder` instance where `$parentOrder->user_id` is `null`.

### `GET /api/vendor/orders`
Paginates (`15` per page) `VendorOrder` records belonging to the authenticated seller with `orderItems.product` and `parentOrder.user` eager-loaded.
- **Auth**: `Bearer` (Vendor required)

### `GET /api/vendor/orders/{id}`
Returns a single `VendorOrder` (`{ "data": { ... } }`) owned by the authenticated vendor.
- **Auth**: `Bearer` (Vendor required)

### `PATCH /api/vendor/orders/{id}/status`
Updates the fulfillment state of a vendor order.
- **Auth**: `Bearer` (Vendor required)
- **Body (`application/json`)**:
  - `shipping_status`: `required|string|in:pending,processing,shipped,delivered,cancelled`
- **Success (`200 OK`)**: `{ "message": "Order status updated successfully.", "data": { ... } }`

---

## 8. Midtrans Payment Webhook

### `POST /api/midtrans-callback`
Receives asynchronous HTTP payment notifications from Midtrans servers. Excluded from CSRF verification in `bootstrap/app.php`.
- **Auth**: Signature verification (`SHA-512`)
- **Signature Formula**:
  ```text
  signature_key == SHA512(order_id + status_code + gross_amount + MIDTRANS_SERVER_KEY)
  ```
- **Status Mapping**:
  - `capture` or `settlement` -> Sets `parent_orders.payment_status = '2'` (Completed).
  - `expire`, `cancel`, or `deny` -> Sets `parent_orders.payment_status = '3'` (Failed) and increments `products.stock_quantity` for every ordered item.
  - `pending` -> Sets `parent_orders.payment_status = '1'` (Pending).
- **Responses**: `200 OK`, `403 Invalid Signature`, `404 Order not found`.
