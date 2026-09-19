import { useLayoutEffect, useRef } from 'react';
import type { CSSProperties } from 'react';
import { planTextColors, type DesignElement, type DesignJSON, type Spec, type TextElement } from '@binder/shared';

export interface MeasuredText {
  index: number;
  w_mm: number;
  h_mm: number;
}

const MM_PER_CSS_PX = 25.4 / 96;

/**
 * Chromium snaps images and box edges to whole CSS pixels (0.26 mm), which
 * moved artwork by up to ~0.13 mm at each edge in early tests. The artwork is
 * therefore laid out LAYOUT_SCALE times larger and shrunk back with a
 * transform: snapping still happens, but in the large space, where one pixel
 * is 1/LAYOUT_SCALE of a pixel of the finished page. The PDF stores the
 * transform as a matrix, so nothing is rasterised and nothing is lost.
 */
const LAYOUT_SCALE = 16;
const L = (mm: number): string => `${mm * LAYOUT_SCALE}mm`;

const FONT_STACK: Record<string, string> = {
  Tajawal: '"Tajawal", "Poppins", sans-serif',
  Poppins: '"Poppins", "Tajawal", sans-serif',
};

function transformFor(el: DesignElement): CSSProperties {
  return el.rotation_deg
    ? { transform: `rotate(${el.rotation_deg}deg)`, transformOrigin: 'center center' }
    : {};
}

function textStyle(el: TextElement, sentinel: [number, number, number]): CSSProperties {
  return {
    left: L(el.x_mm),
    top: L(el.y_mm),
    width: L(el.w_mm),
    ...(el.h_mm ? { height: L(el.h_mm) } : {}),
    fontFamily: FONT_STACK[el.font],
    fontWeight: el.weight,
    fontSize: `${el.size_pt * LAYOUT_SCALE}pt`,
    lineHeight: el.line_height ?? 1.2,
    textAlign: el.align,
    // Sentinel RGB, replaced by the design's exact CMYK in the PDF (see @binder/shared colors.ts).
    color: `rgb(${sentinel[0]}, ${sentinel[1]}, ${sentinel[2]})`,
    ...transformFor(el),
  };
}

interface Props {
  spec: Spec;
  design: DesignJSON;
  /** Called once fonts are loaded, images decoded, layout settled. */
  onReady: (measured: MeasuredText[]) => void;
  onError: (message: string) => void;
}

/**
 * The artwork at true physical size. Layout is in CSS millimetres, so the PDF
 * page Chromium writes is exactly canvas_with_bleed_mm and every element lands
 * at its mm position; raster images keep their native resolution because the
 * PDF stores the original pixels and only a transform matrix.
 */
export function PrintCanvas({ spec, design, onReady, onError }: Props) {
  const root = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    let cancelled = false;

    (async () => {
      const el = root.current;
      if (!el) return;

      const texts = design.elements.filter((e): e is TextElement => e.type === 'text');

      // Load exactly the faces this design uses (both stack members, so Arabic
      // set in Poppins is measured with the Tajawal fallback that will print).
      await Promise.all(
        texts.flatMap((t) =>
          ['Tajawal', 'Poppins'].map((family) => document.fonts.load(`${t.weight} ${t.size_pt}pt "${family}"`, t.text)),
        ),
      );
      await document.fonts.ready;

      const images = Array.from(el.querySelectorAll('img'));
      await Promise.all(
        images.map((img) =>
          img.decode().catch(() => {
            throw new Error(`Image failed to load: ${img.getAttribute('src')}`);
          }),
        ),
      );

      // Let layout settle after fonts/images.
      await new Promise<void>((r) => requestAnimationFrame(() => requestAnimationFrame(() => r())));

      const measured: MeasuredText[] = [];
      el.querySelectorAll<HTMLElement>('[data-text-index]').forEach((node) => {
        // scrollHeight is the height of the text itself, even when the design
        // declared a smaller h_mm (the box is then overflowed, not clipped). The
        // server trusts this number, never the client's claim (§5.3).
        measured.push({
          index: Number(node.dataset.textIndex),
          w_mm: (node.offsetWidth * MM_PER_CSS_PX) / LAYOUT_SCALE,
          h_mm: (Math.max(node.offsetHeight, node.scrollHeight) * MM_PER_CSS_PX) / LAYOUT_SCALE,
        });
      });

      if (!cancelled) onReady(measured);
    })().catch((e: unknown) => {
      if (!cancelled) onError(e instanceof Error ? e.message : String(e));
    });

    return () => {
      cancelled = true;
    };
  }, [spec, design, onReady, onError]);

  const { w, h } = spec.canvas_with_bleed_mm;
  const plan = planTextColors(design);

  return (
    <>
      <style>{`@page { size: ${w}mm ${h}mm; margin: 0; } html, body { width: ${w}mm; height: ${h}mm; overflow: hidden; }`}</style>
      <div style={{ position: 'relative', width: `${w}mm`, height: `${h}mm`, overflow: 'hidden' }}>
      <div
        ref={root}
        id="root-canvas"
        style={{ position: 'absolute', left: 0, top: 0, width: L(w), height: L(h), overflow: 'hidden', transform: `scale(${1 / LAYOUT_SCALE})`, transformOrigin: '0 0' }}
      >
        {design.elements.map((e, i) =>
          e.type === 'image' ? (
            <img
              key={i}
              className="el"
              src={e.src}
              alt=""
              decoding="sync"
              loading="eager"
              style={{ left: L(e.x_mm), top: L(e.y_mm), width: L(e.w_mm), height: L(e.h_mm), ...transformFor(e) }}
            />
          ) : (
            <div
              key={i}
              className="el el-text"
              data-text-index={i}
              lang={e.rtl ? 'ar' : 'en'}
              dir={e.rtl ? 'rtl' : 'ltr'}
              style={textStyle(e, plan.entries[plan.byElement.get(i) ?? 0]!.sentinel)}
            >
              {e.text}
            </div>
          ),
        )}
      </div>
      </div>
    </>
  );
}
