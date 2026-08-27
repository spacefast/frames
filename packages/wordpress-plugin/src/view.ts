import {
  createSpacefastFrame,
  type SpacefastFramePermission,
  type SpacefastFrameSession,
} from "@spacefast/frames";

type FrameConfig = {
  endpoint: string;
  grant: string;
  path: string;
  title: string;
  loading: "eager" | "lazy";
  permissions: SpacefastFramePermission[];
  sandbox: string[];
};

function readConfig(element: HTMLElement): FrameConfig | null {
  const encoded = element.dataset.spacefastConfig;
  if (!encoded) return null;
  try {
    // SAFETY: PHP owns this JSON attribute and emits the exact FrameConfig contract.
    return JSON.parse(encoded) as FrameConfig;
  } catch {
    return null;
  }
}

async function requestSession(config: FrameConfig, signal: AbortSignal) {
  const response = await fetch(config.endpoint, {
    method: "POST",
    credentials: "same-origin",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ grant: config.grant }),
    signal,
  });
  // SAFETY: the branch below checks the one response member consumed before returning it.
  const body = (await response.json().catch(() => null)) as
    | SpacefastFrameSession
    | { message?: string }
    | null;
  if (!response.ok || !body || !("url" in body)) {
    throw new Error(
      body && "message" in body && body.message ? body.message : "The frame could not connect.",
    );
  }
  return body;
}

function mount(element: HTMLElement) {
  const config = readConfig(element);
  if (!config) return;
  const mountPoint = element.querySelector<HTMLElement>("[data-spacefast-frame-mount]");
  if (!mountPoint) return;
  const status = element.querySelector<HTMLElement>("[data-spacefast-frame-status]");
  createSpacefastFrame(mountPoint, {
    path: config.path,
    title: config.title,
    loading: config.loading,
    permissions: config.permissions,
    sandbox: config.sandbox,
    className: "spacefast-frame__iframe",
    session: ({ signal }) => requestSession(config, signal),
    onStateChange(state) {
      if (!status) return;
      status.dataset.state = state.status;
      status.textContent = state.status === "error" ? state.error.message : "";
      status.hidden = state.status !== "error";
    },
  });
}

for (const element of document.querySelectorAll<HTMLElement>("[data-spacefast-config]")) {
  mount(element);
}
