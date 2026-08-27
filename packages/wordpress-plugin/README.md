# Spacefast Frames for WordPress

An installable WordPress plugin with a native dynamic Gutenberg block for
authenticated Spacefast pages.

## What it includes

- OAuth Authorization Code + PKCE connection with refresh-token rotation
- Team-scoped consent and multi-space selection
- Live page discovery from each Space's published file catalog
- Spacefast Frame Links with origin, path, capability, expiry, and revocation controls
- Server-minted short-lived frame sessions; OAuth credentials never reach the browser
- Per-block height, lazy loading, sandbox controls, and browser permissions
- Wide/full alignment, anchors, and WordPress spacing controls

## Build and install

```sh
bun run build:wordpress
cd packages/wordpress-plugin
zip -r spacefast-frames.zip spacefast-frames.php includes build README.md
```

Upload the ZIP in **Plugins → Add Plugin**, activate it, then open
**Settings → Spacefast Frames** and connect a Spacefast account. Add a
**Spacefast page** block and choose any accessible Space and published HTML page.

The plugin stores OAuth credentials encrypted with keys derived from the
WordPress auth salts. Each block stores a Spacefast Frame Link ID. Browser
markup receives only a signed WordPress block grant; the OAuth credential and
durable Spacefast Link token never leave PHP.
