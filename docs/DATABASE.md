# Database Schema & Eloquent ORM Reference

This document details all database tables, migrations, foreign keys, JSON schemas, Eloquent models, seeders, and known schema-vs-code discrepancies in `backend-API/`.

---

## 1. Database Configuration

- **Default Production/Dev Driver** (`.env.example`): PostgreSQL (`DB_CONNECTION=pgsql`, port `5432`, database `backend_api`).
- **Fallback Driver** (`config/database.php`) & **Test Runner** (`phpunit.xml`): SQLite (`database/database.sqlite` or `:memory:`).
- **Migrations Directory**: `backend-API/database/migrations/` (12 migration files total: 3 Laravel core + 1 Sanctum + 8 marketplace tables).

---

## 2. Entity-Relationship Diagram

```mermaid
erDiagram
    USERS ||--o| VENDORS : "owns (1:1)"
    USERS ||--o{ PERSONAL_ACCESS_TOKENS : "authenticates"
    USERS ||--o{ CART : "has items"
    USERS ||--o{ WISHLISTS : "saves"
    USERS ||--o{ PARENT_ORDERS : "places"

    VENDORS ||--o{ PRODUCTS : "lists"
    VENDORS ||--o{ VENDOR_ORDERS : "fulfills"
    VENDORS ||--o{ PAYOUTS : "requests"

    PRODUCTS ||--o{ CART : "referenced in"
    PRODUCTS ||--o{ WISHLISTS : "referenced in"
    PRODUCTS ||--o{ ORDER_ITEMS : "purchased as"

    PARENT_ORDERS ||--|{ VENDOR_ORDERS : "splits into"
    VENDOR_ORDERS ||--|{ ORDER_ITEMS : "contains"

    USERS {
        bigint id PK
        varchar name
        varchar email UK
        timestamp email_verified_at
        varchar password
        varchar profile_picture
        varchar address
        varchar phone_number
        varchar remember_token
        timestamp created_at
        timestamp updated_at
    }

    VENDORS {
        bigint id PK
        bigint user_id FK
        varchar_100 store_name UK
        text store_description
        varchar store_logo
        decimal_5_2 commission_rate
        decimal_20_2 balance
        timestamp created_at
        timestamp updated_at
    }

    PRODUCTS {
        bigint id PK
        bigint vendor_id FK
        varchar title
        decimal_20_2 price
        integer stock_quantity
        json details
        timestamp created_at
        timestamp updated_at
    }

    CART {
        bigint id PK
        bigint user_id FK
        bigint product_id FK
        integer quantity
        timestamp created_at
        timestamp updated_at
    }

    WISHLISTS {
        bigint id PK
        bigint user_id FK
        bigint product_id FK
        timestamp created_at
        timestamp updated_at
    }

    PARENT_ORDERS {
        bigint id PK
        bigint user_id FK
        decimal_20_2 total_amount
        enum payment_status "1|2|3|4"
        varchar snap_token
        timestamp created_at
        timestamp updated_at
    }

    VENDOR_ORDERS {
        bigint id PK
        bigint parent_order_id FK
        bigint vendor_id FK
        decimal_20_2 order_total
        decimal_20_2 commission_fee
        decimal_20_2 net_amount
        varchar_50 shipping_status
        timestamp created_at
        timestamp updated_at
    }

    ORDER_ITEMS {
        bigint id PK
        bigint vendor_order_id FK
        bigint product_id FK
        integer quantity
        decimal_20_2 unit_price
        timestamp created_at
        timestamp updated_at
    }

    PAYOUTS {
        bigint id PK
        bigint vendor_id FK
        decimal_20_2 amount
        varchar_50 status
        datetime payout_date
        timestamp created_at
        timestamp updated_at
    }
```

---

## 3. Domain Table Specifications

### 3.1 `users`
- **Migration**: `0001_01_01_000000_create_users_table.php`
- **Model**: `App\Models\User` (`app/Models/User.php`)

| Column | SQL Type | Nullable | Default | Constraints / Notes |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | No | Auto | Primary Key |
| `name` | `VARCHAR(255)` | No | - | Full name |
| `email` | `VARCHAR(255)` | No | - | `UNIQUE` |
| `email_verified_at` | `TIMESTAMP` | Yes | `NULL` | Cast to `datetime` |
| `password` | `VARCHAR(255)` | No | - | `hashed` cast / `bcrypt` |
| `profile_picture` | `VARCHAR(255)` | Yes | `NULL` | Storage path (not currently uploaded by any endpoint) |
| `address` | `VARCHAR(255)` | Yes | `NULL` | Shipping/contact address (note: `AuthController@update` allows up to 1000 chars!) |
| `phone_number` | `VARCHAR(255)` | Yes | `NULL` | Validated `max:20` in controllers |
| `remember_token` | `VARCHAR(100)` | Yes | `NULL` | Hidden on serialization |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | `NULL` | Eloquent timestamps |

*(The same migration file also creates `password_reset_tokens` and `sessions`; `0001_01_01_000001` creates `cache` & `cache_locks`; `0001_01_01_000002` creates `jobs`, `job_batches`, & `failed_jobs`; `2025_11_19_192237` creates Sanctum's `personal_access_tokens`.)*

---

### 3.2 `vendors`
- **Migration**: `2025_11_25_040910_create_vendor_table.php`
- **Model**: `App\Models\Vendor` (`app/Models/Vendor.php`)

| Column | SQL Type | Nullable | Default | Constraints / Notes |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | No | Auto | Primary Key |
| `user_id` | `BIGINT UNSIGNED` | No | - | FK -> `users(id)` `ON DELETE CASCADE` (missing `UNIQUE` index) |
| `store_name` | `VARCHAR(100)` | No | - | `UNIQUE` (note: controller validates `max:255`) |
| `store_description` | `TEXT` | Yes | `NULL` | Store bio |
| `store_logo` | `VARCHAR(255)` | Yes | `NULL` | Storage path for logo |
| `commission_rate` | `DECIMAL(5,2)` | No | `5.00` | Percentage rate (note: `OrderController` currently hardcodes 10%) |
| `balance` | `DECIMAL(20,2)` | No | `0.00` | Withdrawable earnings balance |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | `NULL` | Eloquent timestamps |

---

### 3.3 `products`
- **Migration**: `2025_11_25_042158_create_products_table.php`
- **Model**: `App\Models\Product` (`app/Models/Product.php`)

| Column | SQL Type | Nullable | Default | Constraints / Notes |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | No | Auto | Primary Key |
| `vendor_id` | `BIGINT UNSIGNED` | No | - | FK -> `vendors(id)` `ON DELETE CASCADE` |
| `title` | `VARCHAR(255)` | No | - | Product display name |
| `price` | `DECIMAL(20,2)` | No | - | Unit price in IDR (`decimal:2` cast) |
| `stock_quantity` | `INTEGER` | No | `0` | Available units (`integer` cast) |
| `details` | `JSON` | Yes | `NULL` | Flexible metadata & image paths (`array` cast) |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | `NULL` | Eloquent timestamps |

#### Canonical `products.details` JSON Structure
```json
{
  "category": "processor",
  "description": "8-Core 16-Thread Desktop Processor",
  "specs": "[Socket: AM5] [Mem: DDR5]",
  "socket": "AM5",
  "memory": "DDR5",
  "images": {
    "main": "products/main/abc123.webp",
    "gallery": [
      "products/gallery/img1.webp",
      "products/gallery/img2.webp"
    ]
  }
}
```

---

### 3.4 `cart`
- **Migration**: `2025_12_05_072358_create_cart_table.php`
- **Model**: `App\Models\Cart` (`app/Models/Cart.php`, `$table = 'cart'`)

| Column | SQL Type | Nullable | Default | Constraints / Notes |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | No | Auto | Primary Key |
| `user_id` | `BIGINT UNSIGNED` | No | - | FK -> `users(id)` `ON DELETE CASCADE` |
| `product_id` | `BIGINT UNSIGNED` | No | - | FK -> `products(id)` `ON DELETE CASCADE` |
| `quantity` | `INTEGER` | No | `1` | Item count |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | `NULL` | Eloquent timestamps |

---

### 3.5 `wishlists`
- **Migration**: `2025_12_12_063525_create_wishlists_table.php`
- **Model**: `App\Models\WishList` (`app/Models/WishList.php`, `$table = 'wishlists'`)

| Column | SQL Type | Nullable | Default | Constraints / Notes |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | No | Auto | Primary Key |
| `user_id` | `BIGINT UNSIGNED` | No | - | FK -> `users(id)` `ON DELETE CASCADE` |
| `product_id` | `BIGINT UNSIGNED` | No | - | FK -> `products(id)` `ON DELETE CASCADE` |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | `NULL` | Composite `UNIQUE(user_id, product_id)` |

---

### 3.6 `parent_orders`
- **Migration**: `2025_11_25_042600_create_parent_orders_table.php`
- **Model**: `App\Models\ParentOrder` (`app/Models/ParentOrder.php`)

| Column | SQL Type | Nullable | Default | Constraints / Notes |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | No | Auto | Primary Key (also passed as Midtrans `order_id`) |
| `user_id` | `BIGINT UNSIGNED` | No | - | FK -> `users(id)` (restrict on delete) |
| `total_amount` | `DECIMAL(20,2)` | No | - | Sum of item subtotals (excludes Rp 4.000 fee added for Midtrans) |
| `payment_status` | `ENUM('1','2','3','4')` | No | `'1'` | `'1'` Pending, `'2'` Completed, `'3'` Failed, `'4'` Refunded |
| `snap_token` | `VARCHAR(255)` | Yes | `NULL` | Midtrans Snap payment token |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | `NULL` | Eloquent timestamps |

---

### 3.7 `vendor_orders`
- **Migration**: `2025_11_25_042623_create_vendors_orders_table.php`
- **Model**: `App\Models\VendorOrder` (`app/Models/VendorOrder.php`)

| Column | SQL Type | Nullable | Default | Constraints / Notes |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | No | Auto | Primary Key |
| `parent_order_id` | `BIGINT UNSIGNED` | No | - | FK -> `parent_orders(id)` |
| `vendor_id` | `BIGINT UNSIGNED` | No | - | FK -> `vendors(id)` |
| `order_total` | `DECIMAL(20,2)` | No | - | Gross vendor subtotal |
| `commission_fee` | `DECIMAL(20,2)` | No | - | Platform fee deducted |
| `net_amount` | `DECIMAL(20,2)` | No | - | Vendor net receivable (`order_total - commission_fee`) |
| `shipping_status` | `VARCHAR(50)` | No | `'processing'` | Set to `'pending'` on checkout; transitions through `'processing'`, `'shipped'`, `'delivered'`, `'cancelled'` |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | `NULL` | Eloquent timestamps |

---

### 3.8 `order_items`
- **Migration**: `2025_11_25_042703_create_order_items_table.php`
- **Model**: `App\Models\OrderItem` (`app/Models/OrderItem.php`)

| Column | SQL Type | Nullable | Default | Constraints / Notes |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | No | Auto | Primary Key |
| `vendor_order_id` | `BIGINT UNSIGNED` | No | - | FK -> `vendor_orders(id)` |
| `product_id` | `BIGINT UNSIGNED` | No | - | FK -> `products(id)` |
| `quantity` | `INTEGER` | No | - | Units ordered |
| `unit_price` | `DECIMAL(20,2)` | No | - | Price snapshot at checkout |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | `NULL` | Eloquent timestamps |

---

### 3.9 `payouts`
- **Migration**: `2025_11_25_042939_create_payouts_table.php`
- **Model**: `App\Models\payout` (`app/Models/payout.php` — unimplemented stub)

| Column | SQL Type | Nullable | Default | Constraints / Notes |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | No | Auto | Primary Key |
| `vendor_id` | `BIGINT UNSIGNED` | No | - | FK -> `vendors(id)` |
| `amount` | `DECIMAL(20,2)` | No | - | Requested withdrawal amount |
| `status` | `VARCHAR(50)` | No | `'pending'` | Payout approval status |
| `payout_date` | `DATETIME` | Yes | `NULL` | Completion timestamp |
| `created_at` / `updated_at` | `TIMESTAMP` | Yes | `NULL` | Eloquent timestamps |

---

## 4. Eloquent Model Relationships & Accessors Summary

| Model | Defined Relationships | Appended / Custom Accessors |
| :--- | :--- | :--- |
| `User` | `vendor()` (`hasOne`), `parentOrders()` (`hasMany`), `Cart()` (`hasMany`), `cartItems()` (**broken**: references missing `CartItem`) | `$appends = ['is_vendor']`; `profile_picture_url` (not appended) |
| `Vendor` | `user()` (`belongsTo`), `products()` (`hasMany`) | `logo_url` (not appended) |
| `Product` | `Vendor()` (`belongsTo` — note PascalCase method name) | `$appends = ['image_url', 'category']`; `gallery_urls` (**broken**: reads `details.gallery_paths` instead of `details.images.gallery`) |
| `Cart` | `user()` (`belongsTo`), `product()` (`belongsTo`) | None |
| `WishList` | `product()` (`belongsTo`) | None |
| `ParentOrder` | `user()` (`belongsTo`), `vendorOrders()` (`hasMany`) | None |
| `VendorOrder` | `parentOrder()` (`belongsTo`), `vendor()` (`belongsTo`), `orderItems()` (`hasMany`) | None |
| `OrderItem` | `vendorOrder()` (`belongsTo`), `product()` (`belongsTo`) | None |
| `payout` | None (empty class) | None |

---

## 5. Seeders Overview (`database/seeders/`)

- `DatabaseSeeder.php`: Creates 5 factory users + 1 test user (`test@example.com`), then invokes `vendorSeeder`, `productsSeeder`, and `cartSeeder`.
- `vendorSeeder.php`: Creates 1 vendor row per user (`commission_rate: 5.00`).
- `productsSeeder.php`: Attempts to seed 20 products (**currently crashes** on line 21 because `$faker->productName` is not a built-in Faker formatter without a third-party provider).
- `cartSeeder.php`: Inserts 20 random cart items across users and products.

---

## 6. Known Migration & Schema Discrepancies

1. **Broken Rollback (`down()`) Table Names**:
   - `2025_11_25_040910_create_vendor_table.php` creates `'vendors'` in `up()`, but drops `'vendor'` in `down()`.
   - `2025_11_25_042623_create_vendors_orders_table.php` creates `'vendor_orders'` in `up()`, but drops `'vendors_orders'` in `down()`.
2. **Column Length Mismatches**:
   - `users.address` is `VARCHAR(255)` while `AuthController@update` validates `max:1000` (and `register` has no `max`).
   - `vendors.store_name` is `VARCHAR(100)` while `VendorController@register` validates `max:255`.
3. **Missing Uniqueness Constraints**:
   - `vendors.user_id` lacks a `UNIQUE` database index despite a `1:1` domain invariant.
   - `cart` lacks `UNIQUE(user_id, product_id)`, allowing duplicate cart rows under concurrent requests.
4. **Missing Historical Order Snapshots**:
   - `parent_orders` does not snapshot the shipping address or Rp 4.000 admin fee; `order_items` snapshots `unit_price` but not `product_title`.
