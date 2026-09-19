import type { Issue } from '@binder/shared';
import { issueText, type T } from './i18n';
import type { Session } from './session';
import { notify } from './session';
import type { EditorConfig } from './config';

/** Save indicator and the primary "Approve" button — identical in both editors. */
export function Actions({ session, t, canApprove }: { session: Session; t: T; canApprove: boolean }) {
  const s = session.saveState;
  return (
    <div className="binder-actions">
      <span className={`binder-save binder-save--${s}`}>
        {s === 'saving' ? t('saving') : s === 'saved' ? `✓ ${t('saved')}` : s === 'error' ? t('save_failed') : ''}
      </span>
      <button type="button" className="binder-btn binder-btn--primary" onClick={() => void session.approve()} disabled={!canApprove}>
        {session.phase === 'failed' ? t('try_again') : t('approve')}
      </button>
    </div>
  );
}

/** The list of issues, errors first. `onSelect` lets a live editor jump to the offending element. */
export function IssueList({ issues, t, onSelect }: { issues: Issue[]; t: T; onSelect?: (element: number) => void }) {
  return (
    <ul className="binder-issues">
      {issues.map((i, n) => (
        <li key={n} className={i.severity === 'error' ? 'is-error' : 'is-warn'}>
          {onSelect && i.element !== undefined ? (
            <button type="button" className="binder-linkish" onClick={() => onSelect(i.element!)}>
              {issueText(t, i)}
            </button>
          ) : (
            issueText(t, i)
          )}
        </li>
      ))}
    </ul>
  );
}

/** What replaces the editing panels while a design is being prepared, is ready, or has failed. */
export function StatusCard({ session, t, cfg }: { session: Session; t: T; cfg: EditorConfig }) {
  if (session.phase === 'done') {
    return (
      <div className="binder-card binder-done" role="status">
        <div className="binder-tick" aria-hidden="true">✓</div>
        <h2>{t('ready_title')}</h2>
        <p>{t('ready_text')}</p>
        {session.proofUrl && (
          <a className="binder-btn binder-btn--ghost" href={session.proofUrl} target="_blank" rel="noopener">
            {t('proof')}
          </a>
        )}
        <button type="button" className="binder-btn" onClick={() => notify(cfg, { type: 'close' })}>
          {t('done')}
        </button>
      </div>
    );
  }

  if (session.phase === 'approving') {
    return (
      <div className="binder-card binder-wait" role="status">
        <div className="binder-spinner" aria-hidden="true" />
        <h2>{t('approving')}</h2>
        <p>{t('approving_hint')}</p>
      </div>
    );
  }

  return null;
}

export function FailureCard({ session, t }: { session: Session; t: T }) {
  const f = session.failure;
  if (!f) return null;
  return (
    <div className="binder-card binder-fail" role="alert">
      <p>{f.message}</p>
      {f.issues.length > 0 && <IssueList issues={f.issues} t={t} />}
    </div>
  );
}
