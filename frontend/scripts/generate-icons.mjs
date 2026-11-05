// Generates the installed app's PNG icons from the logo, app/icon.svg. Run again if the logo changes:
//   node scripts/generate-icons.mjs
import { mkdir, readFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import sharp from "sharp";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const BRAND_GREEN = "#059669";
const LOGO_VIEWBOX = 48; // the viewBox of app/icon.svg

const logo = await readFile(join(root, "app/icon.svg"), "utf8");
const car = logo.match(/<g transform="[^"]*"([^>]*)>([\s\S]*)<\/g>/);
if (!car) throw new Error("Could not find the car in app/icon.svg");

// a density that makes the SVG exactly the wanted size, so nothing is scaled twice
const render = (svg, size, base = LOGO_VIEWBOX) => sharp(Buffer.from(svg), { density: (72 * size) / base }).resize(size, size);

await mkdir(join(root, "public/icons"), { recursive: true });

// "any" icons: the logo as it is, rounded corners included
for (const size of [192, 512]) {
  await render(logo, size).png({ compressionLevel: 9 }).toFile(join(root, `public/icons/icon-${size}.png`));
}

// iOS rounds the corners itself and turns transparency black, so this one fills its square
await render(logo.replace(' rx="12"', ""), 180)
  .flatten({ background: BRAND_GREEN })
  .png({ compressionLevel: 9 })
  .toFile(join(root, "public/apple-touch-icon.png"));

// maskable: launchers crop to any shape inside the middle 80%, so the car alone sits there on solid green.
// The car's 24-unit box is scaled to 384 of 512, and its corners stay inside that circle.
const SCALE = 16;
const maskable = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <rect width="512" height="512" fill="${BRAND_GREEN}"/>
  <g transform="translate(${256 - 12 * SCALE} ${256 - 13 * SCALE}) scale(${SCALE})"${car[1]}>${car[2]}</g>
</svg>`;
await render(maskable, 512, 512).flatten({ background: BRAND_GREEN }).png({ compressionLevel: 9 }).toFile(join(root, "public/icons/icon-maskable-512.png"));
