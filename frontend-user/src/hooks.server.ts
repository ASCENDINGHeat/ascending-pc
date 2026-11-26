import type { HandleFetch } from '@sveltejs/kit';
import { PUBLIC_API_URL } from '$env/static/public';
import { env } from '$env/dynamic/private';

/**
 * Rewrites SSR fetch calls targeting PUBLIC_API_URL to INTERNAL_API_URL when running
 * inside Docker / private container networks so universal +page.ts load functions
 * reach the backend container directly without hair-pinning through the host browser URL.
 */
export const handleFetch: HandleFetch = async ({ request, fetch }) => {
	const internalApiUrl = env.INTERNAL_API_URL?.trim();
	const publicApiUrl = PUBLIC_API_URL?.trim();

	if (internalApiUrl && publicApiUrl) {
		const normalizedPublic = publicApiUrl.replace(/\/+$/, '');
		const normalizedInternal = internalApiUrl.replace(/\/+$/, '');

		if (request.url === normalizedPublic || request.url.startsWith(`${normalizedPublic}/`)) {
			const rewrittenUrl = `${normalizedInternal}${request.url.slice(normalizedPublic.length)}`;
			request = new Request(rewrittenUrl, request);
		}
	}

	return fetch(request);
};
