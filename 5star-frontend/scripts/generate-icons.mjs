import sharp from 'sharp';
import { mkdirSync } from 'fs';

mkdirSync('public/icons', { recursive: true });

const src = 'scripts/icon-source.svg';

const sizes = [
  { file: 'public/icons/icon-192.png', size: 192 },
  { file: 'public/icons/icon-512.png', size: 512 },
  { file: 'public/icons/apple-touch-icon.png', size: 180 },
];

for (const { file, size } of sizes) {
  await sharp(src, { density: 384 }).resize(size, size).png().toFile(file);
  console.log('wrote', file);
}

// Maskable icon: same art but with safe-zone padding (icon content inside the
// inner ~80% so Android's mask doesn't crop the star/text).
await sharp(src, { density: 384 })
  .resize(410, 410)
  .extend({ top: 51, bottom: 51, left: 51, right: 51, background: '#16302a' })
  .png()
  .toFile('public/icons/icon-512-maskable.png');
console.log('wrote public/icons/icon-512-maskable.png');
