import { useCallback, useEffect, useRef, useState } from 'react';
import type { DesignJSON, Issue } from '@binder/shared';
import { ApiError, type Api, type DesignRecord } from './api';
import type { EditorConfig } from './config';
import type { T } from './i18n';

export type Phase = 'edit' | 'approving' | 'done' | 'failed';
export type SaveState = 'idle' | 'saving' | 'saved' | 'error';

/** What an editor hands the session each time it needs to save or submit. */
export interface Snapshot {
  design: DesignJSON;
  warnings: Issue[];
  /** No hard blocks: the design may be submitted. */
  ok: boolean;
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/** Tell the host page (the product page hosting the editor in an iframe). */
export function notify(cfg: EditorConfig, message: Record<string, unknown>): void {
  if (window.parent !== window) {
    window.parent.postMessage({ source: 'binder-editor', template: cfg.template, mode: cfg.mode, ...message }, window.location.origin);
  }
}

/**
 * Everything both editors share: saving a draft (autosaved), submitting it
 * (finalize), and waiting for the print file (poll) — with the customer-facing
 * error mapping. An editor only supplies a snapshot of its design.
 */
export function useDesignSession(cfg: EditorConfig, api: Api, t: T, getSnapshot: () => Snapshot | null) {
  const [designId, setDesignId] = useState<number | undefined>(cfg.designId);
  const [saveState, setSaveState] = useState<SaveState>('idle');
  const [phase, setPhase] = useState<Phase>('edit');
  const [failure, setFailure] = useState<{ message: string; issues: Issue[] } | null>(null);
  const [proofUrl, setProofUrl] = useState('');

  const idRef = useRef<number | undefined>(cfg.designId);
  const timer = useRef<number | undefined>(undefined);
  const snapshot = useRef(getSnapshot);
  snapshot.current = getSnapshot;

  const saveNow = useCallback(async (): Promise<number | undefined> => {
    window.clearTimeout(timer.current);
    const snap = snapshot.current();
    if (!snap) return idRef.current;
    setSaveState('saving');
    try {
      const rec = await api.saveDesign(snap.design, snap.warnings, idRef.current);
      idRef.current = rec.id;
      setDesignId(rec.id);
      setSaveState('saved');
      return rec.id;
    } catch (e) {
      setSaveState('error');
      throw e;
    }
  }, [api]);

  /** Call after every change; saves 0.9 s after the last one. */
  const markDirty = useCallback(() => {
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => void saveNow().catch(() => undefined), 900);
  }, [saveNow]);

  useEffect(() => () => window.clearTimeout(timer.current), []);

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

  const finishDone = useCallback(
    (rec: DesignRecord) => {
      setProofUrl(rec.proof_url);
      setPhase('done');
      notify(cfg, { type: 'design-ready', designId: rec.id, proofUrl: rec.proof_url });
    },
    [cfg],
  );

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
    [api, finishDone, t],
  );

  const approve = useCallback(async () => {
    const snap = snapshot.current();
    if (!snap?.ok) return;
    setFailure(null);
    setPhase('approving');
    try {
      const id = await saveNow();
      if (!id) throw new Error('no id');
      const rec = await api.finalize(id);
      if (rec.status === 'ready') return finishDone(rec);
      await poll(id);
    } catch (e) {
      setFailure(describe(e));
      setPhase('failed');
    }
  }, [api, describe, finishDone, poll, saveNow]);

  /** Restore state for a design opened from the cart or account. */
  const resume = useCallback(
    (rec: DesignRecord) => {
      if (rec.status === 'ready') {
        setProofUrl(rec.proof_url);
        setPhase('done');
      } else if (rec.status === 'rendering') {
        void poll(rec.id);
      }
    },
    [poll],
  );

  return { designId, saveState, phase, failure, proofUrl, markDirty, saveNow, approve, resume, setPhase, setFailure };
}

export type Session = ReturnType<typeof useDesignSession>;
