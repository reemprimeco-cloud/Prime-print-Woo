import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Canvas, FabricImage, Rect, Textbox, type FabricObject } from 'fabric';
import { cmykToRgbCss, effectiveDpi, THRESHOLDS, validateDesign, type Cmyk, type DesignElement, type DesignJSON, type Spec } from '@binder/shared';
import { ApiError, createApi, overlayUrlFor, type TemplateInfo } from '../api';
import type { EditorConfig } from '../config';
import { makeT, type T } from '../i18n';
import { Legend } from '../Legend';
import { Actions, FailureCard, IssueList, StatusCard } from '../Panels';
import { notify, useDesignSession, type Snapshot } from '../session';
import { PALETTE, sameCmyk } from './palette';
import {
  PX_PER_MM,
  backgroundOf,
  backgroundProps,
  homePanel,
  imageToProps,
  isRtlText,
  objectsToElements,
  ptToPx,
  textToProps,
  type BinderMeta,
  type FabricLike,
} from './fabric-map';

/** A Fabric object with the editor's own bookkeeping on it. */
type BObject = FabricObject & { binder?: BinderMeta };

const FONT_FAMILIES = ['Tajawal', 'Poppins'] as const;
const TEXT_WEIGHTS = ['400', '500', '700'] as const;
const MAX_WORK_PX = 1600;
const HISTORY_LIMIT = 60;

const CONTROL_STYLE = {
  transparentCorners: false,
  cornerColor: '#10254A',
  cornerStrokeColor: '#ffffff',
  cornerSize: 12,
  borderColor: '#7CA5C4',
  borderScaleFactor: 2,
  padding: 0,
} as const;

/** Live design mode (§4.1): text, pictures and a colour on the locked template — Fabric.js, no licence. */
export default function LiveEditor({ cfg }: { cfg: EditorConfig }) {
  const api = useMemo(() => createApi(cfg), [cfg]);
  const t = useMemo(() => makeT(cfg.lang), [cfg.lang]);
  const [tpl, setTpl] = useState<TemplateInfo | null>(null);
  const [loadError, setLoadError] = useState(false);

  useEffect(() => {
    api.template(cfg.template, { sticker: cfg.sticker, binding: cfg.binding }).then(setTpl).catch(() => setLoadError(true));
  }, [api, cfg.template, cfg.sticker, cfg.binding]);

  if (loadError) return <div className="binder-app binder-center" role="alert">{t('load_failed')}</div>;
  if (!tpl) return <div className="binder-app binder-center">{t('loading')}</div>;

  return <Inner cfg={cfg} api={api} t={t} tpl={tpl} />;
}

type Api = ReturnType<typeof createApi>;

function Inner({ cfg, api, t, tpl }: { cfg: EditorConfig; api: Api; t: T; tpl: TemplateInfo }) {
  const spec = tpl.spec;
  const W = spec.canvas_with_bleed_mm.w * PX_PER_MM; // working px
  const H = spec.canvas_with_bleed_mm.h * PX_PER_MM;

  const stageRef = useRef<HTMLDivElement>(null);
  const canvasEl = useRef<HTMLCanvasElement>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  const fabric = useRef<Canvas | null>(null);
  const applying = useRef(false); // true while a design is being loaded onto the canvas (no history, no autosave)
  const history = useRef<{ past: DesignJSON[]; future: DesignJSON[] }>({ past: [], future: [] });

  const [stageWidth, setStageWidth] = useState(0);
  const [snap, setSnap] = useState<{ design: DesignJSON; indexOf: number[] }>({ design: emptyDesign(spec), indexOf: [] });
  const [selected, setSelected] = useState<BObject | null>(null);
  const [tick, setTick] = useState(0); // bumps when the selected object changes on the canvas
  const [uploadPct, setUploadPct] = useState<number | null>(null);
  const [uploadError, setUploadError] = useState('');
  const [fontsReady, setFontsReady] = useState(false);

  // ---- Fonts: measured text must use the real faces ------------------------------------------------
  useEffect(() => {
    const loads: Promise<unknown>[] = [];
    for (const f of FONT_FAMILIES) for (const w of TEXT_WEIGHTS) loads.push(document.fonts.load(`${w} 24px "${f}"`, 'Aa الصف'));
    Promise.allSettled(loads).then(() => setFontsReady(true));
  }, []);

  // ---- Design snapshot from the canvas -------------------------------------------------------------------
  const readDesign = useCallback((): { design: DesignJSON; indexOf: number[] } => {
    const c = fabric.current;
    const objects = (c?.getObjects() ?? []) as BObject[];
    const { elements, indexOf } = objectsToElements(objects as unknown as FabricLike[], spec);
    return { design: { ...emptyDesign(spec), elements }, indexOf };
  }, [spec]);

  const commit = useCallback(
    (record = true) => {
      const next = readDesign();
      setSnap((prev) => {
        if (record && !applying.current && JSON.stringify(prev.design) !== JSON.stringify(next.design)) {
          history.current.past.push(prev.design);
          if (history.current.past.length > HISTORY_LIMIT) history.current.past.shift();
          history.current.future = [];
        }
        return next;
      });
    },
    [readDesign],
  );

  // ---- Canvas -------------------------------------------------------------------------------------------------------
  useEffect(() => {
    if (!canvasEl.current) return;
    const c = new Canvas(canvasEl.current, {
      width: W,
      height: H,
      backgroundColor: '#ffffff',
      preserveObjectStacking: true,
      selection: true,
      enableRetinaScaling: true,
      controlsAboveOverlay: true,
    });
    fabric.current = c;

    const onSel = () => {
      const o = (c.getActiveObject() as BObject | undefined) ?? null;
      setSelected(o && o.binder?.kind !== 'background' ? o : null);
    };
    c.on('selection:created', onSel);
    c.on('selection:updated', onSel);
    c.on('selection:cleared', () => setSelected(null));

    c.on('object:modified', (e) => {
      const o = e.target as BObject;
      // A corner-scaled text box: keep the text crisp by folding the scale into font size and width.
      if (o.binder?.kind === 'text' && (o.scaleX !== 1 || o.scaleY !== 1)) {
        const tb = o as Textbox;
        tb.set({ fontSize: tb.fontSize * o.scaleY, width: tb.width * o.scaleX, scaleX: 1, scaleY: 1 });
        tb.setCoords();
      }
      setTick((n) => n + 1);
      commit();
    });
    c.on('text:changed', (e) => {
      const tb = e.target as Textbox & BObject;
      tb.set({ direction: isRtlText(tb.text) ? 'rtl' : 'ltr' });
      setTick((n) => n + 1);
      commit(false);
    });
    c.on('text:editing:exited', () => commit());

    return () => {
      void c.dispose();
      fabric.current = null;
    };
    // The canvas exists once per template.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [spec]);

  // ---- Fit the canvas to the stage: zoom only, the working units never change --------------------------------
  useEffect(() => {
    const node = stageRef.current;
    if (!node || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(([entry]) => entry && setStageWidth(Math.floor(entry.contentRect.width)));
    ro.observe(node);
    setStageWidth(Math.floor(node.getBoundingClientRect().width));
    return () => ro.disconnect();
  }, []);

  const viewW = Math.max(280, Math.min(stageWidth || 800, MAX_WORK_PX));
  const zoom = viewW / W;
  const viewH = Math.round(H * zoom);
  useEffect(() => {
    const c = fabric.current;
    if (!c) return;
    c.setDimensions({ width: viewW, height: viewH });
    c.setZoom(zoom);
    c.requestRenderAll();
  }, [viewW, viewH, zoom]);

  // ---- Building objects ------------------------------------------------------------------------------------------------
  const styleControls = (o: BObject, kind: BinderMeta['kind']) => {
    o.set({ ...CONTROL_STYLE, originX: 'center', originY: 'center', lockScalingFlip: true });
    if (kind === 'image') {
      o.set({ lockUniScaling: true });
      o.setControlsVisibility({ ml: false, mr: false, mt: false, mb: false });
    } else if (kind === 'text') {
      o.setControlsVisibility({ mt: false, mb: false });
    }
  };

  const addTextObject = (el: Extract<DesignElement, { type: 'text' }>): Textbox & BObject => {
    const p = textToProps(el);
    const tb = new Textbox(el.text, {
      left: p.left,
      top: p.top,
      width: p.width,
      angle: p.angle,
      fontFamily: p.fontFamily,
      fontWeight: p.fontWeight,
      fontSize: p.fontSize,
      textAlign: p.textAlign,
      lineHeight: p.lineHeight,
      direction: p.direction,
      fill: cmykToRgbCss(el.color_cmyk),
      editable: true,
    }) as Textbox & BObject;
    tb.binder = p.binder;
    styleControls(tb, 'text');
    fabric.current?.add(tb);
    return tb;
  };

  const addImageObject = async (el: Extract<DesignElement, { type: 'image' }>, displaySrc: string): Promise<FabricImage & BObject> => {
    const img = (await FabricImage.fromURL(displaySrc, { crossOrigin: 'anonymous' })) as FabricImage & BObject;
    const p = imageToProps(el, { width: img.width, height: img.height });
    img.set({ left: p.left, top: p.top, scaleX: p.scaleX, scaleY: p.scaleY, angle: p.angle });
    img.binder = p.binder;
    styleControls(img, 'image');
    fabric.current?.add(img);
    return img;
  };

  const setBackgroundObject = (cmyk: Cmyk | null) => {
    const c = fabric.current;
    if (!c) return;
    const existing = (c.getObjects() as BObject[]).find((o) => o.binder?.kind === 'background');
    if (cmyk === null) {
      if (existing) c.remove(existing);
    } else if (existing) {
      existing.set({ fill: cmykToRgbCss(cmyk) });
      existing.binder = { kind: 'background', cmyk };
    } else {
      const p = backgroundProps(spec, cmyk);
      const r = new Rect({ left: p.left, top: p.top, width: p.width, height: p.height, originX: 'center', originY: 'center', fill: cmykToRgbCss(cmyk), selectable: false, evented: false, hoverCursor: 'default' }) as Rect & BObject;
      r.binder = p.binder;
      c.add(r);
      c.sendObjectToBack(r);
    }
    c.requestRenderAll();
    commit();
  };

  /** Replace everything on the canvas with a saved design (reopen, undo, redo). */
  const loadDesign = useCallback(
    async (design: DesignJSON) => {
      const c = fabric.current;
      if (!c) return;
      applying.current = true;
      try {
        c.discardActiveObject();
        for (const o of [...c.getObjects()]) c.remove(o);
        const bg = backgroundOf(design.elements);
        if (bg) setBackgroundObject(bg.color_cmyk);
        for (const el of design.elements) {
          if (el.type === 'text') addTextObject(el);
          else if (el.type === 'image') await addImageObject(el, el.src);
        }
        c.requestRenderAll();
      } finally {
        applying.current = false;
        setSelected(null);
        commit(false);
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [spec, commit],
  );

  // ---- Reopen a saved design -----------------------------------------------------------------------------------------------
  const result = useMemo(() => validateDesign(snap.design, spec), [snap.design, spec]);
  const hasContent = snap.design.elements.length > 0;
  const snapRef = useRef({ snap, result, hasContent });
  snapRef.current = { snap, result, hasContent };
  const getSnapshot = useCallback((): Snapshot | null => {
    const { snap: s, result: r, hasContent: has } = snapRef.current;
    return has ? { design: s.design, warnings: r.warnings, ok: r.ok } : null;
  }, []);
  const session = useDesignSession(cfg, api, t, getSnapshot);

  useEffect(() => {
    if (!cfg.designId || !fontsReady) return;
    api
      .getDesign(cfg.designId)
      .then(async (rec) => {
        if (rec.design_json) await loadDesign(rec.design_json);
        session.resume(rec);
      })
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cfg.designId, fontsReady]);

  useEffect(() => {
    if (hasContent && session.phase === 'edit' && !applying.current) session.markDirty();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [snap.design, session.phase]);

  // ---- History ------------------------------------------------------------------------------------------------------------------------
  const undo = () => {
    const prev = history.current.past.pop();
    if (!prev) return;
    history.current.future.push(snap.design);
    void loadDesign(prev);
  };
  const redo = () => {
    const next = history.current.future.pop();
    if (!next) return;
    history.current.past.push(snap.design);
    void loadDesign(next);
  };

  // ---- Adding things -------------------------------------------------------------------------------------------------------------
  const home = useMemo(() => homePanel(spec), [spec]);

  const addText = () => {
    const c = fabric.current;
    if (!c) return;
    const wMm = Math.min(90, home.w);
    const arabic = cfg.lang === 'ar';
    const tb = addTextObject({
      type: 'text',
      text: t('default_text'),
      font: arabic ? 'Tajawal' : 'Poppins',
      size_pt: Math.min(28, Math.max(8, home.w / 4)),
      weight: '700',
      color_cmyk: [0, 0, 0, 100],
      x_mm: home.x + home.w / 2 - wMm / 2,
      y_mm: home.y + home.h / 2 - 6,
      w_mm: wMm,
      h_mm: 12,
      align: 'center',
      rtl: arabic,
    });
    c.setActiveObject(tb);
    c.requestRenderAll();
    commit();
  };

  const addImage = async (file: File) => {
    const c = fabric.current;
    if (!c) return;
    setUploadError('');
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return setUploadError(t('upload_type'));
    setUploadPct(0);
    try {
      const res = await api.upload(file, (f) => setUploadPct(Math.round(f * 100)));
      const wMm = Math.min(70, home.w);
      const hMm = (wMm * res.source_px.h) / res.source_px.w;
      const img = await addImageObject(
        { type: 'image', src: res.url, x_mm: home.x + home.w / 2 - wMm / 2, y_mm: home.y + home.h / 2 - hMm / 2, w_mm: wMm, h_mm: hMm, rotation_deg: 0, source_px: res.source_px },
        res.proxy_url || res.url,
      );
      c.setActiveObject(img);
      c.requestRenderAll();
      commit();
    } catch (e) {
      const code = e instanceof ApiError ? e.code : '';
      setUploadError(code === 'binder_upload_type' ? t('upload_type') : code === 'binder_upload_size' ? t('upload_size') : code === 'binder_rate_limited' ? t('upload_rate') : t('upload_failed'));
    } finally {
      setUploadPct(null);
    }
  };

  // ---- Acting on the selection ---------------------------------------------------------------------------------------------------------
  const touch = () => {
    fabric.current?.requestRenderAll();
    setTick((n) => n + 1);
    commit();
  };
  const withSel = (fn: (o: BObject, c: Canvas) => void) => {
    const c = fabric.current;
    if (!c || !selected) return;
    fn(selected, c);
    selected.setCoords();
    touch();
  };
  const remove = () => withSel((o, c) => { c.remove(o); c.discardActiveObject(); });
  const duplicate = () =>
    void (async () => {
      const c = fabric.current;
      if (!c || !selected) return;
      const copy = (await selected.clone(['binder'])) as BObject;
      copy.set({ left: selected.left + 20, top: selected.top + 20 });
      copy.binder = { ...(selected.binder ?? { kind: 'text' }), locked: false };
      c.add(copy);
      c.setActiveObject(copy);
      touch();
    })();
  const forward = () => withSel((o, c) => c.bringObjectForward(o));
  const backward = () =>
    withSel((o, c) => {
      c.sendObjectBackwards(o);
      const bg = (c.getObjects() as BObject[]).find((x) => x.binder?.kind === 'background');
      if (bg) c.sendObjectToBack(bg); // the background stays the bottom layer
    });
  const center = () => withSel((o) => o.set({ left: (home.x + home.w / 2) * PX_PER_MM, top: (home.y + home.h / 2) * PX_PER_MM }));
  const turn = () => withSel((o) => o.set({ angle: (o.angle + 90) % 360 }));
  const toggleLock = () =>
    withSel((o) => {
      const locked = !o.binder?.locked;
      o.binder = { ...(o.binder ?? { kind: 'text' }), locked };
      o.set({ lockMovementX: locked, lockMovementY: locked, lockRotation: locked, lockScalingX: locked, lockScalingY: locked, hasControls: !locked, editable: !locked });
    });
  const fillSheet = () =>
    withSel((o) => {
      const px = o.binder?.source_px;
      if (!px) return;
      const scale = Math.max(W / px.w, H / px.h);
      o.set({ angle: 0, scaleX: (px.w * scale) / o.width, scaleY: (px.h * scale) / o.height, left: W / 2, top: H / 2 });
    });

  const selectElement = (designIndex: number) => {
    const c = fabric.current;
    const i = snap.indexOf[designIndex];
    if (!c || i === undefined) return;
    const o = c.getObjects()[i] as BObject | undefined;
    if (o && o.binder?.kind !== 'background') {
      c.setActiveObject(o);
      c.requestRenderAll();
      setSelected(o);
    }
  };

  const onKey = (ev: React.KeyboardEvent) => {
    const c = fabric.current;
    const o = c?.getActiveObject() as (BObject & { isEditing?: boolean }) | undefined;
    if (!c || !o || o.isEditing || o.binder?.locked) return;
    const step = (ev.shiftKey ? 10 : 1) * (1 / zoom);
    const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    if (ev.key === 'Delete' || ev.key === 'Backspace') {
      ev.preventDefault();
      remove();
    } else if (moves[ev.key]) {
      ev.preventDefault();
      o.set({ left: o.left + moves[ev.key]![0], top: o.top + moves[ev.key]![1] });
      o.setCoords();
      touch();
    }
  };

  // ---- Render ---------------------------------------------------------------------------------------------------------------------------------
  const issues = [...result.errors, ...result.warnings];
  const busy = session.phase === 'approving' || session.phase === 'done';
  const currentBackground = backgroundOf(snap.design.elements)?.color_cmyk ?? null;
  const layers = ((fabric.current?.getObjects() ?? []) as BObject[]).map((o, i) => ({ o, i })).filter(({ o }) => o.binder && o.binder.kind !== 'background').reverse();

  return (
    <div className="binder-app" dir={cfg.lang === 'ar' ? 'rtl' : 'ltr'} lang={cfg.lang}>
      <header className="binder-bar">
        <div>
          <h1>{t(`title_${cfg.template}`)}</h1>
          <p>{spec.sticker ? t('subtitle_sticker', { shape: t(`shape_${spec.sticker.shape}`), w: spec.trim_mm.w, h: spec.trim_mm.h }) : `${t(`binding_${spec.binding ?? 'ltr'}`)} · ${t('subtitle', { w: spec.trim_mm.w, h: spec.trim_mm.h })}`}</p>
        </div>
        <button type="button" className="binder-btn binder-btn--ghost" onClick={() => notify(cfg, { type: 'close' })}>
          {t('close')}
        </button>
      </header>

      <main className="binder-main">
        <section className="binder-stagewrap">
          <div className="binder-livebar">
            <div className="binder-row binder-row--tight">
              <button type="button" className="binder-btn binder-btn--ghost binder-btn--small" onClick={undo} disabled={history.current.past.length === 0}>↶ {t('undo')}</button>
              <button type="button" className="binder-btn binder-btn--ghost binder-btn--small" onClick={redo} disabled={history.current.future.length === 0}>↷ {t('redo')}</button>
            </div>
            <span className="binder-muted">{t('live_hint')}</span>
          </div>
          <div className="binder-stage" ref={stageRef} tabIndex={0} onKeyDown={onKey} aria-label={t(`title_${cfg.template}`)}>
            <div className="binder-frame" style={{ width: viewW, height: viewH }} dir="ltr">
              <canvas ref={canvasEl} />
              <img className="binder-overlay" src={overlayUrlFor(tpl)} alt="" draggable={false} />
            </div>
          </div>
          <Legend spec={spec} t={t} />
        </section>

        <aside className="binder-panel" aria-live="polite">
          <input ref={fileInput} type="file" hidden accept="image/jpeg,image/png,image/webp" onChange={(e) => { const f = e.target.files?.[0]; if (f) void addImage(f); e.target.value = ''; }} />

          {busy ? (
            <StatusCard session={session} t={t} cfg={cfg} />
          ) : (
            <>
              <div className="binder-card">
                <h2>{t('add_title')}</h2>
                <div className="binder-row">
                  <button type="button" className="binder-btn" onClick={addText} disabled={!fontsReady}>{t('add_text')}</button>
                  <button type="button" className="binder-btn" onClick={() => fileInput.current?.click()} disabled={uploadPct !== null}>
                    {uploadPct !== null ? t('uploading', { pct: uploadPct }) : t('add_image')}
                  </button>
                </div>
                {uploadPct !== null && <progress max={100} value={uploadPct} />}
                {uploadError && <p className="binder-msg binder-msg--error" role="alert">{uploadError}</p>}
              </div>

              <div className="binder-card">
                <h2>{t('bg_title')}</h2>
                <Swatches value={currentBackground} onPick={setBackgroundObject} lang={cfg.lang} allowNone noneLabel={t('bg_none')} />
              </div>

              <Selection key={`${selected ? 'sel' : 'none'}-${tick}`} selected={selected} t={t} lang={cfg.lang} design={snap.design} indexOf={snap.indexOf} objects={(fabric.current?.getObjects() ?? []) as BObject[]} onChange={touch} actions={{ remove, duplicate, forward, backward, center, turn, toggleLock, fillSheet }} />

              {layers.length > 0 && (
                <div className="binder-card">
                  <h2>{t('layers')}</h2>
                  <ul className="binder-layers">
                    {layers.map(({ o, i }) => (
                      <li key={i} className={`${o === selected ? 'is-on' : ''} ${o.binder?.locked ? 'is-locked' : ''}`}>
                        <button type="button" onClick={() => { const c = fabric.current; if (c) { c.setActiveObject(o); c.requestRenderAll(); setSelected(o); } }}>
                          <span className="binder-layer__kind">{o.binder?.kind === 'text' ? t('layer_text') : t('layer_image')}</span>
                          {o.binder?.kind === 'text' ? String((o as Textbox).text).replace(/\s+/g, ' ').slice(0, 40) : (o.binder?.source_px ? `${o.binder.source_px.w} × ${o.binder.source_px.h} px` : '')}
                          {o.binder?.locked ? ' 🔒' : ''}
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="binder-card">
                <h2>{t('checks')}</h2>
                {!hasContent ? (
                  <p className="binder-muted">{t('checks_empty_live')}</p>
                ) : issues.length === 0 ? (
                  <p className="binder-msg binder-msg--ok">{t('checks_ok')}</p>
                ) : (
                  <IssueList issues={issues} t={t} onSelect={selectElement} />
                )}
              </div>

              <FailureCard session={session} t={t} />
              <Actions session={session} t={t} canApprove={hasContent && result.ok} />
            </>
          )}
        </aside>
      </main>
    </div>
  );
}

function emptyDesign(spec: Spec): DesignJSON {
  return { template: spec.template, mode: 'live', canvas_mm: { ...spec.canvas_with_bleed_mm }, elements: [], ...(spec.sticker ? { sticker: spec.sticker } : { binding: spec.binding ?? 'ltr' }) };
}

function Swatches({ value, onPick, lang, allowNone, noneLabel }: { value: Cmyk | null; onPick: (c: Cmyk | null) => void; lang: 'en' | 'ar'; allowNone?: boolean; noneLabel?: string }) {
  return (
    <div className="binder-swatches" role="listbox" aria-label="colour">
      {allowNone && (
        <button type="button" role="option" aria-selected={value === null} title={noneLabel} className={`binder-swatch binder-swatch--none${value === null ? ' is-on' : ''}`} onClick={() => onPick(null)} />
      )}
      {PALETTE.map((s) => (
        <button
          key={s.cmyk.join(',')}
          type="button"
          role="option"
          aria-selected={!!value && sameCmyk(value, s.cmyk)}
          title={s[lang]}
          aria-label={s[lang]}
          className={`binder-swatch${value && sameCmyk(value, s.cmyk) ? ' is-on' : ''}`}
          style={{ background: cmykToRgbCss(s.cmyk) }}
          onClick={() => onPick(s.cmyk)}
        />
      ))}
    </div>
  );
}

interface SelActions {
  remove: () => void;
  duplicate: () => void;
  forward: () => void;
  backward: () => void;
  center: () => void;
  turn: () => void;
  toggleLock: () => void;
  fillSheet: () => void;
}

/** Controls for whatever is selected on the sheet. */
function Selection({ selected, t, lang, design, indexOf, objects, onChange, actions }: { selected: BObject | null; t: T; lang: 'en' | 'ar'; design: DesignJSON; indexOf: number[]; objects: BObject[]; onChange: () => void; actions: SelActions }) {
  if (!selected) {
    return (
      <div className="binder-card">
        <h2>{t('sel_text')} / {t('sel_image')}</h2>
        <p className="binder-muted">{t('sel_none')}</p>
      </div>
    );
  }

  const locked = !!selected.binder?.locked;
  const common = (
    <>
      {locked && <p className="binder-muted">{t('sel_locked')}</p>}
      <div className="binder-row">
        <button type="button" className="binder-btn binder-btn--ghost" onClick={actions.center} disabled={locked}>{t('center_it')}</button>
        <button type="button" className="binder-btn binder-btn--ghost" onClick={actions.forward}>{t('layer_up')}</button>
        <button type="button" className="binder-btn binder-btn--ghost" onClick={actions.backward}>{t('layer_down')}</button>
      </div>
      <div className="binder-row">
        <button type="button" className="binder-btn binder-btn--ghost" onClick={actions.turn} disabled={locked}>↻ {t('turn')}</button>
        <button type="button" className="binder-btn binder-btn--ghost" onClick={actions.toggleLock}>{locked ? t('unlock') : t('lock')}</button>
        <button type="button" className="binder-btn binder-btn--ghost" onClick={actions.duplicate}>{t('duplicate')}</button>
        <button type="button" className="binder-btn binder-btn--ghost binder-btn--danger" onClick={actions.remove}>{t('delete')}</button>
      </div>
    </>
  );

  if (selected.binder?.kind === 'text') {
    const tb = selected as Textbox & BObject;
    const cmyk = tb.binder?.cmyk ?? [0, 0, 0, 100];
    const weight = String(tb.fontWeight === 'bold' ? '700' : tb.fontWeight === 'normal' ? '400' : (tb.fontWeight ?? '400'));
    const set = (props: Record<string, unknown>) => {
      tb.set(props);
      tb.setCoords();
      onChange();
    };
    return (
      <div className="binder-card">
        <h2>{t('sel_text')}</h2>
        <label className="binder-field">
          <span>{t('text_content')}</span>
          <textarea rows={3} dir="auto" value={tb.text} disabled={locked} onChange={(e) => set({ text: e.target.value, direction: isRtlText(e.target.value) ? 'rtl' : 'ltr' })} />
        </label>
        <div className="binder-row">
          <label className="binder-field binder-field--grow">
            <span>{t('font')}</span>
            <select value={tb.fontFamily} disabled={locked} onChange={(e) => set({ fontFamily: e.target.value })}>
              {FONT_FAMILIES.map((f) => <option key={f} value={f}>{f}</option>)}
            </select>
          </label>
          <label className="binder-field binder-field--grow">
            <span>{t('weight')}</span>
            <select value={weight} disabled={locked} onChange={(e) => set({ fontWeight: e.target.value })}>
              {TEXT_WEIGHTS.map((w) => <option key={w} value={w}>{t(`weight_${w}`)}</option>)}
            </select>
          </label>
        </div>
        <div className="binder-row">
          <label className="binder-field binder-field--grow">
            <span>{t('text_size')}</span>
            <input type="number" min={4} max={400} step={1} value={Math.round((tb.fontSize / ptToPx(1)) * 10) / 10} disabled={locked} onChange={(e) => { const pt = Number(e.target.value); if (pt >= 4 && pt <= 400) set({ fontSize: ptToPx(pt) }); }} />
          </label>
          <div className="binder-field binder-field--grow">
            <span>{' '}</span>
            <div className="binder-seg">
              {(['left', 'center', 'right'] as const).map((a) => (
                <button key={a} type="button" className={tb.textAlign === a ? 'is-on' : ''} disabled={locked} onClick={() => set({ textAlign: a })}>{t(`align_${a}`)}</button>
              ))}
            </div>
          </div>
        </div>
        <div className="binder-field">
          <span>{t('color')}</span>
          <Swatches value={cmyk} lang={lang} onPick={(c) => { if (!c || locked) return; tb.binder = { ...(tb.binder ?? { kind: 'text' }), cmyk: c }; set({ fill: cmykToRgbCss(c) }); }} />
        </div>
        {common}
      </div>
    );
  }

  // Picture
  const objIndex = objects.indexOf(selected);
  const designIndex = indexOf.indexOf(objIndex);
  const el = designIndex >= 0 ? design.elements[designIndex] : undefined;
  const dpi = el?.type === 'image' ? Math.round(effectiveDpi(el)) : 0;
  const quality = !dpi ? '' : dpi < THRESHOLDS.blockDpi ? 'low' : dpi < THRESHOLDS.warnDpi ? 'ok' : 'good';
  return (
    <div className="binder-card">
      <h2>{t('sel_image')}</h2>
      <button type="button" className="binder-btn binder-btn--ghost" onClick={actions.fillSheet} disabled={locked}>{t('fill_canvas')}</button>
      {quality && (
        <div className={`binder-quality binder-quality--${quality}`}>
          <span>{t('quality')}</span>
          <strong>{t(`quality_${quality}`)}</strong>
          <small>{t('dpi', { dpi })}</small>
        </div>
      )}
      {common}
    </div>
  );
}
