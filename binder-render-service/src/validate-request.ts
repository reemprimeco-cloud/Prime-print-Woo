import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  isParametricTemplate,
  normalizeParametricParams,
  parametricSpec,
  validateDesign,
  type DesignJSON,
  type Issue,
  type Spec,
  type TemplateKey,
  type ValidationResult,
} from '@binder/shared';
import type { Config } from './config.ts';

const TEMPLATE_FILES: Record<Exclude<TemplateKey, 'sticker' | 'uvdtf'>, string> = {
  binder_outer: 'binder-outer-spec.json',
  binder_inner: 'binder-inner-spec.json',
};

const specCache = new Map<string, Spec>();

export function isTemplate(v: unknown): v is TemplateKey {
  return v === 'binder_outer' || v === 'binder_inner' || v === 'sticker' || v === 'uvdtf';
}

/**
 * The spec for a template. The binder covers come from their spec.json files;
 * a sticker's is derived from the size and shape the design itself declares
 * (the plugin has already checked those against the order).
 */
export function loadSpec(cfg: Config, template: TemplateKey, design?: unknown): Spec | null {
  if (isParametricTemplate(template)) {
    const d = typeof design === 'object' && design !== null ? (design as { sticker?: unknown }).sticker : undefined;
    const params = normalizeParametricParams(template, d);
    return params ? parametricSpec(template, params) : null;
  }
  const key = `${cfg.templatesDir}|${template}`;
  let spec = specCache.get(key);
  if (!spec) {
    spec = JSON.parse(readFileSync(join(cfg.templatesDir, TEMPLATE_FILES[template]), 'utf8')) as Spec;
    specCache.set(key, spec);
  }
  return spec;
}

export interface RenderRequest {
  design_id: number;
  template: TemplateKey;
  design_json: DesignJSON;
  session_token: string;
  callback_url?: string;
  title?: string;
}

const err = (code: string, message: string, element?: number): Issue => ({
  code,
  severity: 'error',
  message,
  ...(element === undefined ? {} : { element }),
});

/**
 * Everything checked before a browser is launched: the request envelope, the
 * design itself (shared §4.5 rules, re-run server-side per §5.3) and where its
 * images and callback are allowed to point.
 *
 * Image URLs come from customers. The renderer is a headless browser inside
 * our network, so a `src` must be on an explicitly allowed host; anything else
 * (an internal address, cloud metadata, a file: URL) is refused up front, and
 * the browser is additionally firewalled to the same list (pipeline/browser.ts).
 */
export function validateRequest(
  cfg: Config,
  body: unknown,
): { ok: true; req: RenderRequest; spec: Spec; result: ValidationResult } | { ok: false; status: number; error: string; errors: Issue[] } {
  const bad = (status: number, error: string, errors: Issue[] = []) => ({ ok: false as const, status, error, errors });

  if (typeof body !== 'object' || body === null) return bad(400, 'invalid_body', [err('request.body', 'JSON object expected.')]);
  const b = body as Record<string, unknown>;

  if (!Number.isInteger(b.design_id) || (b.design_id as number) < 1) return bad(400, 'invalid_design_id', [err('request.design_id', 'design_id must be a positive integer.')]);
  if (!isTemplate(b.template)) return bad(400, 'invalid_template', [err('request.template', 'template must be binder_outer, binder_inner, sticker or uvdtf.')]);
  if (typeof b.session_token !== 'string' || !/^[A-Za-z0-9-]{16,64}$/.test(b.session_token)) {
    return bad(400, 'invalid_session_token', [err('request.session_token', 'session_token must be 16-64 letters, digits or dashes.')]);
  }
  if (b.title !== undefined && (typeof b.title !== 'string' || b.title.length > 120)) return bad(400, 'invalid_title', [err('request.title', 'title must be a string of at most 120 characters.')]);

  let callback: string | undefined;
  if (b.callback_url !== undefined) {
    try {
      const u = new URL(String(b.callback_url));
      if ((u.protocol !== 'https:' && u.protocol !== 'http:') || !cfg.allowedCallbackHosts.includes(u.hostname.toLowerCase())) {
        return bad(400, 'callback_not_allowed', [err('request.callback_url', 'callback_url host is not on the allow-list.')]);
      }
      callback = u.href;
    } catch {
      return bad(400, 'invalid_callback', [err('request.callback_url', 'callback_url is not a valid URL.')]);
    }
  }

  const template = b.template;
  const spec = loadSpec(cfg, template, b.design_json);
  if (!spec) return bad(422, 'validation_failed', [err('shape.sticker', 'A sticker or transfer design must carry a valid size and shape.')]);
  const result = validateDesign(b.design_json, spec);
  if (!result.ok) return { ok: false, status: 422, error: 'validation_failed', errors: result.errors };

  const design = b.design_json as DesignJSON;

  // Where may Chromium fetch images from?
  const srcErrors: Issue[] = [];
  design.elements.forEach((el, i) => {
    if (el.type !== 'image') return;
    if (el.src.startsWith('/')) {
      if (!cfg.enableSamples) srcErrors.push(err('src.relative', 'Image src must be an absolute URL on an allowed host.', i));
      return;
    }
    const host = new URL(el.src).hostname.toLowerCase();
    if (!cfg.allowedImageHosts.includes(host)) {
      srcErrors.push(err('src.host_not_allowed', `Image host "${host}" is not allowed.`, i));
    }
  });
  if (srcErrors.length) return { ok: false, status: 422, error: 'validation_failed', errors: srcErrors };

  return {
    ok: true,
    req: { design_id: b.design_id as number, template, design_json: design, session_token: b.session_token, ...(callback ? { callback_url: callback } : {}), ...(typeof b.title === 'string' ? { title: b.title } : {}) },
    spec,
    result,
  };
}
