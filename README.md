# Spacefast Frames

Secure Spacefast embeds for websites, WordPress, dashboards, and MCP Apps.

This repository contains two maintained integrations:

- [`@spacefast/frames`](packages/frames) — a small browser library that mounts a Spacefast iframe and renews its short-lived frame session.
- [`Spacefast Frames for WordPress`](packages/wordpress-plugin) — a native dynamic Gutenberg block with OAuth PKCE, multi-space page selection, path and capability controls, and server-side session minting.

The durable OAuth credential and Frame Link token stay on the host server. The browser receives only an origin-bound, short-lived frame session. Public Spaces can use the same embed path without requiring a viewer login; the Link still defines the allowed origin and capabilities.

## Develop

Requirements: [Bun](https://bun.sh/) 1.3.14 or newer and PHP 8.1 or newer.

```sh
bun install
bun run check
```

Build the WordPress plugin, then create an installable ZIP:

```sh
bun run build:wordpress
cd packages/wordpress-plugin
zip -r spacefast-frames.zip spacefast-frames.php includes build README.md
```

## License

MIT
