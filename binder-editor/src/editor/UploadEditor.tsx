import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Canvas, FabricImage } from 'fabric';
import {
  effectiveDpi,
  THRESHOLDS,
  validateDesign,
  type DesignJSON,
  type ImageElement,
  type Issue,
} from '@binder/shared';
import { ApiError, createApi, type DesignRecord, type TemplateInfo } from './api';
import type { EditorConfig } from './config';
import { fillElement, fitElement, elementToFabric, fabricToElement, movedElement, rotatedElement, scaledElement, type ImageSource } from './design';
import { issueText, makeT } from './i18n';

type Phase = 'edit' | 'approving' | 'done' | 'failed';
type SaveState = 'idle' | 'saving' | 'saved' | 'error';

interface Source extends ImageSource {
  /** What the canvas displays: a downsized proxy when the server made one. */
  displaySrc: string;
  name: string;
}

const MAX_WORK_PX = 1600;
/** The colours and names printed on the template's own legend. Values shown come from spec.json. */
const LEGEND: Array<{ key: string; color: string; mm?: 'bleed' | 'safe' | 'turnin' }> = [
  { key: 'legend_bleed', color: '#F2836B', mm: 'bleed' },
  { key: 'legend_trim', color: '#000000' },
  { key: 'legend_fold', color: '#00AEEF' },
  { key: 'legend_safe', color: '#EC008C', mm: 'safe' },
  { key: 'legend_miter', color: '#F7941D' },
  { key: 'legend_turnin', color: '#BDBDBD', mm: 'turnin' },
];

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

function notify(cfg: EditorConfig, message: Record<string, unknown>): void {
  if (window.parent !== window) window.parent.postMessage({ source: 'binder-editor', template: cfg.template, mode: cfg.mode, ...message }, window.location.origin);
}

export function UploadEditor({ cfg }: { cfg: EditorConfig }) {
  const api = useMemo(() => createApi(cfg), [cfg]);
  const t = useMemo(() => makeT(cfg.lang), [cfg.lang]);

  const [tpl, setTpl] = useState<TemplateInfo | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [source, setSource] = useState<Source | null>(null);
  const [el, setEl] = useState<ImageElement | null>(null);
  const [uploadPct, setUploadPct] = useState<number | null>(null);
  const [uploadError, setUploadError] = useState('');
  const [designId, setDesignId] = useState<number | undefined>(cfg.designId);
  const [saveState, setSaveState] = useState<SaveState>('idle');
  const [phase, setPhase] = useState<Phase>('edit');
  const [failure, setFailure] = useState<{ message: string; issues: Issue[] } | null>(null);
  const [proofUrl, setProofUrl] = useState('');
  const [stageWidth, setStageWidth] = useState(0);
  const [dragging, setDragging] = useState(false);

  const stageRef = useRef<HTMLDivElement>(null);
  const canvasEl = useRef<HTMLCanvasElement>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  const fabric = useRef<Canvas | null>(null);
  const image = useRef<FabricImage | null>(null);
  const saving = useRef<Promise<number | undefined> | null>(null);
  const idRef = useRef<number | undefined>(cfg.designId);
  const elRef = useRef<ImageElement | null>(null);
  elRef.current = el;

  // ---- Template ---------------------------------------------------------------------
  useEffect(() => {
    api.template(cfg.template).then(setTpl).catch(() => setLoadError(true));
  }, [api, cfg.template]);

  const spec = tpl?.spec ?? null;
  const canvasMm = spec?.canvas_with_bleed_mm;

  // ---- Stage size ------------------------------------------------------------------------
  useEffect(() => {
    const node = stageRef.current;
    if (!node || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(([entry]) => entry && setStageWidth(Math.floor(entry.contentRect.width)));
    ro.observe(node);
    setStageWidth(Math.floor(node.getBoundingClientRect().width));
    return () => ro.disconnect();
  }, [tpl]);

  const workW = Math.max(280, Math.min(stageWidth || 800, MAX_WORK_PX));
  const pxPerMm = canvasMm ? workW / canvasMm.w : 1;
  const workH = canvasMm ? Math.round(workW * (canvasMm.h / canvasMm.w)) : 0;

  // ---- Design + checks ---------------------------------------------------------------------
  const design: DesignJSON | null = useMemo(
    () => (spec && el ? { template: spec.template, mode: 'upload', canvas_mm: { ...spec.canvas_with_bleed_mm }, elements: [el] } : null),
    [spec, el],
  );
  const result = useMemo(() => (design && spec ? validateDesign(design, spec) : null), [design, spec]);
  const dpi = el ? Math.round(effectiveDpi(el)) : 0;

  // ---- Fabric canvas -----------------------------------------------------------------------------
  useEffect(() => {
    if (!tpl || !canvasEl.current) return;
    const canvas = new Canvas(canvasEl.current, {
      width: workW,
      height: workH,
      selection: false,
      preserveObjectStacking: true,
      backgroundColor: '#ffffff',
      enableRetinaScaling: false, // working resolution only; the print file is rendered from the mm design
    });
    fabric.current = canvas;
    return () => {
      void canvas.dispose();
      fabric.current = null;
      image.current = null;
    };
    // The canvas is created once per template; size changes are applied below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tpl]);

  useEffect(() => {
    const c = fabric.current;
    if (!c || !workH) return;
    c.setDimensions({ width: workW, height: workH });
    c.requestRenderAll();
  }, [workW, workH]);

  const fromFabric = useCallback(() => {
    const o = image.current;
    const s = sourceRef.current;
    if (!o || !s) return;
    setEl(
      fabricToElement(
        { left: o.left, top: o.top, width: o.width, height: o.height, scaleX: o.scaleX, scaleY: o.scaleY, angle: o.angle },
        s,
        pxPerMmRef.current,
      ),
    );
  }, []);

  const sourceRef = useRef<Source | null>(null);
  sourceRef.current = source;
  const pxPerMmRef = useRef(pxPerMm);
  pxPerMmRef.current = pxPerMm;

  // Load the picture into Fabric when the source changes.
  useEffect(() => {
    const c = fabric.current;
    if (!c || !source) return;
    let cancelled = false;

    FabricImage.fromURL(source.displaySrc).then((img) => {
      if (cancelled || !fabric.current) return;
      if (image.current) c.remove(image.current);
      img.set({
        originX: 'center',
        originY: 'center',
        lockUniScaling: true,
        lockScalingFlip: true,
        centeredRotation: true,
        centeredScaling: true,
        transparentCorners: false,
        cornerColor: '#10254A',
        cornerStrokeColor: '#ffffff',
        cornerSize: 14,
        borderColor: '#7CA5C4',
        borderScaleFactor: 2,
        padding: 0,
      });
      img.setControlsVisibility({ ml: false, mr: false, mt: false, mb: false });
      image.current = img;
      c.add(img);
      c.setActiveObject(img);
      const live = () => requestAnimationFrame(fromFabric);
      img.on('moving', live);
      img.on('scaling', live);
      img.on('rotating', live);
      img.on('modified', fromFabric);
      // Position from the current design element (set before the bitmap existed).
      if (elRef.current) applyToFabric(elRef.current);
      c.requestRenderAll();
    });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [source, tpl]);

  const applyToFabric = useCallback((e: ImageElement) => {
    const o = image.current;
    const c = fabric.current;
    if (!o || !c) return;
    const f = elementToFabric(e, { width: o.width, height: o.height }, pxPerMmRef.current);
    o.set({ left: f.left, top: f.top, scaleX: f.scaleX, scaleY: f.scaleY, angle: f.angle });
    o.setCoords();
    c.requestRenderAll();
  }, []);

  // Element state -> Fabric (buttons, slider, resize).
  useEffect(() => {
    if (el) applyToFabric(el);
  }, [el, pxPerMm, applyToFabric]);

  // ---- Saving ------------------------------------------------------------------------------------------
  const save = useCallback(async (): Promise<number | undefined> => {
    const current = elRef.current;
    if (!current || !spec) return idRef.current;
    const d: DesignJSON = { template: spec.template, mode: 'upload', canvas_mm: { ...spec.canvas_with_bleed_mm }, elements: [current] };
    const warnings = validateDesign(d, spec).warnings;

    const run = (async () => {
      setSaveState('saving');
      try {
        const rec = await api.saveDesign(d, warnings, idRef.current);
        idRef.current = rec.id;
        setDesignId(rec.id);
        setSaveState('saved');
        return rec.id;
      } catch (e) {
        setSaveState('error');
        throw e;
      }
    })();
    saving.current = run;
    return run;
  }, [api, spec]);

  // Autosave 0.9 s after the last change.
  useEffect(() => {
    if (!el || phase !== 'edit') return;
    const h = window.setTimeout(() => void save().catch(() => undefined), 900);
    return () => window.clearTimeout(h);
  }, [el, phase, save]);

  // ---- Upload -----------------------------------------------------------------------------------------------
  const upload = useCallback(
    async (file: File) => {
      setUploadError('');
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return setUploadError(t('upload_type'));
      setUploadPct(0);
      try {
        const res = await api.upload(file, (f) => setUploadPct(Math.round(f * 100)));
        const s: Source = { src: res.url, displaySrc: res.proxy_url || res.url, source_px: res.source_px, name: file.name };
        if (spec) setEl(fillElement(spec, s));
        setSource(s);
      } catch (e) {
        const code = e instanceof ApiError ? e.code : '';
        setUploadError(
          code === 'binder_upload_type' ? t('upload_type') : code === 'binder_upload_size' ? t('upload_size') : code === 'binder_rate_limited' ? t('upload_rate') : t('upload_failed'),
        );
      } finally {
        setUploadPct(null);
      }
    },
    [api, spec, t],
  );

  const pick = (files: FileList | null | undefined) => {
    const f = files?.[0];
    if (f) void upload(f);
  };

  // ---- Reopen a saved design -----------------------------------------------------------------------------------
  useEffect(() => {
    if (!cfg.designId || !spec) return;
    api.getDesign(cfg.designId).then((rec) => {
      const first = rec.design_json?.elements?.[0];
      if (first?.type === 'image') {
        setSource({ src: first.src, displaySrc: first.src, source_px: first.source_px, name: '' });
        setEl(first);
      }
      if (rec.status === 'ready') {
        setProofUrl(rec.proof_url);
        setPhase('done');
      } else if (rec.status === 'rendering') {
        void poll(rec.id);
      }
    }).catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cfg.designId, spec]);

  // ---- Approve -----------------------------------------------------------------------------------------------------
  const describe = useCallback(
    (e: unknown): { message: string; issues: Issue[] } => {
      if (e instanceof ApiError) {
        if (e.status === 422) return { message: t('err_rejected'), issues: e.errors };
        if (e.status === 429 || e.status === 503) return { message: t('err_busy'), issues: [] };
        if (e.status === 502 || e.code === 'binder_not_configured') return { message: t('err_unavailable'), issues: [] };
        if (e.status === 0) return { message: t('err_network'), issues: [] };
      }
      return { message: t('err_generic'), issues: [] };
    },
    [t],
  );

  const finishDone = (rec: DesignRecord) => {
    setProofUrl(rec.proof_url);
    setPhase('done');
    notify(cfg, { type: 'design-ready', designId: rec.id, proofUrl: rec.proof_url });
  };

  const poll = useCallback(
    async (id: number) => {
      setPhase('approving');
      const deadline = Date.now() + 5 * 60_000;
      let delay = 1500;
      while (Date.now() < deadline) {
        await sleep(delay);
        delay = Math.min(delay + 500, 4000);
        try {
          const rec = await api.status(id);
          if (rec.status === 'ready') return finishDone(rec);
          if (rec.status === 'failed') {
            setFailure({ message: t('err_failed'), issues: rec.errors ?? [] });
            return setPhase('failed');
          }
        } catch (e) {
          if (e instanceof ApiError && e.status !== 0 && e.status < 500) break; // a real refusal, not a blip
        }
      }
      setFailure({ message: t('err_generic'), issues: [] });
      setPhase('failed');
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [api, t],
  );

  const approve = async () => {
    if (!result?.ok) return;
    setFailure(null);
    setPhase('approving');
    try {
      const id = await save();
      if (!id) throw new Error('no id');
      const rec = await api.finalize(id);
      if (rec.status === 'ready') return finishDone(rec);
      await poll(id);
    } catch (e) {
      setFailure(describe(e));
      setPhase('failed');
    }
  };

  // ---- Toolbar actions ------------------------------------------------------------------------------------------------
  const rotation = el?.rotation_deg ?? 0;
  const fillNow = () => spec && source && setEl(fillElement(spec, source, Math.abs(rotation) === 90 ? rotation : 0));
  const fitNow = () => spec && source && setEl(fitElement(spec, source, Math.abs(rotation) === 90 ? rotation : 0));
  const centerNow = () => canvasMm && el && setEl(movedElement(el, { x: canvasMm.w / 2, y: canvasMm.h / 2 }));
  const turn = (by: number) => el && setEl(rotatedElement(el, by));
  const fillWidth = spec && source ? fillElement(spec, source, Math.abs(rotation) === 90 ? rotation : 0).w_mm : 1;
  const zoom = el ? el.w_mm / fillWidth : 1;

  const nudge = (ev: React.KeyboardEvent) => {
    if (!el) return;
    const step = ev.shiftKey ? 10 : 1;
    const d: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    const m = d[ev.key];
    if (!m) return;
    ev.preventDefault();
    setEl({ ...el, x_mm: el.x_mm + m[0], y_mm: el.y_mm + m[1] });
  };

  // ---- Render --------------------------------------------------------------------------------------------------------------
  if (loadError) return <div className="binder-app binder-center" role="alert">{t('load_failed')}</div>;
  if (!tpl || !spec) return <div className="binder-app binder-center">{t('loading')}</div>;

  const mm = { bleed: spec.bleed_mm, safe: spec.safe_margin_mm, turnin: spec.turn_in_mm };
  const quality = !el ? '' : dpi < THRESHOLDS.blockDpi ? 'low' : dpi < THRESHOLDS.warnDpi ? 'ok' : 'good';
  const issues = [...(result?.errors ?? []), ...(result?.warnings ?? [])];
  const busy = phase === 'approving';

  return (
    <div className="binder-app" dir={cfg.lang === 'ar' ? 'rtl' : 'ltr'} lang={cfg.lang}>
      <header className="binder-bar">
        <div>
          <h1>{t(`title_${cfg.template}`)}</h1>
          <p>{t('subtitle', { w: spec.trim_mm.w, h: spec.trim_mm.h })}</p>
        </div>
        <button type="button" className="binder-btn binder-btn--ghost" onClick={() => notify(cfg, { type: 'close' })}>
          {t('close')}
        </button>
      </header>

      <main className="binder-main">
        <section
          className={`binder-stagewrap${dragging ? ' is-drag' : ''}`}
          onDragOver={(e) => {
            e.preventDefault();
            setDragging(true);
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragging(false);
            pick(e.dataTransfer.files);
          }}
        >
          <div className="binder-stage" ref={stageRef} tabIndex={0} onKeyDown={nudge} aria-label={t(`title_${cfg.template}`)}>
            <div className="binder-frame" style={{ width: workW, height: workH }} dir="ltr">
              <canvas ref={canvasEl} />
              <img className="binder-overlay" src={tpl.overlay_url} alt="" draggable={false} />
              {!source && (
                <button type="button" className="binder-drop" onClick={() => fileInput.current?.click()}>
                  <strong>{uploadPct === null ? t('upload_cta') : t('uploading', { pct: uploadPct })}</strong>
                  <span>{t('drop_hint')}</span>
                </button>
              )}
            </div>
          </div>

          <div className="binder-legend" aria-label={t('legend')}>
            {LEGEND.map((l) => (
              <span key={l.key}>
                <i style={{ background: l.color }} />
                {t(l.key, l.mm ? { mm: mm[l.mm] } : {})}
              </span>
            ))}
            <small>{t('legend_note')}</small>
          </div>
        </section>

        <aside className="binder-panel" aria-live="polite">
          <input ref={fileInput} type="file" hidden accept="image/jpeg,image/png,image/webp" onChange={(e) => { pick(e.target.files); e.target.value = ''; }} />

          {phase === 'done' ? (
            <div className="binder-card binder-done" role="status">
              <div className="binder-tick" aria-hidden="true">✓</div>
              <h2>{t('ready_title')}</h2>
              <p>{t('ready_text')}</p>
              {proofUrl && (
                <a className="binder-btn binder-btn--ghost" href={proofUrl} target="_blank" rel="noopener">
                  {t('proof')}
                </a>
              )}
              <button type="button" className="binder-btn" onClick={() => notify(cfg, { type: 'close' })}>
                {t('done')}
              </button>
            </div>
          ) : busy ? (
            <div className="binder-card binder-wait" role="status">
              <div className="binder-spinner" aria-hidden="true" />
              <h2>{t('approving')}</h2>
              <p>{t('approving_hint')}</p>
            </div>
          ) : (
            <>
              <div className="binder-card">
                <h2>{t('upload_title')}</h2>
                <button type="button" className="binder-btn" onClick={() => fileInput.current?.click()} disabled={uploadPct !== null}>
                  {uploadPct !== null ? t('uploading', { pct: uploadPct }) : source ? t('upload_replace') : t('upload_cta')}
                </button>
                {source && <p className="binder-file">{source.name} · {source.source_px.w} × {source.source_px.h} px</p>}
                {uploadPct !== null && <progress max={100} value={uploadPct} />}
                {uploadError && <p className="binder-msg binder-msg--error" role="alert">{uploadError}</p>}
              </div>

              {source && el && (
                <div className="binder-card">
                  <h2>{t('position')}</h2>
                  <div className="binder-row">
                    <button type="button" className="binder-btn binder-btn--ghost" onClick={fillNow}>{t('fill')}</button>
                    <button type="button" className="binder-btn binder-btn--ghost" onClick={fitNow}>{t('fit')}</button>
                    <button type="button" className="binder-btn binder-btn--ghost" onClick={centerNow}>{t('center')}</button>
                  </div>
                  <div className="binder-row">
                    <button type="button" className="binder-btn binder-btn--ghost" onClick={() => turn(-90)}>↺ {t('rotate_left')}</button>
                    <button type="button" className="binder-btn binder-btn--ghost" onClick={() => turn(90)}>↻ {t('rotate_right')}</button>
                  </div>
                  <label className="binder-slider">
                    <span>{t('size')}</span>
                    <input type="range" min={0.2} max={3} step={0.01} value={Math.min(3, Math.max(0.2, zoom))} onChange={(e) => setEl(scaledElement(el, Number(e.target.value) / zoom))} />
                  </label>

                  <div className={`binder-quality binder-quality--${quality}`}>
                    <span>{t('quality')}</span>
                    <strong>{t(`quality_${quality}`)}</strong>
                    <small>{t('dpi', { dpi })}</small>
                  </div>
                </div>
              )}

              <div className="binder-card">
                <h2>{t('checks')}</h2>
                {!el ? (
                  <p className="binder-muted">{t('checks_none')}</p>
                ) : issues.length === 0 ? (
                  <p className="binder-msg binder-msg--ok">{t('checks_ok')}</p>
                ) : (
                  <ul className="binder-issues">
                    {issues.map((i, n) => (
                      <li key={n} className={i.severity === 'error' ? 'is-error' : 'is-warn'}>
                        {issueText(t, i)}
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              {failure && (
                <div className="binder-card binder-fail" role="alert">
                  <p>{failure.message}</p>
                  {failure.issues.length > 0 && (
                    <ul className="binder-issues">
                      {failure.issues.map((i, n) => (
                        <li key={n} className="is-error">{issueText(t, i)}</li>
                      ))}
                    </ul>
                  )}
                </div>
              )}

              <div className="binder-actions">
                <span className={`binder-save binder-save--${saveState}`}>
                  {saveState === 'saving' ? t('saving') : saveState === 'saved' ? `✓ ${t('saved')}` : saveState === 'error' ? t('save_failed') : ''}
                </span>
                <button type="button" className="binder-btn binder-btn--primary" onClick={() => void approve()} disabled={!result?.ok || !el}>
                  {phase === 'failed' ? t('try_again') : t('approve')}
                </button>
              </div>
            </>
          )}
        </aside>
      </main>
    </div>
  );
}
