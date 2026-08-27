import { copyFile, mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const buildDir = resolve(root, "build");
await mkdir(buildDir, { recursive: true });

async function bundle(options) {
  const result = await Bun.build(options);
  if (!result.success) {
    throw new AggregateError(result.logs, "WordPress asset build failed.");
  }
}

await Promise.all([
  bundle({
    entrypoints: [resolve(root, "src/editor.ts")],
    outdir: buildDir,
    naming: "editor.js",
    format: "iife",
    target: "browser",
    minify: true,
  }),
  bundle({
    entrypoints: [resolve(root, "src/view.ts")],
    outdir: buildDir,
    naming: "view.js",
    format: "iife",
    target: "browser",
    minify: true,
    alias: { "@spacefast/frames": resolve(root, "../frames/src/index.ts") },
  }),
  copyFile(resolve(root, "block.json"), resolve(buildDir, "block.json")),
  copyFile(resolve(root, "src/style.css"), resolve(buildDir, "style.css")),
  copyFile(resolve(root, "src/editor.css"), resolve(buildDir, "editor.css")),
  copyFile(resolve(root, "src/editor.asset.php"), resolve(buildDir, "editor.asset.php")),
  copyFile(resolve(root, "src/view.asset.php"), resolve(buildDir, "view.asset.php")),
  copyFile(resolve(root, "src/render.php"), resolve(buildDir, "render.php")),
]);
