import { STICKER_GUIDES, type Spec } from '@binder/shared';
import type { T } from './i18n';

interface Item {
  key: string;
  color: string;
  mm?: 'bleed' | 'safe' | 'turnin';
  dashed?: boolean;
}

/** The colours and names printed on the binder templates' own legend. Values shown come from spec.json. */
const BINDER_ITEMS: Item[] = [
  { key: 'legend_bleed', color: '#F2836B', mm: 'bleed' },
  { key: 'legend_trim', color: '#000000' },
  { key: 'legend_fold', color: '#00AEEF' },
  { key: 'legend_safe', color: '#EC008C', mm: 'safe' },
  { key: 'legend_miter', color: '#F7941D' },
  { key: 'legend_turnin', color: '#BDBDBD', mm: 'turnin' },
];

/** The sticker guide (sticker.ts draws it): cut line, bleed, safe zone. */
const STICKER_ITEMS: Item[] = [
  { key: 'legend_trim', color: STICKER_GUIDES.cut },
  { key: 'legend_bleed', color: STICKER_GUIDES.bleed, mm: 'bleed', dashed: true },
  { key: 'legend_safe', color: STICKER_GUIDES.safe, mm: 'safe', dashed: true },
];

/** Persistent guide legend (§4.2), shown beside the stage, never drawn on the canvas. */
export function Legend({ spec, t }: { spec: Spec; t: T }) {
  if (spec.template === 'uvdtf') return <p className="binder-legend binder-legend--note"><small>{t('legend_uvdtf')}</small></p>;
  if (spec.sticker?.shape === 'custom') {
    return (
      <div className="binder-legend" aria-label={t('legend')}>
        <span><i style={{ background: STICKER_GUIDES.cut }} />{t('legend_trim')}</span>
        <span><i style={{ background: `repeating-linear-gradient(90deg, ${STICKER_GUIDES.bleed} 0 0.3rem, transparent 0.3rem 0.5rem)` }} />{t('legend_sticker_size')}</span>
        <small>{t('legend_custom')}</small>
      </div>
    );
  }
  const mm = { bleed: spec.bleed_mm, safe: spec.safe_margin_mm, turnin: spec.turn_in_mm };
  const items = spec.sticker ? STICKER_ITEMS : BINDER_ITEMS.filter((l) => l.mm !== 'turnin' || spec.turn_in_mm > 0);
  return (
    <div className="binder-legend" aria-label={t('legend')}>
      {items.map((l) => (
        <span key={l.key}>
          <i style={l.dashed ? { background: `repeating-linear-gradient(90deg, ${l.color} 0 0.3rem, transparent 0.3rem 0.5rem)` } : { background: l.color }} />
          {t(l.key, l.mm ? { mm: mm[l.mm] } : {})}
        </span>
      ))}
      <small>{t('legend_note')}</small>
    </div>
  );
}
