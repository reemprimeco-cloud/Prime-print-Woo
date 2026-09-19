import type { Spec } from '@binder/shared';
import type { T } from './i18n';

/** The colours and names printed on the template's own legend. Values shown come from spec.json. */
const ITEMS: Array<{ key: string; color: string; mm?: 'bleed' | 'safe' | 'turnin' }> = [
  { key: 'legend_bleed', color: '#F2836B', mm: 'bleed' },
  { key: 'legend_trim', color: '#000000' },
  { key: 'legend_fold', color: '#00AEEF' },
  { key: 'legend_safe', color: '#EC008C', mm: 'safe' },
  { key: 'legend_miter', color: '#F7941D' },
  { key: 'legend_turnin', color: '#BDBDBD', mm: 'turnin' },
];

/** Persistent guide legend (§4.2), shown beside the stage, never drawn on the canvas. */
export function Legend({ spec, t }: { spec: Spec; t: T }) {
  const mm = { bleed: spec.bleed_mm, safe: spec.safe_margin_mm, turnin: spec.turn_in_mm };
  return (
    <div className="binder-legend" aria-label={t('legend')}>
      {ITEMS.filter((l) => l.mm !== 'turnin' || spec.turn_in_mm > 0).map((l) => (
        <span key={l.key}>
          <i style={{ background: l.color }} />
          {t(l.key, l.mm ? { mm: mm[l.mm] } : {})}
        </span>
      ))}
      <small>{t('legend_note')}</small>
    </div>
  );
}
