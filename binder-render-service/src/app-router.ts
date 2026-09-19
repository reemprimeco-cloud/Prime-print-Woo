import express, { type Router } from 'express';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { Config } from './config.ts';

const TEMPLATE_FILES: Record<string, string> = {
  binder_outer: 'binder-outer-spec.json',
  binder_inner: 'binder-inner-spec.json',
};

/**
 * What the print page needs from its own origin (§5.1): the built editor
 * (print.html + fonts + JS), the spec files, and — only when enabled, for
 * testing — the sample designs. Nothing here is customer data.
 */
export function createAppRouter(cfg: Config): Router {
  const r = express.Router();

  r.get('/spec/:template.json', (req, res) => {
    const file = TEMPLATE_FILES[req.params.template ?? ''];
    if (!file) return res.status(404).json({ error: 'unknown_template' });
    res.type('application/json').send(readFileSync(join(cfg.templatesDir, file)));
  });

  if (cfg.enableSamples) {
    r.use('/samples', express.static(cfg.samplesDir, { fallthrough: false }));
  }

  // The print route is one static page; the template comes from the path.
  r.get('/print-render/:template', (_req, res) => {
    res.sendFile(join(cfg.editorDist, 'print.html'));
  });

  if (existsSync(cfg.editorDist)) {
    // Only the assets the print page needs; the editor's own index.html stays private.
    r.use('/assets', express.static(join(cfg.editorDist, 'assets'), { immutable: true, maxAge: '1y' }));
    r.use('/fonts', express.static(join(cfg.editorDist, 'fonts'), { immutable: true, maxAge: '1y' }));
  }

  return r;
}
