/**
 * 엔진 병합 함수 적재 — `StyleHelpers.ts` 원본을 esbuild 로 즉석 번들해 그대로 쓴다.
 *
 * 변환 도구가 「구간 className 을 classMerge 로 바꿔도 같은 결과」를 판정하려면 엔진과 **같은 함수**로
 * 계산해야 한다. 복사본을 두면 엔진 그룹 표가 바뀔 때 도구만 낡으므로 원본을 번들한다.
 *
 * 엔진의 `splitExtensionClassPrefix` 는 호출 시점에 `window.G7Config.extensionClassPrefixes` 를 읽는다.
 * 번들 맨 앞에 모듈 범위 `window` 를 선언해 전역을 건드리지 않고 앞글자 목록을 심는다.
 */
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

/** 저장소 루트 */
export const REPO_ROOT = path.resolve(here, '..', '..', '..');

/** 엔진 병합 규칙 원본 */
export const STYLE_HELPERS_TS = path.join(REPO_ROOT, 'resources', 'js', 'core', 'template-engine', 'helpers', 'StyleHelpers.ts');

const BANNER = [
  '// layout-classmerge 즉석 번들 — 엔진 원본 StyleHelpers.ts (수정 금지, 실행마다 다시 만든다)',
  'let window = { G7Config: { extensionClassPrefixes: [] } };',
  'export function __setExtensionClassPrefixes(list) { window.G7Config.extensionClassPrefixes = Array.isArray(list) ? [...list] : []; }',
].join('\n');

let cached = null;

/**
 * 확장 앞글자 목록을 계산한다 (PHP `App\Support\Style\ExtensionClassPrefixes::collect` 와 같은 계산).
 *
 * `modules/_bundled/*\/module.json`, `plugins/_bundled/*\/plugin.json` 의 `assets.css.namespace` 에서
 * 영문 소문자만 남긴다(`pay-kginicis` → `paykginicis`). 빈 값은 빼고, 처음 나온 순서로 중복 없이 모은다.
 *
 * @param {string} [repoRoot] 저장소 루트
 * @returns {string[]} 앞글자 목록
 */
export function loadExtensionPrefixes(repoRoot = REPO_ROOT) {
  const prefixes = [];
  for (const [dir, manifest] of [
    ['modules/_bundled', 'module.json'],
    ['plugins/_bundled', 'plugin.json'],
  ]) {
    const base = path.join(repoRoot, dir);
    if (!fs.existsSync(base)) continue;
    for (const entry of fs.readdirSync(base).sort()) {
      const file = path.join(base, entry, manifest);
      if (!fs.existsSync(file)) continue;
      let json;
      try {
        json = JSON.parse(fs.readFileSync(file, 'utf8'));
      } catch {
        continue;
      }
      const css = json?.assets?.css;
      const namespace = css && typeof css === 'object' && !Array.isArray(css) ? css.namespace : undefined;
      if (typeof namespace !== 'string' || namespace === '') continue;
      const letters = namespace.replace(/[^a-z]/g, '');
      if (letters !== '' && !prefixes.includes(letters)) prefixes.push(letters);
    }
  }
  return prefixes;
}

/**
 * 엔진 병합 함수를 적재한다 (프로세스당 한 번 번들).
 *
 * @param {{prefixes?: string[], source?: string}} [options] prefixes: 확장 앞글자 목록, source: 번들할 TS 경로
 * @returns {Promise<{mergeClasses: Function, applyResponsiveClassMerge: Function, splitExtensionClassPrefix: Function, prefixes: string[]}>}
 */
export async function loadMergeEngine(options = {}) {
  const source = options.source ?? STYLE_HELPERS_TS;
  if (!cached || cached.source !== source) {
    cached = { source, mod: await bundleAndImport(source) };
  }
  const prefixes = options.prefixes ?? [];
  cached.mod.__setExtensionClassPrefixes(prefixes);
  return {
    mergeClasses: cached.mod.mergeClasses,
    applyResponsiveClassMerge: cached.mod.applyResponsiveClassMerge,
    splitExtensionClassPrefix: cached.mod.splitExtensionClassPrefix,
    prefixes: [...prefixes],
  };
}

async function bundleAndImport(source) {
  const require = createRequire(path.join(REPO_ROOT, 'package.json'));
  let esbuild;
  try {
    esbuild = require('esbuild');
  } catch (error) {
    throw new Error(`esbuild 를 찾지 못했습니다(node_modules/esbuild). npm install 후 다시 실행하세요: ${error.message}`);
  }
  const result = await esbuild.build({
    entryPoints: [source],
    bundle: true,
    write: false,
    format: 'esm',
    platform: 'neutral',
    target: 'es2020',
    banner: { js: BANNER },
    logLevel: 'silent',
  });
  const code = result.outputFiles[0].text;
  for (const name of ['mergeClasses', 'applyResponsiveClassMerge', 'splitExtensionClassPrefix']) {
    if (!new RegExp(`\\b${name}\\b`).test(code)) {
      throw new Error(`StyleHelpers 번들에 ${name} 가 없습니다 — 엔진 export 이름이 바뀌었는지 확인하세요`);
    }
  }
  const hash = createHash('sha1').update(code).digest('hex').slice(0, 12);
  const out = path.join(os.tmpdir(), `g7-layout-classmerge-stylehelpers-${hash}.mjs`);
  if (!fs.existsSync(out)) fs.writeFileSync(out, code);
  return import(pathToFileURL(out).href);
}
