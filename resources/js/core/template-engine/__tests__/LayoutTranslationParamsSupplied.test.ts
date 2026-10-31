/**
 * 번역 인자 공급 가드 (저장소 전역 레이아웃 계약)
 *
 * `$t:키` 의 번역문에 `{{이름}}` 자리가 있으면 그 키를 쓰는 레이아웃이 같은 이름의 인자를
 * 넘겨야 한다. 넘기지 않으면 `TranslationEngine.replaceParams` 가 그 자리를 건드리지 않아
 * 화면에 `{{count}}` 가 글자 그대로 나온다. 7.1.0 실측에서 할인코드 일괄 상태 변경 창이
 * 「선택된 {{count}}개 할인코드의 상태가 {{status}}으로 변경됩니다.」를 그대로 보여 줬다.
 *
 * 인자 판독은 엔진과 같은 규칙을 쓴다. 키 바로 뒤의 `|` 부터 다음 `$t:` 전까지가 인자
 * 문자열이고(`TRANSLATION_PATTERN`), `{{…}}` 안의 `|`·`&` 를 보호한 뒤 `이름=값` 을 `|`·`&` 로
 * 가른다(`parseParams`). 식 안에서 문자열을 이어 붙여 인자를 만드는 형태
 * (`'$t:키|name=' + … + '|email=' + …`)도 평가 결과가 같은 문자열이 되므로 같은 규칙으로 읽힌다.
 *
 * 번역문 쪽 자리는 `{{이름}}` 만 본다. 홑중괄호 `{이름}` 은 알림 템플릿 변수 안내처럼 글자
 * 그대로 보여 주려는 문구에도 쓰여 인자 누락과 가를 수 없다.
 *
 * 모집단은 저장소에서 파생한다. 새 레이아웃과 새 번역 키가 들어오면 자동으로 검사 대상이 된다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..', '..', '..', '..', '..',
);

/** 소스(`.g7.html`)가 있는 레이아웃 뿌리. 짝 `.json` 은 여기서 생성되므로 소스만 본다. */
const LAYOUT_ROOTS = ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled'];

/** 번역문 자리를 확인할 로케일. 언어마다 자리가 다를 수 있어 둘을 합쳐 본다. */
const LOCALES = ['ko', 'en'] as const;

/** 엔진 `TranslationEngine.TRANSLATION_PATTERN` 과 같은 모양. */
const TRANSLATION_TOKEN = /\$t:(?:defer:)?([a-zA-Z0-9._-]+)(\|(?:(?!\$t:).)+)?/g;

/** 엔진 `parseParams` 가 인자를 가르기 전에 보호하는 `{{…}}` 표현식. */
const PROTECTED_EXPRESSION = /\{\{(?:[^}]|\}(?!\}))*\}\}/g;

/** 엔진 `TranslationEngine.PARAM_PATTERN`. */
const PARAM_PATTERN = /([^=|&]+)=([^|&]+)/g;

/**
 * 엔진 `NESTED_TRANSLATION_PARAM_PATTERN` — 인자 값 자리의 `$t:키` 는 바깥 토큰보다 먼저 번역문으로
 * 바뀐다. 엔진은 문자열 하나를 받으므로 끝(`$`)이 경계지만, 소스는 줄 단위로 읽으므로 그 문자열이
 * 끝나는 자리(본문의 `<`, 속성의 `"`, 식 안 문자열의 `'`)도 경계로 친다.
 */
const NESTED_TRANSLATION_PARAM = /=(\$t:([a-zA-Z0-9._-]+))(?=[|&\s<"']|$)/g;

/** 엔진 `replaceParams` 가 채우는 겹중괄호 자리. 공백이 끼면 엔진도 채우지 않는다. */
const PLACEHOLDER = /\{\{([A-Za-z_]\w*)\}\}/g;

type Dict = Record<string, unknown>;

/**
 * JSON 파일을 읽는다. 없거나 깨졌으면 null.
 *
 * @param file 절대경로
 * @returns 파싱 결과 또는 null
 */
function readJson(file: string): Dict | null {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8')) as Dict;
  } catch {
    return null;
  }
}

/**
 * 서버 `ResolvesLanguageFragments` 와 같은 규칙으로 `$partial` 을 푼다. 기준 경로는 언어 디렉터리다.
 *
 * @param node 다국어 노드
 * @param baseDir 언어 디렉터리 절대경로
 * @param depth 현재 깊이
 * @returns `$partial` 을 푼 노드
 */
function resolvePartials(node: unknown, baseDir: string, depth = 0): unknown {
  if (depth > 10 || node === null || typeof node !== 'object' || Array.isArray(node)) return node;
  const out: Dict = {};
  for (const [key, value] of Object.entries(node as Dict)) {
    const partial = value && typeof value === 'object' && !Array.isArray(value) ? (value as Dict).$partial : undefined;
    out[key] = typeof partial === 'string'
      ? resolvePartials(readJson(path.join(baseDir, partial)) ?? {}, baseDir, depth + 1)
      : resolvePartials(value, baseDir, depth);
  }

  return out;
}

/**
 * 다국어 트리를 점 경로 사전에 펼친다. 같은 키는 나중에 넣은 쪽이 이긴다(서버 병합 순서와 같다).
 *
 * @param node 다국어 노드
 * @param prefix 키 접두
 * @param out 결과 사전
 * @returns void
 */
function flatten(node: unknown, prefix: string, out: Map<string, string>): void {
  if (typeof node === 'string') {
    out.set(prefix, node);

    return;
  }
  if (!node || typeof node !== 'object' || Array.isArray(node)) return;
  for (const [key, value] of Object.entries(node as Dict)) flatten(value, prefix ? `${prefix}.${key}` : key, out);
}

/**
 * 언어 디렉터리의 `{locale}.json` 을 `$partial` 까지 풀어 사전에 넣는다.
 *
 * @param langDir 언어 디렉터리 절대경로
 * @param locale 로케일
 * @param prefix 확장 식별자 접두(코어·템플릿은 빈 문자열)
 * @param out 결과 사전
 * @returns void
 */
function loadLang(langDir: string, locale: string, prefix: string, out: Map<string, string>): void {
  const root = readJson(path.join(langDir, `${locale}.json`));
  if (root) flatten(resolvePartials(root, langDir), prefix, out);
}

/**
 * 디렉터리 아래 파일 경로를 모은다(빌드 산출물·의존성·테스트 제외).
 *
 * @param dir 훑을 디렉터리 절대경로
 * @param out 수집 결과
 * @returns void
 */
function collectFiles(dir: string, out: string[]): void {
  let entries: fs.Dirent[];
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch {
    return;
  }
  for (const entry of entries) {
    if (['node_modules', 'dist', 'vendor', '__tests__', 'tests'].includes(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collectFiles(full, out);
    else if (entry.name.endsWith('.g7.html')) out.push(full);
  }
}

/**
 * 인자 문자열에서 엔진이 읽어 낼 인자 이름을 뽑는다(`TranslationEngine.parseParams` 와 같은 규칙).
 *
 * @param paramsStr 키 뒤의 `|…` 문자열(없으면 undefined)
 * @returns 인자 이름 집합
 */
export function suppliedParamNames(paramsStr: string | undefined): Set<string> {
  const names = new Set<string>();
  if (!paramsStr) return names;
  const clean = paramsStr.startsWith('|') ? paramsStr.slice(1) : paramsStr;
  const guarded = clean.replace(PROTECTED_EXPRESSION, '__EXPR__');
  for (const match of guarded.matchAll(PARAM_PATTERN)) names.add(match[1].trim());

  return names;
}

/**
 * 소스 한 벌에서 `$t:` 토큰을 줄 단위로 뽑는다. 주석은 런타임이 먹지 않으므로 걷어 낸다.
 *
 * @param source `.g7.html` 원문
 * @returns 키·인자 문자열·줄 번호 목록
 */
export function translationTokens(source: string): Array<{ key: string; params: string | undefined; line: number }> {
  const withoutComments = source.replace(/<!--[\s\S]*?-->/g, (comment) => comment.replace(/[^\n]/g, ' '));
  const tokens: Array<{ key: string; params: string | undefined; line: number }> = [];
  withoutComments.split('\n').forEach((rawLine, index) => {
    let line = rawLine.replace(/&amp;/g, '&');
    // 1단계(엔진과 같은 순서): 인자 값 자리의 `$t:키` 를 먼저 푼다. 그 키는 인자 없이 번역된다.
    for (let depth = 0; depth < 5 && line.includes('=$t:'); depth++) {
      const before = line;
      line = line.replace(NESTED_TRANSLATION_PARAM, (_match, _token, key: string) => {
        tokens.push({ key, params: undefined, line: index + 1 });

        return '=__NESTED__';
      });
      if (line === before) break;
    }
    // 2단계: 바깥 `$t:` 토큰
    for (const match of line.matchAll(TRANSLATION_TOKEN)) tokens.push({ key: match[1], params: match[2], line: index + 1 });
  });

  return tokens;
}

/**
 * 번역문이 요구하는 자리 이름.
 *
 * @param text 번역문
 * @returns 자리 이름 목록
 */
function requiredParamNames(text: string): string[] {
  return [...new Set([...text.matchAll(PLACEHOLDER)].map((m) => m[1]))];
}

/** 템플릿 식별자 → 종류(admin|user). 확장 레이아웃은 경로의 admin/user 로 고른다. */
function templateTypes(): Map<string, string> {
  const types = new Map<string, string>();
  for (const id of fs.readdirSync(path.join(REPO_ROOT, 'templates/_bundled'))) {
    const manifest = readJson(path.join(REPO_ROOT, 'templates/_bundled', id, 'template.json'));
    if (manifest && typeof manifest.type === 'string') types.set(id, manifest.type);
  }

  return types;
}

/**
 * 레이아웃이 읽는 사전을 만든다. 서버 병합 순서(코어 → 템플릿 → 모듈 → 플러그인)를 따른다.
 *
 * @param templateId 호스트 템플릿 식별자
 * @param locale 로케일
 * @returns 점 경로 사전
 */
function dictionaryFor(templateId: string, locale: string): Map<string, string> {
  const dict = new Map<string, string>();
  loadLang(path.join(REPO_ROOT, 'lang'), locale, '', dict);
  loadLang(path.join(REPO_ROOT, 'templates/_bundled', templateId, 'lang'), locale, '', dict);
  for (const base of ['modules/_bundled', 'plugins/_bundled']) {
    for (const id of fs.readdirSync(path.join(REPO_ROOT, base))) {
      loadLang(path.join(REPO_ROOT, base, id, 'resources/lang'), locale, id, dict);
    }
  }

  return dict;
}

describe('번역 인자 판독 — 엔진 규칙과 같다', () => {
  // @scenario source=fixture
  // @effects layout_translation_params_parsed_like_engine
  it('`|` 와 `&` 로 가른 인자를 모두 읽고, 식 안의 `|`·`&` 는 가르지 않는다', () => {
    const [pipe] = translationTokens('<p>$t:a.b|count={{(x || []).length}}|status={{y &amp;&amp; z}}</p>');
    expect(pipe.key).toBe('a.b');
    expect([...suppliedParamNames(pipe.params)].sort()).toEqual(['count', 'status']);

    const [amp] = translationTokens('<p>$t:a.b|max=500&amp;width=500</p>');
    expect([...suppliedParamNames(amp.params)].sort()).toEqual(['max', 'width']);
  });

  // @scenario source=fixture
  // @effects layout_translation_params_parsed_like_engine
  it('문자열을 이어 붙여 만든 인자도 읽고, 인자 없는 갈래는 빈 집합이다', () => {
    const tokens = translationTokens(
      `<x description="{{id ? '$t:a.edit|name=' + (u?.name ?? '') + '|email=' + (u?.email ?? '') : '$t:a.create'}}"/>`,
    );
    expect(tokens.map((t) => t.key)).toEqual(['a.edit', 'a.create']);
    expect([...suppliedParamNames(tokens[0].params)].sort()).toEqual(['email', 'name']);
    expect(suppliedParamNames(tokens[1].params).size).toBe(0);
  });

  // @scenario source=fixture
  // @effects layout_translation_params_parsed_like_engine
  it('인자 값 자리의 `$t:키` 는 먼저 풀린 인자로 세고, 그 키는 인자 없는 토큰으로 따로 센다', () => {
    const tokens = translationTokens('<p>$t:a.msg|type=$t:a.type|count={{n}}</p>\n<p>$t:a.msg|count={{n}}|status=$t:a.state</p>');
    const outer = tokens.filter((t) => t.key === 'a.msg');
    expect(outer.map((t) => [...suppliedParamNames(t.params)].sort())).toEqual([['count', 'type'], ['count', 'status']]);
    expect(tokens.filter((t) => t.key !== 'a.msg').map((t) => [t.key, t.params])).toEqual([['a.type', undefined], ['a.state', undefined]]);
  });

  // @scenario source=fixture
  // @effects layout_translation_params_parsed_like_engine
  it('주석 안의 토큰은 세지 않는다', () => {
    expect(translationTokens('<!-- $t:a.b 를 쓴다 -->\n<p>$t:c.d</p>').map((t) => [t.key, t.line])).toEqual([['c.d', 2]]);
  });
});

describe('번역 인자 공급 — 번역문 자리를 레이아웃이 채운다', () => {
  const files: string[] = [];
  for (const root of LAYOUT_ROOTS) collectFiles(path.join(REPO_ROOT, root), files);
  const layouts = files.filter((file) => file.replace(/\\/g, '/').includes('/layouts/'));

  const types = templateTypes();
  const adminTemplate = 'sirsoft-admin_basic';
  const userTemplate = 'sirsoft-basic';
  const dictionaries = new Map<string, Map<string, string>[]>();
  /** 레이아웃 경로 → 호스트 템플릿 사전(로케일별). */
  const dictionariesOf = (file: string): Map<string, string>[] => {
    const rel = path.relative(REPO_ROOT, file).replace(/\\/g, '/');
    const templateMatch = rel.match(/^templates\/_bundled\/([^/]+)\//);
    const templateId = templateMatch
      ? templateMatch[1]
      : (/\/layouts\/admin\//.test(rel) ? adminTemplate : userTemplate);
    if (!dictionaries.has(templateId)) dictionaries.set(templateId, LOCALES.map((locale) => dictionaryFor(templateId, locale)));

    return dictionaries.get(templateId)!;
  };

  let checkedTokens = 0;
  const offenders: string[] = [];
  for (const file of layouts) {
    const dicts = dictionariesOf(file);
    for (const token of translationTokens(fs.readFileSync(file, 'utf8'))) {
      const texts = dicts.map((dict) => dict.get(token.key)).filter((t): t is string => typeof t === 'string');
      const required = [...new Set(texts.flatMap(requiredParamNames))];
      if (required.length === 0) continue;
      checkedTokens++;
      const supplied = suppliedParamNames(token.params);
      const missing = required.filter((name) => !supplied.has(name));
      if (missing.length > 0) {
        offenders.push(`${path.relative(REPO_ROOT, file).replace(/\\/g, '/')}:${token.line} ${token.key} missing=${missing.join(',')}`);
      }
    }
  }

  // @scenario source=bundled
  // @effects layout_translation_population_is_derived_from_repository
  it('모집단과 사전이 비어 있지 않다', () => {
    // 파생이 빈 배열을 돌려주면 아래 단언이 조용히 통과한다. 그 구멍을 먼저 막는다.
    expect(types.get(adminTemplate)).toBe('admin');
    expect(types.get(userTemplate)).toBe('user');
    expect(layouts.length).toBeGreaterThan(300);
    expect(dictionariesOf(layouts[0])[0].size).toBeGreaterThan(5000);
    expect(checkedTokens).toBeGreaterThan(200);
  });

  // @scenario source=bundled
  // @effects layout_translation_placeholders_supplied_by_every_bundled_layout
  it('번역문 자리를 채우지 않고 쓰는 `$t:` 가 없다', () => {
    expect(offenders.sort()).toEqual([]);
  });
});
