// Local visual QA only. No database mutations or simulated successful actions.
// Run Laravel on 8018, then: node tests/Frontend/preview-server.mjs
import http from 'node:http';
import { readFileSync } from 'node:fs';
const fixtures = JSON.parse(readFileSync(new URL('./fixtures/features.json', import.meta.url)));
http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1:8020');
    if (url.pathname.startsWith('/api/frontend/')) {
        const data = fixtures[url.pathname];
        res.writeHead(req.method === 'GET' && data ? 200 : 503, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
        res.end(JSON.stringify(data && req.method === 'GET' ? { data } : { message: 'Prévia visual: alterações indisponíveis.' })); return;
    }
    const upstream = http.request({ hostname: '127.0.0.1', port: 8018, path: req.url, method: req.method, headers: { ...req.headers, host: '127.0.0.1:8020' } }, response => {
        const html = response.headers['content-type']?.includes('text/html');
        if (!html) { res.writeHead(response.statusCode, response.headers); response.pipe(res); return; }
        let body = ''; response.setEncoding('utf8'); response.on('data', chunk => body += chunk);
        response.on('end', () => {
            const headers = { ...response.headers }; delete headers['content-length'];
            res.writeHead(response.statusCode, headers);
            res.end(body.replace('<main', '<div class="feature-message" role="note">Prévia visual — dados fictícios. Alterações indisponíveis.</div><main'));
        });
    });
    upstream.on('error', () => { res.writeHead(503); res.end('Inicie o Laravel local na porta 8018.'); });
    req.pipe(upstream);
}).listen(8020, '127.0.0.1', () => console.log('Prévia local com fixtures: http://127.0.0.1:8020/practice'));
