import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const shared = resolve(here, '../binder-shared');

const TEMPLATE_FILES: Record<string, string> = {
  binder_outer: 'binder-outer-spec.json',
  binder_inner: 'binder-inner-spec.json',
};

/**
 * Dev/preview only. The render service serves the same three things in
 * production (binder-render-service/src/server.ts): the spec files, the sample
 * designs, and the print page under /print-render/<template>.
 */
function binderDevRoutes(): Plugin {
  const handler = (req: { url?: string }, res: any, next: () => void) => {
    const url = (req.url ?? '').split('?')[0] ?? '';

    const spec = /^\/spec\/(binder_[a-z]+)\.json$/.exec(url);
    if (spec) {
      const file = TEMPLATE_FILES[spec[1]!];
      if (!file) return next();
      res.setHeader('Content-Type', 'application/json');
      res.end(readFileSync(join(shared, 'templates', file)));
      return;
    }

    const sample = /^\/samples\/(assets\/)?([A-Za-z0-9._-]+)$/.exec(url);
    if (sample) {
      const path = join(shared, 'samples', sample[1] ?? '', sample[2]!);
      if (!existsSync(path)) return next();
      res.setHeader('Content-Type', path.endsWith('.json') ? 'application/json' : path.endsWith('.png') ? 'image/png' : 'image/jpeg');
      res.end(readFileSync(path));
      return;
    }

    if (url.startsWith('/print-render/')) {
      req.url = '/print.html' + (req.url ?? '').slice((req.url ?? '').indexOf('?') >= 0 ? (req.url ?? '').indexOf('?') : (req.url ?? '').length);
    }
    next();
  };

  return {
    name: 'binder-dev-routes',
    configureServer: (s) => void s.middlewares.use(handler),
    configurePreviewServer: (s) => void s.middlewares.use(handler),
  };
}

/**
 * Two builds, because they are deployed to different places:
 *   --mode print   /print-render/<template>, served by the render service from
 *                  the site root, so asset URLs are absolute (base '/');
 *   --mode editor  the customer editor, dropped into the WordPress plugin under
 *                  an arbitrary path, so every URL must be relative (base './').
 */
export default defineConfig(({ mode }) => {
  const print = mode === 'print';

  return {
    base: print ? '/' : './',
    plugins: [react(), binderDevRoutes()],
    resolve: { alias: { '@binder/shared': resolve(shared, 'src/index.ts') } },
    server: { port: 5180, fs: { allow: [here, shared] } },
    preview: { port: 5180 },
    build: {
      outDir: print ? 'dist' : 'dist-editor',
      emptyOutDir: true,
      rollupOptions: { input: print ? resolve(here, 'print.html') : resolve(here, 'index.html') },
    },
  };
});
