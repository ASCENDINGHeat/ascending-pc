<script lang="ts">
	import { page } from '$app/stores';
	import BrandButton from './BrandButton.svelte';
	import UserMenu from './UserMenu.svelte';
	// 1. Pastikan Component CartDrawer diimport
	import CartDrawer from './CartDrawer.svelte'; 
	import { goto } from '$app/navigation';
	import { cart } from '$lib/stores/cart';
	import { wishlist } from '$lib/stores/wishlist';
	import { user, isLoggedIn, logout } from '$lib/stores/auth';
	import { PUBLIC_API_URL } from '$env/static/public';
	import { createEventDispatcher } from 'svelte';
	import { fly, fade } from 'svelte/transition';

	const dispatch = createEventDispatcher();

	// --- Search State ---
	let searchTerm = '';
	function handleSearch() {
		if (searchTerm.trim()) {
			mobileDrawerOpen = false;
			goto(`/web/categories/all?search=${encodeURIComponent(searchTerm.trim())}`);
		}
	}

	function handleSearchKeydown(e: KeyboardEvent) {
		if (e.key === 'Enter') handleSearch();
	}

	// --- Menu States ---
	let cartOpen: boolean = false;
	let wishlistOpen: boolean = false;
	let showUserDropdown: boolean = false;
	let mobileDrawerOpen: boolean = false;

	// Elements for click-outside detection
	let cartBtnEl: HTMLElement | null = null;
	let wishlistEl: HTMLElement | null = null;
	let wishlistBtnEl: HTMLElement | null = null;

	const mobileCategories = [
		{ label: 'Semua Kategori', slug: 'all' },
		{ label: 'Processor', slug: 'processor' },
		{ label: 'Motherboard', slug: 'motherboard' },
		{ label: 'CPU Cooler', slug: 'cooler' },
		{ label: 'VGA / GPU', slug: 'vga' },
		{ label: 'RAM', slug: 'ram' },
		{ label: 'Storage', slug: 'storage' },
		{ label: 'PSU', slug: 'psu' },
		{ label: 'Casing', slug: 'casing' },
		{ label: 'Monitor', slug: 'monitor' }
	];

	// --- Handlers ---
	function toggleCart(): void {
		cartOpen = !cartOpen;
		if (cartOpen) { 
			wishlistOpen = false;
			showUserDropdown = false;
			mobileDrawerOpen = false;
		}
		dispatch('cartClick');
	}

	function closeCart(): void { 
		cartOpen = false; 
	}
	
	function toggleWishlist(): void {
		wishlistOpen = !wishlistOpen;
		if (wishlistOpen) { 
			cartOpen = false; 
			showUserDropdown = false; 
		}
	}
	
	function closeWishlist(): void { 
		wishlistOpen = false;
	}

	function toggleUserMenu(): void {
		showUserDropdown = !showUserDropdown;
		if (showUserDropdown) { 
			cartOpen = false; 
			wishlistOpen = false;
		}
	}

	function navigateMobile(path: string): void {
		mobileDrawerOpen = false;
		goto(path);
	}

	async function handleMobileLogout(): Promise<void> {
		mobileDrawerOpen = false;
		await logout(PUBLIC_API_URL);
		goto('/login');
	}

	// Click Outside Handler
	function onWindowClick(e: Event): void {
		const target = (e as MouseEvent).target as Node | null;
		
		if (wishlistOpen && target && wishlistEl && wishlistBtnEl && !wishlistEl.contains(target) && !wishlistBtnEl.contains(target)) {
			wishlistOpen = false;
		}
		
		const userMenuArea = document.querySelector('.user-menu-area');
		if (showUserDropdown && target && userMenuArea && !userMenuArea.contains(target)) {
			showUserDropdown = false;
		}
	}

	$: if (wishlistOpen) setTimeout(() => wishlistEl?.focus(), 0);

	function handleCategoryClick(path: string) {
		goto(path.startsWith('/web') ? path : `/web${path}`);
	}

	function handleWishlistClick(path: string) {
		closeWishlist();
		goto(path.startsWith('/web') ? path : `/web${path}`);
	}

	function onKeyDown(e: KeyboardEvent): void {
		if (e.key === 'Escape') {
			showUserDropdown = false;
			cartOpen = false;
			wishlistOpen = false;
			mobileDrawerOpen = false;
		}
	}
</script>

<CartDrawer isOpen={cartOpen} on:close={closeCart} />

<header class="site-header">
	<div class="site-top">
		<div class="brand">
			<div class="brand-top-row">
				<div class="mobile-header-bar">
					<a href="/web" class="logo" aria-label="Beranda - PC Store">TechForge</a>
					<div class="mobile-quick-actions">
						<button
							type="button"
							class="mobile-icon-btn"
							aria-label="Keranjang"
							on:click={toggleCart}
						>
							<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M6 6h15l-1.68 9.39A2 2 0 0 1 17.36 17H8.64a2 2 0 0 1-1.96-1.61L5 4H3"></path>
								<circle cx="10" cy="20" r="1"></circle>
								<circle cx="18" cy="20" r="1"></circle>
							</svg>
							{#if $cart.length > 0}
								<span class="mobile-badge">{$cart.length}</span>
							{/if}
						</button>
						<button
							type="button"
							class="mobile-menu-btn"
							aria-label="Menu"
							aria-expanded={mobileDrawerOpen}
							on:click={() => (mobileDrawerOpen = !mobileDrawerOpen)}
						>
							<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="white" stroke-width="2">
								<line x1="3" y1="6" x2="21" y2="6"></line>
								<line x1="3" y1="12" x2="21" y2="12"></line>
								<line x1="3" y1="18" x2="21" y2="18"></line>
							</svg>
						</button>
					</div>
				</div>
				
				<div class="brand-action-row">
					<BrandButton variant="pc" ariaLabel="PC Ready" on:click={() => goto('/web/pc-ready')}>PC Ready</BrandButton>
					<BrandButton variant="sell" ariaLabel="Simulasi PC" on:click={() => goto('/web/simulasi-pc')}>Simulasi PC</BrandButton>
				</div>

				<div class="mobile-search-bar" role="search">
					<input
						class="mobile-search-input"
						type="search"
						placeholder="Cari komponen PC..."
						aria-label="Cari produk"
						bind:value={searchTerm}
						on:keydown={handleSearchKeydown}
					/>
					<button type="button" class="mobile-search-submit" aria-label="Cari" on:click={handleSearch}>
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="white" stroke-width="2">
							<circle cx="11" cy="11" r="6"></circle>
							<path d="M21 21l-4.35-4.35"></path>
						</svg>
					</button>
				</div>
			</div>
		</div>

		<div class="top-right desktop-only">
			<div class="search-wrap" role="search">
				<input 
					class="search-input" 
					type="search" 
					placeholder="Cari produk ..." 
					aria-label="Cari produk"
					bind:value={searchTerm}
					on:keydown={handleSearchKeydown}
				/>
				<button class="search-btn" aria-hidden="true" title="Cari" on:click={handleSearch}>
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="white" stroke-width="2" aria-hidden="true">
						<circle cx="11" cy="11" r="6"></circle>
						<path d="M21 21l-4.35-4.35"></path>
					</svg>
				</button>
			</div>

			<div class="cart-wrapper">
				<button
					class="cart-btn"
					on:click={() => toggleCart()}
					bind:this={cartBtnEl}
					aria-haspopup="true"
					aria-expanded={cartOpen}
					aria-label="Keranjang"
				>
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
						<path d="M6 6h15l-1.68 9.39A2 2 0 0 1 17.36 17H8.64a2 2 0 0 1-1.96-1.61L5 4H3"></path>
						<circle cx="10" cy="20" r="1"></circle>
						<circle cx="18" cy="20" r="1"></circle>
					</svg>
					<span class="cart-count">{$cart.length}</span>
				</button>
			</div>

			<div class="wishlist-wrapper">
				<button
					class="wishlist-btn"
					on:click={toggleWishlist}
					bind:this={wishlistBtnEl}
					aria-haspopup="true"
					aria-expanded={wishlistOpen}
					aria-controls="wishlist-menu"
					aria-label="Wishlist"
				>
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
						<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
					</svg>
					<span class="wishlist-count">{$wishlist.length}</span>
				</button>

				{#if wishlistOpen}
					<div class="wishlist-menu menu" bind:this={wishlistEl} id="wishlist-menu" role="menu" tabindex="-1" on:click|stopPropagation>
						<a href="/web/wishlist" class="menu-item" role="menuitem" on:click|preventDefault={() => handleWishlistClick('/web/wishlist')}>
							<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l7.78-7.78a5.5 5.5 0 0 0 0-7.78z"/></svg>
							<span>Lihat Wishlist</span>
						</a>
					</div>
				{/if}
			</div>

			<div class="user-menu-area">
				<button
					class="btn-icon-user"
					on:click={toggleUserMenu}
					aria-haspopup="true"
					aria-expanded={showUserDropdown}
					aria-label="Menu Pengguna"
				>
					<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
						<circle cx="12" cy="7" r="4"></circle>
					</svg>
				</button>

				{#if showUserDropdown}
					<div class="dropdown-wrapper">
						<UserMenu on:close={() => showUserDropdown = false} />
					</div>
				{/if}
			</div>
		</div>
	</div>
</header>

{#if mobileDrawerOpen}
	<div class="mobile-drawer-backdrop" role="presentation" on:click={() => (mobileDrawerOpen = false)} transition:fade={{ duration: 180 }}></div>
	<aside class="mobile-drawer" aria-label="Menu Navigasi Mobile" transition:fly={{ x: -300, duration: 220 }}>
		<div class="mobile-drawer-header">
			<span class="mobile-drawer-brand">TechForge</span>
			<button type="button" class="mobile-drawer-close" aria-label="Tutup Menu" on:click={() => (mobileDrawerOpen = false)}>✕</button>
		</div>

		<div class="mobile-drawer-user">
			{#if $isLoggedIn}
				<div class="mobile-user-greeting">
					<strong>{$user?.name || 'Member'}</strong>
					<span>{$user?.email || ''}</span>
				</div>
				<div class="mobile-account-links">
					<button type="button" on:click={() => navigateMobile('/web/profile')}>👤 Profil & Pesanan</button>
					<button type="button" on:click={() => navigateMobile('/web/cart')}>🛒 Keranjang ({$cart.length})</button>
					<button type="button" on:click={() => navigateMobile('/web/wishlist')}>❤️ Wishlist ({$wishlist.length})</button>
					{#if $user?.is_vendor}
						<button type="button" on:click={() => navigateMobile('/web/dashboard')}>🏪 Dashboard Toko</button>
					{:else}
						<button type="button" on:click={() => navigateMobile('/registervendor')}>🏪 Buka Toko Vendor</button>
					{/if}
				</div>
			{:else}
				<div class="mobile-auth-buttons">
					<button type="button" class="btn-m-login" on:click={() => navigateMobile('/login')}>Masuk</button>
					<button type="button" class="btn-m-register" on:click={() => navigateMobile('/register')}>Daftar</button>
				</div>
			{/if}
		</div>

		<div class="mobile-drawer-section">
			<div class="mobile-section-title">Fitur Utama</div>
			<button type="button" class="mobile-nav-item" on:click={() => navigateMobile('/web')}>🏠 Beranda Toko</button>
			<button type="button" class="mobile-nav-item" on:click={() => navigateMobile('/web/pc-ready')}>🖥️ PC Ready</button>
			<button type="button" class="mobile-nav-item" on:click={() => navigateMobile('/web/simulasi-pc')}>⚙️ Simulasi Rakit PC</button>
		</div>

		<div class="mobile-drawer-section">
			<div class="mobile-section-title">Kategori Komponen</div>
			<div class="mobile-cat-grid">
				{#each mobileCategories as cat}
					<button type="button" class="mobile-cat-chip" on:click={() => navigateMobile(`/web/categories/${cat.slug}`)}>
						{cat.label}
					</button>
				{/each}
			</div>
		</div>

		{#if $isLoggedIn}
			<button type="button" class="mobile-logout-btn" on:click={handleMobileLogout}>🚪 Keluar Akun</button>
		{/if}
	</aside>
{/if}

<nav class="brand-category-bar bottom desktop-only" aria-label="Kategori - full width">
	<div class="categories" role="navigation" aria-label="Kategori produk">
		<a class="cat-pill large" href="/web/categories/all" on:click|preventDefault={() => handleCategoryClick('/web/categories/all')}>ALL CATEGORIES</a>
		<a class="cat-pill" href="/web/categories/processor" on:click|preventDefault={() => handleCategoryClick('/web/categories/processor')}>PROCESSOR</a>
		<a class="cat-pill" href="/web/categories/motherboard" on:click|preventDefault={() => handleCategoryClick('/web/categories/motherboard')}>MOTHERBOARD</a>
		<a class="cat-pill" href="/web/categories/cooler" on:click|preventDefault={() => handleCategoryClick('/web/categories/cooler')}>COOLER</a>
		<a class="cat-pill" href="/web/categories/vga" on:click|preventDefault={() => handleCategoryClick('/web/categories/vga')}>VGA</a>
		<a class="cat-pill" href="/web/categories/storage" on:click|preventDefault={() => handleCategoryClick('/web/categories/storage')}>STORAGE</a>
		<a class="cat-pill" href="/web/categories/ram" on:click|preventDefault={() => handleCategoryClick('/web/categories/ram')}>RAM</a>
		<a class="cat-pill" href="/web/categories/casing" on:click|preventDefault={() => handleCategoryClick('/web/categories/casing')}>CASING</a>
		<a class="cat-pill" href="/web/categories/psu" on:click|preventDefault={() => handleCategoryClick('/web/categories/psu')}>PSU</a>
		<a class="cat-pill" href="/web/categories/monitor" on:click|preventDefault={() => handleCategoryClick('/web/categories/monitor')}>MONITOR</a>
	</div>
</nav>

<svelte:window on:click={onWindowClick} on:keydown={onKeyDown} />

<style>
	:root { --category-bar-height: 56px; }

	.brand-category-bar {
		min-height: var(--category-bar-height);
		padding: 0 16px;
		display: flex;
		align-items: center;
		box-sizing: border-box;
	}

	.site-top {
		min-height: var(--category-bar-height);
		align-items: center;
	}

	.brand,
	.brand-top-row,
	.brand-action-row,
	.top-right,
	.search-wrap {
		height: 100%;
		display: flex;
		align-items: center;
	}

	.logo {
		display: inline-flex;
		align-items: center;
		height: 100%;
	}

	.brand-action-row :global(button),
	.brand-action-row .brand-action {
		height: calc(var(--category-bar-height) - 14px);
		padding: 0 18px;
		border-radius: 10px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
	}

	.search-wrap {
		height: calc(var(--category-bar-height) - 14px);
		padding: 0 12px;
		border-radius: 999px;
	}

	.search-input {
		height: 100%;
		line-height: 1;
		padding: 0 8px;
	}

	.cart-btn,
	.wishlist-btn,
	.icon-btn {
		width: calc(var(--category-bar-height) - 12px);
		height: calc(var(--category-bar-height) - 12px);
		min-width: calc(var(--category-bar-height) - 12px);
		min-height: calc(var(--category-bar-height) - 12px);
		display: inline-flex;
		align-items: center;
		justify-content: center;
		border-radius: 999px;
	}

	.cart-count { top: -6px; right: -6px; }

	@media (max-width: 900px) {
		:root { --category-bar-height: 48px; }
		.search-input { width: 140px; }
	}

	@media (max-width: 480px) {
		:root { --category-bar-height: 44px; }
		.search-input { width: 100px; }
		.logo { font-size: 1.2rem; }
	}

	.site-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 14px 20px;
		background: #8E42E1;
		color: #fff;
		box-shadow: 0 2px 6px rgba(0,0,0,0.12);
		position: sticky;
		top: 0;
		z-index: 20;
	}

	.site-top {
		display: flex;
		align-items: center;
		gap: 18px;
		width: 100%;
		justify-content: space-between;
	}

	.brand {
		display: flex;
		flex-direction: column;
		gap: 10px;
		min-width: 220px;
	}

	.brand-top-row {
		display: flex;
		align-items: center;
		gap: 16px;
	}

	.logo {
		font-weight: 900;
		font-size: 3.2rem;
		color: #fff;
		line-height: 1;
		text-decoration: none;
		cursor: pointer;
		transition: opacity 0.2s;
	}

	.logo:hover { opacity: 0.8; }

	.brand-action-row {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-left: 8px;
	}

	.brand-category-bar {
		display: flex;
		align-items: center;
		overflow: hidden;
		background: linear-gradient(90deg,#d33ad3,#6b3bff);
		padding: 11px 10px;
		width: 95%;
		margin: 0px auto 0 auto;
		border-radius: 0 0 14px 14px;
		box-shadow: none; 
		box-sizing: border-box;
	}

	.brand-category-bar.bottom { padding-left: 24px; padding-right: 24px; }
	.categories { display: flex; gap: 10px; width: 100%; }
	.cat-pill { color: #fff; font-weight: 900; padding: 12px 18px; border-radius: 999px; background: rgba(255,255,255,0.10); font-size: 1.12rem; white-space: nowrap; }

	.cat-pill, .cat-pill:link, .cat-pill:visited, .cat-pill:hover, .cat-pill:focus { text-decoration: none; }
	
	.top-right { display: flex; align-items: center; gap: 12px; flex: 0 0 auto; margin-left: 0; }
	.search-input { background: transparent; border: 2px solid #fff; color: #fff; width: 420px; outline: none; font-size: 1.18rem; padding: 6px 12px; border-radius: 15px; transition: border-color 0.2s; }
	.search-input:focus { border-color: #ff5f8a; }
	.search-input::placeholder { color: rgba(255,255,255,0.65); font-size: 1.08rem; }

	.cart-wrapper { position: relative; margin-right: 12px; }
	.cart-btn { width: 64px; height: 64px; border-radius: 100%; background: linear-gradient(135deg,#7B4BFF,#4BB1FF); border: 2px solid rgba(255,255,255,0.12); color: white; display: inline-flex; align-items: center; justify-content: center; position: relative; cursor: pointer; }
	.cart-count { position: absolute; right: -6px; top: -6px; background: #ff3b30; color: white; font-weight: 700; font-size: 0.7rem; padding: 2px 6px; border-radius: 999px; border: 2px solid #0f0d28; }

	.menu-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; color: #EDE7FF; text-decoration: none; border-radius: 8px; transition: background 0.12s ease; }
	.menu-item svg { opacity: 0.9; }
	.menu-item:hover { background: rgba(255,255,255,0.03); }

	.search-btn { background: transparent; border: none; padding: 6px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
	.mobile-menu-btn { display: none; background: transparent; border: none; color: white; cursor: pointer; padding: 8px; }

	.mobile-header-bar {
		display: flex;
		align-items: center;
		justify-content: space-between;
	}
	.mobile-quick-actions,
	.mobile-search-bar {
		display: none;
	}

	.mobile-icon-btn {
		position: relative;
		width: 40px;
		height: 40px;
		border-radius: 50%;
		border: 1px solid rgba(255, 255, 255, 0.25);
		background: rgba(255, 255, 255, 0.14);
		color: #fff;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		cursor: pointer;
	}
	.mobile-badge {
		position: absolute;
		top: -4px;
		right: -4px;
		background: #ef4444;
		color: #fff;
		font-size: 0.68rem;
		font-weight: 800;
		padding: 1px 5px;
		border-radius: 999px;
	}

	@media (max-width: 768px) {
		.site-header { padding: 12px 16px; }
		.site-top { flex-direction: column; gap: 10px; align-items: stretch; }
		.brand { min-width: 100%; width: 100%; }
		.brand-top-row { width: 100%; flex-direction: column; gap: 10px; align-items: stretch; }
		.mobile-header-bar { width: 100%; display: flex; align-items: center; justify-content: space-between; }
		.mobile-quick-actions { display: inline-flex; align-items: center; gap: 8px; }
		.mobile-menu-btn { display: inline-flex; align-items: center; justify-content: center; position: static; padding: 6px; }
		.logo { font-size: 1.75rem; letter-spacing: 0.03em; }
		.brand-action-row { width: 100%; gap: 8px; flex-wrap: wrap; margin-left: 0; }
		.mobile-search-bar {
			display: flex;
			align-items: center;
			width: 100%;
			background: rgba(255, 255, 255, 0.16);
			border: 1.5px solid rgba(255, 255, 255, 0.45);
			border-radius: 999px;
			padding: 4px 6px 4px 14px;
			box-sizing: border-box;
		}
		.mobile-search-input {
			flex: 1;
			background: transparent;
			border: none;
			color: #fff;
			font-size: 0.95rem;
			outline: none;
		}
		.mobile-search-input::placeholder { color: rgba(255, 255, 255, 0.78); }
		.mobile-search-submit {
			width: 34px;
			height: 34px;
			border-radius: 50%;
			border: none;
			background: rgba(15, 23, 42, 0.35);
			display: inline-flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
		}
		.desktop-only { display: none !important; }
		.mobile-only { display: flex !important; }
	}

	@media (max-width: 480px) {
		.site-header { padding: 10px 12px; }
		.logo { font-size: 1.45rem; }
	}

	.mobile-drawer-backdrop {
		position: fixed;
		inset: 0;
		background: rgba(15, 23, 42, 0.55);
		z-index: 1200;
	}
	.mobile-drawer {
		position: fixed;
		top: 0;
		left: 0;
		bottom: 0;
		width: 310px;
		max-width: 86vw;
		background: #0f172a;
		color: #f8fafc;
		z-index: 1250;
		display: flex;
		flex-direction: column;
		padding: 18px 16px;
		gap: 16px;
		overflow-y: auto;
		box-shadow: 8px 0 30px rgba(0, 0, 0, 0.45);
		box-sizing: border-box;
	}
	.mobile-drawer-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding-bottom: 12px;
		border-bottom: 1px solid rgba(255, 255, 255, 0.1);
	}
	.mobile-drawer-brand { font-size: 1.35rem; font-weight: 900; color: #fff; }
	.mobile-drawer-close { background: none; border: none; color: #cbd5e1; font-size: 1.3rem; cursor: pointer; }
	.mobile-drawer-user {
		background: rgba(255, 255, 255, 0.05);
		border: 1px solid rgba(255, 255, 255, 0.08);
		border-radius: 12px;
		padding: 12px;
	}
	.mobile-user-greeting { display: flex; flex-direction: column; gap: 2px; margin-bottom: 10px; }
	.mobile-user-greeting strong { font-size: 0.95rem; color: #fff; }
	.mobile-user-greeting span { font-size: 0.8rem; color: #94a3b8; }
	.mobile-account-links { display: flex; flex-direction: column; gap: 6px; }
	.mobile-account-links button,
	.mobile-nav-item {
		width: 100%;
		text-align: left;
		background: rgba(255, 255, 255, 0.04);
		border: none;
		color: #e2e8f0;
		padding: 10px 12px;
		border-radius: 8px;
		font-size: 0.92rem;
		font-weight: 600;
		cursor: pointer;
	}
	.mobile-account-links button:hover,
	.mobile-nav-item:hover { background: rgba(142, 66, 225, 0.28); color: #fff; }
	.mobile-auth-buttons { display: flex; gap: 8px; }
	.btn-m-login, .btn-m-register {
		flex: 1;
		padding: 10px;
		border-radius: 8px;
		font-weight: 700;
		cursor: pointer;
		border: none;
	}
	.btn-m-login { background: #8E42E1; color: #fff; }
	.btn-m-register { background: rgba(255, 255, 255, 0.12); color: #fff; }
	.mobile-drawer-section { display: flex; flex-direction: column; gap: 8px; }
	.mobile-section-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; font-weight: 700; }
	.mobile-cat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; }
	.mobile-cat-chip {
		background: rgba(30, 41, 59, 0.9);
		border: 1px solid rgba(255, 255, 255, 0.08);
		color: #e2e8f0;
		padding: 9px 8px;
		border-radius: 8px;
		font-size: 0.82rem;
		font-weight: 600;
		cursor: pointer;
		text-align: center;
	}
	.mobile-cat-chip:hover { border-color: #8E42E1; color: #fff; }
	.mobile-logout-btn {
		margin-top: auto;
		width: 100%;
		padding: 11px;
		border-radius: 8px;
		border: 1px solid rgba(239, 68, 68, 0.35);
		background: rgba(239, 68, 68, 0.15);
		color: #fca5a5;
		font-weight: 700;
		cursor: pointer;
	}

	.search-wrap { position: relative; }
	.search-input { padding: 8px 44px 8px 12px; }

	.search-btn {
		position: absolute;
		right: 8px;
		top: 50%;
		transform: translateY(-50%);
		width: 36px;
		height: 36px;
		border-radius: 999px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		background: rgba(255,255,255,0.08);
		border: none;
		color: #fff;
		cursor: pointer;
		padding: 0;
	}

	.wishlist-wrapper { position: relative; margin: 0 8px; display: inline-flex; align-items: center; }
	.wishlist-btn {
		width: 64px;
		height: 64px;
		border-radius: 999px;
		background: linear-gradient(135deg,#FF77A9,#FFB86A);
		border: 2px solid rgba(255,255,255,0.12);
		color: white;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		position: relative;
		cursor: pointer;
	}
	.wishlist-count {
		position: absolute;
		right: -6px;
		top: -6px;
		background: #ff3b30;
		color: white;
		font-weight: 700;
		font-size: 0.65rem;
		padding: 2px 6px;
		border-radius: 999px;
		border: 2px solid rgba(15,13,40,0.9);
	}

	.wishlist-menu {
		position: absolute;
		right: 0;
		top: calc(100% + 8px);
		min-width: 200px;
		background: #0f0d28;
		border-radius: 10px;
		box-shadow: 0 10px 30px rgba(0,0,0,0.45);
		padding: 8px;
		z-index: 30;
	}

	.user-menu-area {
		position: relative;
		display: inline-flex;
		align-items: center;
		margin-left: 8px;
	}

	.btn-icon-user {
		width: 64px;
		height: 64px;
		border-radius: 50%;
		background: linear-gradient(135deg, #FF6B9D, #C44569);
		border: 2px solid rgba(255, 255, 255, 0.12);
		color: white;
		cursor: pointer;
		padding: 0;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		position: relative;
		transition: transform 0.2s, box-shadow 0.2s;
	}

	.btn-icon-user:hover {
		transform: scale(1.05);
		box-shadow: 0 8px 20px rgba(255, 107, 157, 0.3);
	}

	.dropdown-wrapper {
		position: absolute;
		top: 100%;
		right: 0;
		margin-top: 8px;
		z-index: 9999;
	}
</style>