import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Canvas, FabricImage } from 'fabric';
import { effectiveDpi, THRESHOLDS, validateDesign, type DesignJSON, type ImageElement } from '@binder/shared';
import { ApiError, createApi, overlayUrlFor, type TemplateInfo } from './api';
import type { EditorConfig } from './config';
import {
  elementToFabric,
  fabricToElement,
  fillElement,
  fitElement,
  movedElement,
  rotatedElement,
  scaledElement,
  type ImageSource,
} from './design';
import { makeT } from './i18n';
import { Actions, FailureCard, IssueList, StatusCard } from './Panels';
import { notify, useDesignSession, type Snapshot } from './session';
import { Legend } from './Legend';

interface Source extends ImageSource {
  /** What the canvas displays: a downsized proxy when the server made one. */
  displaySrc: string;
  name: string;
}

const MAX_WORK_PX = 1600;

/** Upload mode (§4.1): one finished image, positioned inside the locked template. */
export function UploadEditor({ cfg }: { cfg: EditorConfig }) {
  const api = useMemo(() => createApi(cfg), [cfg]);
  const t = useMemo(() => makeT(cfg.lang), [cfg.lang]);

  const [tpl, setTpl] = useState<TemplateInfo | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [source, setSource] = useState<Source | null>(null);
  const [el, setEl] = useState<ImageElement | null>(null);
  const [uploadPct, setUploadPct] = useState<number | null>(null);
  const [uploadError, setUploadError] = useState('');
  const [stageWidth, setStageWidth] = useState(0);
  const [dragging, setDragging] = useState(false);

  const stageRef = useRef<HTMLDivElement>(null);
  const canvasEl = useRef<HTMLCanvasElement>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  const fabric = useRef<Canvas | null>(null);
  const image = useRef<FabricImage | null>(null);
  const elRef = useRef<ImageElement | null>(null);
  elRef.current = el;

  // ---- Template ---------------------------------------------------------------------
  useEffect(() => {
    api.template(cfg.template, cfg.sticker).then(setTpl).catch(() => setLoadError(true));
  }, [api, cfg.template, cfg.sticker]);

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
  const pxPerMmRef = useRef(pxPerMm);
  pxPerMmRef.current = pxPerMm;

  // ---- Design + checks ---------------------------------------------------------------------
  const design: DesignJSON | null = useMemo(
    () => (spec && el ? { template: spec.template, mode: 'upload', canvas_mm: { ...spec.canvas_with_bleed_mm }, elements: [el], ...(spec.sticker ? { sticker: spec.sticker } : {}) } : null),
    [spec, el],
  );
  const result = useMemo(() => (design && spec ? validateDesign(design, spec) : null), [design, spec]);
  const dpi = el ? Math.round(effectiveDpi(el)) : 0;

  const getSnapshot = useCallback((): Snapshot | null => {
    const d = designRef.current;
    const r = resultRef.current;
    return d && r ? { design: d, warnings: r.warnings, ok: r.ok } : null;
  }, []);
  const designRef = useRef(design);
  designRef.current = design;
  const resultRef = useRef(result);
  resultRef.current = result;

  const session = useDesignSession(cfg, api, t, getSnapshot);

  // Autosave after every change while editing.
  useEffect(() => {
    if (el && session.phase === 'edit') session.markDirty();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [el, session.phase]);

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

  const sourceRef = useRef<Source | null>(null);
  sourceRef.current = source;

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

  const applyToFabric = useCallback((e: ImageElement) => {
    const o = image.current;
    const c = fabric.current;
    if (!o || !c) return;
    const f = elementToFabric(e, { width: o.width, height: o.height }, pxPerMmRef.current);
    o.set({ left: f.left, top: f.top, scaleX: f.scaleX, scaleY: f.scaleY, angle: f.angle });
    o.setCoords();
    c.requestRenderAll();
  }, []);

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
      if (elRef.current) applyToFabric(elRef.current); // position from the element set before the bitmap existed
      c.requestRenderAll();
    });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [source, tpl]);

  // Element state -> Fabric (buttons, slider, resize).
  useEffect(() => {
    if (el) applyToFabric(el);
  }, [el, pxPerMm, applyToFabric]);

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
    api
      .getDesign(cfg.designId)
      .then((rec) => {
        const first = rec.design_json?.elements?.[0];
        if (first?.type === 'image') {
          setSource({ src: first.src, displaySrc: first.src, source_px: first.source_px, name: '' });
          setEl(first);
        }
        session.resume(rec);
      })
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cfg.designId, spec]);

  // ---- Toolbar actions ------------------------------------------------------------------------------------------------
  const rotation = el?.rotation_deg ?? 0;
  const quarter = Math.abs(rotation) === 90 ? rotation : 0;
  const fillNow = () => spec && source && setEl(fillElement(spec, source, quarter));
  const fitNow = () => spec && source && setEl(fitElement(spec, source, quarter));
  const centerNow = () => canvasMm && el && setEl(movedElement(el, { x: canvasMm.w / 2, y: canvasMm.h / 2 }));
  const turn = (by: number) => el && setEl(rotatedElement(el, by));
  const fillWidth = spec && source ? fillElement(spec, source, quarter).w_mm : 1;
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

  const quality = !el ? '' : dpi < THRESHOLDS.blockDpi ? 'low' : dpi < THRESHOLDS.warnDpi ? 'ok' : 'good';
  const issues = [...(result?.errors ?? []), ...(result?.warnings ?? [])];
  const status = <StatusCard session={session} t={t} cfg={cfg} />;
  const busy = session.phase === 'approving' || session.phase === 'done';

  return (
    <div className="binder-app" dir={cfg.lang === 'ar' ? 'rtl' : 'ltr'} lang={cfg.lang}>
      <header className="binder-bar">
        <div>
          <h1>{t(`title_${cfg.template}`)}</h1>
          <p>{spec.sticker ? t('subtitle_sticker', { shape: t(`shape_${spec.sticker.shape}`), w: spec.trim_mm.w, h: spec.trim_mm.h }) : t('subtitle', { w: spec.trim_mm.w, h: spec.trim_mm.h })}</p>
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
              <img className="binder-overlay" src={overlayUrlFor(tpl)} alt="" draggable={false} />
              {!source && (
                <button type="button" className="binder-drop" onClick={() => fileInput.current?.click()}>
                  <strong>{uploadPct === null ? t('upload_cta') : t('uploading', { pct: uploadPct })}</strong>
                  <span>{t('drop_hint')}</span>
                </button>
              )}
            </div>
          </div>
          <Legend spec={spec} t={t} />
        </section>

        <aside className="binder-panel" aria-live="polite">
          <input ref={fileInput} type="file" hidden accept="image/jpeg,image/png,image/webp" onChange={(e) => { pick(e.target.files); e.target.value = ''; }} />

          {busy ? (
            status
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
                  <IssueList issues={issues} t={t} />
                )}
              </div>

              <FailureCard session={session} t={t} />
              <Actions session={session} t={t} canApprove={!!result?.ok && !!el} />
            </>
          )}
        </aside>
      </main>
    </div>
  );
}
