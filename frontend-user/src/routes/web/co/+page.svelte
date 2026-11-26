<script lang="ts">
    import { goto } from '$app/navigation';
    import { onMount, onDestroy } from 'svelte';
    import { cart, selectedItemIds, removeFromCart, mapApiCartItem } from '$lib/stores/cart'; 
    import { user, normalizeUser } from '$lib/stores/auth';
    import { PUBLIC_API_URL } from '$env/static/public';

    $: cartItems = $cart.filter(item => $selectedItemIds.includes(String(item.id)));

    let shippingAddress = '';
    let recipientPhone = '';
    let addressInitialized = false;

    $: if ($user && !addressInitialized) {
        shippingAddress = $user.address || '';
        recipientPhone = $user.phone_number || $user.phone || '';
        addressInitialized = true;
    }

    $: buyerInfo = {
        name: $user?.name || "Memuat data user...",
        email: $user?.email || "Memuat email..."
    };

    let isLoading = false;
    let isHydrating = true;
    let paymentUrl = "";
    let errorMessage = "";
    let checkedOutIds: string[] = [];

    $: subTotal = cartItems.reduce((sum, item) => sum + (Number(item.price) * item.quantity), 0);
    const adminFee = 4000; 
    $: grandTotal = subTotal + adminFee;

    function formatRupiah(number: number) {
        return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(number);
    }
    
    async function fetchUserProfile(token: string) {
        try {
            const res = await fetch(`${PUBLIC_API_URL}/user`, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json'
                }
            });

            if (res.ok) {
                const raw = await res.json();
                const normalized = normalizeUser(raw);
                if (normalized) {
                    user.set(normalized);
                    if (!shippingAddress) shippingAddress = normalized.address || '';
                    if (!recipientPhone) recipientPhone = normalized.phone_number || '';
                }
            }
        } catch (e) {
            console.error("Gagal mengambil profil user:", e);
        }
    }

    async function hydrateCartIfNeeded(token: string) {
        if ($cart.length > 0) return;
        try {
            const res = await fetch(`${PUBLIC_API_URL}/cart`, {
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json'
                }
            });
            if (res.ok) {
                const data = await res.json();
                cart.set((data.data || []).map(mapApiCartItem));
            }
        } catch (e) {
            console.error("Gagal menyinkronkan keranjang:", e);
        }
    }

    async function handleCheckout() { 
        if (cartItems.length === 0) {
            alert("Error: Tidak ada item yang dipilih.");
            goto('/web/cart');
            return;
        }

        if (!shippingAddress.trim()) {
            errorMessage = "Mohon lengkapi alamat pengiriman terlebih dahulu.";
            return;
        }

        isLoading = true; 
        errorMessage = "";

        try {
            const token = localStorage.getItem('auth_token');
            if (!token) {
                alert("Sesi habis, silakan login kembali.");
                goto('/login');
                return;
            }

            const selected_ids_payload = cartItems
                .map(item => Number(item.id))
                .filter(id => Number.isInteger(id) && id > 0);

            if (selected_ids_payload.length === 0) {
                errorMessage = "Data keranjang tidak sinkron. Silakan muat ulang halaman keranjang.";
                return;
            }

            const response = await fetch(`${PUBLIC_API_URL}/checkout`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    selected_cart_ids: selected_ids_payload,
                    shipping_address: shippingAddress.trim(),
                    recipient_phone: recipientPhone.trim() || null
                })
            });

            const data = await response.json();

            if (response.ok) {
                checkedOutIds = selected_ids_payload.map(String);
                paymentUrl = data.redirect_url;
                if ($user && (!$user.address || !$user.phone_number)) {
                    user.set({
                        ...$user,
                        address: $user.address || shippingAddress.trim(),
                        phone_number: $user.phone_number || recipientPhone.trim() || undefined
                    });
                }
            } else {
                const details = data.errors ? ' ' + Object.values(data.errors).flat().join(' ') : '';
                errorMessage = (data.message || "Checkout gagal.") + details;
            }

        } catch (error) {
            console.error("Checkout Error:", error);
            errorMessage = "Gagal menghubungi server.";
        } finally {
            isLoading = false;
        }
    }

    let prevBodyOverflow = '';

    onMount(async () => {
        prevBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const token = localStorage.getItem('auth_token');
        if (!token) {
            alert("Anda belum login.");
            goto('/login');
            return;
        }

        if ($selectedItemIds.length === 0) {
            alert("Silakan pilih barang di keranjang terlebih dahulu.");
            goto('/web/cart');
            return;
        }

        await Promise.all([
            !$user ? fetchUserProfile(token) : Promise.resolve(),
            hydrateCartIfNeeded(token)
        ]);

        isHydrating = false;

        if ($cart.filter(item => $selectedItemIds.includes(String(item.id))).length === 0) {
            alert("Barang yang dipilih sudah tidak ada di keranjang.");
            goto('/web/cart');
        }
    });

    onDestroy(() => {
        if (typeof document !== 'undefined') document.body.style.overflow = prevBodyOverflow || '';
        if (checkedOutIds.length > 0) {
            checkedOutIds.forEach(id => removeFromCart(id));
        }
    });
</script>

<div class="fixed inset-0 bg-black/50 backdrop-blur-sm flex justify-center items-center z-[9999] p-5">
    <div class="w-full max-w-[520px] max-h-[88vh] flex">
        <div class="bg-white w-full rounded-2xl flex flex-col overflow-hidden shadow-2xl">
            
            <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-white">
                <h1 class="m-0 text-lg text-gray-800 font-bold">Konfirmasi Pesanan</h1>
                <button class="bg-none border-none text-2xl cursor-pointer text-gray-400 hover:text-gray-600" on:click={() => goto('/web/cart')}>&times;</button>
            </div>

            <div class="flex-1 overflow-y-auto p-5 bg-gray-50">
                {#if errorMessage}
                    <div class="bg-red-100 text-red-600 p-3 rounded-lg mb-4 text-sm border border-red-200">{errorMessage}</div>
                {/if}

                {#if isHydrating}
                    <div class="p-8 text-center text-gray-500 text-sm">Memuat rincian pesanan...</div>
                {:else}
                    <div class="bg-white p-4 rounded-xl mb-4 border border-gray-100 shadow-sm">
                        <h3 class="m-0 mb-3 text-sm text-slate-500 font-bold uppercase tracking-wide">Informasi Pengiriman</h3>
                        <p class="font-semibold text-gray-800 m-0 mb-0.5">{buyerInfo.name} <span class="bg-green-100 text-green-700 text-xs px-1.5 py-0.5 rounded ml-1">Buyer</span></p>
                        <p class="text-sm text-gray-500 m-0 mb-3">{buyerInfo.email}</p>

                        <div class="flex flex-col gap-2.5">
                            <div>
                                <label for="co-phone" class="block text-xs font-semibold text-slate-600 mb-1">Nomor Telepon Penerima</label>
                                <input
                                    id="co-phone"
                                    type="tel"
                                    bind:value={recipientPhone}
                                    placeholder="Contoh: 081234567890"
                                    disabled={Boolean(paymentUrl)}
                                    class="w-full p-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:border-violet-500 disabled:bg-gray-100"
                                />
                            </div>
                            <div>
                                <label for="co-address" class="block text-xs font-semibold text-slate-600 mb-1">Alamat Lengkap Pengiriman *</label>
                                <textarea
                                    id="co-address"
                                    rows="2"
                                    bind:value={shippingAddress}
                                    placeholder="Nama jalan, nomor rumah, kecamatan, kota, kode pos..."
                                    required
                                    disabled={Boolean(paymentUrl)}
                                    class="w-full p-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:border-violet-500 disabled:bg-gray-100"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-4 rounded-xl mb-4 border border-gray-100 shadow-sm">
                        <h3 class="m-0 mb-3 text-sm text-slate-500 font-bold uppercase tracking-wide">Daftar Produk ({cartItems.length} Item)</h3>
                        <div class="flex flex-col">
                            {#each cartItems as item}
                                <div class="flex gap-3 mb-3 pb-3 border-b border-gray-50 last:mb-0 last:pb-0 last:border-none">
                                    <img src={item.image || '/images/placeholder.png'} alt={item.name} class="w-[50px] h-[50px] rounded-lg object-cover bg-gray-100 border border-gray-100" />
                                    <div class="flex-1 flex flex-col justify-center">
                                        <span class="text-sm font-semibold text-gray-800 overflow-hidden text-ellipsis whitespace-nowrap max-w-[200px]">{item.name}</span>
                                        <span class="text-xs text-gray-400 mt-1">{item.quantity} x {formatRupiah(Number(item.price))}</span>
                                    </div>
                                    <div class="font-semibold text-gray-800 text-sm self-center">
                                        {formatRupiah(Number(item.price) * item.quantity)}
                                    </div>
                                </div>
                            {/each}
                        </div>
                    </div>

                    <div class="mt-2.5 px-1">
                        <div class="flex justify-between mb-2 text-sm text-gray-500">
                            <span>Subtotal Produk</span>
                            <span>{formatRupiah(subTotal)}</span>
                        </div>
                        <div class="flex justify-between mb-2 text-sm text-gray-500">
                            <span>Biaya Layanan (Admin)</span>
                            <span>{formatRupiah(adminFee)}</span>
                        </div>
                        <div class="flex justify-between mt-3 pt-3 border-t border-dashed border-gray-300 font-bold text-gray-800 text-lg">
                            <span>Total Tagihan</span>
                            <span class="text-violet-600">{formatRupiah(grandTotal)}</span>
                        </div>
                    </div>
                {/if}
            </div>

            <div class="p-5 bg-white border-t border-gray-100 shadow-[0_-4px_20px_rgba(0,0,0,0.05)]">
                {#if paymentUrl}
                    <div class="text-center">
                        <p class="m-0 mb-4 text-green-600 font-bold text-lg">✅ Pesanan Dibuat!</p>
                        <a href={paymentUrl} target="_blank" rel="noopener noreferrer" class="block w-full p-4 bg-green-600 text-white text-center no-underline rounded-xl font-bold mb-2.5 hover:bg-green-700">
                            Lanjut ke Pembayaran &rarr;
                        </a>
                        <button class="w-full p-2.5 bg-transparent border border-gray-200 text-gray-500 rounded-xl cursor-pointer font-semibold hover:bg-gray-50" on:click={() => goto('/web/profile')}>Lihat Riwayat Pesanan</button>
                    </div>
                {:else}
                    <button class="w-full p-4 bg-violet-600 text-white border-none rounded-xl text-base font-bold cursor-pointer flex justify-center items-center gap-2.5 transition-colors hover:bg-violet-700 disabled:bg-gray-300 disabled:cursor-not-allowed" on:click={handleCheckout} disabled={isLoading || isHydrating}>
                        {#if isLoading}
                            <span class="w-[18px] h-[18px] border-[3px] border-white/30 border-t-white rounded-full animate-spin"></span> Memproses...
                        {:else}
                            Bayar Sekarang ({formatRupiah(grandTotal)})
                        {/if}
                    </button>
                {/if}
            </div>

        </div>
    </div>
</div>