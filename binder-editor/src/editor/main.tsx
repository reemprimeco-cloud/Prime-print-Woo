import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { readConfig } from './config';
import LiveEditor from './live/LiveEditor';
import './styles.css';

const cfg = readConfig();

document.documentElement.lang = cfg.lang;
document.documentElement.dir = cfg.lang === 'ar' ? 'rtl' : 'ltr';

// One designer page for everything: "Upload your design" opens it on the Image
// tool (the first picture fills the sheet), "Design it now" on the Text tool.
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <LiveEditor cfg={cfg} />
  </StrictMode>,
);
