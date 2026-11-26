import { writable } from 'svelte/store';
import { browser } from '$app/environment';
import { clearCart } from './cart';
import { wishlist } from './wishlist';

export interface User {
	id: number;
	name: string;
	email: string;
	avatar?: string;
	profile_picture?: string;
	phone_number?: string;
	phone?: string;
	address?: string;
	is_vendor?: boolean;
}

export function normalizeUser(raw: any): User | null {
	if (!raw || typeof raw !== 'object') return null;
	const data = raw.data && typeof raw.data === 'object' && !Array.isArray(raw.data) ? raw.data : raw;
	if (!data.email && !data.id) return null;

	const phoneVal = data.phone_number ?? data.phone ?? undefined;
	return {
		id: Number(data.id ?? 0),
		name: String(data.name ?? ''),
		email: String(data.email ?? ''),
		avatar: data.avatar ?? data.profile_picture ?? undefined,
		profile_picture: data.profile_picture ?? data.avatar ?? undefined,
		phone_number: phoneVal,
		phone: phoneVal,
		address: data.address ?? undefined,
		is_vendor: Boolean(data.is_vendor || data.vendor)
	};
}

function readStoredUser(): User | null {
	if (!browser) return null;
	const stored = localStorage.getItem('user_data');
	if (!stored || stored === 'undefined' || stored === 'null') return null;
	try {
		return normalizeUser(JSON.parse(stored));
	} catch (e) {
		console.error('Gagal parsing user_data:', e);
		localStorage.removeItem('user_data');
		return null;
	}
}

const initialToken = browser ? localStorage.getItem('auth_token') : null;
const initialUser = readStoredUser();

export const user = writable<User | null>(initialUser);
export const isLoggedIn = writable<boolean>(Boolean(initialToken));

if (browser) {
	user.subscribe(val => {
		try {
			if (val) {
				localStorage.setItem('user_data', JSON.stringify(val));
			} else {
				localStorage.removeItem('user_data');
			}
		} catch {
			// Ignore storage quota errors
		}
	});
}

export function setAuth(token: string, rawUser: any) {
	const normalized = normalizeUser(rawUser);
	if (browser && token) {
		localStorage.setItem('auth_token', token);
	}
	user.set(normalized);
	isLoggedIn.set(Boolean(token));
}

export async function logout(apiUrl?: string) {
	if (browser) {
		const token = localStorage.getItem('auth_token');
		if (token && apiUrl) {
			try {
				await fetch(`${apiUrl}/logout`, {
					method: 'POST',
					headers: {
						Authorization: `Bearer ${token}`,
						Accept: 'application/json'
					}
				});
			} catch {
				// Proceed with local cleanup even if offline
			}
		}
		localStorage.removeItem('auth_token');
		localStorage.removeItem('user_data');
		localStorage.removeItem('userLoggedIn');
		localStorage.removeItem('userName');
		sessionStorage.removeItem('simulasi_pc_selection');
	}
	user.set(null);
	isLoggedIn.set(false);
	clearCart();
	wishlist.set([]);
}

export function clearUser() {
	user.set(null);
}