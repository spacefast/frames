/* oxlint-disable anti-slop/no-known-value-widening, anti-slop/no-runtime-typeof, anti-slop/no-unknown-parameters, anti-slop/no-unknown-returns, anti-slop/no-unsafe-dictionary-type, anti-slop/require-safety-comment-for-type-assertion -- Gutenberg exposes its packages as untyped wp globals; this file declares and narrows that host boundary. */
interface Window {
  wp: {
    apiFetch: (options: Record<string, unknown>) => Promise<unknown>;
    blockEditor: Record<string, unknown>;
    blocks: { registerBlockType: (name: string, settings: Record<string, unknown>) => void };
    components: Record<string, unknown>;
    element: Record<string, unknown>;
    i18n: { __: (text: string, domain?: string) => string };
  };
  SpacefastFramesBlock: { connected: boolean; settingsUrl: string };
}

type ElementFactory = (
  type: unknown,
  props?: Record<string, unknown> | null,
  ...children: unknown[]
) => unknown;
type Space = { id: string; title: string; liveUrl?: string };
type Page = { path: string; label: string };
type Attributes = {
  spaceId: string;
  linkId: string;
  spaceName: string;
  path: string;
  title: string;
  height: number;
  loading: "lazy" | "eager";
  permissions: string[];
  allowForms: boolean;
  allowDownloads: boolean;
  allowPopups: boolean;
};

const wordpressWindow = window as Window;
const wp = wordpressWindow.wp;
const el = wp.element.createElement as ElementFactory;
const { useEffect, useState } = wp.element as {
  useEffect: (effect: () => void | (() => void), dependencies: unknown[]) => void;
  useState: <T>(initial: T) => [T, (next: T) => void];
};
const { __ } = wp.i18n;
const { InspectorControls, useBlockProps } = wp.blockEditor as {
  InspectorControls: unknown;
  useBlockProps: (props?: Record<string, unknown>) => Record<string, unknown>;
};
const {
  Button,
  CheckboxControl,
  Notice,
  PanelBody,
  Placeholder,
  RangeControl,
  SelectControl,
  Spinner,
  TextControl,
  ToggleControl,
} = wp.components;

const PERMISSIONS = [
  ["accelerometer", __("Motion sensor", "spacefast-frames")],
  ["fullscreen", __("Fullscreen", "spacefast-frames")],
  ["clipboard-read", __("Read clipboard", "spacefast-frames")],
  ["clipboard-write", __("Write clipboard", "spacefast-frames")],
  ["autoplay", __("Autoplay media", "spacefast-frames")],
  ["camera", __("Camera", "spacefast-frames")],
  ["encrypted-media", __("Encrypted media", "spacefast-frames")],
  ["gyroscope", __("Gyroscope", "spacefast-frames")],
  ["microphone", __("Microphone", "spacefast-frames")],
  ["geolocation", __("Location", "spacefast-frames")],
  ["picture-in-picture", __("Picture in picture", "spacefast-frames")],
] as const;

function readList<T>(value: unknown, key: string): T[] {
  if (!value || typeof value !== "object") return [];
  const entries = (value as Record<string, unknown>)[key];
  return Array.isArray(entries) ? (entries as T[]) : [];
}

function SpacefastPageEdit(props: {
  attributes: Attributes;
  setAttributes: (attributes: Partial<Attributes>) => void;
}) {
  const { attributes, setAttributes } = props;
  const [spaces, setSpaces] = useState<Space[]>([]);
  const [pages, setPages] = useState<Page[]>([]);
  const [frameUrl, setFrameUrl] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!wordpressWindow.SpacefastFramesBlock.connected) return undefined;
    let active = true;
    setBusy(true);
    void wp.apiFetch({ path: "/spacefast/v1/spaces" }).then(
      (value) => {
        if (active) setSpaces(readList<Space>(value, "spaces"));
        if (active) setBusy(false);
      },
      (cause) => {
        if (active)
          setError(
            cause instanceof Error
              ? cause.message
              : __("Could not load spaces.", "spacefast-frames"),
          );
        if (active) setBusy(false);
      },
    );
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (!attributes.spaceId) {
      setPages([]);
      return undefined;
    }
    let active = true;
    setBusy(true);
    void wp
      .apiFetch({ path: `/spacefast/v1/spaces/${encodeURIComponent(attributes.spaceId)}/pages` })
      .then(
        (value) => {
          if (active) setPages(readList<Page>(value, "pages"));
          if (active) setBusy(false);
        },
        (cause) => {
          if (active)
            setError(
              cause instanceof Error
                ? cause.message
                : __("Could not load pages.", "spacefast-frames"),
            );
          if (active) setBusy(false);
        },
      );
    return () => {
      active = false;
    };
  }, [attributes.spaceId]);

  useEffect(() => {
    if (!attributes.spaceId || !attributes.path) {
      setFrameUrl("");
      return undefined;
    }
    if (attributes.linkId) return undefined;
    let active = true;
    setBusy(true);
    void wp
      .apiFetch({
        path: "/spacefast/v1/frame-links",
        method: "POST",
        data: { spaceId: attributes.spaceId, path: attributes.path },
      })
      .then(
        (value) => {
          const linkId =
            value && typeof value === "object" ? (value as Record<string, unknown>).linkId : null;
          if (active && typeof linkId === "string") setAttributes({ linkId });
          if (active) setBusy(false);
        },
        (cause) => {
          if (active)
            setError(
              cause instanceof Error
                ? cause.message
                : __("Could not create the Frame Link.", "spacefast-frames"),
            );
          if (active) setBusy(false);
        },
      );
    return () => {
      active = false;
    };
  }, [attributes.linkId, attributes.path, attributes.spaceId, setAttributes]);

  useEffect(() => {
    if (!attributes.spaceId || !attributes.linkId || !attributes.path) {
      setFrameUrl("");
      return undefined;
    }
    let active = true;
    setBusy(true);
    void wp
      .apiFetch({
        path: "/spacefast/v1/frame-session",
        method: "POST",
        data: {
          spaceId: attributes.spaceId,
          linkId: attributes.linkId,
          path: attributes.path,
        },
      })
      .then(
        (value) => {
          const url =
            value && typeof value === "object" ? (value as Record<string, unknown>).url : null;
          if (active) setFrameUrl(typeof url === "string" ? url : "");
          if (active) setBusy(false);
        },
        (cause) => {
          if (active)
            setError(
              cause instanceof Error
                ? cause.message
                : __("Could not open the preview.", "spacefast-frames"),
            );
          if (active) setBusy(false);
        },
      );
    return () => {
      active = false;
    };
  }, [attributes.linkId, attributes.path, attributes.spaceId]);

  const blockProps = useBlockProps({ className: "spacefast-frame-editor" });
  const spaceOptions = [
    { label: __("Choose a space", "spacefast-frames"), value: "" },
    ...spaces.map((space) => ({ label: space.title, value: space.id })),
  ];
  const pageOptions = [
    { label: __("Choose a page", "spacefast-frames"), value: "" },
    ...pages.map((page) => ({ label: page.label, value: page.path })),
  ];

  const selectSpace = (spaceId: string) => {
    const space = spaces.find((candidate) => candidate.id === spaceId);
    setAttributes({ spaceId, linkId: "", spaceName: space?.title ?? "", path: "/" });
  };
  const togglePermission = (permission: string, enabled: boolean) => {
    setAttributes({
      permissions: enabled
        ? [...new Set([...attributes.permissions, permission])]
        : attributes.permissions.filter((candidate) => candidate !== permission),
    });
  };
  const previewSandbox = [
    "allow-modals",
    "allow-popups-to-escape-sandbox",
    "allow-same-origin",
    "allow-scripts",
    ...(attributes.allowForms ? ["allow-forms"] : []),
    ...(attributes.allowDownloads ? ["allow-downloads"] : []),
    ...(attributes.allowPopups ? ["allow-popups"] : []),
  ].join(" ");

  const inspector = el(
    InspectorControls,
    null,
    el(
      PanelBody,
      { title: __("Page", "spacefast-frames"), initialOpen: true },
      el(SelectControl, {
        label: __("Space", "spacefast-frames"),
        value: attributes.spaceId,
        options: spaceOptions,
        onChange: selectSpace,
      }),
      el(SelectControl, {
        label: __("Published page", "spacefast-frames"),
        value: attributes.path,
        options: pageOptions,
        disabled: !attributes.spaceId,
        onChange: (path: string) => setAttributes({ path, linkId: "" }),
      }),
      el(TextControl, {
        label: __("Path", "spacefast-frames"),
        help: __(
          "Use a clean absolute route such as /calculator. This also limits frame navigation to that route and its children.",
          "spacefast-frames",
        ),
        value: attributes.path,
        disabled: !attributes.spaceId,
        onChange: (path: string) => setAttributes({ path, linkId: "" }),
      }),
      el(TextControl, {
        label: __("Accessible title", "spacefast-frames"),
        help: __("Describes the embedded page to screen-reader users.", "spacefast-frames"),
        value: attributes.title,
        onChange: (title: string) => setAttributes({ title }),
      }),
    ),
    el(
      PanelBody,
      { title: __("Layout", "spacefast-frames"), initialOpen: false },
      el(RangeControl, {
        label: __("Height", "spacefast-frames"),
        value: attributes.height,
        min: 240,
        max: 1600,
        step: 20,
        onChange: (height: number) => setAttributes({ height }),
      }),
      el(ToggleControl, {
        label: __("Load immediately", "spacefast-frames"),
        checked: attributes.loading === "eager",
        onChange: (eager: boolean) => setAttributes({ loading: eager ? "eager" : "lazy" }),
      }),
    ),
    el(
      PanelBody,
      { title: __("Browser permissions", "spacefast-frames"), initialOpen: false },
      el(
        "p",
        { className: "spacefast-frame-editor__help" },
        __("Enable only what this page needs.", "spacefast-frames"),
      ),
      ...PERMISSIONS.map(([permission, label]) =>
        el(CheckboxControl, {
          key: permission,
          label,
          checked: attributes.permissions.includes(permission),
          onChange: (enabled: boolean) => togglePermission(permission, enabled),
        }),
      ),
      el(ToggleControl, {
        label: __("Allow forms", "spacefast-frames"),
        checked: attributes.allowForms,
        onChange: (allowForms: boolean) => setAttributes({ allowForms }),
      }),
      el(ToggleControl, {
        label: __("Allow downloads", "spacefast-frames"),
        checked: attributes.allowDownloads,
        onChange: (allowDownloads: boolean) => setAttributes({ allowDownloads }),
      }),
      el(ToggleControl, {
        label: __("Allow popups", "spacefast-frames"),
        checked: attributes.allowPopups,
        onChange: (allowPopups: boolean) => setAttributes({ allowPopups }),
      }),
    ),
  );

  if (!wordpressWindow.SpacefastFramesBlock.connected) {
    return el(
      "div",
      blockProps,
      inspector,
      el(
        Placeholder,
        {
          icon: "embed-page",
          label: __("Spacefast page", "spacefast-frames"),
          instructions: __(
            "Connect WordPress to Spacefast before choosing a page.",
            "spacefast-frames",
          ),
        },
        el(
          Button,
          { variant: "primary", href: wordpressWindow.SpacefastFramesBlock.settingsUrl },
          __("Connect Spacefast", "spacefast-frames"),
        ),
      ),
    );
  }

  if (!attributes.spaceId) {
    return el(
      "div",
      blockProps,
      inspector,
      el(
        Placeholder,
        {
          icon: "embed-page",
          label: __("Spacefast page", "spacefast-frames"),
          instructions: __(
            "Pick a space and page. Private pages authenticate automatically for visitors.",
            "spacefast-frames",
          ),
        },
        busy
          ? el(Spinner)
          : el(SelectControl, {
              label: __("Space", "spacefast-frames"),
              hideLabelFromVision: true,
              value: "",
              options: spaceOptions,
              onChange: selectSpace,
            }),
      ),
    );
  }

  return el(
    "div",
    blockProps,
    inspector,
    error
      ? el(Notice, { status: "error", isDismissible: true, onRemove: () => setError("") }, error)
      : null,
    el(
      "div",
      { className: "spacefast-frame-editor__bar" },
      el("div", null, el("strong", null, attributes.spaceName), el("span", null, attributes.path)),
      busy ? el(Spinner) : null,
    ),
    frameUrl
      ? el("iframe", {
          className: "spacefast-frame-editor__preview",
          src: frameUrl,
          title: attributes.title,
          loading: "eager",
          sandbox: previewSandbox,
          style: { height: `${attributes.height}px` },
        })
      : el(
          "div",
          { className: "spacefast-frame-editor__empty" },
          __("Preparing preview…", "spacefast-frames"),
        ),
  );
}

wp.blocks.registerBlockType("spacefast/page", { edit: SpacefastPageEdit, save: () => null });
