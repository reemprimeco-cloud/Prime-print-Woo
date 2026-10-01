import { useCallback, useEffect, useMemo, useRef, useState, type ReactElement } from 'react';
import { Canvas, Control, FabricImage, Path, Rect, Textbox, type FabricObject, type TPointerEventInfo } from 'fabric';
import {
  cmykToRgbCss,
  customCutPath,
  customOverlayDataUrl,
  effectiveDpi,
  FONT_LIST,
  panelsInCanvas,
  SHAPE_KINDS,
  STICKER,
  stickerOutlines,
  svgPathData,
  THRESHOLDS,
  validateDesign,
  type Cmyk,
  type DesignElement,
  type DesignJSON,
  type PathCmd,
  type ShapeKind,
  type Spec,
} from '@binder/shared';
import { ApiError, createApi, overlayUrlFor, type TemplateInfo } from '../api';
import type { EditorConfig } from '../config';
import { makeT, type T } from '../i18n';
import { Legend } from '../Legend';
import { FailureCard, IssueList, StatusCard } from '../Panels';
import { notify, useDesignSession, type Snapshot } from '../session';
import { cmykToHex, hexToCmyk, QUICK_COLOURS, rgbToHex, sameCmyk } from './color';
import {
  PX_PER_MM,
  backgroundProps,
  homePanel,
  imageToProps,
  isRtlText,
  isSpineRect,
  objectsToElements,
  ptToPx,
  shapePathData,
  shapeToProps,
  spineBox,
  textToProps,
  type BinderMeta,
  type FabricLike,
} from './fabric-map';
import { STARTERS } from './templates';

/** A Fabric object with the editor's own bookkeeping on it. */
type BObject = FabricObject & { binder?: BinderMeta };
type Tool = 'text' | 'image' | 'shapes' | 'colours' | 'templates';

const TOOLS_EXTRA: Array<{ id: Tool; icon: ReactElement }> = [
  { id: 'shapes', icon: <path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.8z" /> },
  { id: 'colours', icon: <><circle cx="12" cy="12" r="8" /><path d="M12 4a8 8 0 0 0 0 16c1.2 0 1.8-.8 1.8-1.7 0-1.6-1.4-1.8-1.4-3.1 0-1 .8-1.7 1.8-1.7H17a3 3 0 0 0 3-3C20 6.5 16.4 4 12 4z" /></> },
  { id: 'templates', icon: <><rect x="4" y="4" width="7" height="7" rx="1" /><rect x="13" y="4" width="7" height="7" rx="1" /><rect x="4" y="13" width="7" height="7" rx="1" /><rect x="13" y="13" width="7" height="7" rx="1" /></> },
];
type PickTarget = 'selected' | 'background' | 'spine';

const TEXT_WEIGHTS = ['400', '500', '700'] as const;
const MAX_VIEW_PX = 1400;
const HISTORY_LIMIT = 60;
const CONTROL_STYLE = {
  transparentCorners: false,
  cornerColor: '#ffffff',
  cornerStrokeColor: '#10254A',
  cornerSize: 11,
  borderColor: '#2A7DE1',
  borderScaleFactor: 1.5,
  padding: 0,
} as const;

/** The round red delete button drawn on the selected item's top-right corner. */
function renderDeleteControl(ctx: CanvasRenderingContext2D, left: number, top: number) {
  const r = 13;
  ctx.save();
  ctx.translate(left, top);
  ctx.shadowColor = 'rgba(8, 19, 42, 0.25)';
  ctx.shadowBlur = 4;
  ctx.beginPath();
  ctx.arc(0, 0, r, 0, Math.PI * 2);
  ctx.fillStyle = '#ffffff';
  ctx.fill();
  ctx.shadowBlur = 0;
  ctx.lineWidth = 1.5;
  ctx.strokeStyle = '#b23a38';
  ctx.stroke();
  // Trash can: lid, handle, body with two lines.
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.beginPath();
  ctx.moveTo(-6, -4.5); ctx.lineTo(6, -4.5);
  ctx.moveTo(-2, -4.5); ctx.lineTo(-2, -6.5); ctx.lineTo(2, -6.5); ctx.lineTo(2, -4.5);
  ctx.moveTo(-4.5, -2.5); ctx.lineTo(-3.8, 6.5); ctx.lineTo(3.8, 6.5); ctx.lineTo(4.5, -2.5);
  ctx.moveTo(-1.3, -0.5); ctx.lineTo(-1.1, 4.3);
  ctx.moveTo(1.3, -0.5); ctx.lineTo(1.1, 4.3);
  ctx.stroke();
  ctx.restore();
}

/**
 * The designer (binders and stickers, upload and design alike): a tool rail and
 * its panel on the start side, the sheet in the middle, the order, the
 * selected item and the layers on the end side. Fabric.js, no licence.
 */
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

  return <Studio cfg={cfg} api={api} t={t} tpl={tpl} />;
}

type Api = ReturnType<typeof createApi>;

function Studio({ cfg, api, t, tpl }: { cfg: EditorConfig; api: Api; t: T; tpl: TemplateInfo }) {
  const spec = tpl.spec;
  const W = spec.canvas_with_bleed_mm.w * PX_PER_MM; // working px (96 per inch)
  const H = spec.canvas_with_bleed_mm.h * PX_PER_MM;
  const isBinder = !spec.sticker;
  // A UV DTF transfer: a transparent artboard of the typed size, text and pictures only.
  const isTransfer = spec.template === 'uvdtf';
  // A custom-shape sticker: transparent sheet, and the cut line is traced around whatever is placed.
  const isCustom = spec.template === 'sticker' && spec.sticker?.shape === 'custom';
  const transparentSheet = isTransfer || isCustom;

  const stageRef = useRef<HTMLDivElement>(null);
  const topRef = useRef<HTMLElement>(null);
  const selRef = useRef<HTMLElement>(null);
  const canvasEl = useRef<HTMLCanvasElement>(null);
  const fontWarm = useRef<HTMLDivElement>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  const fabric = useRef<Canvas | null>(null);
  const applying = useRef(false);
  const history = useRef<{ past: DesignJSON[]; future: DesignJSON[] }>({ past: [], future: [] });
  const firstUpload = useRef(cfg.mode === 'upload');

  const [tool, setTool] = useState<Tool>(cfg.mode === 'upload' ? 'image' : 'text');
  const [stageWidth, setStageWidth] = useState(0);
  const [snap, setSnap] = useState<{ design: DesignJSON; indexOf: number[] }>({ design: emptyDesign(spec, cfg), indexOf: [] });
  const [selected, setSelected] = useState<BObject | null>(null);
  const [, setTick] = useState(0);
  const [uploadPct, setUploadPct] = useState<number | null>(null);
  const [uploadError, setUploadError] = useState('');
  const [dragging, setDragging] = useState(false);
  const [fontsReady, setFontsReady] = useState(false);
  const [picking, setPicking] = useState<PickTarget | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const pickingRef = useRef<PickTarget | null>(null);
  pickingRef.current = picking;
  const bump = () => setTick((n) => n + 1);

  // ---- Fonts ------------------------------------------------------------------------------------------------
  useEffect(() => {
    const loads = [document.fonts.load('700 24px "Tajawal"', 'Aa الصف'), document.fonts.load('700 24px "Poppins"', 'Aa')];
    Promise.allSettled(loads).then(() => setFontsReady(true));
  }, []);
  // Then every other face, one at a time in the background: WebKit's canvas
  // has been seen sticking with the fallback for a family it first met before
  // that family was loaded, so by the time a font is chosen it is already there.
  useEffect(() => {
    if (!fontsReady) return;
    let stop = false;
    const w = window as unknown as { requestIdleCallback?: (f: () => void) => void };
    const idle = (fn: () => void) => (w.requestIdleCallback ? w.requestIdleCallback(fn) : window.setTimeout(fn, 400));
    idle(() => {
      void (async () => {
        for (const f of FONT_LIST) {
          if (stop) return;
          for (const w of ['400', '700']) await ensureFontRef.current(f.name, w, f.script === 'ar' ? 'عيد ميلاد' : 'Aa');
        }
      })();
    });
    return () => {
      stop = true;
    };
  }, [fontsReady]);
  /**
   * Make a face usable by the canvas. document.fonts.load() is enough for
   * Chromium, but WebKit (Safari, every iPhone browser) hands the canvas a
   * web font only once the page has actually drawn text in it — the font
   * menu's own preview does not count. So each face is also set on a hidden
   * element, and the canvas is told to repaint once the browser has laid it
   * out (Reem, 2026-10-01: "the Arabic font is not changing").
   */
  const ensureFont = async (family: string, weight: string, sample: string) => {
    const text = sample || 'Aa الصف';
    const warm = fontWarm.current;
    const key = `${family}|${weight}`;
    if (warm && !warm.querySelector(`[data-face="${CSS.escape(key)}"]`)) {
      const span = document.createElement('span');
      span.dataset.face = key;
      span.style.fontFamily = `"${family}"`;
      span.style.fontWeight = weight;
      span.textContent = text;
      warm.appendChild(span);
    }
    try {
      // Never wait on this forever: WebKit has been seen leaving the promise pending.
      await Promise.race([document.fonts.load(`${weight} 24px "${family}"`, text), new Promise((r) => setTimeout(r, 1500))]);
    } catch {
      /* the fallback face is used */
    }
    await new Promise<void>((r) => requestAnimationFrame(() => requestAnimationFrame(() => r())));
    fabric.current?.getObjects().forEach((o) => {
      const tb = o as Textbox & BObject & { _cacheCanvas?: unknown; _cacheContext?: unknown };
      if (tb.binder?.kind === 'text' && tb.fontFamily === family) {
        // Drop the object's cached bitmap outright, so the next paint measures and draws with the loaded face.
        (tb as { _cacheCanvas?: unknown })._cacheCanvas = undefined;
        (tb as { _cacheContext?: unknown })._cacheContext = undefined;
        tb.initDimensions?.();
        tb.setCoords();
        tb.dirty = true;
      }
    });
    fabric.current?.requestRenderAll();
  };
  const ensureFontRef = useRef(ensureFont);
  ensureFontRef.current = ensureFont;

  // ---- Design snapshot ------------------------------------------------------------------------------------------
  const readDesign = useCallback((): { design: DesignJSON; indexOf: number[] } => {
    const objects = (fabric.current?.getObjects() ?? []) as BObject[];
    const { elements, indexOf } = objectsToElements(objects as unknown as FabricLike[], spec);
    const d = emptyDesign(spec, cfg);
    const onlyImage = elements.length === 1 && elements[0]!.type === 'image';
    return { design: { ...d, mode: cfg.mode === 'upload' && onlyImage ? 'upload' : 'live', elements }, indexOf };
  }, [spec, cfg]);

  const commit = useCallback(
    (record = true) => {
      const next = readDesign();
      setSnap((prev) => {
        if (record && !applying.current && JSON.stringify(prev.design.elements) !== JSON.stringify(next.design.elements)) {
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
    const c = new Canvas(canvasEl.current, { width: W, height: H, backgroundColor: transparentSheet ? '' : '#ffffff', preserveObjectStacking: true, selection: false, enableRetinaScaling: true });
    fabric.current = c;
    // Test hook for the browser checks; only with ?debug=1.
    if (new URLSearchParams(location.search).get('debug') === '1') (window as unknown as { __studioCanvas?: Canvas }).__studioCanvas = c;

    // A face that arrives after the text was first painted (a 400 KB font on a
    // real connection) would otherwise never show: the browser keeps the
    // fallback it drew, and the object's cached bitmap is reused. Whenever any
    // font finishes loading, every text object is measured and painted again.
    const onFontsLoaded = () => {
      c.getObjects().forEach((o) => {
        const tb = o as Textbox & BObject;
        if (tb.binder?.kind !== 'text') return;
        (tb as { _cacheCanvas?: unknown })._cacheCanvas = undefined;
        (tb as { _cacheContext?: unknown })._cacheContext = undefined;
        tb.initDimensions?.();
        tb.setCoords();
        tb.dirty = true;
      });
      c.requestRenderAll();
    };
    document.fonts.addEventListener('loadingdone', onFontsLoaded);

    const onSel = () => {
      const o = (c.getActiveObject() as BObject | undefined) ?? null;
      setSelected(o && o.binder && o.binder.kind !== 'background' && o.binder.kind !== 'spine' ? o : null);
    };
    c.on('selection:created', onSel);
    c.on('selection:updated', onSel);
    c.on('selection:cleared', () => setSelected(null));
    c.on('object:modified', (e) => {
      const o = e.target as BObject;
      if (o.binder?.kind === 'text' && (o.scaleX !== 1 || o.scaleY !== 1)) {
        const tb = o as Textbox;
        tb.set({ fontSize: tb.fontSize * o.scaleY, width: tb.width * o.scaleX, scaleX: 1, scaleY: 1 });
        tb.setCoords();
      }
      bump();
      commit();
    });
    c.on('text:changed', (e) => {
      const tb = e.target as Textbox;
      tb.set({ direction: isRtlText(tb.text) ? 'rtl' : 'ltr' });
      bump();
      commit(false);
    });
    c.on('text:editing:exited', () => commit());
    c.on('mouse:down', (opt: TPointerEventInfo) => {
      const target = pickingRef.current;
      if (!target) return;
      const pt = c.getViewportPoint(opt.e);
      const r = c.getRetinaScaling();
      const px = c.lowerCanvasEl.getContext('2d')?.getImageData(Math.round(pt.x * r), Math.round(pt.y * r), 1, 1).data;
      setPicking(null);
      c.skipTargetFind = false;
      if (px) applyColourRef.current(target, hexToCmyk(rgbToHex(px[0]!, px[1]!, px[2]!)));
    });

    return () => {
      document.fonts.removeEventListener('loadingdone', onFontsLoaded);
      void c.dispose();
      fabric.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [spec]);

  useEffect(() => {
    if (fabric.current) fabric.current.skipTargetFind = !!picking;
  }, [picking]);

  // ---- Fit to the stage (zoom only) ------------------------------------------------------------------------------------
  useEffect(() => {
    const node = stageRef.current;
    if (!node || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(([entry]) => entry && setStageWidth(Math.floor(entry.contentRect.width)));
    ro.observe(node);
    setStageWidth(Math.floor(node.getBoundingClientRect().width));
    return () => ro.disconnect();
  }, []);
  // On a phone the sheet stays pinned at the top while the tools scroll under it,
  // so it gets a bit under half the screen height.
  const narrow = typeof window !== 'undefined' && window.innerWidth <= 820;
  const maxH = typeof window !== 'undefined' ? (narrow ? Math.max(200, window.innerHeight * 0.4) : Math.max(260, window.innerHeight - 260)) : 700;
  // Stage padding plus the sheet's frame padding on both sides.
  const chrome = (stageWidth || 800) < 700 ? 40 : 72;
  const viewW = Math.max(220, Math.min((stageWidth || 800) - chrome, MAX_VIEW_PX, (maxH * W) / H));
  const zoom = viewW / W;
  const viewH = Math.round(H * zoom);
  useEffect(() => {
    const c = fabric.current;
    if (!c) return;
    c.setDimensions({ width: Math.round(viewW), height: viewH });
    c.setZoom(zoom);
    c.requestRenderAll();
  }, [viewW, viewH, zoom]);

  // ---- Building objects ------------------------------------------------------------------------------------------------------
  const removeRef = useRef<(o: BObject) => void>(() => undefined);
  const deleteControl = useMemo(
    () =>
      new Control({
        // Under the item's bottom-centre: a wide text box or a picture reaching the side
        // of the sheet would push a corner button off the canvas.
        x: 0,
        y: 0.5,
        offsetX: 0,
        offsetY: 24,
        sizeX: 26,
        sizeY: 26,
        touchSizeX: 40,
        touchSizeY: 40,
        cursorStyle: 'pointer',
        withConnection: false,
        render: renderDeleteControl,
        mouseUpHandler: (_e, transform) => {
          removeRef.current(transform.target as BObject);
          return true;
        },
      }),
    [],
  );

  const style = (o: BObject, kind: BinderMeta['kind']) => {
    o.set({ ...CONTROL_STYLE, originX: 'center', originY: 'center', lockScalingFlip: true });
    // Every item gets the 🗑️ button under it (hidden with the other controls when locked).
    o.controls = { ...o.controls, deleteControl };
    if (kind === 'image') {
      o.set({ lockUniScaling: true });
      o.setControlsVisibility({ ml: false, mr: false, mt: false, mb: false });
    } else if (kind === 'text') {
      o.setControlsVisibility({ mt: false, mb: false });
    }
  };
  const applyLock = (o: BObject) => {
    const locked = !!o.binder?.locked;
    o.set({ lockMovementX: locked, lockMovementY: locked, lockRotation: locked, lockScalingX: locked, lockScalingY: locked, hasControls: !locked });
    if (o.binder?.kind === 'text') (o as Textbox).set({ editable: !locked });
  };

  const addText = async (el: Extract<DesignElement, { type: 'text' }>) => {
    await ensureFont(el.font, el.weight, el.text);
    const p = textToProps(el);
    const tb = new Textbox(el.text, {
      left: p.left, top: p.top, width: p.width, angle: p.angle, fontFamily: p.fontFamily, fontWeight: p.fontWeight,
      fontSize: p.fontSize, textAlign: p.textAlign, lineHeight: p.lineHeight, direction: p.direction, fill: cmykToRgbCss(el.color_cmyk),
    }) as Textbox & BObject;
    tb.binder = p.binder;
    style(tb, 'text');
    fabric.current?.add(tb);
    return tb;
  };
  const addImage = async (el: Extract<DesignElement, { type: 'image' }>, displaySrc: string) => {
    const img = (await FabricImage.fromURL(displaySrc, { crossOrigin: 'anonymous' })) as FabricImage & BObject;
    const p = imageToProps(el, { width: img.width, height: img.height });
    img.set({ left: p.left, top: p.top, scaleX: p.scaleX, scaleY: p.scaleY, angle: p.angle, opacity: el.opacity ?? 1 });
    img.binder = p.binder;
    style(img, 'image');
    fabric.current?.add(img);
    return img;
  };
  const addShape = (el: Extract<DesignElement, { type: 'shape' }>) => {
    const p = shapeToProps(el);
    const path = new Path(p.path, { fill: cmykToRgbCss(el.color_cmyk), opacity: p.opacity, strokeWidth: 0 }) as Path & BObject;
    path.set({ left: p.left, top: p.top, angle: p.angle, scaleX: p.width / (path.width || 1), scaleY: p.height / (path.height || 1) });
    path.binder = p.binder;
    style(path, 'shape');
    fabric.current?.add(path);
    return path;
  };
  const fillObject = (kind: 'background' | 'spine', cmyk: Cmyk) => {
    const c = fabric.current!;
    let box = { left: W / 2, top: H / 2, width: W, height: H };
    if (kind === 'spine') {
      const b = spineBox(spec)!;
      box = { left: (b.x + b.w / 2) * PX_PER_MM, top: H / 2, width: b.w * PX_PER_MM, height: H };
    } else {
      const p = backgroundProps(spec, cmyk);
      box = { left: p.left, top: p.top, width: p.width, height: p.height };
    }
    const r = new Rect({ ...box, originX: 'center', originY: 'center', fill: cmykToRgbCss(cmyk), selectable: false, evented: false, hoverCursor: 'default' }) as Rect & BObject;
    r.binder = { kind, cmyk };
    c.add(r);
    return r;
  };
  /** Background at the very bottom, the spine colour right above it. */
  const restack = () => {
    const c = fabric.current!;
    const objs = c.getObjects() as BObject[];
    const spine = objs.find((o) => o.binder?.kind === 'spine');
    const bg = objs.find((o) => o.binder?.kind === 'background');
    if (spine) c.sendObjectToBack(spine);
    if (bg) c.sendObjectToBack(bg);
  };
  const setFill = (kind: 'background' | 'spine', cmyk: Cmyk | null) => {
    const c = fabric.current;
    if (!c) return;
    const existing = (c.getObjects() as BObject[]).find((o) => o.binder?.kind === kind);
    if (cmyk === null) {
      if (existing) c.remove(existing);
    } else if (existing) {
      existing.set({ fill: cmykToRgbCss(cmyk) });
      existing.binder = { kind, cmyk };
    } else {
      fillObject(kind, cmyk);
    }
    restack();
    c.requestRenderAll();
    bump();
    commit();
  };

  const loadDesign = useCallback(
    async (design: DesignJSON) => {
      const c = fabric.current;
      if (!c) return;
      applying.current = true;
      try {
        c.discardActiveObject();
        for (const o of [...c.getObjects()]) c.remove(o);
        for (const el of design.elements) {
          if (el.type === 'rect') fillObject(isSpineRect(el, spec) ? 'spine' : 'background', el.color_cmyk);
          else if (el.type === 'text') await addText(el);
          else if (el.type === 'image') await addImage(el, el.src);
          else if (el.type === 'shape') addShape(el);
        }
        restack();
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

  // ---- Custom shape: the cut line follows the artwork ---------------------------------------------------------------------
  // Traced from the canvas itself (contour.ts, the same code the render service
  // runs on its own capture), a border outside the artwork, a moment after each change.
  const [cutPath, setCutPath] = useState<PathCmd[]>([]);
  const traceCut = useCallback(() => {
    const c = fabric.current;
    if (!c || !isCustom) return;
    if (c.getObjects().length === 0) return setCutPath([]);
    const long = Math.max(c.getWidth(), c.getHeight());
    const el = c.toCanvasElement(Math.min(640, Math.max(long, spec.canvas_with_bleed_mm.w * 4)) / long);
    const ctx = el.getContext('2d');
    if (!ctx) return;
    const { data } = ctx.getImageData(0, 0, el.width, el.height);
    const alpha = new Uint8Array(el.width * el.height);
    for (let i = 0; i < alpha.length; i++) alpha[i] = data[i * 4 + 3]!;
    setCutPath(customCutPath(alpha, el.width, el.height, spec.canvas_with_bleed_mm, STICKER.custom_border_mm, { x: spec.bleed_mm, y: spec.bleed_mm, w: spec.trim_mm.w, h: spec.trim_mm.h }));
  }, [isCustom, spec]);
  useEffect(() => {
    if (!isCustom) return;
    const id = window.setTimeout(traceCut, 250);
    return () => window.clearTimeout(id);
  }, [isCustom, snap.design, traceCut]);

  // ---- Checks, session, reopening ------------------------------------------------------------------------------------------
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

  // ---- Phones: keep the pinned sheet's height known, and bring the selected item's settings up under it --------------------
  const [topH, setTopH] = useState(57);
  useEffect(() => {
    const node = topRef.current;
    if (!node || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(() => setTopH(Math.round(node.getBoundingClientRect().height)));
    ro.observe(node);
    return () => ro.disconnect();
  }, []);
  useEffect(() => {
    if (!selected || !narrow) return;
    const sec = selRef.current;
    const stage = stageRef.current;
    if (!sec || !stage) return;
    const gap = sec.getBoundingClientRect().top - stage.getBoundingClientRect().bottom;
    if (Math.abs(gap) > 4) window.scrollBy({ top: gap, behavior: 'smooth' });
    // Only when the selection changes, not on every edit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected]);

  // ---- Adding --------------------------------------------------------------------------------------------------------------------------
  const home = useMemo(() => homePanel(spec), [spec]);
  const select = (o: BObject) => {
    const c = fabric.current;
    if (!c) return;
    c.setActiveObject(o);
    c.requestRenderAll();
    setSelected(o);
  };

  const newText = async (heading: boolean) => {
    const arabic = cfg.lang === 'ar';
    // A heading is about a tenth of the panel height (≈ 30 mm on a binder cover, 9 mm on a 10 cm sticker).
    const sizeMm = Math.max(3, Math.min(home.h * (heading ? 0.1 : 0.05), home.w * (heading ? 0.09 : 0.045)));
    const wMm = home.w * 0.9;
    const tb = await addText({
      type: 'text', text: t(heading ? 'heading_text' : 'body_text'), font: arabic ? (heading ? 'Cairo' : 'Tajawal') : heading ? 'Montserrat' : 'Poppins',
      size_pt: Math.round(sizeMm * (72 / 25.4) * 10) / 10, weight: heading ? '700' : '400', color_cmyk: [0, 0, 0, 100],
      x_mm: home.x + (home.w - wMm) / 2, y_mm: home.y + home.h / 2 - sizeMm * 0.7, w_mm: wMm, h_mm: sizeMm * 1.4, align: 'center', rtl: arabic,
    });
    select(tb);
    commit();
  };

  const newShape = (shape: ShapeKind) => {
    const side = Math.min(home.w, home.h) * 0.3;
    const w = shape === 'rectangle' ? side * 1.5 : side;
    const s = addShape({ type: 'shape', shape, x_mm: home.x + (home.w - w) / 2, y_mm: home.y + (home.h - side) / 2, w_mm: w, h_mm: side, color_cmyk: [0, 25, 85, 10] });
    select(s);
    commit();
  };

  const upload = async (file: File) => {
    const c = fabric.current;
    if (!c) return;
    setUploadError('');
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return setUploadError(t('upload_type'));
    setUploadPct(0);
    try {
      const res = await api.upload(file, (f) => setUploadPct(Math.round(f * 100)));
      let el: Extract<DesignElement, { type: 'image' }>;
      if (firstUpload.current && !hasContent) {
        // Upload mode: the first picture fills the whole sheet, like a finished design. A
        // transfer is never cropped: its picture fits inside the artboard instead.
        const scale = (isTransfer ? Math.min : Math.max)(spec.canvas_with_bleed_mm.w / res.source_px.w, spec.canvas_with_bleed_mm.h / res.source_px.h);
        const w = res.source_px.w * scale;
        const h = res.source_px.h * scale;
        el = { type: 'image', src: res.url, x_mm: (spec.canvas_with_bleed_mm.w - w) / 2, y_mm: (spec.canvas_with_bleed_mm.h - h) / 2, w_mm: w, h_mm: h, rotation_deg: 0, source_px: res.source_px };
      } else {
        const wMm = Math.min(home.w * 0.6, 90);
        const hMm = (wMm * res.source_px.h) / res.source_px.w;
        el = { type: 'image', src: res.url, x_mm: home.x + (home.w - wMm) / 2, y_mm: home.y + (home.h - hMm) / 2, w_mm: wMm, h_mm: hMm, rotation_deg: 0, source_px: res.source_px };
      }
      firstUpload.current = false;
      const img = await addImage(el, res.proxy_url || res.url);
      select(img);
      commit();
    } catch (e) {
      const code = e instanceof ApiError ? e.code : '';
      setUploadError(code === 'binder_upload_type' ? t('upload_type') : code === 'binder_upload_size' ? t('upload_size') : code === 'binder_rate_limited' ? t('upload_rate') : t('upload_failed'));
    } finally {
      setUploadPct(null);
    }
  };

  const applyTemplate = async (id: string) => {
    const tplDef = STARTERS.find((s) => s.id === id);
    if (!tplDef) return;
    history.current.past.push(snap.design);
    history.current.future = [];
    await loadDesign({ ...emptyDesign(spec, cfg), elements: tplDef.build(spec) });
  };

  // ---- Acting on the selection -----------------------------------------------------------------------------------------------------------
  const touch = () => {
    fabric.current?.requestRenderAll();
    bump();
    commit();
  };
  const withSel = (fn: (o: BObject, c: Canvas) => void) => {
    const c = fabric.current;
    if (!c || !selected) return;
    fn(selected, c);
    selected.setCoords();
    touch();
  };
  const remove = (o: BObject | null = selected) => {
    const c = fabric.current;
    if (!c || !o) return;
    c.remove(o);
    if (o === selected) c.discardActiveObject();
    touch();
  };
  removeRef.current = (o) => remove(o);
  const duplicate = () =>
    void (async () => {
      const c = fabric.current;
      if (!c || !selected) return;
      const copy = (await selected.clone(['binder'])) as BObject;
      copy.set({ left: selected.left + 12 / zoom, top: selected.top + 12 / zoom });
      copy.binder = { ...(selected.binder ?? { kind: 'text' }), locked: false };
      style(copy, copy.binder.kind);
      c.add(copy);
      select(copy);
      touch();
    })();
  const forward = () => withSel((o, c) => c.bringObjectForward(o));
  const backward = () =>
    withSel((o, c) => {
      c.sendObjectBackwards(o);
      restack();
    });
  const toggleLock = (o: BObject | null = selected) => {
    if (!o) return;
    o.binder = { ...(o.binder ?? { kind: 'text' }), locked: !o.binder?.locked };
    applyLock(o);
    touch();
  };

  /** Axis-aligned box of an object in canvas px (centre origin, any rotation). */
  const boxOf = (o: BObject) => {
    const w = o.width * o.scaleX;
    const h = o.height * o.scaleY;
    const a = ((o.angle ?? 0) * Math.PI) / 180;
    const bw = Math.abs(w * Math.cos(a)) + Math.abs(h * Math.sin(a));
    const bh = Math.abs(w * Math.sin(a)) + Math.abs(h * Math.cos(a));
    return { x: o.left - bw / 2, y: o.top - bh / 2, w: bw, h: bh };
  };
  /** The safe box (px) of the panel the object's centre sits on. */
  const panelOf = (o: BObject) => {
    const cx = o.left / PX_PER_MM;
    const panels = panelsInCanvas(spec);
    const p = panels.find((q) => cx >= q.trim.x && cx <= q.trim.x + q.trim.w) ?? panels[0]!;
    return { x: p.safe.x * PX_PER_MM, y: p.safe.y * PX_PER_MM, w: p.safe.w * PX_PER_MM, h: p.safe.h * PX_PER_MM, trim: p.trim };
  };
  const align = (how: 'left' | 'hcenter' | 'right' | 'top' | 'vmiddle' | 'bottom') =>
    withSel((o) => {
      if (o.binder?.locked) return;
      const b = boxOf(o);
      const p = panelOf(o);
      if (how === 'left') o.set({ left: o.left + (p.x - b.x) });
      if (how === 'right') o.set({ left: o.left + (p.x + p.w - (b.x + b.w)) });
      if (how === 'hcenter') o.set({ left: p.x + p.w / 2 });
      if (how === 'top') o.set({ top: o.top + (p.y - b.y) });
      if (how === 'bottom') o.set({ top: o.top + (p.y + p.h - (b.y + b.h)) });
      if (how === 'vmiddle') o.set({ top: p.y + p.h / 2 });
    });
  /** Cover the whole sheet, or the panel the picture sits on (to the bleed / turn-in edge). */
  const fillWith = (target: 'sheet' | 'panel') =>
    withSel((o) => {
      const px = o.binder?.source_px;
      if (!px) return;
      let box = { x: 0, y: 0, w: W, h: H };
      if (target === 'panel') {
        const tr = panelOf(o).trim;
        const first = tr.x <= spec.bleed_mm + spec.turn_in_mm + 0.01;
        const last = tr.x + tr.w >= spec.canvas_with_bleed_mm.w - spec.bleed_mm - spec.turn_in_mm - 0.01;
        const x0 = first ? 0 : tr.x;
        const x1 = last ? spec.canvas_with_bleed_mm.w : tr.x + tr.w;
        box = { x: x0 * PX_PER_MM, y: 0, w: (x1 - x0) * PX_PER_MM, h: H };
      }
      const scale = Math.max(box.w / px.w, box.h / px.h);
      o.set({ angle: 0, scaleX: (px.w * scale) / o.width, scaleY: (px.h * scale) / o.height, left: box.x + box.w / 2, top: box.y + box.h / 2 });
    });

  const applyColour = (target: PickTarget, cmyk: Cmyk) => {
    if (target === 'background' || target === 'spine') return setFill(target, cmyk);
    const o = selected;
    if (!o || o.binder?.locked) return;
    o.binder = { ...(o.binder ?? { kind: 'text' }), cmyk };
    o.set({ fill: cmykToRgbCss(cmyk) });
    touch();
  };
  const applyColourRef = useRef(applyColour);
  applyColourRef.current = applyColour;

  const onKey = (ev: React.KeyboardEvent) => {
    const c = fabric.current;
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'z') {
      ev.preventDefault();
      return ev.shiftKey ? redo() : undo();
    }
    const o = c?.getActiveObject() as (BObject & { isEditing?: boolean }) | undefined;
    if (!c || !o || o.isEditing || o.binder?.locked) return;
    const step = (ev.shiftKey ? 10 : 1) / zoom;
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

  // ---- Preview of the print file (client-side, artwork only) --------------------------------------------------------------------------------
  const openPreview = () => {
    const c = fabric.current;
    if (!c) return;
    c.discardActiveObject();
    c.requestRenderAll();
    const k = 2;
    const src = c.toDataURL({ format: 'png', multiplier: k });
    const img = new Image();
    img.onload = () => {
      const out = document.createElement('canvas');
      out.width = img.width;
      out.height = img.height;
      const ctx = out.getContext('2d')!;
      const perMm = img.width / spec.canvas_with_bleed_mm.w;
      ctx.drawImage(img, 0, 0);
      ctx.save();
      ctx.scale(perMm, perMm);
      ctx.lineWidth = 0.4;
      ctx.strokeStyle = '#EC008C';
      if (isTransfer) {
        // Nothing is cut: no line.
      } else if (isCustom) {
        if (cutPath.length) ctx.stroke(new Path2D(svgPathData(cutPath)));
      } else if (spec.sticker) {
        ctx.stroke(new Path2D(svgPathData(stickerOutlines(spec).cut)));
      } else {
        ctx.strokeRect(spec.bleed_mm, spec.bleed_mm, spec.trim_mm.w, spec.trim_mm.h);
      }
      ctx.restore();
      setPreview(out.toDataURL('image/png'));
    };
    img.src = src;
  };

  const saveAndBack = async () => {
    try {
      if (hasContent && session.phase === 'edit') await session.saveNow();
    } catch {
      /* the draft is kept locally in the session; leaving is still allowed */
    }
    notify(cfg, { type: 'close' });
  };

  // ---- Render ---------------------------------------------------------------------------------------------------------------------------------------
  const issues = [...result.errors, ...result.warnings];
  const busy = session.phase === 'approving' || session.phase === 'done';
  const objects = (fabric.current?.getObjects() ?? []) as BObject[];
  const bgCmyk = (objects.find((o) => o.binder?.kind === 'background')?.binder?.cmyk ?? null) as Cmyk | null;
  const spineCmyk = (objects.find((o) => o.binder?.kind === 'spine')?.binder?.cmyk ?? null) as Cmyk | null;
  const subtitle = isTransfer
    ? t('subtitle_uvdtf', { w: spec.trim_mm.w, h: spec.trim_mm.h })
    : spec.sticker
    ? t('subtitle_sticker', { shape: t(`shape_${spec.sticker.shape}`), w: spec.trim_mm.w, h: spec.trim_mm.h })
    : `${t(`binding_${spec.binding ?? 'ltr'}`)} · ${t('subtitle', { w: spec.trim_mm.w, h: spec.trim_mm.h })}`;
  const productTitle = cfg.order?.title || t(`title_${cfg.template}`);
  const rtl = cfg.lang === 'ar';

  const tools: Array<{ id: Tool; icon: ReactElement }> = [
    { id: 'text', icon: <path d="M5 5h14M12 5v14" /> },
    { id: 'image', icon: <><rect x="4" y="5" width="16" height="14" rx="1.5" /><circle cx="9" cy="10" r="1.6" /><path d="M5 17l5-5 4 4 2-2 3 3" /></> },
    // A transfer has no fills, no shapes and no background: text and pictures only.
    ...(isTransfer ? [] : TOOLS_EXTRA),
  ];

  return (
    <div className="studio" dir={rtl ? 'rtl' : 'ltr'} lang={cfg.lang} style={{ ['--top-h' as string]: `${topH}px` }}>
      <header className="studio-top" ref={topRef}>
        <button type="button" className="studio-back" onClick={() => void saveAndBack()}>
          <span aria-hidden="true">{rtl ? '›' : '‹'}</span> {t('save_back')}
        </button>
        <span className="studio-top__title">{productTitle}</span>
        <div className="studio-top__hist">
          <button type="button" className="studio-icon" onClick={undo} disabled={history.current.past.length === 0} aria-label={t('undo')} title={t('undo')}>
            <svg viewBox="0 0 24 24"><path d="M9 7L4 12l5 5M4 12h11a5 5 0 0 1 0 10h-2" /></svg>
          </button>
          <button type="button" className="studio-icon" onClick={redo} disabled={history.current.future.length === 0} aria-label={t('redo')} title={t('redo')}>
            <svg viewBox="0 0 24 24"><path d="M15 7l5 5-5 5M20 12H9a5 5 0 0 0 0 10h2" /></svg>
          </button>
        </div>
      </header>

      <div className="studio-body">
        <nav className="studio-rail" aria-label="tools">
          {tools.map((tl) => (
            <button key={tl.id} type="button" className={`studio-rail__btn${tool === tl.id ? ' is-on' : ''}`} onClick={() => setTool(tl.id)} aria-pressed={tool === tl.id}>
              <svg viewBox="0 0 24 24" aria-hidden="true">{tl.icon}</svg>
              <span>{t(`tool_${tl.id}`)}</span>
            </button>
          ))}
        </nav>

        <aside className="studio-panel">
          {tool === 'text' && (
            <>
              <h2>{t('tool_text')}</h2>
              <p className="studio-muted">{t('text_panel_hint')}</p>
              <button type="button" className="studio-add studio-add--heading" onClick={() => void newText(true)} disabled={!fontsReady || busy}>{t('add_heading')}</button>
              <button type="button" className="studio-add" onClick={() => void newText(false)} disabled={!fontsReady || busy}>{t('add_body')}</button>
            </>
          )}
          {tool === 'image' && (
            <>
              <h2>{t('tool_image')}</h2>
              <p className="studio-muted">{t('image_panel_hint')}</p>
              <div
                className={`studio-drop${dragging ? ' is-drag' : ''}`}
                onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => { e.preventDefault(); setDragging(false); const f = e.dataTransfer.files?.[0]; if (f) void upload(f); }}
              >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V5M7 10l5-5 5 5M5 19h14" /></svg>
                <button type="button" className="studio-btn studio-btn--ghost" onClick={() => fileInput.current?.click()} disabled={uploadPct !== null || busy}>
                  {uploadPct !== null ? t('uploading', { pct: uploadPct }) : t('choose_image')}
                </button>
                <span className="studio-muted">{t('or_drop')}</span>
                {uploadPct !== null && <progress max={100} value={uploadPct} />}
              </div>
              {uploadError && <p className="studio-error" role="alert">{uploadError}</p>}
            </>
          )}
          {tool === 'shapes' && (
            <>
              <h2>{t('tool_shapes')}</h2>
              <p className="studio-muted">{t('shapes_panel_hint')}</p>
              <div className="studio-shapes">
                {SHAPE_KINDS.map((k) => (
                  <button key={k} type="button" className="studio-shape" onClick={() => newShape(k)} disabled={busy} title={t(`shape_${k}`)}>
                    <svg viewBox={k === 'rectangle' ? '0 0 36 24' : '0 0 30 30'} aria-hidden="true"><path d={k === 'rectangle' ? shapePathData(k, 36, 24) : shapePathData(k, 30, 30)} /></svg>
                    <span>{t(`shape_${k}`)}</span>
                  </button>
                ))}
              </div>
            </>
          )}
          {tool === 'colours' && (
            <>
              <h2>{t('colours_bg')}</h2>
              <ColourPicker t={t} value={bgCmyk} allowNone onChange={(c) => setFill('background', c)} onPick={() => setPicking('background')} picking={picking === 'background'} onCancelPick={() => setPicking(null)} />
              {isBinder && spineBox(spec) && (
                <>
                  <h2 className="studio-gap">{t('colours_spine')}</h2>
                  <p className="studio-muted">{t('colours_spine_hint')}</p>
                  <ColourPicker t={t} value={spineCmyk} allowNone onChange={(c) => setFill('spine', c)} onPick={() => setPicking('spine')} picking={picking === 'spine'} onCancelPick={() => setPicking(null)} />
                </>
              )}
            </>
          )}
          {tool === 'templates' && (
            <>
              <h2>{t('tool_templates')}</h2>
              <p className="studio-muted">{t('templates_hint')}</p>
              <div className="studio-templates">
                {STARTERS.map((s) => (
                  <button key={s.id} type="button" className="studio-template" onClick={() => void applyTemplate(s.id)} disabled={busy}>
                    <TemplateThumb spec={spec} elements={s.build(spec)} />
                    <span>{s[cfg.lang]}</span>
                  </button>
                ))}
              </div>
            </>
          )}
        </aside>

        <main className="studio-stage" ref={stageRef}>
          <p className="studio-stage__title">{subtitle}</p>
          {picking && (
            <div className="studio-picking" role="status">
              {t('picking')} <button type="button" onClick={() => setPicking(null)}>{t('cancel')}</button>
            </div>
          )}
          <div className={`studio-sheet${picking ? ' is-picking' : ''}`} tabIndex={0} onKeyDown={onKey} aria-label={productTitle}>
            <div className="studio-fontwarm" ref={fontWarm} aria-hidden="true" />
            <div className={`binder-frame${transparentSheet ? ' binder-frame--transparent' : ''}`} style={{ width: Math.round(viewW), height: viewH }} dir="ltr">
              <canvas ref={canvasEl} />
              <img className="binder-overlay" src={isCustom ? customOverlayDataUrl(spec, cutPath) : overlayUrlFor(tpl)} alt="" draggable={false} />
            </div>
          </div>
          <Legend spec={spec} t={t} />
        </main>

        <aside className="studio-side" aria-live="polite">
          <section className="studio-sec studio-sec--order">
            <h2>{t('your_order')}</h2>
            <p className="studio-order__name">{productTitle}</p>
            {cfg.order && cfg.order.chips.length > 0 && (
              <div className="studio-chips">{cfg.order.chips.map((c, i) => <span key={i} className="studio-chip">{c}</span>)}</div>
            )}
            <p className="studio-muted studio-small">{t('order_note')}</p>
            {cfg.order?.total && (
              <div className="studio-total">
                <div>
                  <span>{t('total')}</span>
                  {cfg.order.note && <small>{cfg.order.note}</small>}
                </div>
                <strong>{cfg.order.total}</strong>
              </div>
            )}
            {busy ? (
              <StatusCard session={session} t={t} cfg={cfg} />
            ) : (
              <>
                <button type="button" className="studio-btn studio-btn--primary" onClick={() => void session.approve()} disabled={!(hasContent && result.ok)}>
                  {session.phase === 'failed' ? t('try_again') : t('approve')}
                </button>
                <button type="button" className="studio-btn studio-btn--ghost" onClick={openPreview} disabled={!hasContent}>{t('preview_file')}</button>
                <span className={`studio-save studio-save--${session.saveState}`}>
                  {session.saveState === 'saving' ? t('saving') : session.saveState === 'saved' ? `✓ ${t('saved')}` : session.saveState === 'error' ? t('save_failed') : ''}
                </span>
              </>
            )}
            <FailureCard session={session} t={t} />
          </section>

          {hasContent && issues.length > 0 && (
            <section className="studio-sec studio-sec--checks">
              <h2>{t('checks')}</h2>
              <IssueList issues={issues} t={t} onSelect={(i) => { const o = objects[snap.indexOf[i] ?? -1]; if (o && o.binder?.kind !== 'background' && o.binder?.kind !== 'spine') select(o); }} />
            </section>
          )}

          {!busy && (
            <section className={`studio-sec studio-sec--selected${selected ? ' has-sel' : ''}`} ref={selRef}>
              <div className="studio-sec__head">
                <h2>{t('selected')}</h2>
                {selected && (
                  <button type="button" className="studio-trash" onClick={() => remove()} aria-label={t('delete')} title={t('delete')}>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 7V4.5h4V7M6.5 7l1 13h9l1-13M10 11v6M14 11v6" /></svg>
                  </button>
                )}
              </div>
              <Selected
                key={selected ? String(objects.indexOf(selected)) : 'none'}
                o={selected}
                t={t}
                design={snap.design}
                indexOf={snap.indexOf}
                objects={objects}
                onChange={touch}
                ensureFont={ensureFont}
                picking={picking === 'selected'}
                actions={{ remove: () => remove(), duplicate, forward, backward, toggleLock: () => toggleLock(), align, fillWith, pick: () => setPicking('selected'), cancelPick: () => setPicking(null), colour: (c) => applyColour('selected', c) }}
              />
            </section>
          )}

          {!busy && objects.length > 0 && (
            <section className="studio-sec studio-sec--layers">
              <h2>{t('layers')}</h2>
              <ul className="studio-layers">
                {[...objects].reverse().map((o) => {
                  const k = o.binder?.kind;
                  const fill = k === 'background' || k === 'spine';
                  const name = k === 'text' ? String((o as Textbox).text).replace(/\s+/g, ' ').slice(0, 36) : k === 'shape' ? t(`shape_${o.binder?.shape}`) : k === 'image' ? t('layer_image') : k === 'spine' ? t('layer_spine') : t('layer_background');
                  const icon = k === 'text' ? 'T' : k === 'shape' ? '★' : k === 'image' ? '▣' : '◐';
                  return (
                    <li key={objects.indexOf(o)} className={o === selected ? 'is-on' : ''}>
                      <button type="button" className="studio-layer" onClick={() => (fill ? setTool('colours') : select(o))}>
                        <i aria-hidden="true">{icon}</i>
                        <span dir="auto">{name}</span>
                      </button>
                      {!fill && (
                        <button type="button" className={`studio-mini${o.binder?.locked ? ' is-locked' : ''}`} onClick={() => toggleLock(o)} title={o.binder?.locked ? t('unlock_it') : t('lock_it')} aria-label={o.binder?.locked ? t('unlock_it') : t('lock_it')}>
                          <svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="1.5" />{o.binder?.locked ? <path d="M8 11V8a4 4 0 0 1 8 0v3" /> : <path d="M8 11V8a4 4 0 0 1 7.5-2" />}</svg>
                        </button>
                      )}
                      <button type="button" className="studio-mini" onClick={() => (fill ? setFill(k as 'background' | 'spine', null) : remove(o))} title={t('remove')} aria-label={t('remove')}>×</button>
                    </li>
                  );
                })}
              </ul>
            </section>
          )}
        </aside>
      </div>

      <input ref={fileInput} type="file" hidden accept="image/jpeg,image/png,image/webp" onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = ''; }} />

      {preview && (
        <div className="studio-modal" role="dialog" aria-modal="true" aria-label={t('preview_title')} onClick={() => setPreview(null)}>
          <div className="studio-modal__box" onClick={(e) => e.stopPropagation()}>
            <div className="studio-modal__head">
              <h2>{t('preview_title')}</h2>
              <button type="button" className="studio-mini" onClick={() => setPreview(null)} aria-label={t('close')}>×</button>
            </div>
            <img src={preview} alt="" />
            <p className="studio-muted">{isTransfer ? t('preview_note_uvdtf') : spec.sticker ? t('preview_note') : t('preview_note_binder')}</p>
          </div>
        </div>
      )}
    </div>
  );
}

function emptyDesign(spec: Spec, cfg: EditorConfig): DesignJSON {
  return {
    template: spec.template,
    mode: cfg.mode === 'upload' ? 'upload' : 'live',
    canvas_mm: { ...spec.canvas_with_bleed_mm },
    elements: [],
    ...(spec.sticker ? { sticker: spec.sticker } : { binding: spec.binding ?? 'ltr' }),
  };
}

/** A small drawing of a starter design: colour fills, shapes and the text lines. */
function TemplateThumb({ spec, elements }: { spec: Spec; elements: DesignElement[] }) {
  const { w, h } = spec.canvas_with_bleed_mm;
  return (
    <svg className="studio-template__thumb" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="xMidYMid meet" aria-hidden="true">
      <rect x="0" y="0" width={w} height={h} fill="#fff" />
      {elements.map((el, i) =>
        el.type === 'rect' ? (
          <rect key={i} x={el.x_mm} y={el.y_mm} width={el.w_mm} height={el.h_mm} fill={cmykToRgbCss(el.color_cmyk)} />
        ) : el.type === 'shape' ? (
          <path key={i} transform={`translate(${el.x_mm} ${el.y_mm})`} d={shapePathData(el.shape, el.w_mm, el.h_mm)} fill={cmykToRgbCss(el.color_cmyk)} />
        ) : el.type === 'text' ? (
          <text key={i} x={el.x_mm + el.w_mm / 2} y={el.y_mm + (el.h_mm ?? 0) * 0.75} fontSize={(el.size_pt * 25.4) / 72} fontFamily={`"${el.font}", Tajawal, Poppins, sans-serif`} fontWeight={el.weight} fill={cmykToRgbCss(el.color_cmyk)} textAnchor="middle" direction={el.rtl ? 'rtl' : 'ltr'}>
            {el.text}
          </text>
        ) : null,
      )}
    </svg>
  );
}

function ColourPicker({ t, value, onChange, allowNone, onPick, picking, onCancelPick }: { t: T; value: Cmyk | null; onChange: (c: Cmyk | null) => void; allowNone?: boolean; onPick?: () => void; picking?: boolean; onCancelPick?: () => void }) {
  const hex = value ? cmykToHex(value) : '#ffffff';
  const [draft, setDraft] = useState(hex);
  useEffect(() => setDraft(hex), [hex]);
  return (
    <div className="studio-colour">
      <div className="studio-colour__row">
        <label className="studio-colour__well" style={{ background: value ? hex : undefined }} data-none={value ? undefined : ''}>
          <input type="color" value={hex} onChange={(e) => onChange(hexToCmyk(e.target.value))} aria-label={t('color')} />
        </label>
        <label className="studio-colour__hex">
          <span>{t('hex')}</span>
          <input
            type="text"
            value={draft}
            maxLength={7}
            dir="ltr"
            onChange={(e) => setDraft(e.target.value)}
            onBlur={() => /^#?[0-9a-f]{6}$/i.test(draft) && onChange(hexToCmyk(draft.startsWith('#') ? draft : `#${draft}`))}
            onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
          />
        </label>
        {onPick && (
          <button type="button" className={`studio-btn studio-btn--ghost studio-btn--small${picking ? ' is-on' : ''}`} onClick={picking ? onCancelPick : onPick} title={t('pick_from_design')}>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 4.5l5 5M17 7l-9.5 9.5L4 20l3.5-3.5M12 6l6 6" /></svg>
            {picking ? t('cancel') : t('pick_from_design')}
          </button>
        )}
      </div>
      <div className="studio-swatches">
        {allowNone && <button type="button" className={`studio-swatch studio-swatch--none${value === null ? ' is-on' : ''}`} title={t('no_colour')} aria-label={t('no_colour')} onClick={() => onChange(null)} />}
        {QUICK_COLOURS.map((c) => (
          <button key={c.join(',')} type="button" className={`studio-swatch${value && sameCmyk(value, c) ? ' is-on' : ''}`} style={{ background: cmykToRgbCss(c) }} aria-label={cmykToHex(c)} onClick={() => onChange(c)} />
        ))}
      </div>
      {value && <p className="studio-muted studio-small" dir="ltr">C {value[0]} · M {value[1]} · Y {value[2]} · K {value[3]}</p>}
    </div>
  );
}

interface SelActions {
  remove: () => void;
  duplicate: () => void;
  forward: () => void;
  backward: () => void;
  toggleLock: () => void;
  align: (how: 'left' | 'hcenter' | 'right' | 'top' | 'vmiddle' | 'bottom') => void;
  fillWith: (target: 'sheet' | 'panel') => void;
  pick: () => void;
  cancelPick: () => void;
  colour: (c: Cmyk) => void;
}

function Selected({ o, t, design, indexOf, objects, onChange, ensureFont, picking, actions }: { o: BObject | null; t: T; design: DesignJSON; indexOf: number[]; objects: BObject[]; onChange: () => void; ensureFont: (f: string, w: string, s: string) => Promise<void>; picking: boolean; actions: SelActions }) {
  if (!o) return <p className="studio-muted">{t('sel_nothing')}</p>;
  const locked = !!o.binder?.locked;
  const kind = o.binder?.kind;

  const alignRow = (
    <div className="studio-field">
      <span>{t('align_on_panel')}</span>
      <div className="studio-aligns">
        {(['left', 'hcenter', 'right', 'top', 'vmiddle', 'bottom'] as const).map((a) => (
          <button key={a} type="button" className="studio-icon" onClick={() => actions.align(a)} disabled={locked} title={t(`al_${a}`)} aria-label={t(`al_${a}`)}>
            <svg viewBox="0 0 24 24" aria-hidden="true">{ALIGN_ICONS[a]}</svg>
          </button>
        ))}
      </div>
    </div>
  );
  const opacityRow = (
    <label className="studio-field">
      <span>{t('opacity')} · {Math.round((o.opacity ?? 1) * 100)}%</span>
      <input type="range" min={0.05} max={1} step={0.05} value={o.opacity ?? 1} disabled={locked} onChange={(e) => { o.set({ opacity: Number(e.target.value) }); onChange(); }} />
    </label>
  );
  const common = (
    <>
      <div className="studio-actions">
        <button type="button" className="studio-btn studio-btn--ghost studio-btn--small" onClick={actions.forward}>{t('layer_up')}</button>
        <button type="button" className="studio-btn studio-btn--ghost studio-btn--small" onClick={actions.backward}>{t('layer_down')}</button>
        <button type="button" className="studio-btn studio-btn--ghost studio-btn--small" onClick={actions.toggleLock}>{locked ? t('unlock_it') : t('lock_it')}</button>
        <button type="button" className="studio-btn studio-btn--ghost studio-btn--small" onClick={actions.duplicate}>{t('duplicate')}</button>
        <button type="button" className="studio-btn studio-btn--ghost studio-btn--small studio-btn--danger" onClick={actions.remove}>{t('delete')}</button>
      </div>
    </>
  );
  const colourRow = (
    <div className="studio-field">
      <span>{t('color')}</span>
      <ColourPicker t={t} value={(o.binder?.cmyk ?? [0, 0, 0, 100]) as Cmyk} onChange={(c) => c && !locked && actions.colour(c)} onPick={actions.pick} picking={picking} onCancelPick={actions.cancelPick} />
    </div>
  );

  if (locked) {
    return (
      <>
        <p className="studio-muted">{t('sel_locked')}</p>
        {common}
      </>
    );
  }

  if (kind === 'text') {
    const tb = o as Textbox & BObject;
    const weight = String(tb.fontWeight === 'bold' ? '700' : tb.fontWeight === 'normal' ? '400' : (tb.fontWeight ?? '400'));
    const set = (props: Record<string, unknown>) => {
      tb.set(props);
      tb.initDimensions?.();
      tb.setCoords();
      onChange();
    };
    const setFont = async (family: string, w = weight) => {
      await ensureFont(family, w, tb.text);
      set({ fontFamily: family, fontWeight: w });
    };
    return (
      <>
        <label className="studio-field">
          <span>{t('text_content')}</span>
          <textarea rows={2} dir="auto" value={tb.text} onChange={(e) => set({ text: e.target.value, direction: isRtlText(e.target.value) ? 'rtl' : 'ltr' })} />
        </label>
        <label className="studio-field">
          <span>{t('font')}</span>
          <select value={tb.fontFamily} onChange={(e) => void setFont(e.target.value)} style={{ fontFamily: `"${tb.fontFamily}", Tajawal, Poppins, sans-serif` }}>
            <optgroup label={t('fonts_ar')}>
              {FONT_LIST.filter((f) => f.script === 'ar').map((f) => <option key={f.name} value={f.name} style={{ fontFamily: `"${f.name}"` }}>{f.name}</option>)}
            </optgroup>
            <optgroup label={t('fonts_latin')}>
              {FONT_LIST.filter((f) => f.script === 'latin').map((f) => <option key={f.name} value={f.name} style={{ fontFamily: `"${f.name}"` }}>{f.name}</option>)}
            </optgroup>
          </select>
        </label>
        <div className="studio-two">
          <label className="studio-field">
            <span>{t('weight')}</span>
            <select value={weight} onChange={(e) => void setFont(tb.fontFamily, e.target.value)}>
              {TEXT_WEIGHTS.map((w) => <option key={w} value={w}>{t(`weight_${w}`)}</option>)}
            </select>
          </label>
          <label className="studio-field">
            <span>{t('text_size')}</span>
            <input type="number" min={4} max={400} step={1} value={Math.round((tb.fontSize / ptToPx(1)) * 10) / 10} onChange={(e) => { const pt = Number(e.target.value); if (pt >= 4 && pt <= 400) set({ fontSize: ptToPx(pt) }); }} />
          </label>
        </div>
        <div className="studio-field">
          <span>{' '}</span>
          <div className="studio-seg">
            {(['left', 'center', 'right'] as const).map((a) => (
              <button key={a} type="button" className={tb.textAlign === a ? 'is-on' : ''} onClick={() => set({ textAlign: a })}>{t(`align_${a}`)}</button>
            ))}
          </div>
        </div>
        {colourRow}
        {alignRow}
        {common}
      </>
    );
  }

  if (kind === 'shape') {
    return (
      <>
        <p className="studio-sel-name">{t(`shape_${o.binder?.shape}`)}</p>
        {colourRow}
        {opacityRow}
        {alignRow}
        {common}
      </>
    );
  }

  // Picture
  const designIndex = indexOf.indexOf(objects.indexOf(o));
  const el = designIndex >= 0 ? design.elements[designIndex] : undefined;
  const dpi = el?.type === 'image' ? Math.round(effectiveDpi(el)) : 0;
  const quality = !dpi ? '' : dpi < THRESHOLDS.blockDpi ? 'low' : dpi < THRESHOLDS.warnDpi ? 'ok' : 'good';
  return (
    <>
      {quality && <p className={`studio-quality studio-quality--${quality}`}>{t('dpi', { dpi })} · {t(`quality_${quality}`)}</p>}
      <div className="studio-actions">
        {design.template !== 'uvdtf' && <button type="button" className="studio-btn studio-btn--ghost studio-btn--small" onClick={() => actions.fillWith('sheet')}>{t('fill_canvas')}</button>}
        {design.template !== 'sticker' && design.template !== 'uvdtf' && <button type="button" className="studio-btn studio-btn--ghost studio-btn--small" onClick={() => actions.fillWith('panel')}>{t('fill_panel')}</button>}
      </div>
      {opacityRow}
      {alignRow}
      {common}
    </>
  );
}

const ALIGN_ICONS: Record<string, ReactElement> = {
  left: <><path d="M4 4v16" /><rect x="7" y="7" width="10" height="4" /><rect x="7" y="13" width="6" height="4" /></>,
  hcenter: <><path d="M12 4v16" /><rect x="6" y="7" width="12" height="4" /><rect x="8" y="13" width="8" height="4" /></>,
  right: <><path d="M20 4v16" /><rect x="7" y="7" width="10" height="4" /><rect x="11" y="13" width="6" height="4" /></>,
  top: <><path d="M4 4h16" /><rect x="7" y="7" width="4" height="10" /><rect x="13" y="7" width="4" height="6" /></>,
  vmiddle: <><path d="M4 12h16" /><rect x="7" y="6" width="4" height="12" /><rect x="13" y="8" width="4" height="8" /></>,
  bottom: <><path d="M4 20h16" /><rect x="7" y="7" width="4" height="10" /><rect x="13" y="11" width="4" height="6" /></>,
};
