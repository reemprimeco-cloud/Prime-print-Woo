import { stickerOverlayDataUrl, type DesignJSON, type Issue, type Spec, type StickerParams, type TemplateKey } from '@binder/shared';
import type { EditorConfig } from './config';

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public errors: Issue[] = [],
  ) {
    super(message);
  }
}

export interface TemplateInfo {
  template: TemplateKey;
  spec: Spec;
  /** Signed URL of the guide PNG; empty for the sticker template, whose guide is drawn from the spec. */
  overlay_url: string;
}

/** The guide image an editor lays over the canvas. */
export function overlayUrlFor(tpl: TemplateInfo): string {
  return tpl.overlay_url || (tpl.spec.sticker ? stickerOverlayDataUrl(tpl.spec) : '');
}

export interface UploadResult {
  url: string;
  /** Downsized copy for on-screen use; absent when the original is small or could not be resized. */
  proxy_url?: string | null;
  source_px: { w: number; h: number };
  bytes: number;
  mime: string;
}

export type DesignStatus = 'draft' | 'rendering' | 'ready' | 'failed';

export interface DesignRecord {
  id: number;
  status: DesignStatus;
  preview_url: string | null;
  proof_url: string;
  errors: Issue[] | null;
  design_json?: DesignJSON;
}

/** Thin client for the binder/v1 REST API. Guests are identified by the session-token header. */
export function createApi(cfg: EditorConfig) {
  const headers = (extra: Record<string, string> = {}): Record<string, string> => ({
    Accept: 'application/json',
    'X-Binder-Session': cfg.sessionToken,
    ...extra,
  });

  async function parse<T>(res: Response): Promise<T> {
    const body = (await res.json().catch(() => ({}))) as Record<string, any>;
    if (!res.ok) {
      throw new ApiError(res.status, String(body.code ?? 'error'), String(body.message ?? res.statusText), (body.data?.errors ?? []) as Issue[]);
    }
    return body as T;
  }

  const json = <T>(path: string, method: string, body?: unknown): Promise<T> =>
    fetch(`${cfg.rest}${path}`, {
      method,
      credentials: 'same-origin',
      headers: headers(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    }).then((r) => parse<T>(r));

  return {
    template(t: TemplateKey, sticker?: StickerParams) {
      const q = t === 'sticker' && sticker ? `?w=${sticker.w_mm}&h=${sticker.h_mm}&shape=${encodeURIComponent(sticker.shape)}` : '';
      return json<TemplateInfo>(`/template/${t}${q}`, 'GET');
    },

    /** XHR rather than fetch: an artwork file can be tens of MB and the customer needs a progress bar. */
    upload(file: File, onProgress: (fraction: number) => void): Promise<UploadResult> {
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `${cfg.rest}/upload`);
        xhr.setRequestHeader('X-Binder-Session', cfg.sessionToken);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded / e.total);
        xhr.onload = () => {
          let body: Record<string, any> = {};
          try {
            body = JSON.parse(xhr.responseText);
          } catch {
            /* non-JSON error page */
          }
          if (xhr.status >= 200 && xhr.status < 300) return resolve(body as UploadResult);
          reject(new ApiError(xhr.status, String(body.code ?? 'binder_upload_failed'), String(body.message ?? 'Upload failed')));
        };
        xhr.onerror = () => reject(new ApiError(0, 'network', 'Network error'));
        const form = new FormData();
        form.append('file', file);
        xhr.send(form);
      });
    },

    saveDesign(design: DesignJSON, warnings: Issue[], id?: number) {
      return json<DesignRecord>('/design', 'POST', {
        ...(id ? { id } : {}),
        session_token: cfg.sessionToken,
        product_id: cfg.productId,
        template: design.template,
        mode: design.mode,
        design_json: design,
        validation_warnings: warnings,
      });
    },

    getDesign: (id: number) => json<DesignRecord>(`/design/${id}`, 'GET'),
    preview: (id: number) => json<{ preview_url: string }>(`/design/${id}/preview`, 'POST'),
    finalize: (id: number) => json<DesignRecord>(`/design/${id}/finalize`, 'POST'),
    status: (id: number) => json<DesignRecord>(`/design/${id}/status`, 'GET'),
  };
}

export type Api = ReturnType<typeof createApi>;
