#!/usr/bin/env node
/**
 * 폐지 예정 반응형 접두 클래스 보존 블록 — 기준 집합 산출과 Tailwind 입력 CSS 주입
 *
 * 번들 레이아웃·부품은 화면 폭 분기를 Tailwind 반응형 접두(`sm:`·`md:`·`lg:`·`xl:`·`2xl:`·`max-*:`·`min-[..]:`,
 * 확장은 `{접두}:md:` 형태) 대신 엔진 `responsive` 로 한다. 그러자 레이아웃 스캔으로 빌드 CSS 에 들어오던 반응형 접두
 * 클래스가 빠졌다. 이미 설치한 사이트가 편집기로 고쳐 DB 에 저장한 레이아웃과 사이트 자체 확장은 그 클래스를 계속 쓸 수
 * 있으므로, 마지막 공개 출시본의 빌드 CSS 에 있던 반응형 접두 클래스를 각 템플릿·확장의 Tailwind 입력 CSS 에
 * `@source inline(...)` 폐지 예정 블록으로 남긴다. 다음 출시 이후 블록과 기준 픽스처를 함께 지운다.
 *
 * 기준 집합을 고르는 규칙(대상별) — 출시본 빌드 CSS 에 실제로 있던 이름만 남긴다(사이트가 쓸 수 있었던 이름):
 *   - 공개 출시본(`--release`, 기본 origin/main)에 그 템플릿·확장이 없으면 대상이 아니다(설치한 사이트가 없다).
 *   - 출시본에 빌드 CSS 가 있으면 출시본의 빌드 CSS 에서 뽑는다. 출시본에 빌드 CSS 가 없던 확장은 대상이 아니다
 *     (그 확장의 접두 이름은 출시된 적이 없다).
 *   - 확장 출시본 손 CSS 가 싣던 접두 없는 반응형 클래스(예: 이커머스 `md:w-24`)는 지금 접두 빌드가 만들 수 없으므로
 *     관리자 템플릿 블록에 더한다(`merged`). 출시본에서 그 확장 화면은 관리자 템플릿 CSS 와 함께 그려졌다.
 *
 * 사용법:
 *   node scripts/extension-css/deprecated-responsive-classes.mjs list <git 참조> <빌드 CSS 경로>
 *       그 참조의 빌드 CSS 에서 반응형 접두 클래스 이름을 한 줄에 하나씩 낸다.
 *   node scripts/extension-css/deprecated-responsive-classes.mjs baseline [--release <참조>]
 *       위 규칙으로 기준 집합을 뽑아 시험 픽스처(`scripts/extension-css/__tests__/fixtures/deprecated-responsive-baseline.json`)에 쓴다.
 *       참조는 커밋 해시로 고정해 적는다. 생략하면 픽스처에 적힌 값을 다시 쓴다.
 *   node scripts/extension-css/deprecated-responsive-classes.mjs utilities
 *       두 템플릿의 출시본 빌드 CSS 에 있던 반응형 아닌 클래스 중 지금 빌드 CSS 에 없는 것(레이아웃이 더 쓰지 않아 빠진
 *       일반 유틸리티)을 픽스처의 `utilities` 에 더한다. 이미 적힌 이름은 지우지 않는다 — 보존 블록을 넣은 뒤에 다시
 *       돌리면 차집합이 비므로 줄어들지 않게 합친다. 템플릿을 빌드한 뒤에 돌린다.
 *   node scripts/extension-css/deprecated-responsive-classes.mjs inject
 *       픽스처의 기준 집합을 각 Tailwind 입력 CSS 의 폐지 예정 블록(`DEPRECATED-RESPONSIVE-START`~`END`)에 쓴다.
 *       블록이 있으면 그 안만 바꾸고, 없으면 템플릿은 마지막 최상위 `@source` 뒤, 확장은 생성 블록
 *       (`g7-extension-css:end`) 뒤에 넣는다. 대상이 아닌 번들 입력 CSS 에 블록이 있으면 걷어낸다. 다시 돌려도 결과가 같다.
 *
 * 입력 CSS: 템플릿 src/styles/main.css, 모듈·플러그인은 manifest `assets.css.entry`.
 * 관리자 템플릿의 `templates/_bundled/sirsoft-admin_basic/scripts/extract-safelist.cjs` 는 `SAFELIST-START`~`END` 구간만 다시 만들므로 이 블록을 건드리지 않는다.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import postcss from 'postcss';
import { classNamesInCss, hasWidthVariant, responsiveClassesInCss } from './lib/responsive-classes.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(here, '..', '..');
export const FIXTURE = path.join(here, '__tests__', 'fixtures', 'deprecated-responsive-baseline.json');
export const MARKER_START = '/* DEPRECATED-RESPONSIVE-START';
export const MARKER_END = '/* DEPRECATED-RESPONSIVE-END */';
const DIST_CSS_RE = /^(modules|plugins|templates)\/_bundled\/([^/]+)\/dist\/css\/[^/]+\.css$/;
const KINDS = [
  { kind: 'modules', manifest: 'module.json' },
  { kind: 'plugins', manifest: 'plugin.json' },
  { kind: 'templates', manifest: 'template.json' },
];

function git(args) {
  return execFileSync('git', ['-C', REPO, ...args], { encoding: 'utf8', maxBuffer: 1 << 28 });
}

function gitHas(ref, rel) {
  try {
    execFileSync('git', ['-C', REPO, 'cat-file', '-e', `${ref}:${rel}`], { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

function shortSha(ref) {
  return git(['rev-parse', '--short=10', `${ref}^{commit}`]).trim();
}

function distFilesAt(ref) {
  return git(['ls-tree', '-r', '--name-only', ref, '--', 'modules/_bundled', 'plugins/_bundled', 'templates/_bundled'])
    .split('\n')
    .filter((f) => DIST_CSS_RE.test(f))
    .sort();
}

function manifestOf(kind, id) {
  const k = KINDS.find((x) => x.kind === kind);
  const p = path.join(REPO, kind, '_bundled', id, k.manifest);
  return { rel: `${kind}/_bundled/${id}/${k.manifest}`, json: existsSync(p) ? JSON.parse(readFileSync(p, 'utf8')) : null };
}

/**
 * 빌드 CSS 경로에 대응하는 Tailwind 입력 CSS(저장소 기준 경로).
 *
 * @param {string} distRel 빌드 CSS 경로
 * @returns {string} 입력 CSS 경로
 */
export function inputCssFor(distRel) {
  const m = DIST_CSS_RE.exec(distRel);
  if (!m) throw new Error(`번들 빌드 CSS 경로가 아니다: ${distRel}`);
  const [, kind, id] = m;
  const root = `${kind}/_bundled/${id}`;
  if (kind === 'templates') return `${root}/src/styles/main.css`;
  const entry = manifestOf(kind, id).json?.assets?.css?.entry;
  if (!entry) throw new Error(`${root} manifest 에 assets.css.entry 가 없다`);
  return `${root}/${entry}`;
}

/**
 * 지금 빌드가 만들 수 있는 이름인가 — 접두 빌드 확장은 `{접두}:` 로 시작하는 이름만 만든다.
 *
 * @param {string} distRel 빌드 CSS 경로
 * @param {string} name 클래스 이름
 * @returns {boolean} 만들 수 있으면 true
 */
function buildable(distRel, name) {
  const [, kind, id] = DIST_CSS_RE.exec(distRel);
  if (kind === 'templates') return true;
  const ns = manifestOf(kind, id).json?.assets?.css?.namespace;
  if (!ns) return true;
  return name.startsWith(`${ns.replace(/[^a-z]/g, '')}:`);
}

/** 접두 없는 확장 반응형 클래스를 받는 관리자 템플릿 빌드 CSS */
const ADMIN_DIST = 'templates/_bundled/sirsoft-admin_basic/dist/css/components.css';

/**
 * 대상별 기준 집합.
 *
 * @param {string} release 공개 출시본 참조
 * @returns {{release: string, sets: Record<string, {ref: string, classes: string[], merged?: Record<string, string[]>}>, skipped: Record<string, string>}} 기준
 */
export function computeBaseline(release) {
  const rel = shortSha(release);
  const sets = {};
  const skipped = {};
  const merged = {};
  for (const f of distFilesAt('HEAD')) {
    const [, kind, id] = DIST_CSS_RE.exec(f);
    const { rel: manifestRel } = manifestOf(kind, id);
    if (!gitHas(rel, manifestRel)) {
      skipped[f] = '공개 출시본에 없는 확장';
      continue;
    }
    if (!gitHas(rel, f)) {
      skipped[f] = '공개 출시본에 빌드 CSS 가 없다(그 빌드의 이름은 출시된 적이 없다)';
      continue;
    }
    const all = responsiveClassesInCss(git(['show', `${rel}:${f}`]));
    const classes = all.filter((c) => buildable(f, c));
    const unprefixed = all.filter((c) => !buildable(f, c));
    if (unprefixed.length > 0) {
      merged[f] = unprefixed;
      skipped[f] = `접두 없는 이름 ${unprefixed.length}개는 관리자 템플릿 블록으로 옮겼다: ${unprefixed.join(' ')}`;
    }
    if (classes.length > 0) sets[f] = { ref: rel, classes };
    else if (unprefixed.length === 0) skipped[f] = '공개 출시본 빌드 CSS 에 반응형 접두 클래스가 없다';
  }
  if (Object.keys(merged).length > 0) {
    const admin = sets[ADMIN_DIST];
    if (!admin) throw new Error('관리자 템플릿 기준 집합이 없어 확장의 접두 없는 이름을 옮길 곳이 없다');
    admin.classes = [...new Set([...admin.classes, ...Object.values(merged).flat()])].sort();
    admin.merged = merged;
  }
  return { release: rel, sets, skipped };
}

/**
 * 폐지 예정 블록 문자열.
 *
 * @param {string[]} classes 클래스 이름
 * @param {string} ref 기준 커밋
 * @param {Record<string, string[]>} [merged] 더한 확장 손 CSS 이름(빌드 CSS 경로 → 이름)
 * @param {string[]} [utilities] 출시본에 있다가 지금 빌드에서 빠진 일반 유틸리티 이름
 * @returns {string} 블록
 */
export function renderBlock(classes, ref, merged = {}, utilities = []) {
  const extra = Object.entries(merged).map(
    ([f, list]) => ` * 지금 접두 빌드가 만들 수 없는 확장 출시본 손 CSS 의 접두 없는 이름도 더했다(${f.split('/')[2]}: ${list.join(' ')}).`,
  );
  const util = utilities.length > 0
    ? [
        ` * 출시본 빌드 CSS 에 있던 일반 유틸리티 중 번들 레이아웃이 더 쓰지 않아 지금 빌드에서 빠진 ${utilities.length}개도 같은 이유로 남긴다`,
        ' *   (사이트가 직접 쓴 레이아웃과 CSS 의 호환, `utilities` 명령으로 뽑는다).',
      ]
    : [];
  const names = [...classes, ...utilities];
  return [
    `${MARKER_START} — 폐지 예정(Deprecated): 다음 출시 이후 이 블록을 지운다.`,
    ' * 번들 레이아웃·부품은 화면 폭 분기를 엔진 `responsive` 로 하고 Tailwind 반응형 접두를 쓰지 않는다. 이미 설치한 사이트가',
    ' * 편집기로 고쳐 저장한 레이아웃과 사이트 자체 확장이 그대로 보이도록, 출시본 빌드 CSS 에 있던 반응형 접두 클래스를 남긴다.',
    ...extra,
    ...util,
    ` * 기준: 커밋 ${ref}(공개 출시본)의 빌드 CSS, ${names.length}개. 손으로 고치지 않는다 — 다시 만들 때:`,
    ' *   node scripts/extension-css/deprecated-responsive-classes.mjs inject',
    ' */',
    `@source inline("${names.join(' ')}");`,
    MARKER_END,
  ].join('\n');
}

/**
 * 출시본 빌드 CSS 에 있던 반응형 아닌 클래스 중 지금 빌드 CSS 에 없는 이름.
 *
 * @param {string} releaseCss 출시본 빌드 CSS 원문
 * @param {string} currentCss 지금 빌드 CSS 원문
 * @returns {string[]} 이름(정렬)
 */
export function missingUtilities(releaseCss, currentCss) {
  const current = classNamesInCss(currentCss);
  return [...classNamesInCss(releaseCss)].filter((c) => !current.has(c) && !hasWidthVariant(c)).sort();
}

/**
 * 입력 CSS 원문에서 블록을 걷어낸다(앞의 빈 줄 하나 포함).
 *
 * @param {string} css 입력 CSS 원문
 * @returns {string} 바뀐 원문
 */
export function removeBlock(css) {
  const s = css.indexOf(MARKER_START);
  if (s === -1) return css;
  const e = css.indexOf(MARKER_END, s);
  if (e === -1) throw new Error('DEPRECATED-RESPONSIVE-END 표식이 없다');
  const head = css.slice(0, s).replace(/\n\n$/, '');
  return head + css.slice(e + MARKER_END.length);
}

/**
 * 입력 CSS 원문에 블록을 넣거나 바꾼다.
 *
 * @param {string} css 입력 CSS 원문
 * @param {string} block 블록
 * @param {'template'|'extension'} kind 대상 종류
 * @returns {string} 바뀐 원문
 */
export function upsertBlock(css, block, kind) {
  const s = css.indexOf(MARKER_START);
  if (s !== -1) {
    const e = css.indexOf(MARKER_END, s);
    if (e === -1) throw new Error('DEPRECATED-RESPONSIVE-END 표식이 없다');
    return css.slice(0, s) + block + css.slice(e + MARKER_END.length);
  }
  let at;
  if (kind === 'extension') {
    const endMark = '/* g7-extension-css:end */';
    const i = css.indexOf(endMark);
    if (i === -1) throw new Error('g7-extension-css:end 표식이 없다');
    at = i + endMark.length;
  } else {
    const root = postcss.parse(css);
    let last = null;
    root.each((node) => {
      if (node.type === 'atrule' && node.name === 'source') last = node;
    });
    if (!last) throw new Error('최상위 @source 가 없다');
    at = last.source.end.offset;
    if (css[at - 1] !== ';') at = css.indexOf(';', at - 1) + 1;
  }
  return `${css.slice(0, at)}\n\n${block}${css.slice(at)}`;
}

/** 번들 템플릿·확장의 Tailwind 입력 CSS 전부(저장소 기준 경로). */
function allInputCss() {
  const out = [];
  for (const { kind, manifest } of KINDS) {
    const dir = path.join(REPO, kind, '_bundled');
    if (!existsSync(dir)) continue;
    for (const id of readdirSync(dir)) {
      if (!existsSync(path.join(dir, id, manifest))) continue;
      if (kind === 'templates') {
        out.push(`${kind}/_bundled/${id}/src/styles/main.css`);
        continue;
      }
      const entry = manifestOf(kind, id).json?.assets?.css?.entry;
      if (entry) out.push(`${kind}/_bundled/${id}/${entry}`);
    }
  }
  return out.filter((rel) => existsSync(path.join(REPO, rel)));
}

function argValue(rest, name) {
  const i = rest.indexOf(name);
  return i === -1 ? undefined : rest[i + 1];
}

function main(argv) {
  const [cmd, ...rest] = argv;
  if (cmd === 'list') {
    const [ref, rel] = rest;
    if (!ref || !rel) throw new Error('사용법: list <git 참조> <빌드 CSS 경로>');
    process.stdout.write(`${responsiveClassesInCss(git(['show', `${ref}:${rel}`])).join('\n')}\n`);
    return;
  }
  if (cmd === 'baseline') {
    const prev = existsSync(FIXTURE) ? JSON.parse(readFileSync(FIXTURE, 'utf8')) : {};
    const release = argValue(rest, '--release') ?? prev.release ?? 'origin/main';
    const base = computeBaseline(release);
    writeFileSync(FIXTURE, `${JSON.stringify(base, null, 2)}\n`);
    for (const [f, { ref, classes }] of Object.entries(base.sets)) console.log(`${classes.length}\t${ref}\t${f}`);
    for (const [f, why] of Object.entries(base.skipped)) console.log(`제외\t${f}\t${why}`);
    return;
  }
  if (cmd === 'utilities') {
    const base = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    for (const [distRel, set] of Object.entries(base.sets)) {
      if (!distRel.startsWith('templates/')) continue;
      const found = missingUtilities(git(['show', `${set.ref}:${distRel}`]), readFileSync(path.join(REPO, distRel), 'utf8'));
      set.utilities = [...new Set([...(set.utilities ?? []), ...found])].sort();
      console.log(`${set.utilities.length}\t(이번에 찾은 ${found.length})\t${distRel}`);
    }
    writeFileSync(FIXTURE, `${JSON.stringify(base, null, 2)}\n`);
    return;
  }
  if (cmd === 'inject') {
    const { sets } = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    const targeted = new Set();
    for (const [distRel, { ref, classes, merged, utilities }] of Object.entries(sets)) {
      const inputRel = inputCssFor(distRel);
      targeted.add(inputRel);
      const abs = path.join(REPO, inputRel);
      const css = readFileSync(abs, 'utf8');
      const kind = distRel.startsWith('templates/') ? 'template' : 'extension';
      const next = upsertBlock(css, renderBlock(classes, ref, merged, utilities), kind);
      if (next !== css) writeFileSync(abs, next);
      console.log(`${next === css ? '그대로' : '갱신'}\t${classes.length}\t${inputRel}`);
    }
    for (const inputRel of allInputCss()) {
      if (targeted.has(inputRel)) continue;
      const abs = path.join(REPO, inputRel);
      const css = readFileSync(abs, 'utf8');
      const next = removeBlock(css);
      if (next !== css) {
        writeFileSync(abs, next);
        console.log(`걷어냄\t${inputRel}`);
      }
    }
    return;
  }
  throw new Error('명령: list | baseline | utilities | inject');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    main(process.argv.slice(2));
  } catch (e) {
    console.error(e.message);
    process.exit(1);
  }
}
