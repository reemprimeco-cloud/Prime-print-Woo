import { lazy, StrictMode, Suspense } from 'react';
import { createRoot } from 'react-dom/client';
import { readConfig } from './config';
import { UploadEditor } from './UploadEditor';
import './styles.css';

// Both modes share Fabric.js; the live editor is still split out so an
// upload-only visit downloads less.
const LiveEditor = lazy(() => import('./live/LiveEditor'));

const cfg = readConfig();

document.documentElement.lang = cfg.lang;
document.documentElement.dir = cfg.lang === 'ar' ? 'rtl' : 'ltr';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {cfg.mode === 'live' ? (
      <Suspense fallback={<div className="binder-app binder-center">…</div>}>
        <LiveEditor cfg={cfg} />
      </Suspense>
    ) : (
      <UploadEditor cfg={cfg} />
    )}
  </StrictMode>,
);
