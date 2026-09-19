// Syntax-check PHP files with the real PHP parser (php-wasm, no PHP install needed).
// Usage (from the project root): node dev/tools/php-lint.mjs file.php [more.php ...]
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import fs from 'fs';

const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
let bad = 0;

for (const file of process.argv.slice(2)) {
  php.writeFile('/tmp/lint-target.php', fs.readFileSync(file, 'utf8'));
  const res = await php.run({
    code: `<?php
      try { token_get_all(file_get_contents('/tmp/lint-target.php'), TOKEN_PARSE); echo 'OK'; }
      catch (ParseError $e) { echo 'PARSE ERROR: ' . $e->getMessage() . ' on line ' . $e->getLine(); }`,
  });
  const ok = res.text.startsWith('OK');
  console.log((ok ? '  ok   ' : '  FAIL ') + file + (ok ? '' : '  ' + res.text));
  if (!ok) bad++;
}

process.exit(bad ? 1 : 0);
