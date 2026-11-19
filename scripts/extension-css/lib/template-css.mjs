/**
 * 템플릿 의미 클래스 색인과 복사 — 확장 기본값 칸(`@layer g7-extension`) 생성
 *
 * 템플릿 원본 CSS(`src/styles/**\/*.css`)에 클래스 선택자로 정의된 이름을 「의미 클래스」 후보로 모으고,
 * 정의 본문은 템플릿 **빌드 산출**(`<템플릿>/dist/css/components.css`, `@apply` 가 풀리고 `.dark .x` 로 평탄화된 것)에서
 * 가져온다. 복사할 때 세 가지를 바꾼다.
 *
 * 1. 선택자: 템플릿 클래스를 `{namespace}-{token}`(이름을 나눈 사용자 쪽은 `{namespace}-user-{token}`)으로 바꾼다.
 *    선택자에 같이 걸린 다른 의미 클래스도 같은 규칙으로 바꾸고 복사 대상에 넣는다(폐포).
 * 2. 다크: `.dark` 조상 선택자를 떼고 주체 복합 선택자에 중립 표식 규격을 붙인다.
 * 3. 변수: 템플릿 테마 변수(`--color-*`·`--spacing`·`--font-*`·`--text-*`·`--radius-*` 등)를 템플릿 `:root` 값으로
 *    풀어 쓴다. `--tw-*` 는 그대로 두고, 참조된 `@property --tw-*` 와 초기값 폴백을 함께 복사한다.
 */
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import postcss from 'postcss';
import { cssEscape, cssUnescape, DARK_SUFFIX } from './tailwind-classifier.mjs';

/** 생성 파일에서 층 밖 의미 규칙 구간을 여는 주석. */
export const UNLAYERED_MARK = '/* 템플릿에서 층 밖이던 의미 규칙 — 확장 유틸리티 뒤에서 이기도록 층 밖에 둔다 */';

const LEGACY_PSEUDO_ELEMENT =/^:(before|after|first-line|first-letter)(?![\w-])/i;

/**
 * 디렉터리 아래 CSS 파일 목록.
 *
 * @param {string} dir 디렉터리
 * @returns {string[]} 파일 경로
 */
function listCss(dir) {
  if (!existsSync(dir)) return [];
  const out = [];
  for (const name of readdirSync(dir)) {
    const full = path.join(dir, name);
    if (statSync(full).isDirectory()) out.push(...listCss(full));
    else if (name.endsWith('.css')) out.push(full);
  }
  return out.sort();
}

/**
 * 노드가 특정 at-rule 안에 있는지.
 *
 * @param {import('postcss').Node} node 노드
 * @param {(a: import('postcss').AtRule) => boolean} test 판정
 * @returns {boolean} 포함 여부
 */
function insideAtRule(node, test) {
  let p = node.parent;
  while (p && p.type !== 'root') {
    if (p.type === 'atrule' && test(p)) return true;
    p = p.parent;
  }
  return false;
}

/**
 * 최상위 쉼표로 선택자 목록을 나눈다.
 *
 * @param {string} selector 선택자 목록
 * @returns {string[]} 복합 선택자
 */
export function splitSelectorList(selector) {
  const out = [];
  let depth = 0;
  let quote = null;
  let start = 0;
  for (let i = 0; i < selector.length; i += 1) {
    const ch = selector[i];
    if (quote) { if (ch === '\\') i += 1; else if (ch === quote) quote = null; continue; }
    if (ch === '\\') { i += 1; continue; }
    if (ch === '"' || ch === "'") quote = ch;
    else if (ch === '(' || ch === '[') depth += 1;
    else if (ch === ')' || ch === ']') depth -= 1;
    else if (ch === ',' && depth === 0) { out.push(selector.slice(start, i).trim()); start = i + 1; }
  }
  out.push(selector.slice(start).trim());
  return out.filter(Boolean);
}

/**
 * 선택자 안 클래스 이름을 바꾼다(속성 선택자·문자열 안은 건드리지 않음).
 *
 * @param {string} selector 선택자
 * @param {(name: string) => string|null} rename 새 이름(그대로 두려면 null)
 * @returns {{ selector: string, classes: string[] }} 새 선택자와 원래 클래스 목록
 */
export function rewriteClasses(selector, rename) {
  let out = '';
  const classes = [];
  let i = 0;
  let bracket = 0;
  let quote = null;
  while (i < selector.length) {
    const ch = selector[i];
    if (quote) {
      out += ch;
      if (ch === '\\') { out += selector[i + 1] ?? ''; i += 2; continue; }
      if (ch === quote) quote = null;
      i += 1;
      continue;
    }
    if (ch === '"' || ch === "'") { quote = ch; out += ch; i += 1; continue; }
    if (ch === '[') bracket += 1;
    if (ch === ']') bracket -= 1;
    if (ch === '.' && bracket === 0) {
      const m = /^(?:-?(?:[_a-zA-Z -￿]|\\[0-9a-fA-F]{1,6}\s?|\\.))(?:[\w -￿-]|\\[0-9a-fA-F]{1,6}\s?|\\.)*/.exec(selector.slice(i + 1));
      if (m) {
        const name = cssUnescape(m[0].replace(/\s$/, ''));
        classes.push(name);
        const next = rename(name);
        out += `.${next === null ? m[0] : cssEscape(next)}`;
        i += 1 + m[0].length;
        continue;
      }
    }
    out += ch;
    i += 1;
  }
  return { selector: out, classes };
}

/**
 * 선택자의 클래스 목록.
 *
 * @param {string} selector 선택자
 * @returns {string[]} 클래스
 */
export function classesOf(selector) {
  return rewriteClasses(selector, () => null).classes;
}

/**
 * `.dark X` 를 `X` 의 주체 복합 선택자에 다크 규격을 붙인 형태로 바꾼다.
 *
 * @param {string} selector 복합 선택자(클래스 개명 전·후 어느 쪽이든)
 * @returns {{ ok: boolean, selector: string, dark: boolean }} 결과(`.dark` 가 맨 앞 조상이 아니면 ok=false)
 */
export function applyDarkSpec(selector) {
  const m = /^\.dark(\s+)(?=\S)/.exec(selector);
  const hasDark = /(^|[^\w\\-])\.dark(?![\w-]|\\)/.test(selector);
  if (!m && hasDark) {
    // `:where(.dark X)`·`:is(.dark X)` 처럼 통째로 감싼 경우는 안쪽 목록에 적용한다
    const wrapped = /^:(where|is)\(/.exec(selector);
    if (wrapped && selector.endsWith(')')) {
      const inner = selector.slice(wrapped[0].length, -1);
      // 감싼 괄호가 끝까지 한 쌍인지 확인
      let depth = 0;
      let whole = true;
      for (let i = wrapped[0].length - 1; i < selector.length; i += 1) {
        if (selector[i] === '\\') { i += 1; continue; }
        if (selector[i] === '(') depth += 1;
        else if (selector[i] === ')') { depth -= 1; if (depth === 0 && i !== selector.length - 1) { whole = false; break; } }
      }
      if (whole) {
        const parts = splitSelectorList(inner).map((s) => applyDarkSpec(s));
        if (parts.every((p) => p.ok)) {
          return { ok: true, selector: `:${wrapped[1]}(${parts.map((p) => p.selector).join(', ')})`, dark: parts.some((p) => p.dark) };
        }
      }
    }
    return { ok: false, selector, dark: true };
  }
  if (!m) return { ok: true, selector, dark: false };
  const rest = selector.slice(m[0].length);
  if (/(^|[^\w\\-])\.dark(?![\w-]|\\)/.test(rest)) return { ok: false, selector, dark: true };
  // 주체 복합 선택자 시작 = 최상위 마지막 결합자 뒤
  let depth = 0;
  let quote = null;
  let subjectStart = 0;
  for (let i = 0; i < rest.length; i += 1) {
    const ch = rest[i];
    if (quote) { if (ch === '\\') i += 1; else if (ch === quote) quote = null; continue; }
    if (ch === '\\') { i += 1; continue; }
    if (ch === '"' || ch === "'") quote = ch;
    else if (ch === '(' || ch === '[') depth += 1;
    else if (ch === ')' || ch === ']') depth -= 1;
    else if (depth === 0 && /[\s>+~]/.test(ch)) subjectStart = i + 1;
  }
  // 의사 요소 앞에 붙인다
  let insertAt = rest.length;
  depth = 0;
  for (let i = subjectStart; i < rest.length; i += 1) {
    const ch = rest[i];
    if (ch === '\\') { i += 1; continue; }
    if (ch === '(' || ch === '[') depth += 1;
    else if (ch === ')' || ch === ']') depth -= 1;
    else if (depth === 0 && ch === ':' && (rest[i + 1] === ':' || LEGACY_PSEUDO_ELEMENT.test(rest.slice(i)))) { insertAt = i; break; }
  }
  return { ok: true, selector: `${rest.slice(0, insertAt)}${DARK_SUFFIX}${rest.slice(insertAt)}`, dark: true };
}

/**
 * 값 안 `var(--x)` 를 테마 값으로 풀어 쓴다(재귀). `--tw-*` 와 테마에 없는 변수는 남긴다.
 *
 * @param {string} value 선언 값
 * @param {Map<string,string>} theme 테마 변수
 * @param {Set<string>} unresolved 풀지 못한 비-tw 변수 누적
 * @param {number} [depth] 재귀 깊이
 * @returns {string} 새 값
 */
export function resolveThemeVars(value, theme, unresolved, depth = 0) {
  let out = '';
  let i = 0;
  while (i < value.length) {
    const k = value.indexOf('var(', i);
    if (k === -1 || (k > 0 && /[\w-]/.test(value[k - 1]))) {
      if (k === -1) { out += value.slice(i); break; }
      out += value.slice(i, k + 4);
      i = k + 4;
      continue;
    }
    out += value.slice(i, k);
    let level = 0;
    let j = k + 3;
    for (; j < value.length; j += 1) {
      if (value[j] === '(') level += 1;
      else if (value[j] === ')') { level -= 1; if (level === 0) break; }
    }
    const inner = value.slice(k + 4, j);
    let comma = -1;
    let lv = 0;
    for (let c = 0; c < inner.length; c += 1) {
      if (inner[c] === '(') lv += 1;
      else if (inner[c] === ')') lv -= 1;
      else if (inner[c] === ',' && lv === 0) { comma = c; break; }
    }
    const name = (comma === -1 ? inner : inner.slice(0, comma)).trim();
    const fallback = comma === -1 ? null : inner.slice(comma + 1);
    if (!name.startsWith('--tw-') && theme.has(name) && depth < 20) {
      out += resolveThemeVars(theme.get(name), theme, unresolved, depth + 1);
    } else {
      if (!name.startsWith('--tw-') && !theme.has(name)) unresolved.add(name);
      out += `var(${name}${fallback === null ? '' : `,${resolveThemeVars(fallback, theme, unresolved, depth + 1)}`})`;
    }
    i = j + 1;
  }
  return out;
}

/**
 * @typedef {object} TemplateIndex
 * @property {string} id 템플릿 디렉터리 이름
 * @property {Set<string>} srcClasses 원본 CSS 의 클래스 선택자 이름(`dark` 제외)
 * @property {Set<string>} srcUnlayered 원본 CSS 에서 어느 `@layer` 에도 속하지 않은 규칙의 클래스 이름
 * @property {Array<[string,string]>} srcTheme 원본 `@theme` 선언
 * @property {Map<string,string>} theme 산출 `:root,:host` 테마 변수
 * @property {Map<string, import('postcss').AtRule>} keyframes 산출 `@keyframes`
 * @property {Map<string, import('postcss').AtRule>} properties 산출 `@property`
 * @property {{params:string, values: Map<string,string>}|null} propertyFallback `@layer properties` 초기값 폴백
 * @property {Array<{rule: import('postcss').Rule, chain: Array<{name:string, params:string}>, selectors: Array<{text:string, classes:string[]}>}>} entries 산출 규칙
 * @property {Map<string, number[]>} byClass 클래스 → entries 위치
 */

/**
 * 템플릿 하나를 색인한다.
 *
 * @param {string} dir 템플릿 디렉터리
 * @returns {TemplateIndex} 색인
 */
export function loadTemplateIndex(dir) {
  const srcClasses = new Set();
  const srcUnlayered = new Set();
  const srcTheme = [];
  for (const file of listCss(path.join(dir, 'src', 'styles'))) {
    const root = postcss.parse(readFileSync(file, 'utf8'), { from: file });
    root.walkRules((rule) => {
      if (insideAtRule(rule, (a) => /keyframes$/i.test(a.name))) return;
      const layered = insideAtRule(rule, (a) => a.name === 'layer');
      classesOf(rule.selector).forEach((c) => {
        if (c === 'dark') return;
        srcClasses.add(c);
        if (!layered) srcUnlayered.add(c);
      });
    });
    root.walkAtRules('theme', (at) => {
      at.each((n) => { if (n.type === 'decl') srcTheme.push([n.prop, n.value]); });
    });
  }
  const distFile = path.join(dir, 'dist', 'css', 'components.css');
  const theme = new Map();
  const keyframes = new Map();
  const properties = new Map();
  let propertyFallback = null;
  const entries = [];
  const byClass = new Map();
  if (existsSync(distFile)) {
    const root = postcss.parse(readFileSync(distFile, 'utf8'), { from: distFile });
    root.walkAtRules((at) => {
      if (/keyframes$/i.test(at.name)) keyframes.set(at.params.trim(), at);
      else if (at.name === 'property') properties.set(at.params.trim(), at);
    });
    root.walkRules((rule) => {
      if (insideAtRule(rule, (a) => /keyframes$/i.test(a.name))) return;
      const inTheme = insideAtRule(rule, (a) => a.name === 'layer' && a.params.trim() === 'theme');
      const inProps = insideAtRule(rule, (a) => a.name === 'layer' && a.params.trim() === 'properties');
      if (inTheme && /:root/.test(rule.selector)) {
        rule.each((d) => { if (d.type === 'decl' && d.prop.startsWith('--')) theme.set(d.prop, d.value); });
        return;
      }
      if (inProps) {
        const sup = rule.parent?.type === 'atrule' && rule.parent.name === 'supports' ? rule.parent.params : '';
        propertyFallback = propertyFallback ?? { params: sup, values: new Map() };
        rule.each((d) => { if (d.type === 'decl') propertyFallback.values.set(d.prop, d.value); });
        return;
      }
      if (inTheme) return;
      const chain = [];
      let p = rule.parent;
      while (p && p.type !== 'root') {
        if (p.type === 'atrule' && p.name !== 'layer') chain.unshift({ name: p.name, params: p.params });
        p = p.parent;
      }
      const selectors = splitSelectorList(rule.selector).map((text) => ({ text, classes: classesOf(text) }));
      const idx = entries.length;
      // 층 위치(@layer 안/밖)를 기억한다 — 템플릿에서 층 밖이던 의미 규칙은 생성 파일에서도 층 밖에 둔다
      const layered = insideAtRule(rule, (a) => a.name === 'layer');
      entries.push({ rule, chain, selectors, layered });
      new Set(selectors.flatMap((s) => s.classes)).forEach((c) => {
        if (!byClass.has(c)) byClass.set(c, []);
        byClass.get(c).push(idx);
      });
    });
  }
  return { id: path.basename(dir), srcClasses, srcUnlayered, srcTheme, theme, keyframes, properties, propertyFallback, entries, byClass, hasDist: existsSync(distFile) };
}

/**
 * 두 템플릿 `@theme` 선언의 합집합(같은 속성이 다르면 충돌로 돌려준다).
 *
 * @param {TemplateIndex[]} templates 템플릿 색인
 * @returns {{ theme: Array<[string,string]>, conflicts: Array<{prop:string, values:string[]}> }} 합집합
 */
export function unionTheme(templates) {
  const map = new Map();
  const conflicts = [];
  templates.forEach((t) => t.srcTheme.forEach(([prop, value]) => {
    const v = value.replace(/\s+/g, ' ').trim();
    if (!map.has(prop)) map.set(prop, v);
    else if (map.get(prop) !== v) conflicts.push({ prop, values: [map.get(prop), v] });
  }));
  return { theme: [...map.entries()], conflicts };
}

/**
 * 토큰을 선택자에 포함한 산출 규칙 중 복사 가능한 선택자(모든 클래스가 의미·Tailwind·dark 이고 `.dark` 위치가 지원 형태).
 *
 * 템플릿 밖 클래스(라이브러리 등)가 섞여 복사 때 버려지는 선택자는 빠진다.
 *
 * @param {TemplateIndex} t 템플릿 색인
 * @param {string} token 토큰
 * @param {(token: string) => boolean} isTailwind Tailwind 유틸리티·표지 판정
 * @returns {string[]} 복사 가능한 선택자 원문
 */
export function copyableSelectors(t, token, isTailwind) {
  const out = [];
  (t.byClass.get(token) ?? []).forEach((idx) => {
    t.entries[idx].selectors.forEach((sel) => {
      if (!sel.classes.includes(token)) return;
      if (!sel.classes.every((c) => c === 'dark' || t.srcClasses.has(c) || isTailwind(c))) return;
      if (!applyDarkSpec(sel.text).ok) return;
      out.push(sel.text);
    });
  });
  return out;
}

/**
 * 의미 클래스 정의를 복사해 `@layer g7-extension` CSS 를 만든다.
 *
 * @param {object} opts 옵션
 * @param {{admin: TemplateIndex, user: TemplateIndex}} opts.templates 템플릿 색인
 * @param {{admin: Set<string>, user: Set<string>}} opts.needs 쪽별로 직접 쓰인 의미 클래스
 * @param {{admin: Set<string>, user: Set<string>}} [opts.dual] 쪽별 겹침 토큰(Tailwind 이름과 의미 이름을 함께 받는 것) — 선택자에서 의미 이름으로 바꾼다
 * @param {string} opts.ns namespace
 * @param {string} opts.letters Tailwind 접두
 * @param {(token: string) => boolean} opts.isTailwind Tailwind 유틸리티·표지 판정
 * @returns {{ css: string, split: Set<string>, needs: {admin:Set<string>, user:Set<string>}, nameFor: (side:string, cls:string) => string,
 *   issues: Array<object>, missing: Array<object>, unresolvedVars: string[], ruleCount: number }} 결과
 */
export function buildSemanticCss({ templates, needs, dual = { admin: new Set(), user: new Set() }, ns, letters, isTailwind }) {
  const sides = ['admin', 'user'];
  const issues = [];
  const issueKeys = new Set();
  const unresolved = new Set();

  /**
   * 선택자가 복사 가능한지(모든 클래스가 의미·Tailwind·dark 중 하나).
   *
   * @param {TemplateIndex} t 템플릿
   * @param {{text:string, classes:string[]}} sel 선택자
   * @returns {boolean} 가능 여부
   */
  const selectorUsable = (t, sel) => sel.classes.every((c) => c === 'dark' || t.srcClasses.has(c) || isTailwind(c));

  // 1. 폐포
  const closed = {};
  sides.forEach((side) => {
    const t = templates[side];
    const need = new Set([...needs[side]].filter((c) => t.srcClasses.has(c)));
    const queue = [...need];
    while (queue.length > 0) {
      const c = queue.pop();
      (t.byClass.get(c) ?? []).forEach((idx) => {
        t.entries[idx].selectors.forEach((sel) => {
          if (!sel.classes.includes(c) || !selectorUsable(t, sel)) return;
          sel.classes.forEach((k) => {
            if (k !== 'dark' && t.srcClasses.has(k) && !isTailwind(k) && !need.has(k)) { need.add(k); queue.push(k); }
          });
        });
      });
    }
    closed[side] = need;
  });

  const split = new Set();
  const nameFor = (side, cls) => (side === 'user' && split.has(cls) ? `${ns}-user-${cls}` : `${ns}-${cls}`);

  /**
   * 선택자 하나를 바꾼다.
   *
   * @param {string} side 쪽
   * @param {{text:string, classes:string[]}} sel 선택자
   * @param {(side:string, cls:string) => string} naming 이름 규칙
   * @param {boolean} record 문제를 보고서에 남길지
   * @returns {string|null} 새 선택자(복사 불가면 null)
   */
  const transformSelector = (side, sel, naming, record) => {
    const t = templates[side];
    const note = (reason, extra) => {
      if (!record) return;
      const key = `${t.id}|${sel.text}|${reason}`;
      if (issueKeys.has(key)) return;
      issueKeys.add(key);
      issues.push({ template: t.id, selector: sel.text, reason, ...extra });
    };
    const foreign = sel.classes.filter((c) => c !== 'dark' && !t.srcClasses.has(c) && !isTailwind(c));
    if (foreign.length > 0) { note('foreign-class', { classes: foreign }); return null; }
    const mixed = sel.classes.filter((c) => c !== 'dark' && isTailwind(c) && !dual[side].has(c));
    const renamed = rewriteClasses(sel.text, (c) => {
      if (c === 'dark') return null;
      if (dual[side].has(c)) return naming(side, c);
      if (isTailwind(c)) return `${letters}:${c}`;
      return naming(side, c);
    }).selector;
    const dark = applyDarkSpec(renamed);
    if (!dark.ok) { note('dark-position'); return null; }
    if (mixed.length > 0) note('tailwind-in-selector', { classes: mixed });
    return dark.selector;
  };

  /**
   * 선언 값을 바꾼다(변수 풀어쓰기·keyframes 개명).
   *
   * @param {string} side 쪽
   * @param {import('postcss').Declaration} decl 선언
   * @param {Set<string>} usedKeyframes keyframes 누적
   * @returns {string} 새 값
   */
  const transformValue = (side, decl, usedKeyframes) => {
    const t = templates[side];
    let value = resolveThemeVars(decl.value, t.theme, unresolved);
    if (/^(-webkit-)?animation(-name)?$/.test(decl.prop)) {
      t.keyframes.forEach((_, name) => {
        const re = new RegExp(`(?<![\\w-])${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w-])`, 'g');
        if (re.test(value)) {
          usedKeyframes.add(`${side}|${name}`);
          value = value.replace(re, `${ns}-${name}`);
        }
      });
    }
    return value;
  };

  /**
   * 한 쪽의 규칙을 만든다.
   *
   * @param {string} side 쪽
   * @param {Set<string>} need 대상 클래스
   * @param {(side:string, cls:string) => string} naming 이름 규칙
   * @param {boolean} record 문제 기록 여부
   * @param {Set<string>} usedKeyframes keyframes 누적
   * @returns {Array<{chain: Array<{name:string, params:string}>, selectors:string[], decls: Array<[string,string,boolean]>}>} 규칙
   */
  const renderSide = (side, need, naming, record, usedKeyframes) => {
    const t = templates[side];
    const idxs = new Set();
    need.forEach((c) => (t.byClass.get(c) ?? []).forEach((i) => idxs.add(i)));
    return [...idxs].sort((a, b) => a - b).map((idx) => {
      const entry = t.entries[idx];
      const selectors = entry.selectors
        .filter((sel) => sel.classes.some((c) => need.has(c)))
        .map((sel) => transformSelector(side, sel, naming, record))
        .filter(Boolean);
      if (selectors.length === 0) return null;
      const decls = [];
      entry.rule.each((d) => { if (d.type === 'decl') decls.push([d.prop, transformValue(side, d, usedKeyframes), d.important]); });
      return { chain: entry.chain, selectors, decls, layered: entry.layered };
    }).filter(Boolean);
  };

  // 2. 이름 나누기(정의가 다른 공통 토큰) — 고정점까지
  const both = [...closed.admin].filter((c) => closed.user.has(c));
  let changed = true;
  while (changed) {
    changed = false;
    for (const cls of both) {
      if (split.has(cls)) continue;
      const neutral = (side, c) => (c === cls ? `__probe__-${c}` : nameFor(side, c));
      const sig = (side) => JSON.stringify(renderSide(side, new Set([cls]), neutral, false, new Set()));
      if (sig('admin') !== sig('user')) { split.add(cls); changed = true; }
    }
  }

  // 3. 출력
  const usedKeyframes = new Set();
  const blocks = [];
  const seen = new Set();
  sides.forEach((side) => {
    renderSide(side, closed[side], nameFor, true, usedKeyframes).forEach((r) => {
      const key = JSON.stringify(r);
      if (seen.has(key)) return;
      seen.add(key);
      blocks.push(r);
    });
  });

  const missing = [];
  sides.forEach((side) => needs[side].forEach((c) => {
    if (!templates[side].srcClasses.has(c)) return;
    if (!(templates[side].byClass.get(c) ?? []).length) missing.push({ template: templates[side].id, token: c, reason: 'not-in-dist' });
  }));

  const body = [];
  const twVars = new Set();
  blocks.forEach((b) => b.decls.forEach(([prop, value]) => {
    `${prop} ${value}`.replace(/--tw-[\w-]+/g, (m) => { twVars.add(m); return m; });
  }));
  const keyframeBlocks = [];
  usedKeyframes.forEach((key) => {
    const [side, name] = key.split('|');
    const at = templates[side].keyframes.get(name);
    const lines = [`@keyframes ${ns}-${name} {`];
    at.each((frame) => {
      if (frame.type !== 'rule') return;
      lines.push(`  ${frame.selector} {`);
      frame.each((d) => { if (d.type === 'decl') lines.push(`    ${d.prop}: ${resolveThemeVars(d.value, templates[side].theme, unresolved)}${d.important ? ' !important' : ''};`); });
      lines.push('  }');
    });
    lines.push('}');
    keyframeBlocks.push(lines.join('\n'));
  });

  // 초기값 폴백(@property 미지원 브라우저용) — 참조된 --tw-* 만
  const fallbackSource = templates.admin.propertyFallback ?? templates.user.propertyFallback;
  const fallbackVars = [...twVars].filter((v) => fallbackSource?.values.has(v)).sort();
  if (fallbackVars.length > 0) {
    const inner = [
      '*, ::before, ::after, ::backdrop {',
      ...fallbackVars.map((v) => `  ${v}: ${fallbackSource.values.get(v)};`),
      '}',
    ];
    body.push(fallbackSource.params ? wrap([{ name: 'supports', params: fallbackSource.params }], inner) : inner.join('\n'));
  }
  body.push(...keyframeBlocks);
  const unlayeredBody = [];
  blocks.forEach((b) => {
    const inner = [
      `${b.selectors.join(',\n')} {`,
      ...b.decls.map(([prop, value, important]) => `  ${prop}: ${value}${important ? ' !important' : ''};`),
      '}',
    ];
    (b.layered ? body : unlayeredBody).push(wrap(b.chain, inner));
  });

  const propertyBlocks = [...twVars].sort().map((v) => {
    const at = templates.admin.properties.get(v) ?? templates.user.properties.get(v);
    if (!at) return null;
    const lines = [`@property ${v} {`];
    at.each((d) => { if (d.type === 'decl') lines.push(`  ${d.prop}: ${d.value};`); });
    lines.push('}');
    return lines.join('\n');
  }).filter(Boolean);

  const header = [
    '/*',
    ' * 생성 파일 — 손으로 고치지 않는다.',
    ' * scripts/extension-css/convert-layout-classes.mjs 가 템플릿 의미 클래스 정의를 복사해 만든다.',
    ' * 템플릿이 같은 이름을 덮을 수 있도록 가장 낮은 칸(@layer g7-extension)에 둔다.',
    ' * 다만 템플릿에서 층 밖(@layer 미소속)이던 규칙은 유틸리티를 이기던 우선순위를 지키려고 층 밖에 둔다.',
    ' */',
  ].join('\n');
  const layer = body.length > 0
    ? `@layer g7-extension {\n${body.map((b) => indent(b, '  ')).join('\n\n')}\n}\n`
    : '@layer g7-extension {\n}\n';
  const outside = unlayeredBody.length > 0
    ? `\n${UNLAYERED_MARK}\n${unlayeredBody.join('\n\n')}\n`
    : '';
  const css = `${header}\n${layer}${outside}${propertyBlocks.length ? `\n${propertyBlocks.join('\n\n')}\n` : ''}`;

  return {
    css,
    split,
    needs: closed,
    nameFor,
    issues,
    missing,
    unresolvedVars: [...unresolved].sort(),
    ruleCount: blocks.length,
    unlayeredRuleCount: unlayeredBody.length,
  };
}

/**
 * at-rule 사슬로 감싼다.
 *
 * @param {Array<{name:string, params:string}>} chain 바깥부터의 at-rule
 * @param {string[]} innerLines 본문 줄
 * @returns {string} CSS
 */
function wrap(chain, innerLines) {
  let text = innerLines.join('\n');
  [...chain].reverse().forEach((c) => { text = `@${c.name} ${c.params} {\n${indent(text, '  ')}\n}`; });
  return text;
}

/**
 * 각 줄을 들여쓴다.
 *
 * @param {string} text 원문
 * @param {string} pad 들여쓰기
 * @returns {string} 결과
 */
function indent(text, pad) {
  return text.split('\n').map((l) => (l ? pad + l : l)).join('\n');
}
