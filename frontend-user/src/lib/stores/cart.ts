import { writable } from 'svelte/store';
import { browser } from '$app/environment';

export type CartItem = {
	id: string;       // ID Cart (Primary Key)
	product_id: number;
	name: string;
	price: number;
	image: string;
	quantity: number;
	stock?: number;
};

let initialSelectedIds: string[] = [];
if (browser) {
	try {
		const raw = sessionStorage.getItem('selected_cart_ids');
		if (raw && raw !== 'undefined' && raw !== 'null') {
			const parsed = JSON.parse(raw);
			if (Array.isArray(parsed)) {
				initialSelectedIds = parsed.map(String);
			}
		}
	} catch {
		initialSelectedIds = [];
	}
}

export const cart = writable<CartItem[]>([]);
export const selectedItemIds = writable<string[]>(initialSelectedIds);

if (browser) {
	selectedItemIds.subscribe(ids => {
		try {
			if (ids && ids.length > 0) {
				sessionStorage.setItem('selected_cart_ids', JSON.stringify(ids));
			} else {
				sessionStorage.removeItem('selected_cart_ids');
			}
		} catch {
			// Ignore storage quota errors
		}
	});
}

export function mapApiCartItem(item: any): CartItem {
	return {
		id: String(item.id),
		product_id: Number(item.product_id ?? item.product?.id ?? 0),
		name: item.product?.title || item.product?.name || 'Unknown Product',
		price: Number(item.product?.price ?? 0),
		image: item.product?.image_url || item.product?.image || '/images/placeholder.png',
		quantity: Number(item.quantity ?? 1),
		stock: item.product?.stock_quantity ?? item.product?.stock
	};
}

export function addToCart(item: CartItem) {
	cart.update(items => {
		const existing = items.find(
			i =>
				String(i.id) === String(item.id) ||
				(i.product_id > 0 && item.product_id > 0 && Number(i.product_id) === Number(item.product_id))
		);
		if (existing) {
			return items.map(i =>
				String(i.id) === String(existing.id)
					? { ...i, id: String(item.id), quantity: i.quantity + item.quantity }
					: i
			);
		}
		return [...items, { ...item, id: String(item.id) }];
	});
}

// Helper Hapus (Pastikan konversi string agar aman)
export function removeFromCart(id: string | number) {
	cart.update(items => items.filter(i => String(i.id) !== String(id)));
	selectedItemIds.update(ids => ids.filter(itemId => String(itemId) !== String(id)));
}

export function clearCart() {
	cart.set([]);
	selectedItemIds.set([]);
}