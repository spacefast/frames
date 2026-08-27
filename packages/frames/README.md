# @spacefast/frames

Embed a Spacefast page without putting a Spacefast OAuth token in the browser.
The host application mints a short-lived, origin-bound frame session on its
server; this library mounts the iframe and renews that session before it expires.

```ts
import { createSpacefastFrame } from "@spacefast/frames";

createSpacefastFrame(document.querySelector("#demo")!, {
  path: "/pricing",
  title: "Pricing calculator",
  permissions: ["clipboard-write", "fullscreen"],
  session: async ({ path, signal }) => {
    const response = await fetch("/api/spacefast/frame-session", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ path }),
      signal,
    });
    if (!response.ok) throw new Error(`Frame session failed (${response.status}).`);
    return response.json();
  },
});
```

Create a Spacefast Link with `constraints.frameOrigin`, then launch sessions
from `/v1/spaces/{spaceId}/share-links/{linkId}/frame-session`. The launch
derives its origin, resources, capabilities, expiry, and revocation state from
that Link. The host server keeps its OAuth credential and Link token private.
Spacefast frame URLs are HTTPS-only except on loopback development hosts.
