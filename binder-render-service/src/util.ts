import { createHmac, timingSafeEqual } from 'node:crypto';

/** Constant-time string comparison. */
export function safeEqual(a: string, b: string): boolean {
  const ab = Buffer.from(a);
  const bb = Buffer.from(b);
  return ab.length === bb.length && timingSafeEqual(ab, bb);
}

export function hmacHex(secret: string, message: string): string {
  return createHmac('sha256', secret).update(message).digest('hex');
}

/** Sliding-window limiter keyed by an arbitrary string (the session_token, §5.3). */
export class RateLimiter {
  private hits = new Map<string, number[]>();

  constructor(
    private max: number,
    private windowMs: number,
  ) {}

  /** Records a hit; false when the key is over its limit. */
  allow(key: string, now = Date.now()): boolean {
    const recent = (this.hits.get(key) ?? []).filter((t) => now - t < this.windowMs);
    if (recent.length >= this.max) {
      this.hits.set(key, recent);
      return false;
    }
    recent.push(now);
    this.hits.set(key, recent);
    if (this.hits.size > 5000) this.prune(now);
    return true;
  }

  /** Seconds until the key may try again. */
  retryAfter(key: string, now = Date.now()): number {
    const recent = this.hits.get(key) ?? [];
    const oldest = recent[0];
    return oldest === undefined ? 0 : Math.max(1, Math.ceil((oldest + this.windowMs - now) / 1000));
  }

  private prune(now: number): void {
    for (const [k, v] of this.hits) {
      if (v.every((t) => now - t >= this.windowMs)) this.hits.delete(k);
    }
  }
}

/** Runs at most `limit` jobs at once; extra callers wait, up to `maxQueue`. */
export class Limiter {
  private active = 0;
  private waiting: Array<() => void> = [];

  constructor(
    private limit: number,
    private maxQueue: number,
  ) {}

  get queued(): number {
    return this.waiting.length;
  }

  /** Throws QueueFullError when too many are already waiting. */
  async run<T>(fn: () => Promise<T>): Promise<T> {
    if (this.active >= this.limit) {
      if (this.waiting.length >= this.maxQueue) throw new QueueFullError();
      await new Promise<void>((resolve) => this.waiting.push(resolve));
    }
    this.active++;
    try {
      return await fn();
    } finally {
      this.active--;
      this.waiting.shift()?.();
    }
  }
}

export class QueueFullError extends Error {
  constructor() {
    super('Render queue is full');
  }
}
