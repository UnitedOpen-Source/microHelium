import { ref, reactive, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { request } from './api.js';

export function useFeature(endpoint) {
    const data = ref(null), loading = ref(false), error = ref(null), busy = ref(false), notice = ref(''), actionError = ref(null);
    let controller, alive = true, pendingAction;
    const params = new URLSearchParams(location.search);
    const query = reactive(Object.fromEntries([...params].filter(([key]) => ['q', 'page', 'contest_id', 'organization_id', 'status', 'site_id', 'judge_id', 'skill'].includes(key))));
    async function load(filters = query) {
        controller?.abort(); const active = controller = new AbortController();
        loading.value = true; error.value = null;
        try {
            const search = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '' && value != null));
            const result = await request(`${endpoint}${search.size ? `?${search}` : ''}`, { signal: active.signal });
            if (alive && active === controller) data.value = result;
        } catch (failure) { if (!active.signal.aborted && alive && active === controller) { error.value = failure; if ([401, 403].includes(failure.status)) data.value = null; } }
        finally { if (active === controller && alive) loading.value = false; }
    }
    function filter(values) {
        Object.assign(query, values);
        const url = new URL(location.href);
        Object.entries(query).forEach(([key, value]) => value === '' || value == null ? url.searchParams.delete(key) : url.searchParams.set(key, value));
        if (url.href !== location.href) history.pushState(history.state, '', url); return load();
    }
    async function act(path, body, message, method = 'POST', form) {
        if (busy.value || loading.value || error.value) return null;
        busy.value = true; notice.value = ''; actionError.value = null;
        try {
            // Issue #390 (P2): FormData stringifies as {}, so a file upload
            // is signed by its entries -- a retry of the same file reuses the
            // key, a different file gets a new one.
            const signed = typeof FormData !== 'undefined' && body instanceof FormData
                ? [...body.entries()].map(([name, value]) => [name, typeof value === 'string' ? value : [value.name, value.size, value.lastModified]])
                : body;
            const signature = JSON.stringify([path, method, signed]);
            if (pendingAction?.signature !== signature) pendingAction = { signature, key: crypto.randomUUID() };
            const result = await request(path, { method, body, key: pendingAction.key });
            pendingAction = null;
            if (alive) notice.value = message;
            return result;
        } catch (failure) {
            if (alive) {
                actionError.value = failure;
                busy.value = false;
                if (failure.status === 422) pendingAction = null;
                await nextTick();
                const first = Object.keys(failure.errors || {})[0];
                const field = first && form?.elements.namedItem(first);
                (field || document.querySelector('[data-feature-action-error]'))?.focus();
            }
            return null;
        } finally { busy.value = false; }
    }
    function restore() {
        const next = new URLSearchParams(location.search);
        Object.keys(query).forEach(key => delete query[key]);
        for (const key of ['q', 'page', 'contest_id', 'organization_id', 'status', 'site_id', 'judge_id', 'skill']) if (next.has(key)) query[key] = next.get(key);
        load();
    }
    onMounted(() => { load(); window.addEventListener('popstate', restore); });
    onBeforeUnmount(() => { alive = false; controller?.abort(); window.removeEventListener('popstate', restore); });
    return { data, loading, error, busy, notice, actionError, query, load, filter, act };
}
