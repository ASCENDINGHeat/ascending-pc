# Frontend Guide (`frontend-user`)

This guide documents the architecture, route tree, reusable components, client stores, API integration conventions, and build workflow of `frontend-user/`.

---

## 1. Framework & Tooling Stack

- **Meta-Framework**: `@sveltejs/kit ^2.47.1` with `@sveltejs/adapter-node ^5.4.0` (outputs standalone Node server to `build/`).
- **UI Compiler**: `svelte ^5.41.0` *(Note: all custom application components and pages currently author components in Svelte 4 compatibility syntax: `export let`, `$:`, `on:click`, `<slot />`)*.
- **Bundler**: `vite ^7.1.10` (`@sveltejs/vite-plugin-svelte ^6.2.1`).
- **Styling**: `tailwindcss ^4.1.17` (`@tailwindcss/vite`, `@tailwindcss/forms`, `@tailwindcss/typography`) imported in `src/routes/layout.css`, mixed with component-scoped `<style>` blocks.
- **Type Checking**: `svelte-check ^4.3.3` + `typescript ^5.9.3` configured via `jsconfig.json` (`"strict": true`, `"checkJs": true`).

---

## 2. Environment Configuration

`frontend-user` statically imports two environment variables from `$env/static/public`. A `.env` file **must** exist in `frontend-user/` prior to running `npm run dev`, `npm run check`, or `npm run build`:

```env
PUBLIC_API_URL=http://127.0.0.1:8000/api
PUBLIC_STORAGE_URL=http://127.0.0.1:8000
```

- `PUBLIC_API_URL`: Base prefix for backend REST calls (e.g., `${PUBLIC_API_URL}/product`).
- `PUBLIC_STORAGE_URL`: Backend host origin prepended to relative storage paths (e.g., `${PUBLIC_STORAGE_URL}/storage/${path}`).

---

## 3. Complete Route Map (`src/routes/`)

### 3.1 Active Application Routes

| Route URL | File Path | Layout Chrome | Primary Function |
| :--- | :--- | :--- | :--- |
| `/` | `src/routes/+page.svelte` | Hidden (`+layout.svelte` hides Header/Footer on `'/'`) | Split-screen welcome hero linking to `/web` (*Belanja Sekarang*) and `/login` (*Area Pengguna*). |
| `/login` | `src/routes/login/+page.svelte` | Modal over layout | Email/password login (`POST /login`), stores `auth_token` & `user_data`. |
| `/register` | `src/routes/register/+page.svelte` | Modal over layout | Buyer registration (`POST /register`) with phone & address fields. |
| `/registervendor` | `src/routes/registervendor/+page.svelte` | Modal over layout | Seller onboarding form (`POST /vendor/register`), redirects to `/web/dashboard`. |
| `/web` | `src/routes/web/+page.svelte` | Header + Footer | Main storefront: dual promotional carousels + 8 featured products (`GET /product`). |
| `/web/categories/[category]` | `src/routes/web/categories/[category]/+page.svelte` | Header + Footer | Dynamic category view (`all`, `processor`, `vga`, etc.) & keyword search (`?search=...`). |
| `/web/pc-ready` | `src/routes/web/pc-ready/+page.svelte` | Header + Footer | Pre-built PC catalog (`GET /product?category=pc-ready`). |
| `/web/product/[id]` | `src/routes/web/product/[id]/+page.{ts,svelte}` | Header + Footer | Product detail page (universal `load` in `+page.ts`), quantity picker, Add to Cart, Wishlist toggle. |
| `/web/simulasi-pc` | `src/routes/web/simulasi-pc/+page.svelte` | Header + Footer | 9-slot custom PC configurator with socket/RAM compatibility checks & bulk cart add. |
| `/web/cart` | `src/routes/web/cart/+page.svelte` | Header + Footer | Full shopping cart (`GET /cart`, `PUT /cart/update/{id}`, `DELETE /cart/delete/{id}`) with item selection checkboxes. |
| `/web/co` | `src/routes/web/co/+page.svelte` | Modal over layout | Checkout summary (Subtotal + Rp 4.000 Admin Fee), calls `POST /checkout`, opens Midtrans `redirect_url`. |
| `/web/wishlist` | `src/routes/web/wishlist/+page.svelte` | Header + Footer | Saved wishlist grid (`GET /wishlist`, `DELETE /wishlist/{productId}`). |
| `/web/profile` | `src/routes/web/profile/+page.svelte` | Header + Footer | Buyer biodata editor (`PUT /user`) and order history (`GET /orders`) with 5-second pending-payment polling. |
| `/web/dashboard` | `src/routes/web/dashboard/+page.svelte` | Self-contained seller shell (still wrapped by root layout) | Seller portal: KPI cards, order status workflow (`PATCH /vendor/orders/{id}/status`), product list, and product creation form (`POST /product`). |
| `/web/sidebar-mobile` | `src/routes/web/sidebar-mobile/+page.svelte` | Standalone route | Mobile navigation menu page (navigated to via hamburger icon in `Header.svelte`). |

### 3.2 Incomplete, Orphaned & Boilerplate Routes

| Route / Files | Status & Explanation |
| :--- | :--- |
| `/jual-pc` (`src/routes/jual-pc/+page.svelte`) | Unlinked static mockup rendering 10 dummy `"PC Bekas"` items with dead `/sell/{i}` links. |
| `/pengaturan` (`src/routes/pengaturan/+page.svelte`) | Unlinked static settings UI shell (4 tabs, no state bindings or API calls). |
| `/pengguna` (`src/routes/pengguna/+page.svelte`) | Legacy user hub checking dead `localStorage.userLoggedIn` key (always redirects to `/login`). |
| `/web/product-pcready/[id]` (`+page.{ts,svelte}`) | Orphaned detail route reading static `pc-ready-products.ts`; `/web/pc-ready` now links to `/web/product/[id]`. |
| `/about`, `/sverdle`, `/sverdle/how-to-play` | Default `npx sv create` demo pages left in the repository. |
| `src/routes/web/categories/*.svelte` (**11 files**: `[category].svelte`, `all.svelte`, `processor.svelte`, `motherboard.svelte`, `CPU Cooler (HSF).svelte`, `vga.svelte`, `ram.svelte`, `storage.svelte`, `casing.svelte`, `psu.svelte`, `monitor.svelte`) | Non-routable pre-SvelteKit-v1 filenames ignored by SvelteKit v2 (superseded by `categories/[category]/+page.svelte`). |

---

## 4. Component Library (`src/lib/components/`)

| Component | Used By | Responsibilities |
| :--- | :--- | :--- |
| `Header.svelte` | `+layout.svelte` | Top navigation bar (`PC Store` brand, search input -> `/web/categories/all?search=...`, nav links, wishlist badge, cart drawer toggle, `<UserMenu />`, mobile hamburger link to `/web/sidebar-mobile`). Mounts `<CartDrawer />`. |
| `Footer.svelte` | `+layout.svelte` | Site-wide 4-column footer with quick links and contact details. |
| `ProductCard.svelte` | `web/categories/[category]/+page.svelte` | Reusable catalog card resolving `image_url` / `PUBLIC_STORAGE_URL`, stock status, `POST /cart/{id}`, and `POST /wishlist/{id}` toggle. |
| `CartDrawer.svelte` | `Header.svelte` | Slide-over cart sidebar syncing with `GET /cart`, quantity `+/-` (`PUT /cart/update/{id}`), item removal (`DELETE /cart/delete/{id}`), and navigation to `/web/cart`. |
| `UserMenu.svelte` | `Header.svelte` | Avatar dropdown fetching `GET /user` on open; links to `/web/profile`, `/web/dashboard` (if `is_vendor`), or `/registervendor`, plus `POST /logout`. |
| `Breadcrumb.svelte` | `jual-pc`, `product-pcready/[id]`, legacy category files | Breadcrumb trail renderer (`items: { name, href }[]`). |
| `BrandButton.svelte` | `web/+page.svelte` (imported) | Simple styled pill button for brand filtering. |
| `HeroCarousel.svelte` | Legacy `categories/all.svelte` only | Auto-rotating banner carousel. |
| `PromoSidebar.svelte` | Legacy `categories/all.svelte` only | Vertical promo image stack. |
| `CategoryBar.svelte` | Unused (0 imports) | Horizontal category pill strip. |
| `ProductGrid.svelte` | Legacy `categories/*.svelte` only | Grid wrapper around `ProductCard` (outdated prop names). |

---

## 5. Client State Management (`src/lib/stores/`)

| Store Module | Exported Stores & Helpers | Storage Backing | Role in App |
| :--- | :--- | :--- | :--- |
| `auth.ts` | `user`, `isLoggedIn`, `logout()` | Reads `auth_token` & `user_data` from `localStorage` on browser init | Primary authentication state read by login, register, checkout, wishlist, and product detail pages. |
| `user.ts` | `user`, `clearUser()` | Two-way synced with `localStorage.user_data` | Secondary user profile store populated by `+layout.svelte` *(overlaps with `auth.ts` — see `docs/IMPROVEMENTS.md`)*. |
| `cart.ts` | `cart`, `selectedItemIds`, `addToCart()`, `removeFromCart()`, `clearCart()` | In-memory Svelte `writable` | Holds active cart items (`CartItem[]`) and IDs selected on `/web/cart` for `/web/co` checkout. |
| `wishlist.ts` | `wishlist`, `addToWishlistStore()`, `removeFromWishlistStore()` | In-memory Svelte `writable` | Tracks wishlisted products interacted with via `ProductCard.svelte`. |
| `cartStore.ts` | `cart` (custom store) | In-memory | **Unused duplicate store** (0 imports across codebase). |

---

## 6. Scripts & Production Build

```bash
# Start development server (default port 5173)
npm run dev

# Run SvelteKit type generation & svelte-check static analysis
npm run check

# Check / apply Prettier formatting
npm run lint
npm run format

# Build standalone Node.js production server into ./build
npm run build

# Preview production build locally
npm run preview
```

To run the compiled `@sveltejs/adapter-node` server in production:
```bash
PORT=3000 ORIGIN=https://your-domain.com node build/index.js
```
