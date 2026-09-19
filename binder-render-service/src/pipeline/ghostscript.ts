import { execFile } from 'node:child_process';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Config } from '../config.ts';

function run(bin: string, args: string[], timeoutMs: number): Promise<void> {
  return new Promise((res, rej) => {
    execFile(bin, args, { timeout: timeoutMs, maxBuffer: 32 * 1024 * 1024 }, (error, stdout, stderr) => {
      if (error) rej(new Error(`Ghostscript failed: ${error.message}\n${stderr || stdout}`.trim()));
      else res();
    });
  });
}

/**
 * RGB -> CMYK through the FOGRA39 profile (§5.2 step 4).
 *
 * The pdfwrite settings matter as much as the colour strategy:
 *  - no downsampling, and DCT at a high quality factor with no chroma
 *    subsampling, so the customer's 300 DPI artwork is not degraded;
 *  - fonts stay embedded and subset;
 *  - relative colorimetric with black point compensation, the usual print
 *    intent for photographic content.
 * Vector text was already rewritten to exact DeviceCMYK (colors.ts) and is
 * passed through, not re-mapped.
 */
export async function convertToCmyk(cfg: Config, input: string, output: string): Promise<void> {
  if (!existsSync(cfg.gsBin)) throw new Error(`Ghostscript not found at ${cfg.gsBin} (set GS_BIN)`);
  if (!existsSync(cfg.iccProfile)) throw new Error(`ICC profile not found at ${cfg.iccProfile} (set ICC_PROFILE)`);

  const icc = resolve(cfg.iccProfile);
  const inFile = resolve(input);
  const outFile = resolve(output);

  await run(
    cfg.gsBin,
    [
      '-dSAFER',
      `--permit-file-read=${icc}`,
      `--permit-file-read=${inFile}`,
      `--permit-file-write=${outFile}`,
      '-dBATCH',
      '-dNOPAUSE',
      '-dNOCACHE',
      '-q',
      '-sDEVICE=pdfwrite',
      '-dCompatibilityLevel=1.7',
      '-sColorConversionStrategy=CMYK',
      '-sProcessColorModel=DeviceCMYK',
      `-sOutputICCProfile=${icc}`,
      `-sDefaultCMYKProfile=${icc}`,
      '-dRenderIntent=1',
      '-dBlackPtComp=1',
      '-dEmbedAllFonts=true',
      '-dSubsetFonts=true',
      '-dDownsampleColorImages=false',
      '-dDownsampleGrayImages=false',
      '-dDownsampleMonoImages=false',
      '-dAutoFilterColorImages=false',
      '-dAutoFilterGrayImages=false',
      '-dColorImageFilter=/DCTEncode',
      '-dGrayImageFilter=/DCTEncode',
      `-sOutputFile=${outFile}`,
      '-c',
      '<< /ColorImageDict << /QFactor 0.15 /Blend 1 /HSamples [1 1 1 1] /VSamples [1 1 1 1] >> >> setdistillerparams',
      '-f',
      inFile,
    ],
    cfg.renderTimeoutMs,
  );

  if (!existsSync(outFile)) throw new Error('Ghostscript produced no output file');
}
