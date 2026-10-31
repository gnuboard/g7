#!/usr/bin/env node
/**
 * 확장 레이아웃 클래스 변환기 — 템플릿 CSS 의존 클래스를 확장 자기 CSS 이름으로 바꾼다
 *
 * 확장 화면이 템플릿 CSS(Tailwind 유틸리티·의미 클래스)에 기대지 않고 스스로 스타일을 싣도록,
 * 레이아웃·조각의 클래스 토큰을 다음 규칙으로 바꾼다.
 *
 * | 분류 | 판정 | 변환 |
 * |---|---|---|
 * | 자기 것 | `{ns}-…`·`{letters}:…` | 유지 |
 * | Tailwind 표지 | `group`·`peer`·`group/x`·`peer/x` | `{letters}:group` 등 |
 * | Tailwind 유틸리티 | 접두 진입 CSS 로 컴파일해 선택자가 나옴 | `{letters}:<원 토큰>`(변형·`!`·음수 포함) |
 * | 템플릿 의미 클래스 | 템플릿 원본 CSS 에 정의된 비-Tailwind 클래스 | `{ns}-{token}`(나눈 사용자 쪽은 `{ns}-user-{token}`) |
 * | FontAwesome | `fa`·`fas`·`far`·`fab`·`fa-*` | 바꾸지 않고 보고 |
 * | 미분류 | 그 밖 | 바꾸지 않고 보고 |
 *
 * 의미 클래스 정의는 템플릿 빌드 산출에서 복사해 `<확장>/resources/css/g7-extension.generated.css` 에 쓰고,
 * `<확장>/resources/css/main.css` 맨 앞에 Tailwind 접두 빌드 진입 블록을 생성·갱신한다.
 *
 * 사용:
 *   node scripts/extension-css/convert-layout-classes.mjs <확장경로> [--dry-run] [--report <json>]
 *     [--namespace <ns>] [--php <glob>]… [--php-context admin|user] [--admin-template <dir>] [--user-template <dir>] [--repo-root <dir>]
 *
 * - namespace 는 확장 manifest(`module.json`·`plugin.json`)의 `assets.css.namespace` 를 읽는다.
 *   없으면 `--namespace` 로 준다. 둘 다 있고 다르면 오류다.
 * - `--php <glob>` 은 확장 경로 기준 글롭이다. PHP 의 `'class' =>`·`'className' =>`·`'*ClassName' =>` 문자열 값을 같은 규칙으로 바꾼다.
 * - PHP 의 사용처(관리자/사용자)는 `--php-context` 로 준다. 없으면 경로에 admin 이 있을 때 관리자, 아니면 양쪽으로 보고
 *   두 템플릿 정의가 같거나 한쪽에만 있는 의미 클래스만 바꾼다.
 * - `--dry-run` 은 아무 파일도 쓰지 않는다(`--report` 보고서만 쓴다).
 * - 같은 입력에 다시 돌려도 결과가 같다(멱등). 이미 바뀐 `{ns}-…` 토큰에서 복사할 의미 클래스를 다시 찾는다.
 *
 * 종료 코드: 0 성공, 1 실행 오류, 2 사용 오류.
 */
import { readFileSync, writeFileSync, existsSync, readdirSync, statSync, mkdirSync, globSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
  analyzeClassValue, applyEdits, scanJsonClassSlots, scanHtmlClassSlots, scanPhpClassSlots, lineOf,
} from './lib/class-tokens.mjs';
import { createTailwindClassifier, buildEntryCss, resolveTypography, cssEscape } from './lib/tailwind-classifier.mjs';
import { loadTemplateIndex, unionTheme, buildSemanticCss, classesOf, copyableSelectors } from './lib/template-css.mjs';
import postcss from 'postcss';

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_REPO_ROOT = path.resolve(SCRIPT_DIR, '..', '..');
const NAMESPACE_RE = /^[a-z][a-z0-9]*(-[a-z0-9]+)*$/;
const BLOCK_START = '/* g7-extension-css:start — convert-layout-classes.mjs 가 생성·갱신하는 블록. 손으로 고치지 않는다. */';
const BLOCK_END = '/* g7-extension-css:end */';
const GENERATED_FILE = 'g7-extension.generated.css';
const MARKER_RE = /^(group|peer)(\/[\w-]+)?$/;
const FONTAWESOME_RE = /^(fa|fas|far|fab|fal|fad|fat|fa-solid|fa-regular|fa-brands|fa-light|fa-duotone|fa-[\w-]+)$/;
const CATEGORIES = ['own', 'marker', 'tailwind', 'semantic', 'fontawesome', 'unclassified', 'manual'];

/**
 * namespace 에서 Tailwind 접두(a-z 만)를 만든다.
 *
 * @param {string} ns namespace
 * @returns {string} letters
 */
export function lettersOf(ns) {
  return ns.replace(/[^a-z]/g, '');
}

/**
 * 디렉터리 아래 파일(재귀, posix 상대 경로).
 *
 * @param {string} root 기준
 * @param {string} dir 디렉터리
 * @param {(name: string) => boolean} accept 이름 판정
 * @returns {string[]} 상대 경로
 */
function walk(root, dir, accept) {
  const full = path.join(root, dir);
  if (!existsSync(full)) return [];
  const out = [];
  for (const name of readdirSync(full).sort()) {
    const rel = `${dir}/${name}`;
    if (statSync(path.join(root, rel)).isDirectory()) {
      if (name !== 'node_modules') out.push(...walk(root, rel, accept));
    } else if (accept(name)) out.push(rel);
  }
  return out;
}

/**
 * 확장 manifest 를 읽는다.
 *
 * @param {string} extPath 확장 경로
 * @returns {{ file: string|null, data: Record<string, any> }} manifest
 */
function readManifest(extPath) {
  for (const name of ['module.json', 'plugin.json']) {
    const file = path.join(extPath, name);
    if (existsSync(file)) return { file: name, data: JSON.parse(readFileSync(file, 'utf8')) };
  }
  return { file: null, data: {} };
}

/**
 * 레이아웃 이름·확장 포인트로 조각의 사용처(관리자/사용자)를 판정하는 색인.
 *
 * @param {object} opts 옵션
 * @param {string} opts.repoRoot 저장소 루트
 * @param {string} opts.adminTemplate 관리자 템플릿 경로
 * @param {string} opts.userTemplate 사용자 템플릿 경로
 * @returns {(rel: string, fragmentText: string|null) => {side: 'admin'|'user'|'both', basis: string}} 판정 함수
 */
function createContextResolver({ repoRoot, adminTemplate, userTemplate }) {
  let index = null;
  const build = () => {
    index = { admin: { names: new Set(), points: new Set() }, user: { names: new Set(), points: new Set() } };
    const add = (side, root) => {
      walk(root, '.', (n) => n.endsWith('.json')).forEach((rel) => {
        const clean = rel.replace(/^\.\//, '').replace(/\.json$/, '');
        index[side].names.add(clean);
        index[side].names.add(path.posix.basename(clean));
        const text = readFileSync(path.join(root, rel), 'utf8');
        const ln = /"layout_name"\s*:\s*"([^"]+)"/.exec(text);
        if (ln) index[side].names.add(ln[1]);
        for (const m of text.matchAll(/"name"\s*:\s*"([^"]+)"/g)) index[side].points.add(m[1]);
      });
    };
    add('admin', path.join(adminTemplate, 'layouts'));
    add('user', path.join(userTemplate, 'layouts'));
    for (const kind of ['modules', 'plugins']) {
      const base = path.join(repoRoot, kind, '_bundled');
      if (!existsSync(base)) continue;
      readdirSync(base).forEach((id) => {
        add('admin', path.join(base, id, 'resources', 'layouts', 'admin'));
        add('user', path.join(base, id, 'resources', 'layouts', 'user'));
      });
    }
  };
  const byName = (name) => {
    const fileName = path.posix.basename(name);
    if (/^admin[-_]/.test(fileName)) return 'admin';
    if (/^(user|checkout|shop|mypage|auth)[-_]/.test(fileName)) return 'user';
    return null;
  };
  return (rel, fragmentText) => {
    // rel 은 확장 기준 `resources/layouts/admin/…` 형태
    const parts = rel.split('/');
    if (parts[1] === 'layouts' && (parts[2] === 'admin' || parts[2] === 'user')) return { side: parts[2], basis: 'path' };
    if (fragmentText !== null) {
      const target = /"target_layout"\s*:\s*"([^"]+)"/.exec(fragmentText) ?? /target-layout="([^"]+)"/.exec(fragmentText);
      const point = /"extension_point"\s*:\s*"([^"]+)"/.exec(fragmentText) ?? /extension-point="([^"]+)"/.exec(fragmentText);
      if (!index) build();
      const key = target ? target[1] : point ? point[1] : null;
      if (key) {
        const set = target ? 'names' : 'points';
        const inAdmin = index.admin[set].has(key);
        const inUser = index.user[set].has(key);
        if (inAdmin && !inUser) return { side: 'admin', basis: `${target ? 'target_layout' : 'extension_point'}:${key}` };
        if (inUser && !inAdmin) return { side: 'user', basis: `${target ? 'target_layout' : 'extension_point'}:${key}` };
      }
    }
    const guess = byName(parts[parts.length - 1]);
    if (guess) return { side: guess, basis: 'file-name' };
    return { side: 'both', basis: 'unknown' };
  };
}

/**
 * 토큰 분류(Tailwind 판정 결과 필요).
 *
 * @param {string} token 토큰
 * @param {object} ctx 문맥
 * @returns {string} 분류
 */
function categorize(token, { ns, letters, tailwind, admin, user }) {
  if (token.startsWith(`${ns}-`) || token.startsWith(`${letters}:`)) return 'own';
  if (MARKER_RE.test(token)) return 'marker';
  if (FONTAWESOME_RE.test(token)) return 'fontawesome';
  if (tailwind.has(token)) return 'tailwind';
  if (admin.srcClasses.has(token) || user.srcClasses.has(token)) return 'semantic';
  return 'unclassified';
}

/**
 * 줄 높이를 정하는 유틸리티(변형 뒤 유틸리티 부분): 글자 크기(`text-sm`·`text-sm/6`)와 `leading-*`.
 * 임의값 글자 크기(`text-[2rem]`)는 줄 높이를 정하지 않아 빠진다.
 */
const ICON_SIZE_RE = /^(text-(xs|sm|base|lg|xl|[2-9]xl)(\/\S+)?|leading-\S+)$/;

/**
 * 아이콘 줄 높이 보존 규칙.
 *
 * `<icon>` 은 템플릿이 아이콘 글꼴 `<i>` 로 그린다. 템플릿 아이콘 글꼴 CSS(층 밖, `line-height:1`)는 템플릿 층 안의
 * `text-*` 유틸리티를 이겼지만, 층 밖에 실리는 확장 유틸리티(`{letters}:text-*`)에는 진다. 그래서 아이콘에 단 글자 크기
 * 유틸리티에 한해 층 밖에 `line-height:1` 을 다시 둔다(특이도 (0,1,1) 이라 유틸리티 (0,1,0) 를 이긴다).
 *
 * @param {string[]} tokens 아이콘에 단 `{letters}:…text-*` 토큰
 * @returns {string} CSS(없으면 빈 문자열)
 */
export function iconLineHeightCss(tokens) {
  if (tokens.length === 0) return '';
  return [
    '',
    '/* 아이콘(<icon>)에 단 글자 크기 유틸리티 — 템플릿 아이콘 글꼴 CSS 의 line-height:1 이 이기던 것을 보존한다 */',
    `i:is(${tokens.map((t) => `.${cssEscape(t)}`).join(', ')}) {`,
    '  line-height: 1;',
    '}',
    '',
  ].join('\n');
}

/**
 * 리터럴 원문(따옴표 포함)의 공백 구분 낱말.
 *
 * @param {string} text 리터럴 원문
 * @returns {string[]} 낱말
 */
function literalWords(text) {
  return text.slice(1, -1).split(/s+/).filter(Boolean);
}

/**
 * main.css 의 생성 블록을 넣거나 바꾼다.
 *
 * @param {string|null} current 현재 내용(없으면 null)
 * @param {string} blockCss 블록 본문
 * @returns {string} 새 내용
 */
export function upsertEntryBlock(current, blockCss) {
  const block = `${BLOCK_START}\n${blockCss}${BLOCK_END}\n`;
  if (current === null) return block;
  const s = current.indexOf(BLOCK_START);
  const e = current.indexOf(BLOCK_END);
  if (s !== -1 && e > s) {
    let after = e + BLOCK_END.length;
    if (current[after] === '\r') after += 1;
    if (current[after] === '\n') after += 1;
    return current.slice(0, s) + block + current.slice(after);
  }
  return `${block}\n${current}`;
}

/**
 * 확장 하나를 변환(또는 분석)한다.
 *
 * @param {object} opts 옵션
 * @param {string} opts.extPath 확장 경로
 * @param {string} [opts.namespace] namespace(manifest 에 없을 때)
 * @param {boolean} [opts.dryRun] 쓰지 않음
 * @param {string[]} [opts.phpGlobs] PHP 글롭(확장 경로 기준)
 * @param {'admin'|'user'} [opts.phpContext] PHP 사용처
 * @param {string} [opts.repoRoot] 저장소 루트
 * @param {string} [opts.adminTemplate] 관리자 템플릿 경로
 * @param {string} [opts.userTemplate] 사용자 템플릿 경로
 * @returns {Promise<{ report: Record<string, any>, writes: Array<{file:string, content:string}> }>} 결과
 */
export async function convertExtension(opts) {
  const extPath = path.resolve(opts.extPath);
  const repoRoot = path.resolve(opts.repoRoot ?? DEFAULT_REPO_ROOT);
  const adminTemplate = path.resolve(opts.adminTemplate ?? path.join(repoRoot, 'templates/_bundled/sirsoft-admin_basic'));
  const userTemplate = path.resolve(opts.userTemplate ?? path.join(repoRoot, 'templates/_bundled/sirsoft-basic'));
  if (!existsSync(extPath)) throw new UsageError(`확장 경로가 없다: ${extPath}`);

  const manifest = readManifest(extPath);
  const manifestNs = manifest.data?.assets?.css?.namespace;
  if (manifestNs && opts.namespace && manifestNs !== opts.namespace) {
    throw new UsageError(`manifest namespace(${manifestNs})와 --namespace(${opts.namespace})가 다르다`);
  }
  const ns = manifestNs ?? opts.namespace;
  if (!ns) throw new UsageError('namespace 가 없다 — manifest assets.css.namespace 를 선언하거나 --namespace 로 준다');
  if (!NAMESPACE_RE.test(ns)) throw new UsageError(`namespace 형식 오류: ${ns} (^[a-z][a-z0-9]*(-[a-z0-9]+)*$)`);
  const letters = lettersOf(ns);
  if (!letters) throw new UsageError(`namespace 에 영문 소문자가 없어 Tailwind 접두를 만들 수 없다: ${ns}`);

  const admin = loadTemplateIndex(adminTemplate);
  const user = loadTemplateIndex(userTemplate);
  const { theme, conflicts: themeConflicts } = unionTheme([user, admin]);
  const typographyPath = resolveTypography([
    extPath, repoRoot, userTemplate, adminTemplate,
    path.join(DEFAULT_REPO_ROOT, 'templates/_bundled/sirsoft-basic'),
    path.join(DEFAULT_REPO_ROOT, 'templates/_bundled/sirsoft-admin_basic'),
  ]);

  // 확장 손 CSS 의 클래스(생성 파일 제외)
  const handClasses = new Set();
  walk(extPath, 'resources/css', (n) => n.endsWith('.css') && n !== GENERATED_FILE).forEach((rel) => {
    try {
      postcss.parse(readFileSync(path.join(extPath, rel), 'utf8')).walkRules((r) => classesOf(r.selector).forEach((c) => handClasses.add(c)));
    } catch {
      // 손 CSS 파싱 실패는 판정 보조 정보만 잃는다
    }
  });

  // 1. 대상 파일과 값 수집
  const layoutFiles = [
    ...walk(extPath, 'resources/layouts', (n) => n.endsWith('.g7.html') || n.endsWith('.json')),
    ...walk(extPath, 'resources/extensions', (n) => n.endsWith('.g7.html') || n.endsWith('.json')),
  ];
  const phpFiles = new Set();
  (opts.phpGlobs ?? []).forEach((g) => {
    globSync(g, { cwd: extPath }).forEach((f) => phpFiles.add(f.replace(/\\/g, '/')));
  });
  const resolveContext = createContextResolver({ repoRoot, adminTemplate, userTemplate });

  const files = [];
  [...layoutFiles, ...[...phpFiles].sort()].forEach((rel) => {
    const abs = path.join(extPath, rel);
    const text = readFileSync(abs, 'utf8');
    const kind = rel.endsWith('.json') ? 'json' : rel.endsWith('.g7.html') ? 'html' : 'php';
    let fragmentText = null;
    if (rel.startsWith('resources/extensions/')) {
      const pair = rel.replace(/\.g7\.html$/, '.json');
      fragmentText = kind === 'json' ? text : existsSync(path.join(extPath, pair)) ? readFileSync(path.join(extPath, pair), 'utf8') : text;
    }
    // PHP 는 --php-context 가 우선, 없으면 경로에 admin 이 있을 때 관리자, 아니면 양쪽
    let context;
    if (kind !== 'php') context = resolveContext(rel, fragmentText);
    else if (opts.phpContext) context = { side: opts.phpContext, basis: 'argument' };
    else context = /admin/i.test(rel) ? { side: 'admin', basis: 'path' } : { side: 'both', basis: 'unknown' };
    const slots = kind === 'json' ? scanJsonClassSlots(text) : kind === 'html' ? scanHtmlClassSlots(text) : scanPhpClassSlots(text);
    const values = slots.map((slot) => {
      const raw = text.slice(slot.start, slot.end);
      const entry = { slot, raw, value: raw, blocked: null, analysis: null };
      if (kind === 'json') {
        entry.value = JSON.parse(`"${raw}"`);
        entry.encodable = JSON.stringify(entry.value).slice(1, -1) === raw;
      } else if (kind === 'html') {
        if (/&(quot|#34|#39|apos);/.test(raw)) entry.blocked = 'entity-quote';
      } else {
        if (raw.includes('\\')) entry.blocked = 'escaped-literal';
        else if (slot.quote === '"' && raw.includes('$')) entry.blocked = 'php-interpolation';
      }
      if (!entry.blocked) {
        entry.analysis = analyzeClassValue(entry.value);
        if (kind === 'php' && slot.after === '.' && !/\s$/.test(entry.value) && entry.analysis.tokens.length) {
          entry.analysis.tokens[entry.analysis.tokens.length - 1].assembled = true;
        }
      }
      return entry;
    });
    files.push({ rel, abs, kind, text, context, values });
  });

  // 2. 분류
  const candidates = new Set();
  files.forEach((f) => f.values.forEach((v) => v.analysis?.tokens.forEach((t) => {
    if (!t.token.startsWith(`${ns}-`) && !t.token.startsWith(`${letters}:`) && !MARKER_RE.test(t.token) && !FONTAWESOME_RE.test(t.token)) candidates.add(t.token);
  })));
  // 제외한 리터럴도 판정해 두고, 클래스처럼 보이면 보고서에 올린다(안전망)
  files.forEach((f) => f.values.forEach((v) => v.analysis?.excluded.forEach((x) => {
    literalWords(x.text).forEach((w) => candidates.add(w));
  })));
  // 템플릿 산출 선택자에 함께 걸린 클래스도 판정해 둔다(선택자 혼입 판정용)
  [admin, user].forEach((t) => t.byClass.forEach((_, c) => candidates.add(c)));
  const classifier = await createTailwindClassifier({ letters, theme, typographyPath, base: repoRoot });
  const tailwind = classifier.utilities(candidates);
  const catCtx = { ns, letters, tailwind, admin, user };

  // 3. 의미 클래스 수요(쪽별) — 이미 바뀐 `{ns}-…` 토큰에서도 다시 찾는다(멱등)
  const needs = { admin: new Set(), user: new Set() };
  const sideOfSemantic = (token, side) => {
    const inA = admin.srcClasses.has(token) && !tailwind.has(token);
    const inU = user.srcClasses.has(token) && !tailwind.has(token);
    if (side === 'admin') return inA ? ['admin'] : [];
    if (side === 'user') return inU ? ['user'] : [];
    return [inA && 'admin', inU && 'user'].filter(Boolean);
  };
  const isTailwindLike = (c) => tailwind.has(c) || MARKER_RE.test(c);
  // 겹침 토큰(Tailwind 유틸리티이면서 템플릿 원본 CSS 에도 정의): 템플릿 산출에 복사 가능한 규칙이 있는 쪽이면
  // `{letters}:x {ns}-x` 두 이름을 함께 주고 정의도 복사한다. 없으면 Tailwind 형만.
  const copyableCache = new Map();
  const copyableOf = (side, token) => {
    const key = `${side}|${token}`;
    if (!copyableCache.has(key)) {
      const tpl = side === 'admin' ? admin : user;
      copyableCache.set(key, tpl.srcClasses.has(token) ? copyableSelectors(tpl, token, isTailwindLike) : []);
    }
    return copyableCache.get(key);
  };
  const dualSidesOf = (token, side) => {
    if (!tailwind.has(token)) return [];
    const sides = side === 'both' ? ['admin', 'user'] : [side];
    return sides.filter((s) => copyableOf(s, token).length > 0);
  };
  const dual = { admin: new Set(), user: new Set() };
  const addNeed = (s, token, isDual) => {
    needs[s].add(token);
    if (isDual) dual[s].add(token);
  };
  files.forEach((f) => f.values.forEach((v) => v.analysis?.tokens.forEach((t) => {
    const cat = categorize(t.token, catCtx);
    if (t.assembled) return;
    if (cat === 'semantic') sideOfSemantic(t.token, f.context.side).forEach((s) => addNeed(s, t.token, false));
    if (cat === 'tailwind') dualSidesOf(t.token, f.context.side).forEach((s) => addNeed(s, t.token, true));
    // 확장 손 CSS 에 같은 이름이 있어도(템플릿 정의를 덮거나 층 밖 사본을 둔 경우) 생성 정의는 계속 필요하다
    if (cat === 'own') {
      const userRest = t.token.startsWith(`${ns}-user-`) ? t.token.slice(ns.length + 6) : null;
      if (userRest && sideOfSemantic(userRest, 'user').length) addNeed('user', userRest, false);
      else if (userRest && dualSidesOf(userRest, 'user').length) addNeed('user', userRest, true);
      else if (t.token.startsWith(`${ns}-`)) {
        const rest = t.token.slice(ns.length + 1);
        sideOfSemantic(rest, f.context.side).forEach((s) => addNeed(s, rest, false));
        dualSidesOf(rest, f.context.side).forEach((s) => addNeed(s, rest, true));
      }
    }
  })));
  const semantic = buildSemanticCss({ templates: { admin, user }, needs, dual, ns, letters, isTailwind: isTailwindLike });

  // 4. 치환
  const report = {
    extension: path.basename(extPath),
    path: path.relative(repoRoot, extPath).replace(/\\/g, '/'),
    namespace: ns,
    letters,
    namespaceSource: manifestNs ? 'manifest' : 'argument',
    dryRun: Boolean(opts.dryRun),
    templates: { admin: admin.id, user: user.id },
    summary: { files: files.length, filesChanged: 0, values: 0, tokens: Object.fromEntries(CATEGORIES.map((c) => [c, 0])), excludedLiterals: {}, manualItems: 0 },
    files: {},
    unclassified: [],
    fontawesome: [],
    manual: [],
    excludedClassLike: [],
    overlap: [],
    semantic: [],
    split: [],
    selectorIssues: semantic.issues,
    missingDefinitions: semantic.missing,
    unresolvedVars: semantic.unresolvedVars,
    themeConflicts,
    typography: false,
    generatedRules: semantic.ruleCount,
  };
  const tally = { unclassified: new Map(), fontawesome: new Map(), semantic: new Map(), overlap: new Map() };
  const bump = (map, token, file, extra = {}) => {
    if (!map.has(token)) map.set(token, { token, count: 0, files: new Set(), ...extra });
    const e = map.get(token);
    e.count += 1;
    e.files.add(file);
  };
  const writes = [];
  let usesTypography = false;
  const iconSizes = new Set();

  files.forEach((f) => {
    const counts = Object.fromEntries(CATEGORIES.map((c) => [c, 0]));
    const fileEdits = [];
    f.values.forEach((v) => {
      report.summary.values += 1;
      if (v.blocked) {
        report.manual.push({ file: f.rel, line: lineOf(f.text, v.slot.start), kind: v.blocked, where: v.slot.where, text: v.raw.slice(0, 200) });
        counts.manual += 1;
        return;
      }
      v.analysis.excluded.forEach((x) => {
        report.summary.excludedLiterals[x.kind] = (report.summary.excludedLiterals[x.kind] ?? 0) + 1;
        const words = literalWords(x.text);
        if (words.length > 0 && words.some((w) => ['tailwind', 'semantic'].includes(categorize(w, catCtx)))) {
          report.excludedClassLike.push({ file: f.rel, line: lineOf(f.text, v.slot.start), kind: x.kind, text: x.text.slice(0, 200) });
        }
      });
      v.analysis.manual.forEach((x) => {
        report.manual.push({ file: f.rel, line: lineOf(f.text, v.slot.start), kind: x.kind, where: v.slot.where, text: x.text.slice(0, 200) });
        counts.manual += 1;
      });
      const edits = [];
      v.analysis.tokens.forEach((t) => {
        const cat = categorize(t.token, catCtx);
        if (t.assembled) {
          counts.manual += 1;
          report.manual.push({ file: f.rel, line: lineOf(f.text, v.slot.start), kind: 'assembled', where: v.slot.where, text: t.token });
          return;
        }
        let next = null;
        if (cat === 'marker' || cat === 'tailwind') {
          next = `${letters}:${t.token}`;
          if (cat === 'tailwind') {
            if (/^(not-)?prose(-|$)/.test(t.token.slice(t.token.lastIndexOf(':') + 1))) usesTypography = true;
          }
          if (cat === 'tailwind' && (admin.srcClasses.has(t.token) || user.srcClasses.has(t.token))) {
            // 겹침: 복사 가능한 템플릿 규칙이 있으면 두 이름(`{letters}:x {ns}-x`)
            const sides = dualSidesOf(t.token, f.context.side);
            let decision = 'tailwind-only';
            if (sides.length === 2 && semantic.split.has(t.token)) {
              decision = 'tailwind-only:context-ambiguous';
              report.manual.push({ file: f.rel, line: lineOf(f.text, v.slot.start), kind: 'context-ambiguous', where: v.slot.where, text: t.token });
            } else if (sides.length > 0) {
              decision = 'both';
              next = `${next} ${semantic.nameFor(sides.includes('admin') ? 'admin' : sides[0], t.token)}`;
            }
            if (!tally.overlap.has(t.token)) {
              const evidence = Object.fromEntries([admin, user].filter((tpl) => tpl.srcClasses.has(t.token)).map((tpl) => {
                const side = tpl === admin ? 'admin' : 'user';
                const all = (tpl.byClass.get(t.token) ?? []).flatMap((i) => tpl.entries[i].selectors)
                  .filter((s) => s.classes.includes(t.token)).map((s) => s.text);
                const copyable = copyableOf(side, t.token);
                return [tpl.id, { selectors: all.length, copyable: copyable.length, sample: copyable.slice(0, 8), notCopyable: all.filter((s) => !copyable.includes(s)).slice(0, 4) }];
              }));
              tally.overlap.set(t.token, { token: t.token, count: 0, files: new Set(), templates: Object.keys(evidence), decisions: {}, names: new Set(), evidence });
            }
            const entry = tally.overlap.get(t.token);
            entry.count += 1;
            entry.files.add(f.rel);
            entry.decisions[decision] = (entry.decisions[decision] ?? 0) + 1;
            entry.names.add(next);
          }
        } else if (cat === 'semantic') {
          const sides = sideOfSemantic(t.token, f.context.side);
          if (sides.length === 0) {
            counts.unclassified += 1;
            bump(tally.unclassified, t.token, f.rel, { note: `${f.context.side === 'admin' ? user.id : admin.id} 에만 정의` });
            return;
          }
          if (sides.length === 2 && semantic.split.has(t.token)) {
            counts.manual += 1;
            report.manual.push({ file: f.rel, line: lineOf(f.text, v.slot.start), kind: 'context-ambiguous', where: v.slot.where, text: t.token });
            return;
          }
          next = semantic.nameFor(sides.length === 2 ? 'admin' : sides[0], t.token);
          bump(tally.semantic, t.token, f.rel, { name: next, side: sides.length === 2 ? 'both' : sides[0] });
        } else if (cat === 'fontawesome') {
          bump(tally.fontawesome, t.token, f.rel);
        } else if (cat === 'unclassified') {
          bump(tally.unclassified, t.token, f.rel, handClasses.has(t.token) ? { note: '확장 손 CSS 에 앞글자 없이 정의' } : {});
        }
        counts[cat] += 1;
        if (cat === 'own' && t.token.startsWith(`${letters}:`) && /^(not-)?prose(-|$)/.test(t.token.slice(t.token.lastIndexOf(':') + 1))) {
          usesTypography = true;
        }
        if (/^icon$/i.test(v.slot.component ?? '')) {
          (next ?? t.token).split(' ').forEach((word) => {
            if (word.startsWith(`${letters}:`) && ICON_SIZE_RE.test(word.slice(word.lastIndexOf(':') + 1))) iconSizes.add(word);
          });
        }
        if (next !== null && next !== t.token) edits.push({ start: t.start, end: t.end, text: next });
      });
      if (edits.length === 0) return;
      const newValue = applyEdits(v.value, edits);
      let encoded = newValue;
      if (f.kind === 'json') {
        if (!v.encodable) {
          report.manual.push({ file: f.rel, line: lineOf(f.text, v.slot.start), kind: 'json-escape', where: v.slot.where, text: v.raw.slice(0, 200) });
          counts.manual += 1;
          return;
        }
        encoded = JSON.stringify(newValue).slice(1, -1);
      }
      fileEdits.push({ start: v.slot.start, end: v.slot.end, text: encoded });
    });
    const changed = fileEdits.length > 0;
    if (changed) {
      report.summary.filesChanged += 1;
      writes.push({ file: f.abs, content: applyEdits(f.text, fileEdits) });
    }
    CATEGORIES.forEach((c) => { report.summary.tokens[c] += counts[c]; });
    report.files[f.rel] = { kind: f.kind, context: f.context.side, contextBasis: f.context.basis, changed, counts };
  });

  const listOf = (map) => [...map.values()]
    .map((e) => ({ ...e, files: [...e.files].sort() }))
    .sort((a, b) => b.count - a.count || a.token.localeCompare(b.token));
  report.unclassified = listOf(tally.unclassified);
  report.fontawesome = listOf(tally.fontawesome);
  report.overlap = listOf(tally.overlap).map((e) => {
    const kinds = Object.keys(e.decisions);
    return { ...e, decision: kinds.length === 1 ? kinds[0] : 'mixed', names: [...e.names].sort() };
  });
  report.summary.overlapBoth = report.overlap.filter((e) => e.decisions.both).length;
  report.semantic = listOf(tally.semantic).map(({ files: fl, ...rest }) => ({ ...rest, files: fl.length }));
  report.split = [...semantic.split].sort().map((token) => ({ token, admin: `${ns}-${token}`, user: `${ns}-user-${token}` }));
  report.summary.manualItems = report.manual.length;
  report.typography = usesTypography;
  report.copiedClasses = { admin: [...semantic.needs.admin].sort(), user: [...semantic.needs.user].sort() };

  // 5. 생성 CSS 와 진입 블록
  const cssDir = path.join(extPath, 'resources', 'css');
  report.iconLineHeight = [...iconSizes].sort();
  report.unlayeredRules = semantic.unlayeredRuleCount;
  writes.push({ file: path.join(cssDir, GENERATED_FILE), content: semantic.css + iconLineHeightCss(report.iconLineHeight) });
  const mainFile = path.join(cssDir, 'main.css');
  const blockCss = buildEntryCss({ letters, theme, typography: usesTypography });
  const currentMain = existsSync(mainFile) ? readFileSync(mainFile, 'utf8') : null;
  writes.push({ file: mainFile, content: upsertEntryBlock(currentMain, blockCss) });
  report.outputs = {
    generatedCss: path.relative(extPath, path.join(cssDir, GENERATED_FILE)).replace(/\\/g, '/'),
    mainCss: path.relative(extPath, mainFile).replace(/\\/g, '/'),
    mainCssExisted: currentMain !== null,
  };

  if (!opts.dryRun) {
    writes.forEach((w) => {
      const prev = existsSync(w.file) ? readFileSync(w.file, 'utf8') : null;
      if (prev === w.content) return;
      mkdirSync(path.dirname(w.file), { recursive: true });
      writeFileSync(w.file, w.content);
    });
  }
  return { report, writes };
}

/** 사용 오류(종료 코드 2). */
class UsageError extends Error {}

/**
 * 명령행 인자를 읽는다.
 *
 * @param {string[]} argv 인자
 * @returns {Record<string, any>} 옵션
 */
function parseArgs(argv) {
  const opts = { phpGlobs: [] };
  const rest = [];
  for (let i = 0; i < argv.length; i += 1) {
    const a = argv[i];
    const value = () => {
      const v = argv[i + 1];
      if (v === undefined) throw new UsageError(`${a} 에 값이 필요하다`);
      i += 1;
      return v;
    };
    if (a === '--dry-run') opts.dryRun = true;
    else if (a === '--report') opts.report = value();
    else if (a === '--namespace') opts.namespace = value();
    else if (a === '--php') opts.phpGlobs.push(value());
    else if (a === '--php-context') {
      opts.phpContext = value();
      if (!['admin', 'user'].includes(opts.phpContext)) throw new UsageError('--php-context 는 admin 또는 user');
    }
    else if (a === '--admin-template') opts.adminTemplate = value();
    else if (a === '--user-template') opts.userTemplate = value();
    else if (a === '--repo-root') opts.repoRoot = value();
    else if (a.startsWith('--')) throw new UsageError(`알 수 없는 옵션: ${a}`);
    else rest.push(a);
  }
  if (rest.length !== 1) throw new UsageError('확장 경로 하나가 필요하다');
  [opts.extPath] = rest;
  return opts;
}

/**
 * 표준출력 요약.
 *
 * @param {Record<string, any>} report 보고서
 * @returns {string} 요약
 */
export function summarize(report) {
  const t = report.summary.tokens;
  return [
    `${report.extension} (namespace ${report.namespace}, letters ${report.letters}, ${report.dryRun ? 'dry-run' : '변환'})`,
    `  파일 ${report.summary.files} · 바뀔 파일 ${report.summary.filesChanged} · 클래스 값 ${report.summary.values}`,
    `  토큰: 자기 것 ${t.own} · 표지 ${t.marker} · Tailwind ${t.tailwind} · 의미 ${t.semantic} · FontAwesome ${t.fontawesome} · 미분류 ${t.unclassified} · 수동 확인 ${t.manual}`,
    `  제외 리터럴: ${Object.entries(report.summary.excludedLiterals).map(([k, v]) => `${k} ${v}`).join(' · ') || '없음'}`,
    `  의미 클래스 ${report.semantic.length}종(이름 나눔 ${report.split.length}) · 복사 규칙 ${report.generatedRules} · 겹침 ${report.overlap.length}(두 이름 ${report.summary.overlapBoth}) · 선택자 문제 ${report.selectorIssues.length}`,
    `  미분류 ${report.unclassified.length}종 · FontAwesome ${report.fontawesome.length}종 · 수동 확인 ${report.manual.length}건 · typography ${report.typography ? '사용' : '미사용'}`,
    `  층 밖 의미 규칙 ${report.unlayeredRules} · 아이콘 줄 높이 보존 토큰 ${report.iconLineHeight.length}`,
  ].join('\n');
}

/**
 * CLI 진입점.
 *
 * @returns {Promise<number>} 종료 코드
 */
async function main() {
  let opts;
  try {
    opts = parseArgs(process.argv.slice(2));
  } catch (e) {
    process.stderr.write(`${e.message}\n사용: node scripts/extension-css/convert-layout-classes.mjs <확장경로> [--dry-run] [--report <json>] [--namespace <ns>] [--php <glob>]…\n`);
    return 2;
  }
  try {
    const { report } = await convertExtension(opts);
    if (opts.report) {
      mkdirSync(path.dirname(path.resolve(opts.report)), { recursive: true });
      writeFileSync(opts.report, `${JSON.stringify(report, null, 2)}\n`);
    }
    process.stdout.write(`${summarize(report)}\n`);
    return 0;
  } catch (e) {
    process.stderr.write(`${e instanceof UsageError ? '' : '실행 오류: '}${e.stack ?? e.message}\n`);
    return e instanceof UsageError ? 2 : 1;
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  main().then((code) => { process.exitCode = code; });
}
