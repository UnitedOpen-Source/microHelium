import { registerHooks } from 'node:module';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
registerHooks({
    load(url, context, nextLoad) {
        if (!url.endsWith('.vue')) return nextLoad(url, context);
        const { descriptor } = parse(readFileSync(new URL(url), 'utf8'), { filename: url });
        return { format: 'module', source: compileScript(descriptor, { id: url, inlineTemplate: true }).content, shortCircuit: true };
    },
});
