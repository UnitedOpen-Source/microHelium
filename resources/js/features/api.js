export class FeatureError extends Error {
    constructor(message, status = 0, errors = {}) { super(message); this.status = status; this.errors = errors; }
}
export function localUrl(value) {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || /[\\\r\n]/.test(value)) return null;
    return value;
}
export async function request(path, { method = 'GET', body, signal, key } = {}) {
    if (!localUrl(path)) throw new FeatureError('Endereço inválido. Atualize a página.');
    const controller = new AbortController();
    const abort = () => controller.abort();
    signal?.addEventListener('abort', abort, { once: true });
    if (signal?.aborted) controller.abort();
    const timeout = setTimeout(abort, 20000);
    try {
        const response = await fetch(path, {
            method, credentials: 'same-origin', signal: controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
                ...(method !== 'GET' ? { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } : {}),
                ...(key ? { 'Idempotency-Key': key } : {}),
            }, ...(body ? { body: JSON.stringify(body) } : {}),
        });
        const json = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null;
        if (!response.ok || !json || response.redirected) {
            const messages = {
                401: 'Sua sessão terminou. Entre novamente para continuar.',
                419: 'Sua sessão expirou. Atualize a página antes de tentar novamente.',
                403: 'Seu perfil não tem acesso a estes dados. Consulte a organização.',
                404: 'Este recurso ainda não está disponível ou não foi encontrado.',
                409: 'Os dados mudaram ou esta operação já está em andamento. Atualize antes de continuar.',
                422: 'Revise os campos indicados e tente novamente.',
                429: 'Muitas solicitações. Aguarde um momento antes de tentar novamente.',
                503: 'Este recurso está temporariamente indisponível. Tente novamente mais tarde.',
            };
            throw new FeatureError(messages[response.redirected ? 401 : response.status] || 'Não foi possível concluir a solicitação. Tente novamente.', response.redirected ? 401 : response.status, json?.errors || {});
        }
        if (!json.data || typeof json.data !== 'object') throw new FeatureError('A resposta está incompleta. Tente atualizar a página.');
        return json.data;
    } catch (error) {
        if (error instanceof FeatureError) throw error;
        if (signal?.aborted) throw error;
        throw new FeatureError(error.name === 'AbortError' ? 'O servidor demorou para responder. Verifique o estado antes de tentar novamente.' : 'Não foi possível conectar. Verifique sua conexão e tente novamente.');
    } finally { clearTimeout(timeout); signal?.removeEventListener('abort', abort); }
}
export const dateTime = value => value && Number.isFinite(new Date(value).getTime()) ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : 'Ainda não registrada';
export const percent = value => value == null ? 'Sem dados' : `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(value)}%`;
