<script lang="ts">
    // --- IMPORT CSS GLOBAL (WAJIB UNTUK TAILWIND) ---
    import './layout.css'; 

	import { page } from '$app/stores';
	import Header from '$lib/components/Header.svelte';
	import Footer from '$lib/components/Footer.svelte';
    import { onMount } from 'svelte';
    import { isLoggedIn, user, normalizeUser } from '$lib/stores/auth';
    import { cart, mapApiCartItem } from '$lib/stores/cart';
    import { get } from 'svelte/store';
    import { PUBLIC_API_URL, PUBLIC_STORAGE_URL } from '$env/static/public';

    $: showHeaderFooter = $page?.url?.pathname && $page.url.pathname !== '/';

    async function initGlobalData() {
        const token = localStorage.getItem('auth_token');
        if (!token) return;

        await Promise.allSettled([
            fetchUser(token),
            fetchCart(token)
        ]);
    }

	async function fetchUser(token: string) {
        if (get(user)) return;

        try {
            const res = await fetch(`${PUBLIC_API_URL}/user`, {
                headers: { 
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json' 
                }
            });

            if (res.ok) {
                const result = await res.json();
                const normalized = normalizeUser(result);
                if (normalized) {
                    if (normalized.avatar && !normalized.avatar.startsWith('http')) {
                        const baseUrl = PUBLIC_STORAGE_URL
                            ? (PUBLIC_STORAGE_URL.endsWith('/') ? PUBLIC_STORAGE_URL : `${PUBLIC_STORAGE_URL}/`)
                            : 'http://127.0.0.1:8000/';
                        normalized.avatar = `${baseUrl}storage/${normalized.avatar}`;
                    }
                    user.set(normalized);
                }
            } else if (res.status === 401) {
                localStorage.removeItem('auth_token');
                user.set(null);
                isLoggedIn.set(false);
            }
        } catch (e) {
            console.error("Gagal load user:", e);
        }
    }

    onMount(async () => {
        // Cek jika user login, langsung tarik data keranjang untuk inisialisasi
        if ($isLoggedIn) {
            initGlobalData();
        }
    });

    async function fetchCart(token: string) {
        try {
            const res = await fetch(`${PUBLIC_API_URL}/cart`, {
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json'
                }
            });

            if (res.ok) {
                const data = await res.json();
                const mappedData = (data.data || []).map(mapApiCartItem);
                cart.set(mappedData);
            }
        } catch (e) {
            console.error("Gagal init keranjang:", e);
        }
    }
</script>

{#if showHeaderFooter}
    <Header />
{/if}

<main class="main-content">
	<slot />
</main>

{#if showHeaderFooter}
    <Footer />
{/if}

<style>
    /* Global Styles can be removed here if moved to layout.css or handled by Tailwind */
	:global(html), :global(body) {
		margin: 0;
		padding: 0;
		width: 100%;
		min-height: 100vh;
		overflow-y: auto !important;
		overflow-x: hidden;
		font-family: 'Segoe UI', sans-serif;
        /* Background color is now handled in layout.css, but okay to keep as fallback */
		background-color: #ffffff;
		scroll-behavior: smooth;
		-webkit-font-smoothing: antialiased;
		-moz-osx-font-smoothing: grayscale;
	}

	.main-content {
		flex: 1;
		min-height: 60vh;
		width: 100%;
	}
</style>