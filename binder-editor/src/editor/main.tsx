import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { readConfig } from './config';
import { UploadEditor } from './UploadEditor';
import './styles.css';

const cfg = readConfig();

document.documentElement.lang = cfg.lang;
document.documentElement.dir = cfg.lang === 'ar' ? 'rtl' : 'ltr';

// The online (design-it-here) editor is being rebuilt on Fabric.js from the
// shop's own designer template; until it lands, every mode opens the upload
// editor. The design JSON (@binder/shared) and the print pipeline already
// handle text and colour elements, so nothing downstream changes.
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <UploadEditor cfg={cfg} />
  </StrictMode>,
);
