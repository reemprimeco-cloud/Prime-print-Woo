import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { observer } from 'mobx-react-lite';
import { PolotnoContainer, WorkspaceWrap } from 'polotno';
import { Workspace } from 'polotno/canvas/workspace';
import 'polotno/ui.css';
import { effectiveDpi, panelsInCanvas, THRESHOLDS, validateDesign, type Cmyk, type DesignJSON, type Spec } from '@binder/shared';
import { ApiError, createApi, overlayUrlFor, type TemplateInfo } from '../api';
import type { EditorConfig } from '../config';
import { makeT, type T } from '../i18n';
import { Legend } from '../Legend';
import { Actions, FailureCard, IssueList, StatusCard } from '../Panels';
import { notify, useDesignSession, type Snapshot } from '../session';
import { PALETTE, sameCmyk } from './palette';
import {
  PX_PER_MM,
  PX_PER_PT,
  backgroundElement,
  cmykToCss,
  designToPolotno,
  polotnoCenter,
  polotnoOrigin,
  polotnoToDesignWithIds,
} from './polotno-map';
import { createLiveStore, FONT_FAMILIES, preloadFonts, TEXT_WEIGHTS, type PolotnoStore } from './store';

/** A Polotno element as this editor uses it (the library's own type is far wider). */
type PEl = {
  id: string;
  type: string;
  x: number;
  y: number;
  width: number;
  height: number;
  rotation: number;
  set: (attrs: Record<string, unknown>) => void;
  moveUp: () => void;
  moveDown: () => void;
  moveBottom: () => void;
  toJSON: () => Record<string, unknown>;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  [key: string]: any;
};

const pageOf = (store: PolotnoStore) => {
  const p = store.activePage;
  if (!p) throw new Error('The template page is missing');
  return p;
};

/** Live design mode (§4.1): the customer builds the cover from text, pictures and a colour, on the locked template. */
export default function LiveEditor({ cfg }: { cfg: EditorConfig }) {
  const api = useMemo(() => createApi(cfg), [cfg]);
  const t = useMemo(() => makeT(cfg.lang), [cfg.lang]);
  const [tpl, setTpl] = useState<TemplateInfo | null>(null);
  const [loadError, setLoadError] = useState(false);

  useEffect(() => {
    api.template(cfg.template, cfg.sticker).then(setTpl).catch(() => setLoadError(true));
  }, [api, cfg.template, cfg.sticker]);

  if (loadError) return <div className="binder-app binder-center" role="alert">{t('load_failed')}</div>;
  if (!tpl) return <div className="binder-app binder-center">{t('loading')}</div>;

  return <Inner cfg={cfg} api={api} t={t} tpl={tpl} />;
}

type Api = ReturnType<typeof createApi>;

function Inner({ cfg, api, t, tpl }: { cfg: EditorConfig; api: Api; t: T; tpl: TemplateInfo }) {
  const spec = tpl.spec;
  const [store, setStore] = useState<PolotnoStore | null>(null);
  const [snap, setSnap] = useState<{ design: DesignJSON; ids: string[] }>({ design: { template: spec.template, mode: 'live', canvas_mm: { ...spec.canvas_with_bleed_mm }, elements: [], ...(spec.sticker ? { sticker: spec.sticker } : {}) }, ids: [] });
  const [uploadPct, setUploadPct] = useState<number | null>(null);
  const [uploadError, setUploadError] = useState('');
  const fileInput = useRef<HTMLInputElement>(null);

  // ---- Store -----------------------------------------------------------------------------------
  useEffect(() => {
    let cancelled = false;
    (async () => {
      await preloadFonts();
      if (cancelled) return;
      const s = createLiveStore(spec, overlayUrlFor(tpl));
      if (cfg.designId) {
        try {
          const rec = await api.getDesign(cfg.designId);
          if (rec.design_json) for (const e of designToPolotno(rec.design_json)) pageOf(s).addElement(e as never);
          session.resume(rec);
        } catch {
          /* open empty */
        }
      }
      // Test hook: lets the end-to-end suite read the canvas model. Only with ?debug=1.
      if (new URLSearchParams(location.search).get('debug') === '1') (window as unknown as { __binderStore?: unknown }).__binderStore = s;
      if (!cancelled) setStore(s);
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [spec, tpl.overlay_url]); // eslint-disable-line react-hooks/exhaustive-deps

  // ---- Design snapshot, kept in step with the canvas -----------------------------------------------
  useEffect(() => {
    if (!store) return;
    let frame = 0;
    const update = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => setSnap(polotnoToDesignWithIds(store.toJSON() as never, spec)));
    };
    update();
    const off = store.on('change', update);
    return () => {
      cancelAnimationFrame(frame);
      off?.();
    };
  }, [store, spec]);

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
    if (hasContent && session.phase === 'edit') session.markDirty();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [snap.design, session.phase]);

  // ---- Adding things --------------------------------------------------------------------------------
  const frontPanel = useMemo(() => {
    const panels = panelsInCanvas(spec);
    return panels.find((p) => p.name === 'front_cover' || p.name === 'inside_front') ?? panels[0]!; // a sticker has one panel
  }, [spec]);

  const addText = () => {
    if (!store) return;
    const w = Math.min(90, frontPanel.safe.w) * PX_PER_MM;
    const arabic = cfg.lang === 'ar';
    const el = pageOf(store).addElement({
      type: 'text',
      x: (frontPanel.safe.x + frontPanel.safe.w / 2) * PX_PER_MM - w / 2,
      y: (frontPanel.safe.y + frontPanel.safe.h / 2) * PX_PER_MM - 20,
      width: w,
      text: t('default_text'),
      fontFamily: arabic ? 'Tajawal' : 'Poppins',
      fontWeight: '700',
      fontSize: 28 * PX_PER_PT,
      fill: cmykToCss([0, 0, 0, 100]),
      align: 'center',
      lineHeight: 1.2,
      letterSpacing: 0,
      custom: { cmyk: [0, 0, 0, 100] },
    } as never);
    store.selectElements([el.id]);
  };

  const addImage = async (file: File) => {
    if (!store) return;
    setUploadError('');
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return setUploadError(t('upload_type'));
    setUploadPct(0);
    try {
      const res = await api.upload(file, (f) => setUploadPct(Math.round(f * 100)));
      const w = Math.min(70, frontPanel.safe.w) * PX_PER_MM;
      const h = (w * res.source_px.h) / res.source_px.w;
      const el = pageOf(store).addElement({
        type: 'image',
        src: res.proxy_url || res.url,
        x: (frontPanel.safe.x + frontPanel.safe.w / 2) * PX_PER_MM - w / 2,
        y: (frontPanel.safe.y + frontPanel.safe.h / 2) * PX_PER_MM - h / 2,
        width: w,
        height: h,
        keepRatio: true,
        custom: { src: res.url, source_px: res.source_px },
      } as never);
      store.selectElements([el.id]);
    } catch (e) {
      const code = e instanceof ApiError ? e.code : '';
      setUploadError(code === 'binder_upload_type' ? t('upload_type') : code === 'binder_upload_size' ? t('upload_size') : code === 'binder_rate_limited' ? t('upload_rate') : t('upload_failed'));
    } finally {
      setUploadPct(null);
    }
  };

  const setBackground = (cmyk: Cmyk | null) => {
    if (!store) return;
    const page = pageOf(store);
    const existing = page.children.find((c: { custom?: { role?: string } }) => c.custom?.role === 'background') as unknown as PEl | undefined;
    if (cmyk === null) {
      if (existing) store.deleteElements([existing.id]);
      return;
    }
    if (existing) {
      existing.set({ fill: cmykToCss(cmyk), custom: { role: 'background', cmyk } });
      return;
    }
    const el = page.addElement(backgroundElement(snap.design, cmyk) as never) as unknown as PEl;
    el.moveBottom();
  };

  const currentBackground: Cmyk | null = (() => {
    const bg = snap.design.elements[0];
    return bg?.type === 'rect' ? bg.color_cmyk : null;
  })();

  const select = (designIndex: number) => {
    const id = snap.ids[designIndex];
    if (store && id) store.selectElements([id]);
  };

  if (!store) return <div className="binder-app binder-center">{t('loading')}</div>;

  const issues = [...result.errors, ...result.warnings];
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
        <section className="binder-stagewrap">
          <div className="binder-livebar">
            <UndoRedo store={store} t={t} />
            <span className="binder-muted">{t('live_hint')}</span>
          </div>
          <div className="binder-livestage" dir="ltr" style={{ aspectRatio: `${spec.canvas_with_bleed_mm.w} / ${spec.canvas_with_bleed_mm.h}` }} data-testid="live-stage">
            <PolotnoContainer style={{ width: '100%', height: '100%' }}>
              <WorkspaceWrap>
                <Workspace
                  store={store}
                  pageControlsEnabled={false}
                  backgroundColor="#eef1f4"
                  activePageBorderColor="transparent"
                  pageBorderColor="transparent"
                  paddingX={6}
                  paddingY={6}
                  components={{ Tooltip: () => null, PageControls: () => null }}
                />
              </WorkspaceWrap>
            </PolotnoContainer>
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
                  <button type="button" className="binder-btn" onClick={addText}>{t('add_text')}</button>
                  <button type="button" className="binder-btn" onClick={() => fileInput.current?.click()} disabled={uploadPct !== null}>
                    {uploadPct !== null ? t('uploading', { pct: uploadPct }) : t('add_image')}
                  </button>
                </div>
                {uploadPct !== null && <progress max={100} value={uploadPct} />}
                {uploadError && <p className="binder-msg binder-msg--error" role="alert">{uploadError}</p>}
              </div>

              <div className="binder-card">
                <h2>{t('bg_title')}</h2>
                <Swatches value={currentBackground} onPick={setBackground} lang={cfg.lang} allowNone noneLabel={t('bg_none')} />
              </div>

              <Selection store={store} t={t} lang={cfg.lang} design={snap.design} ids={snap.ids} spec={spec} />

              <div className="binder-card">
                <h2>{t('checks')}</h2>
                {!hasContent ? (
                  <p className="binder-muted">{t('checks_empty_live')}</p>
                ) : issues.length === 0 ? (
                  <p className="binder-msg binder-msg--ok">{t('checks_ok')}</p>
                ) : (
                  <IssueList issues={issues} t={t} onSelect={select} />
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

const UndoRedo = observer(({ store, t }: { store: PolotnoStore; t: T }) => (
  <div className="binder-row binder-row--tight">
    <button type="button" className="binder-btn binder-btn--ghost binder-btn--small" onClick={() => store.history.undo()} disabled={!store.history.canUndo}>↶ {t('undo')}</button>
    <button type="button" className="binder-btn binder-btn--ghost binder-btn--small" onClick={() => store.history.redo()} disabled={!store.history.canRedo}>↷ {t('redo')}</button>
  </div>
));

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
          style={{ background: cmykToCss(s.cmyk) }}
          onClick={() => onPick(s.cmyk)}
        />
      ))}
    </div>
  );
}

/** Controls for whatever is selected on the cover. */
const Selection = observer(({ store, t, lang, design, ids, spec }: { store: PolotnoStore; t: T; lang: 'en' | 'ar'; design: DesignJSON; ids: string[]; spec: Spec }) => {
  const el = store.selectedElements[0] as unknown as PEl | undefined;
  const hidden = !el || el.custom?.overlay || el.custom?.role === 'background';

  if (hidden) {
    return (
      <div className="binder-card">
        <h2>{t('sel_text')} / {t('sel_image')}</h2>
        <p className="binder-muted">{t('sel_none')}</p>
      </div>
    );
  }

  const common = (
    <>
      <div className="binder-row">
        <button type="button" className="binder-btn binder-btn--ghost" onClick={() => el.moveUp()}>{t('layer_up')}</button>
        <button type="button" className="binder-btn binder-btn--ghost" onClick={() => el.moveDown()}>{t('layer_down')}</button>
      </div>
      <div className="binder-row">
        <button type="button" className="binder-btn binder-btn--ghost" onClick={() => rotateAboutCenter(el, 90)}>↻ {t('turn')}</button>
        <button type="button" className="binder-btn binder-btn--ghost" onClick={() => pageOf(store).addElement({ ...el.toJSON(), id: undefined, x: el.x + 20, y: el.y + 20 } as never)}>{t('duplicate')}</button>
        <button type="button" className="binder-btn binder-btn--ghost binder-btn--danger" onClick={() => store.deleteElements([el.id])}>{t('delete')}</button>
      </div>
    </>
  );

  if (el.type === 'text') {
    const cmyk = (el.custom?.cmyk as Cmyk | undefined) ?? [0, 0, 0, 100];
    const weight = String(el.fontWeight === 'bold' ? '700' : el.fontWeight === 'normal' ? '400' : el.fontWeight ?? '400');
    return (
      <div className="binder-card">
        <h2>{t('sel_text')}</h2>
        <label className="binder-field">
          <span>{t('text_content')}</span>
          <textarea rows={3} dir="auto" value={el.text} onChange={(e) => el.set({ text: e.target.value })} />
        </label>
        <div className="binder-row">
          <label className="binder-field binder-field--grow">
            <span>{t('font')}</span>
            <select value={el.fontFamily} onChange={(e) => el.set({ fontFamily: e.target.value })}>
              {FONT_FAMILIES.map((f) => <option key={f} value={f}>{f}</option>)}
            </select>
          </label>
          <label className="binder-field binder-field--grow">
            <span>{t('weight')}</span>
            <select value={weight} onChange={(e) => el.set({ fontWeight: e.target.value })}>
              {TEXT_WEIGHTS.map((w) => <option key={w} value={w}>{t(`weight_${w}`)}</option>)}
            </select>
          </label>
        </div>
        <div className="binder-row">
          <label className="binder-field binder-field--grow">
            <span>{t('text_size')}</span>
            <input type="number" min={6} max={300} step={1} value={Math.round((el.fontSize / PX_PER_PT) * 10) / 10} onChange={(e) => { const pt = Number(e.target.value); if (pt >= 6 && pt <= 300) el.set({ fontSize: pt * PX_PER_PT }); }} />
          </label>
          <div className="binder-field binder-field--grow">
            <span>{' '}</span>
            <div className="binder-seg">
              {(['left', 'center', 'right'] as const).map((a) => (
                <button key={a} type="button" className={el.align === a ? 'is-on' : ''} onClick={() => el.set({ align: a })}>{t(`align_${a}`)}</button>
              ))}
            </div>
          </div>
        </div>
        <div className="binder-field">
          <span>{t('color')}</span>
          <Swatches value={cmyk} lang={lang} onPick={(c) => c && el.set({ fill: cmykToCss(c), custom: { ...(el.custom ?? {}), cmyk: c } })} />
        </div>
        {common}
      </div>
    );
  }

  // Picture
  const idx = ids.indexOf(el.id);
  const dEl = idx >= 0 ? design.elements[idx] : undefined;
  const dpi = dEl?.type === 'image' ? Math.round(effectiveDpi(dEl)) : 0;
  const quality = !dpi ? '' : dpi < THRESHOLDS.blockDpi ? 'low' : dpi < THRESHOLDS.warnDpi ? 'ok' : 'good';
  return (
    <div className="binder-card">
      <h2>{t('sel_image')}</h2>
      <button type="button" className="binder-btn binder-btn--ghost" onClick={() => coverCanvas(el, spec)}>{t('fill_canvas')}</button>
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
});

/** Turn an element by `by` degrees about its centre (Polotno pivots on the corner). */
function rotateAboutCenter(el: PEl, by: number): void {
  const c = polotnoCenter(el);
  const rotation = (((el.rotation + by) % 360) + 360) % 360;
  const o = polotnoOrigin(c, el.width, el.height, rotation);
  el.set({ rotation, x: o.x, y: o.y });
}

/** Scale a picture (keeping its proportions) until it covers the whole canvas, bleed included. */
function coverCanvas(el: PEl, spec: Spec): void {
  const px = el.custom?.source_px as { w: number; h: number } | undefined;
  if (!px) return;
  const cw = spec.canvas_with_bleed_mm.w * PX_PER_MM;
  const ch = spec.canvas_with_bleed_mm.h * PX_PER_MM;
  const scale = Math.max(cw / px.w, ch / px.h);
  const w = px.w * scale;
  const h = px.h * scale;
  el.set({ rotation: 0, width: w, height: h, x: (cw - w) / 2, y: (ch - h) / 2 });
}
