/**
 * @file layout-translation-keys.test.ts
 * @description 레이아웃과 라우트가 쓰는 다국어 키($t:)가 ko/en/ja 언어 파일에 모두 있다 (7.1.0 O-C3c-1 같은 결함군)
 *
 * 검증 방식: 레이아웃 원본(.g7.html)과 라우트 JSON 의 정적 대조(DOM 비의존).
 *
 * 결함: 사용자 템플릿 인기글 화면의 공유 미리보기 제목 키(`nav.popular_posts`)처럼 어느 언어 파일에도 없는 키를 쓰면
 *   화면과 검색엔진용 렌더가 키 이름이나 사이트 기본 제목으로 대신 나간다. 같은 결함이 다른 레이아웃에도 있었다.
 *
 * 판정 규칙:
 *   - `$t:키`, `$t:defer:키`, `$t('키')` 를 모은다. `{{ }}` 로 이어 붙이는 동적 키, `'접두' + 값` 꼴은 뺀다.
 *   - 확장 식별자로 시작하는 키는 그 확장의 언어 파일(ja 는 그 확장의 ja 언어팩)에서, 나머지는 레이아웃이 그려지는
 *     템플릿(관리자 레이아웃은 sirsoft-admin_basic, 사용자 레이아웃은 sirsoft-basic)과 코어 프런트 언어 파일에서 찾는다.
 *   - 라우트 레이아웃에서 `<include>`·`<modal src>` 로 닿지 않는 partials 파일(아무 화면도 그리지 않는 고아)은 뺀다.
 *
 * @scenario-manifest tests/scenarios/layout-translation-keys.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const EXT_ROOT = path.resolve(__dirname, '../../../..');
const REPO = path.resolve(EXT_ROOT, '../../..');
const EXT_ID = 'sirsoft-ckeditor5';
const EXT_KIND = 'plugin' as 'template' | 'module' | 'plugin';
const LOCALES = ['ko', 'en', 'ja'] as const;

type Dict = Record<string, unknown>;

const walk = (target: string, out: string[] = []): string[] => {
  if (!fs.existsSync(target)) return out;
  if (fs.statSync(target).isFile()) { out.push(target); return out; }
  for (const e of fs.readdirSync(target, { withFileTypes: true })) walk(path.join(target, e.name), out);
  return out;
};

/** `$partial` 을 풀어 언어 묶음을 만든다 */
const loadBundle = (file: string): Dict => {
  if (!fs.existsSync(file)) return {};
  const dir = path.dirname(file);
  const resolve = (o: Dict): Dict => {
    for (const [k, v] of Object.entries(o)) {
      if (v && typeof v === 'object') {
        const partial = (v as Dict).$partial;
        o[k] = typeof partial === 'string' ? resolve(JSON.parse(fs.readFileSync(path.join(dir, partial), 'utf8'))) : resolve(v as Dict);
      }
    }
    return o;
  };
  return resolve(JSON.parse(fs.readFileSync(file, 'utf8')));
};

const extIds = (kind: 'modules' | 'plugins'): string[] => {
  const d = path.join(REPO, kind, '_bundled');
  return fs.existsSync(d) ? fs.readdirSync(d) : [];
};
const MODULES = extIds('modules');
const PLUGINS = extIds('plugins');

const cache = new Map<string, Dict>();
const dict = (locale: string, kind: 'core' | 'template' | 'module' | 'plugin', id = ''): Dict => {
  const ck = `${locale}|${kind}|${id}`;
  if (!cache.has(ck)) {
    let file: string;
    if (kind === 'core') file = locale === 'ja' ? path.join(REPO, 'lang-packs/_bundled/g7-core-ja/frontend/ja.json') : path.join(REPO, `lang/${locale}.json`);
    else if (kind === 'template') file = locale === 'ja' ? path.join(REPO, `lang-packs/_bundled/g7-template-${id}-ja/frontend/ja.json`) : path.join(REPO, `templates/_bundled/${id}/lang/${locale}.json`);
    else file = locale === 'ja'
      ? path.join(REPO, `lang-packs/_bundled/g7-${kind}-${id}-ja/frontend/ja.json`)
      : path.join(REPO, `${kind === 'module' ? 'modules' : 'plugins'}/_bundled/${id}/resources/lang/${locale}.json`);
    cache.set(ck, loadBundle(file));
  }
  return cache.get(ck)!;
};

const get = (o: Dict, key: string): unknown => key.split('.').reduce<unknown>((a, p) => (a && typeof a === 'object' ? (a as Dict)[p] : undefined), o);

const lookup = (locale: string, template: string, key: string): unknown => {
  const first = key.split('.')[0];
  if (MODULES.includes(first)) return get(dict(locale, 'module', first), key.slice(first.length + 1));
  if (PLUGINS.includes(first)) return get(dict(locale, 'plugin', first), key.slice(first.length + 1));
  const v = get(dict(locale, 'template', template), key);
  return v !== undefined ? v : get(dict(locale, 'core'), key);
};

const SOURCE_DIRS = EXT_KIND === 'template'
  ? ['layouts', 'routes.json']
  : ['resources/layouts', 'resources/extensions', 'resources/routes', 'resources/routes.json'];

const sourceFiles = (): string[] => SOURCE_DIRS.flatMap((d) => walk(path.join(EXT_ROOT, d)));

/** 라우트 레이아웃(partials 밖)에서 `<include>`·`<modal src>` 로 닿는 .g7.html 집합 */
const reachable = (htmls: string[]): Set<string> => {
  const exist = new Set(htmls.map((f) => path.resolve(f).toLowerCase()));
  const edges = new Map<string, string[]>();
  for (const f of htmls) {
    const out: string[] = [];
    for (const m of fs.readFileSync(f, 'utf8').matchAll(/<(?:include|modal)\s+[^>]*?\bsrc="([^"]+)"/g)) {
      let dir = path.dirname(f);
      for (;;) {
        const c = path.resolve(dir, m[1]).toLowerCase();
        if (exist.has(c)) { out.push(c); break; }
        if (dir.length <= EXT_ROOT.length) break;
        dir = path.dirname(dir);
      }
    }
    edges.set(path.resolve(f).toLowerCase(), out);
  }
  const seen = new Set<string>();
  const stack = htmls.filter((f) => !path.resolve(f).split(path.sep).includes('partials')).map((f) => path.resolve(f).toLowerCase());
  while (stack.length) {
    const c = stack.pop()!;
    if (seen.has(c)) continue;
    seen.add(c);
    for (const n of edges.get(c) ?? []) stack.push(n);
  }
  return seen;
};

const templateFor = (rel: string): string[] => {
  if (EXT_KIND === 'template') return [EXT_ID];
  if (/(^|\/)user(\/|\.json$)/.test(rel)) return ['sirsoft-basic'];
  if (/(^|\/)admin(\/|\.json$)/.test(rel)) return ['sirsoft-admin_basic'];
  return ['sirsoft-admin_basic', 'sirsoft-basic'];
};

const KEY_RE = /\$t:(?:defer:)?([A-Za-z][\w-]*(?:\.[\w-]+)+)(\.?\{\{)?/g;
const CALL_RE = /\$t\(\s*['"]([A-Za-z][\w-]*(?:\.[\w-]+)+)['"]/g;

/** 파일 하나에서 정적 키와 줄 번호를 모은다 */
const collectKeys = (src: string): { key: string; line: number }[] => {
  const out: { key: string; line: number }[] = [];
  for (const re of [KEY_RE, CALL_RE]) {
    re.lastIndex = 0;
    let m: RegExpExecArray | null;
    while ((m = re.exec(src))) {
      if (m[2]) continue;
      const end = m.index + m[0].length;
      if (src[end] === '{') continue;
      if (re === CALL_RE && /^\s*\+/.test(src.slice(end, end + 5))) continue;
      out.push({ key: m[1].replace(/\.$/, ''), line: src.slice(0, m.index).split('\n').length });
    }
  }
  return out;
};

const scan = () => {
  const all = sourceFiles();
  const htmls = all.filter((f) => f.endsWith('.g7.html'));
  const reach = reachable(htmls);
  const targets = [
    ...htmls.filter((f) => reach.has(path.resolve(f).toLowerCase())),
    // 짝 .g7.html 이 없는 JSON(라우트 정의 등)
    ...all.filter((f) => f.endsWith('.json') && !fs.existsSync(f.replace(/\.json$/, '.g7.html')) && !f.split(path.sep).includes('lang')),
  ];
  const missing: string[] = [];
  let count = 0;
  for (const f of targets) {
    const rel = path.relative(EXT_ROOT, f).replace(/\\/g, '/');
    let src = fs.readFileSync(f, 'utf8');
    if (f.endsWith('.g7.html')) src = src.replace(/<!--[\s\S]*?-->/g, (c) => c.replace(/[^\n]/g, ' '));
    for (const { key, line } of collectKeys(src)) {
      count++;
      for (const locale of LOCALES) {
        if (!templateFor(rel).some((t) => typeof lookup(locale, t, key) === 'string')) missing.push(`${locale}:${key} (${rel}:${line})`);
      }
    }
  }
  return { count, missing: [...new Set(missing)] };
};

describe(`${EXT_ID} 레이아웃·라우트의 다국어 키`, () => {
  /**
   * @scenario source=layout_and_route
   * @effects layout_translation_keys_resolve_in_all_locales
   */
  it('레이아웃과 라우트가 쓰는 $t: 키가 ko/en/ja 언어 파일에 모두 있다', () => {
    const { count, missing } = scan();
    expect(count).toBeGreaterThan(0);
    expect(missing).toEqual([]);
  });

  /**
   * @scenario source=layout_and_route
   * @effects dynamic_and_orphan_excluded
   */
  it('동적 키와 고아 partial 은 판정에서 뺀다', () => {
    expect(collectKeys(`{{$t('a.b_' + x)}} $t:a.status.{{s}} $t:defer:c.d $t:e.f|n=1`).map((k) => k.key)).toEqual(['c.d', 'e.f']);
  });
});
