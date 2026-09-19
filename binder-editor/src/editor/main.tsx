import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { readConfig } from './config';
import { UploadEditor } from './UploadEditor';
import './styles.css';

const cfg = readConfig();

document.documentElement.lang = cfg.lang;
document.documentElement.dir = cfg.lang === 'ar' ? 'rtl' : 'ltr';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <UploadEditor cfg={cfg} />
  </StrictMode>,
);
