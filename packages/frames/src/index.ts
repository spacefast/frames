export const SPACEFAST_FRAME_PERMISSIONS = [
  "accelerometer",
  "autoplay",
  "camera",
  "clipboard-read",
  "clipboard-write",
  "encrypted-media",
  "fullscreen",
  "geolocation",
  "gyroscope",
  "microphone",
  "picture-in-picture",
] as const;

export type SpacefastFramePermission = (typeof SPACEFAST_FRAME_PERMISSIONS)[number];

export const DEFAULT_SPACEFAST_FRAME_SANDBOX = [
  "allow-downloads",
  "allow-forms",
  "allow-modals",
  "allow-popups",
  "allow-popups-to-escape-sandbox",
  "allow-same-origin",
  "allow-scripts",
] as const;

export type SpacefastFrameSession = {
  /** Short-lived, origin-bound URL returned by a trusted server. */
  url: string;
  /** ISO 8601 instant after which the URL and its partitioned frame proof stop working. */
  expiresAt: string;
};

export type SpacefastFrameSessionRequest = {
  path: string;
  signal: AbortSignal;
};

export type SpacefastFrameOptions = {
  path: string;
  title: string;
  session: (request: SpacefastFrameSessionRequest) => Promise<SpacefastFrameSession>;
  permissions?: readonly SpacefastFramePermission[];
  sandbox?: readonly string[] | false;
  loading?: "eager" | "lazy";
  referrerPolicy?: ReferrerPolicy;
  className?: string;
  /** Refresh this many milliseconds before expiry. Defaults to 60 seconds. */
  refreshBeforeMs?: number;
  onStateChange?: (state: SpacefastFrameState) => void;
};

export type SpacefastFrameState =
  | { status: "loading" }
  | { status: "ready"; expiresAt: string }
  | { status: "error"; error: Error };

export type SpacefastFrameController = {
  readonly element: HTMLIFrameElement;
  refresh(): Promise<void>;
  destroy(): void;
};

function hasUnsafePathCharacter(value: string): boolean {
  for (const character of value) {
    const point = character.codePointAt(0);
    if (
      point === undefined ||
      point <= 0x1f ||
      point === 0x7f ||
      character === "\\" ||
      character === "?" ||
      character === "#"
    ) {
      return true;
    }
  }
  return false;
}

export function normalizeFramePath(input: string): string {
  const value = input.trim();
  if (!value.startsWith("/") || value.startsWith("//") || hasUnsafePathCharacter(value)) {
    throw new TypeError("Spacefast frame paths must be plain absolute paths.");
  }
  let decoded: string;
  try {
    decoded = decodeURIComponent(value);
  } catch {
    throw new TypeError("Spacefast frame paths cannot contain malformed percent encoding.");
  }
  if (/%2f|%5c/iu.test(value) || hasUnsafePathCharacter(decoded)) {
    throw new TypeError("Spacefast frame paths cannot contain ambiguous separators.");
  }
  const segments: string[] = [];
  for (const rawSegment of decoded.split("/")) {
    const segment = rawSegment.normalize("NFC");
    if (!segment || segment === ".") continue;
    if (segment === "..") {
      if (segments.length === 0) {
        throw new TypeError("Spacefast frame paths cannot escape the site root.");
      }
      segments.pop();
      continue;
    }
    segments.push(segment);
  }
  if (segments.at(-1) === "index.html") segments.pop();
  return segments.length === 0 ? "/" : `/${segments.join("/")}`;
}

export function frameAllowPolicy(permissions: readonly SpacefastFramePermission[] = []): string {
  const allowed = new Set(SPACEFAST_FRAME_PERMISSIONS);
  return (
    [...new Set(permissions)]
      .filter((permission) => allowed.has(permission))
      .toSorted()
      // An omitted iframe allowlist defaults to `'src'`, so the permission
      // follows the authenticated Spacefast URL without granting nested origins.
      .map((permission) => permission)
      .join("; ")
  );
}

export function assertFrameSession(session: SpacefastFrameSession): SpacefastFrameSession {
  let url: URL;
  try {
    url = new URL(session.url);
  } catch {
    throw new TypeError("The Spacefast frame session returned an invalid URL.");
  }
  if (url.protocol !== "https:" && !(url.protocol === "http:" && isLocalHost(url.hostname))) {
    throw new TypeError("Spacefast frame sessions must use HTTPS.");
  }
  const expiresAt = Date.parse(session.expiresAt);
  if (!Number.isFinite(expiresAt) || expiresAt <= Date.now()) {
    throw new TypeError("The Spacefast frame session is already expired.");
  }
  return session;
}

export function createSpacefastFrame(
  container: HTMLElement,
  options: SpacefastFrameOptions,
): SpacefastFrameController {
  const path = normalizeFramePath(options.path);
  const refreshBeforeMs = options.refreshBeforeMs ?? 60_000;
  if (!Number.isFinite(refreshBeforeMs) || refreshBeforeMs < 0) {
    throw new TypeError("refreshBeforeMs must be a non-negative number.");
  }

  // oxlint-disable-next-line react/iframe-missing-sandbox -- the caller can explicitly disable sandboxing; otherwise it is applied below before insertion.
  const frame = container.ownerDocument.createElement("iframe");
  frame.title = options.title.trim() || "Spacefast page";
  frame.loading = options.loading ?? "lazy";
  frame.referrerPolicy = options.referrerPolicy ?? "no-referrer";
  frame.allow = frameAllowPolicy(options.permissions);
  frame.className = options.className ?? "";
  frame.setAttribute("data-spacefast-frame", "");
  if (options.sandbox !== false) {
    frame.setAttribute(
      "sandbox",
      [...new Set(options.sandbox ?? DEFAULT_SPACEFAST_FRAME_SANDBOX)].join(" "),
    );
  }
  container.replaceChildren(frame);

  let destroyed = false;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let request: AbortController | undefined;

  const refresh = async () => {
    if (destroyed) return;
    request?.abort();
    request = new AbortController();
    if (timer !== undefined) clearTimeout(timer);
    options.onStateChange?.({ status: "loading" });
    try {
      const session = assertFrameSession(await options.session({ path, signal: request.signal }));
      if (destroyed || request.signal.aborted) return;
      frame.src = session.url;
      options.onStateChange?.({ status: "ready", expiresAt: session.expiresAt });
      const refreshIn = Math.max(
        1_000,
        Date.parse(session.expiresAt) - Date.now() - refreshBeforeMs,
      );
      timer = setTimeout(() => void refresh(), refreshIn);
    } catch (cause) {
      if (destroyed || request.signal.aborted) return;
      const error = cause instanceof Error ? cause : new Error("Spacefast frame session failed.");
      options.onStateChange?.({ status: "error", error });
      throw error;
    }
  };

  void refresh().catch(() => undefined);

  return {
    element: frame,
    refresh,
    destroy() {
      destroyed = true;
      request?.abort();
      if (timer !== undefined) clearTimeout(timer);
      frame.remove();
    },
  };
}

function isLocalHost(hostname: string): boolean {
  return hostname === "localhost" || hostname === "127.0.0.1" || hostname === "[::1]";
}
