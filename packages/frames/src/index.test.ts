import { describe, expect, test } from "bun:test";

import { assertFrameSession, frameAllowPolicy, normalizeFramePath } from "./index.js";

describe("normalizeFramePath", () => {
  test("canonicalizes page paths", () => {
    expect(normalizeFramePath("/guides/./start/index.html")).toBe("/guides/start");
    expect(normalizeFramePath("/guides/next/../start")).toBe("/guides/start");
    expect(normalizeFramePath("/")).toBe("/");
  });

  test("rejects URL-shaped and root-escaping input", () => {
    for (const path of [
      "https://example.com/page",
      "//example.com",
      "/../../secret",
      "/a%2Fb",
      "/a?b=1",
    ]) {
      expect(() => normalizeFramePath(path)).toThrow();
    }
  });
});

test("frameAllowPolicy emits only supported, unique permissions", () => {
  expect(frameAllowPolicy(["fullscreen", "camera", "fullscreen"])).toBe("camera; fullscreen");
});

test("assertFrameSession requires a live HTTPS session", () => {
  const expiresAt = new Date(Date.now() + 60_000).toISOString();
  expect(assertFrameSession({ url: "https://demo.view.fast/?__=sfv_token", expiresAt })).toEqual({
    url: "https://demo.view.fast/?__=sfv_token",
    expiresAt,
  });
  expect(() => assertFrameSession({ url: "http://demo.example/page", expiresAt })).toThrow(
    "must use HTTPS",
  );
  expect(() =>
    assertFrameSession({
      url: "https://demo.view.fast/",
      expiresAt: new Date(Date.now() - 1_000).toISOString(),
    }),
  ).toThrow("already expired");
});
