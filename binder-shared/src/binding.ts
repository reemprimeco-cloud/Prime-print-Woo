import type { Binding, Spec } from './types.ts';

export const BINDINGS: readonly Binding[] = ['ltr', 'rtl'];

export function isBinding(v: unknown): v is Binding {
  return v === 'ltr' || v === 'rtl';
}

/** The panel a name becomes when the binder opens from the other side. */
const MIRROR: Record<string, string> = {
  back_cover: 'front_cover',
  front_cover: 'back_cover',
  inside_front: 'inside_back',
  inside_back: 'inside_front',
};

/**
 * A binder spec for one opening direction. The spec files describe an
 * English binder (front cover on the right); for an Arabic one the cover
 * panels swap names and nothing else moves. Idempotent: applying the same
 * binding twice changes nothing.
 */
export function withBinding(spec: Spec, binding: Binding): Spec {
  if (spec.sticker) return spec;
  if ((spec.binding ?? 'ltr') === binding) return { ...spec, binding };
  return {
    ...spec,
    binding,
    panels_relative_to_trim: spec.panels_relative_to_trim.map((p) => ({ ...p, name: MIRROR[p.name] ?? p.name })),
  };
}
