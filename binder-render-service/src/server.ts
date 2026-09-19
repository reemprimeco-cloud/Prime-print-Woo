import express, { type NextFunction, type Request, type Response } from 'express';
import type { Server } from 'node:http';
import { randomUUID } from 'node:crypto';
import { createReadStream, existsSync } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { validateDesign, validateRules, type DesignJSON, type Issue, type Spec } from '@binder/shared';
import { loadConfig, type Config } from './config.ts';
import { createAppRouter } from './app-router.ts';
import { closeBrowser, renderPreviewPng } from './pipeline/browser.ts';
import { renderDesign, type RenderedPdfs } from './pipeline/index.ts';
import { AssetError, prepareAssets } from './pipeline/assets.ts';
import { Storage } from './storage.ts';
import { Limiter, QueueFullError, RateLimiter, hmacHex, safeEqual } from './util.ts';
import { validateRequest, type RenderRequest } from './validate-request.ts';

interface RenderOutcome {
  design_id: number;
  status: 'ready';
  pdf_rgb_url: string;
  pdf_cmyk_url: string;
  warnings: Issue[];
  timings_ms: RenderedPdfs['timingsMs'];
}

type Job =
  | { id: string; design_id: number; status: 'rendering'; created: number }
  | { id: string; design_id: number; status: 'ready'; created: number; outcome: RenderOutcome }
  | { id: string; design_id: number; status: 'failed'; created: number; error: string; errors: Issue[] };

class RenderFailure extends Error {
  constructor(
    public code: string,
    message: string,
    public issues: Issue[] = [],
    public status = 500,
  ) {
    super(message);
  }
}

/**
 * The render service (§5.2): POST /render turns a design JSON into a print-ready
 * CMYK PDF. Synchronous by default; with a callback_url it answers 202 at once
 * and delivers the result to the WordPress plugin when the render finishes, so
 * no PHP request ever waits on Chromium.
 */
export function createServer(cfg: Config): { app: express.Express; storage: Storage; start: (port?: number) => Promise<Server> } {
  const app = express();
  const storage = new Storage(cfg);
  const limiter = new Limiter(cfg.concurrency, cfg.maxQueue);
  const rate = new RateLimiter(cfg.rateLimitMax, cfg.rateLimitWindowMs);
  const jobs = new Map<string, Job>();

  app.disable('x-powered-by');
  app.use(express.json({ limit: '1mb' }));

  // ---- Public: liveness, signed files, and what the print page loads ----------------------------
  app.get('/healthz', (_req, res) => {
    res.json({
      ok: true,
      ghostscript: existsSync(cfg.gsBin),
      icc_profile: existsSync(cfg.iccProfile),
      print_page: existsSync(`${cfg.editorDist}/print.html`),
      queued: limiter.queued,
    });
  });

  app.get('/files/:token/:file', (req, res) => {
    const kind = String(req.params.file).replace(/\.pdf$/, '');
    if (!storage.verify(String(req.params.token), kind, String(req.query.exp ?? ''), String(req.query.sig ?? ''))) {
      return res.status(403).json({ error: 'invalid_or_expired_link' });
    }
    const path = storage.path(String(req.params.token), kind as 'rgb' | 'cmyk');
    if (!existsSync(path)) return res.status(404).json({ error: 'not_found' });
    res.setHeader('Content-Type', 'application/pdf');
    res.setHeader('Content-Disposition', `attachment; filename="binder-${kind}.pdf"`);
    res.setHeader('Cache-Control', 'private, no-store');
    createReadStream(path).pipe(res);
  });

  app.use(createAppRouter(cfg));

  // ---- Authenticated ---------------------------------------------------------------------------------
  const requireSecret = (req: Request, res: Response, next: NextFunction) => {
    const sent = String(req.header('X-Binder-Secret') ?? '');
    if (!cfg.secret || !safeEqual(sent, cfg.secret)) {
      return res.status(401).json({ error: 'unauthorized' });
    }
    next();
  };

  const baseUrlFor = (req: Request): string => cfg.publicBaseUrl || `${req.protocol}://${req.get('host')}`;

  /** The actual work: render, re-check with real text sizes, store, and build the answer. */
  async function process(rq: RenderRequest, spec: Spec, base: string, selfBase: string): Promise<RenderOutcome> {
    // 1. Fetch and inspect every image; the design is re-validated on what the files really are.
    let assets;
    try {
      assets = await prepareAssets(cfg, rq.design_json);
    } catch (e) {
      if (e instanceof AssetError) throw new RenderFailure(e.code, e.message, e.issues, 422);
      throw e;
    }

    try {
      const recheck = validateDesign(assets.design, spec);
      if (!recheck.ok) throw new RenderFailure('validation_failed', 'Design failed validation on the real image sizes.', recheck.errors, 422);

      // 2. Render.
      const pdfs = await limiter.run(() =>
        renderDesign(cfg, selfBase, spec, assets.design, rq.title ?? `Binder design ${rq.design_id}`, assets),
      );

      // 3. Final, authoritative check (§5.3): the browser measured every text box, so text
      // heights are real rather than claimed. A hard block here refuses the file.
      const measured = new Map(pdfs.measured.map((m) => [m.index, m.h_mm]));
      const withHeights: DesignJSON = {
        ...assets.design,
        elements: assets.design.elements.map((el, i) => (el.type === 'text' && measured.has(i) ? { ...el, h_mm: measured.get(i)! } : el)),
      };
      const issues = validateRules(withHeights, spec);
      const errors = issues.filter((x) => x.severity === 'error');
      if (errors.length) throw new RenderFailure('validation_failed', 'Design failed the final check.', errors, 422);

      const { token } = await storage.save(rq.design_id, rq.template, { rgb: pdfs.rgb, cmyk: pdfs.cmyk });
      return {
        design_id: rq.design_id,
        status: 'ready',
        pdf_rgb_url: storage.signedUrl(base, token, 'rgb'),
        pdf_cmyk_url: storage.signedUrl(base, token, 'cmyk'),
        warnings: [...assets.issues, ...issues],
        timings_ms: pdfs.timingsMs,
      };
    } finally {
      await assets.cleanup();
    }
  }

  async function deliver(url: string, payload: unknown): Promise<void> {
    const body = JSON.stringify(payload);
    const signature = `sha256=${hmacHex(cfg.secret || 'dev-only-signing-key', body)}`;
    for (let attempt = 1; attempt <= 4; attempt++) {
      try {
        const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Binder-Signature': signature }, body, signal: AbortSignal.timeout(20_000) });
        if (r.ok) return;
      } catch {
        // retry
      }
      await new Promise((r) => setTimeout(r, attempt * 2000));
    }
    console.error(JSON.stringify({ level: 'error', msg: 'callback delivery failed', url }));
  }

  app.post('/render', requireSecret, async (req, res) => {
    const v = validateRequest(cfg, req.body);
    if (!v.ok) return res.status(v.status).json({ error: v.error, errors: v.errors, warnings: [] });

    const { req: rq, spec, result } = v;

    if (!rate.allow(rq.session_token)) {
      res.setHeader('Retry-After', String(rate.retryAfter(rq.session_token)));
      return res.status(429).json({ error: 'rate_limited' });
    }

    const base = baseUrlFor(req);
    // The print page is fetched by our own Chromium; always reach it over loopback.
    const selfBase = `http://127.0.0.1:${(req.socket.localPort ?? cfg.port)}`;

    if (rq.callback_url) {
      const job: Job = { id: randomUUID(), design_id: rq.design_id, status: 'rendering', created: Date.now() };
      jobs.set(job.id, job);
      res.status(202).json({ job_id: job.id, design_id: rq.design_id, status: 'rendering' });
      const callback = rq.callback_url;

      process(rq, spec, base, selfBase)
        .then(async (outcome) => {
          jobs.set(job.id, { ...job, status: 'ready', outcome });
          await deliver(callback, { job_id: job.id, ...outcome });
        })
        .catch(async (e: unknown) => {
          const f = e instanceof RenderFailure ? e : new RenderFailure('render_failed', e instanceof Error ? e.message : String(e));
          const failed: Job = { ...job, status: 'failed', error: f.code, errors: f.issues };
          jobs.set(job.id, failed);
          console.error(JSON.stringify({ level: 'error', msg: 'render failed', design_id: rq.design_id, error: f.message }));
          await deliver(callback, { job_id: job.id, design_id: rq.design_id, status: 'failed', error: f.code, errors: f.issues });
        });
      return;
    }

    try {
      const outcome = await process(rq, spec, base, selfBase);
      // Warnings from the client-side rules are recorded too.
      const seen = new Set(outcome.warnings.map((w) => `${w.code}:${w.element ?? ''}`));
      outcome.warnings.push(...result.warnings.filter((w) => !seen.has(`${w.code}:${w.element ?? ''}`)));
      res.json(outcome);
    } catch (e) {
      if (e instanceof QueueFullError) return res.status(503).setHeader('Retry-After', '30').json({ error: 'busy' });
      if (e instanceof RenderFailure) return res.status(e.status).json({ error: e.code, errors: e.issues, warnings: [] });
      console.error(JSON.stringify({ level: 'error', msg: 'render failed', design_id: rq.design_id, error: e instanceof Error ? e.message : String(e) }));
      res.status(500).json({ error: 'render_failed' });
    }
  });

  /** Fast RGB PNG proof (§3.3 /preview). Same validation as /render; nothing is stored. */
  app.post('/preview', requireSecret, async (req, res) => {
    const v = validateRequest(cfg, req.body);
    if (!v.ok) return res.status(v.status).json({ error: v.error, errors: v.errors, warnings: [] });
    const { req: rq, spec } = v;
    if (!rate.allow(`preview:${rq.session_token}`)) return res.status(429).json({ error: 'rate_limited' });

    let assets;
    try {
      assets = await prepareAssets(cfg, rq.design_json);
    } catch (e) {
      if (e instanceof AssetError) return res.status(422).json({ error: e.code, errors: e.issues, warnings: [] });
      throw e;
    }
    try {
      const selfBase = `http://127.0.0.1:${req.socket.localPort ?? cfg.port}`;
      const png = await limiter.run(() => renderPreviewPng(cfg, selfBase, spec, assets.design, assets));
      res.setHeader('Content-Type', 'image/png');
      res.setHeader('Cache-Control', 'private, no-store');
      res.send(png);
    } catch (e) {
      if (e instanceof QueueFullError) return res.status(503).setHeader('Retry-After', '15').json({ error: 'busy' });
      console.error(JSON.stringify({ level: 'error', msg: 'preview failed', design_id: rq.design_id, error: e instanceof Error ? e.message : String(e) }));
      res.status(500).json({ error: 'preview_failed' });
    } finally {
      await assets.cleanup();
    }
  });

  app.get('/jobs/:id', requireSecret, (req, res) => {
    const job = jobs.get(String(req.params.id));
    if (!job) return res.status(404).json({ error: 'not_found' });
    res.json(job);
  });

  app.use((_req, res) => res.status(404).json({ error: 'not_found' }));

  const start = async (port = cfg.port): Promise<Server> => {
    await mkdir(cfg.outputDir, { recursive: true });
    const sweeper = setInterval(() => void storage.sweep(), 30 * 60_000);
    sweeper.unref();
    // Old jobs.
    setInterval(() => {
      for (const [id, j] of jobs) if (Date.now() - j.created > 3_600_000) jobs.delete(id);
    }, 10 * 60_000).unref();

    return new Promise((resolve) => {
      const server = app.listen(port, () => resolve(server));
      server.on('close', () => clearInterval(sweeper));
    });
  };

  return { app, storage, start };
}

// ---- Entry point ---------------------------------------------------------------------------------------
if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const cfg = loadConfig();
  const problems: string[] = [];
  if (!existsSync(cfg.gsBin)) problems.push(`Ghostscript not found at ${cfg.gsBin} (GS_BIN)`);
  if (!existsSync(cfg.iccProfile)) problems.push(`ICC profile not found at ${cfg.iccProfile} (ICC_PROFILE)`);
  if (!existsSync(`${cfg.editorDist}/print.html`)) problems.push(`Built editor not found at ${cfg.editorDist} (run: npm run build in binder-editor)`);
  if (cfg.production && !cfg.secret) problems.push('BINDER_SECRET must be set in production');
  if (cfg.production && cfg.allowedImageHosts.length === 0) problems.push('ALLOWED_IMAGE_HOSTS must be set in production');
  if (problems.length) {
    console.error(problems.map((p) => `  - ${p}`).join('\n'));
    process.exit(1);
  }

  const { start } = createServer(cfg);
  const server = await start();
  console.log(JSON.stringify({ level: 'info', msg: 'binder-render-service listening', port: cfg.port, concurrency: cfg.concurrency }));

  const stop = async () => {
    server.close();
    await closeBrowser();
    process.exit(0);
  };
  process.on('SIGTERM', stop);
  process.on('SIGINT', stop);
}
