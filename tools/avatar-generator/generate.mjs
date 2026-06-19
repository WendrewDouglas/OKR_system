/**
 * generate.mjs — Gerador OFFLINE da galeria de avatares padrao do OKR System.
 *
 * Usa DiceBear (estilo "avataaars") para produzir ~110+ SVGs padronizados e
 * diversos: varias etnias / tons de pele, tipos de cabelo, barba e bigode
 * (masculinos), penteados e hijab (femininos), e acessorios como oculos,
 * chapeus e turbante.
 *
 * Saida:
 *   - assets/img/avatars/gallery/av_{m|f|n}_NNN.svg
 *   - assets/img/avatars/gallery/manifest.json  (consumido pelo seeder PHP 007)
 *
 * Uso:
 *   cd tools/avatar-generator && npm install && npm run generate
 *
 * Observacao sobre brincos/colares: o estilo "avataaars" nao modela brincos
 * nem colares. A diversidade feminina vem de penteados, cores de cabelo, oculos
 * e hijab. Para brincos/colares seria preciso outro estilo (ex.: micah/lorelei),
 * ao custo de um visual diferente — trocar STYLE abaixo se desejado.
 */

import { createAvatar } from '@dicebear/core';
import { avataaars } from '@dicebear/collection';
import { mkdirSync, writeFileSync, readdirSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const STYLE = avataaars;
const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT_DIR = join(__dirname, '..', '..', 'assets', 'img', 'avatars', 'gallery');

/* ============ util ============ */
function build(opts) {
  return createAvatar(STYLE, { size: 256, ...opts }).toString();
}
// Mantem apenas ids de opcao realmente suportados nesta versao do DiceBear.
function validate(key, ids, extra = {}) {
  const ok = [];
  for (const id of ids) {
    try {
      build({ seed: 'probe', [key]: [id], ...extra });
      ok.push(id);
    } catch (_) { /* id inexistente nesta versao */ }
  }
  return ok;
}

/* ============ paletas ============ */
const SKINS = [
  { hex: 'ffdbb4', tag: 'pele_clara' },
  { hex: 'edb98a', tag: 'pele_clara_media' },
  { hex: 'd08b5b', tag: 'pele_media' },
  { hex: 'ae5d29', tag: 'pele_morena' },
  { hex: '8d5524', tag: 'pele_castanha' },
  { hex: '613d2b', tag: 'pele_escura' },
  { hex: '3b2219', tag: 'pele_negra' },
];

const HAIR_COLORS = ['2c1b18', '4a312c', '724133', 'a55728', 'b58143', 'd6b370', 'c93305', 'e8e1e1'];

// Pools candidatos (sao filtrados por validate() conforme a versao instalada).
const MASC_HAIR = validate('top', [
  'shortFlat', 'shortRound', 'shortWaved', 'shortCurly', 'theCaesar',
  'theCaesarAndSidePart', 'sides', 'dreads01', 'dreads02', 'frizzle',
  'shaggy', 'shaggyMullet', 'shavedSides', 'caesar',
]);
const FEM_HAIR = validate('top', [
  'longButNotTooLong', 'bob', 'curly', 'curvy', 'straight01', 'straight02',
  'straightAndStrand', 'bigHair', 'miaWallace', 'bun', 'fro', 'froBand', 'frida',
]);
const HATS = validate('top', ['hat', 'winterHat1', 'winterHat02', 'winterHat03', 'winterHat04']);
const TURBAN = validate('top', ['turban']);
const HIJAB = validate('top', ['hijab']);
const BEARDS = validate('facialHair', ['beardMedium', 'beardLight', 'beardMajestic'], { facialHairProbability: 100 });
const MOUSTACHE = validate('facialHair', ['moustacheFancy', 'moustacheMagnum'], { facialHairProbability: 100 });
const GLASSES = validate('accessories', ['prescription01', 'prescription02', 'round', 'sunglasses', 'wayfarers'], { accessoriesProbability: 100 });

console.log('[pools validados]',
  'masc_hair=' + MASC_HAIR.length,
  'fem_hair=' + FEM_HAIR.length,
  'hats=' + HATS.length,
  'turban=' + TURBAN.length,
  'hijab=' + HIJAB.length,
  'beards=' + BEARDS.length,
  'moust=' + MOUSTACHE.length,
  'glasses=' + GLASSES.length
);

const pick = (arr, i) => (arr.length ? arr[i % arr.length] : undefined);

/* ============ geracao ============ */
mkdirSync(OUT_DIR, { recursive: true });
// limpa galeria anterior (apenas SVGs gerados + manifest), idempotente
for (const f of readdirSync(OUT_DIR)) {
  if (/^av_[mfn]_\d+\.svg$/.test(f) || f === 'manifest.json') {
    rmSync(join(OUT_DIR, f));
  }
}

const manifest = [];
const counters = { m: 0, f: 0, n: 0 };

function emit(gender, opts, tags) {
  const code = gender === 'masculino' ? 'm' : gender === 'feminino' ? 'f' : 'n';
  counters[code] += 1;
  const num = String(counters[code]).padStart(3, '0');
  const file = `av_${code}_${num}.svg`;
  const seed = `okr-${code}-${num}`;

  let svg;
  let finalTags = tags;
  try {
    svg = build({ seed, ...opts });
  } catch (_) {
    // fallback minimo: garante saida valida mantendo genero + tom de pele
    svg = build({ seed, skinColor: opts.skinColor, facialHairProbability: opts.facialHairProbability ?? 0 });
    finalTags = tags.filter((t) => t.startsWith('pele_') || ['masculino', 'feminino', 'neutro'].includes(t));
  }
  writeFileSync(join(OUT_DIR, file), svg, 'utf8');
  manifest.push({ file, gender, tags: finalTags });
}

SKINS.forEach((skin, si) => {
  const sc = [skin.hex];
  const hc = (k) => [pick(HAIR_COLORS, si + k)];

  /* ---- Masculino (~6 por tom) ---- */
  const mh = (k) => [pick(MASC_HAIR, si + k)];
  // 1. cabelo curto, sem barba
  emit('masculino', { skinColor: sc, top: mh(0), hairColor: hc(0), facialHairProbability: 0 },
    [skin.tag, 'masculino', 'cabelo_curto']);
  // 2. com barba
  emit('masculino', { skinColor: sc, top: mh(1), hairColor: hc(1), facialHair: [pick(BEARDS, si)], facialHairProbability: 100 },
    [skin.tag, 'masculino', 'cabelo_curto', 'barba']);
  // 3. com bigode
  emit('masculino', { skinColor: sc, top: mh(2), hairColor: hc(2), facialHair: [pick(MOUSTACHE, si)], facialHairProbability: 100 },
    [skin.tag, 'masculino', 'bigode']);
  // 4. com oculos
  emit('masculino', { skinColor: sc, top: mh(3), hairColor: hc(3), facialHairProbability: 0, accessories: [pick(GLASSES, si)], accessoriesProbability: 100 },
    [skin.tag, 'masculino', 'oculos']);
  // 5. barba + oculos
  emit('masculino', { skinColor: sc, top: mh(4), hairColor: hc(4), facialHair: [pick(BEARDS, si + 1)], facialHairProbability: 100, accessories: [pick(GLASSES, si + 1)], accessoriesProbability: 100 },
    [skin.tag, 'masculino', 'barba', 'oculos']);
  // 6. chapeu OU turbante
  if (si % 2 === 0 && HATS.length) {
    emit('masculino', { skinColor: sc, top: [pick(HATS, si)], facialHairProbability: 0 },
      [skin.tag, 'masculino', 'chapeu']);
  } else if (TURBAN.length) {
    emit('masculino', { skinColor: sc, top: TURBAN, facialHairProbability: 0 },
      [skin.tag, 'masculino', 'turbante']);
  } else {
    emit('masculino', { skinColor: sc, top: mh(5), hairColor: hc(5), facialHairProbability: 0 },
      [skin.tag, 'masculino', 'cabelo_curto']);
  }

  /* ---- Feminino (~6 por tom) ---- */
  const fh = (k) => [pick(FEM_HAIR, si + k)];
  emit('feminino', { skinColor: sc, top: fh(0), hairColor: hc(0), facialHairProbability: 0 },
    [skin.tag, 'feminino', 'cabelo_longo']);
  emit('feminino', { skinColor: sc, top: fh(1), hairColor: hc(2), facialHairProbability: 0 },
    [skin.tag, 'feminino', 'cabelo_longo']);
  emit('feminino', { skinColor: sc, top: fh(2), hairColor: hc(4), facialHairProbability: 0, accessories: [pick(GLASSES, si)], accessoriesProbability: 100 },
    [skin.tag, 'feminino', 'oculos']);
  emit('feminino', { skinColor: sc, top: fh(3), hairColor: hc(1), facialHairProbability: 0 },
    [skin.tag, 'feminino', 'cabelo_longo']);
  emit('feminino', { skinColor: sc, top: fh(4), hairColor: hc(3), facialHairProbability: 0 },
    [skin.tag, 'feminino', 'cabelo_longo']);
  if (HIJAB.length) {
    emit('feminino', { skinColor: sc, top: HIJAB, facialHairProbability: 0 },
      [skin.tag, 'feminino', 'hijab']);
  } else {
    emit('feminino', { skinColor: sc, top: fh(5), hairColor: hc(5), facialHairProbability: 0 },
      [skin.tag, 'feminino', 'cabelo_longo']);
  }

  /* ---- Neutro (~4 por tom) ---- */
  emit('todos', { skinColor: sc, top: mh(2), hairColor: hc(0), facialHairProbability: 0 },
    [skin.tag, 'neutro']);
  emit('todos', { skinColor: sc, top: fh(1), hairColor: hc(3), facialHairProbability: 0 },
    [skin.tag, 'neutro']);
  emit('todos', { skinColor: sc, top: mh(3), hairColor: hc(2), facialHairProbability: 0, accessories: [pick(GLASSES, si + 2)], accessoriesProbability: 100 },
    [skin.tag, 'neutro', 'oculos']);
  emit('todos', { skinColor: sc, top: fh(4), hairColor: hc(1), facialHairProbability: 0 },
    [skin.tag, 'neutro']);
});

writeFileSync(join(OUT_DIR, 'manifest.json'), JSON.stringify(manifest, null, 2), 'utf8');

console.log(`[ok] gerados ${manifest.length} avatares em ${OUT_DIR}`);
console.log(`     masculino=${counters.m} feminino=${counters.f} neutro=${counters.n}`);
