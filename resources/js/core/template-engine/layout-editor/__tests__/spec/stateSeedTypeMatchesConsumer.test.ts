/**
 * stateSeedTypeMatchesConsumer.test.ts — 자동 채움 표본이 소비처 타입과 맞는지 정적 가드
 *
 * 배경: 편집기 상태 전수 보강이 빈 자리를 `"샘플 값"` 문자열로 자동 채웠는데, 생성기가
 * **소비처 타입을 보지 않았다.** 레이아웃이 `data-each` 로 순회하거나 `?.length` 로 세거나
 * `?? []`·`?? <수>` 로 방어하거나 `parseInt()`·`Number()` 로 감싸는 자리에 문자열이 들어가면
 * 캔버스가 조용히 틀린 모습으로 열린다 — 문자열은 truthy 이고 `.length` 는 글자 수를 돌려주므로
 * 분기가 통째로 뒤집힌다. 실제로 `_global.moduleUninstallBackups` 가 `"샘플 값"` 이던 동안
 * 모듈 제거 창은 본문·삭제 옵션·버튼이 전부 사라지고 「백업 보존됨」 분기만 열렸고,
 * `selectedPlugin.hooks` 는 문자열에 `.filter()` 를 불러 표현식 자체가 깨졌다.
 *
 * 앞 라운드는 지목된 자리만 고치고 가드를 남기지 않아 같은 부류 13경로·30자리가 그대로 남았다.
 * 이 테스트는 모집단을 저장소에서 파생해 **남은 자리가 0** 임을 가드한다.
 *
 * 식이 아니라 **컴포넌트 속성**으로 받는 자리도 본다 — `<toast toasts="{{_global.toasts}}"/>`
 * 처럼 그대로 넘기면 식에는 `.map(` 이 없어 위 검사를 빠져나가지만, 컴포넌트 안에서 배열로 다뤄 렌더가 깨졌다
 * (화면 채움 상태의 알림·코드 경고·표시 열·설치 확장 목록 7자리). 속성 타입은 번들 템플릿 components.json 이 정본이다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';

const ROOT = process.cwd();
const PLACEHOLDER = '샘플 값';
const BUNDLE_BASES = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'];

/**
 * 표본을 담은 스펙 파일을 매니페스트에서 파생한다 — 손으로 적은 목록을 쓰지 않는다.
 * `$include.states`·`$include.sampleData` 조각과 매니페스트 자신을 모두 본다.
 */
function discoverSpecFiles(): string[] {
  const found = new Set<string>();
  for (const base of BUNDLE_BASES) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const manifestRel = `${base}/${ext}/editor-spec.json`;
      if (!existsSync(resolve(ROOT, manifestRel))) continue;
      found.add(manifestRel);
      const manifest = JSON.parse(readFileSync(resolve(ROOT, manifestRel), 'utf8'));
      for (const field of ['states', 'sampleData'] as const) {
        const inc: string | string[] | undefined = manifest?.$include?.[field];
        if (!inc) continue;
        for (const one of Array.isArray(inc) ? inc : [inc]) {
          const rel = join(dirname(manifestRel), one).split('\\').join('/');
          if (existsSync(resolve(ROOT, rel))) found.add(rel);
        }
      }
    }
  }
  return [...found].sort();
}

/** 번들 확장의 레이아웃 마크업 전량. */
function discoverLayouts(): string[] {
  const found: string[] = [];
  const walk = (absDir: string): void => {
    if (!existsSync(absDir)) return;
    for (const entry of readdirSync(absDir)) {
      const abs = join(absDir, entry);
      if (statSync(abs).isDirectory()) walk(abs);
      else if (entry.endsWith('.g7.html')) found.push(abs);
    }
  };
  for (const base of BUNDLE_BASES) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      walk(resolve(baseDir, ext, 'resources/layouts'));
      walk(resolve(baseDir, ext, 'layouts'));
    }
  }
  return found.sort();
}

interface Seed {
  spec: string;
  path: string;
  parent: string;
  key: string;
}

/**
 * 값 자체가 `"샘플 값"` **스칼라**인 자리만 모은다.
 * 배열 원소(`["샘플 값"]`)는 제외한다 — 1-항목 배열 표본은 의도된 것이고
 * `data-each`·`.length` 가 정상 동작한다.
 */
function collectScalarSeeds(specRel: string): Seed[] {
  const out: Seed[] = [];
  const root = JSON.parse(readFileSync(resolve(ROOT, specRel), 'utf8'));
  const walk = (node: unknown, parts: Array<{ k: boolean; v: string | number }>): void => {
    if (Array.isArray(node)) {
      node.forEach((v, i) => walk(v, [...parts, { k: false, v: i }]));
      return;
    }
    if (node && typeof node === 'object') {
      for (const [k, v] of Object.entries(node as Record<string, unknown>)) {
        walk(v, [...parts, { k: true, v: k }]);
      }
      return;
    }
    if (node !== PLACEHOLDER) return;
    const last = parts[parts.length - 1];
    if (!last || !last.k) return; // 배열 원소
    const keys = parts.filter((p) => p.k).map((p) => String(p.v));
    if (keys.length === 0) return;
    out.push({
      spec: specRel,
      path: parts.map((p) => (p.k ? String(p.v) : `[${p.v}]`)).join('/'),
      parent: keys.length > 1 ? keys[keys.length - 2] : '',
      key: keys[keys.length - 1],
    });
  };
  walk(root, []);
  return out;
}

const RE = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** `부모.키` 를 가리키는 소비 앵커. 이름만 겹치는 다른 경로를 배제한다. */
function anchorOf(parent: string, key: string): string {
  const k = RE(key);
  if (parent === '' || parent === 'global' || parent === 'initialState') {
    return `_global\\s*\\??\\.\\s*${k}`;
  }
  return `${RE(parent)}\\s*\\??\\.\\s*${k}`;
}

/** 문자열로는 성립하지 않는 소비 형태들. */
const TAILS: Array<[string, string]> = [
  ['length', '\\s*\\??\\.\\s*length\\b'],
  ['array-method', '\\s*\\??\\.\\s*(filter|map|slice|some|every|join|find|reduce)\\s*\\('],
  ['?? []', '\\s*(\\?\\?|\\|\\|)\\s*\\[\\s*\\]'],
  ['?? number', '\\s*(\\?\\?|\\|\\|)\\s*\\d'],
  // 수로 셈하는 자리 — `x + 1`, `(x ?? y ?? 1) + 1`. 문자열이면 「샘플 값1」 처럼 이어 붙어 쪽 번호가 깨진다
  // (공통 레이아웃 알림 더 보기의 page, 공통정보 목록의 무한 스크롤 page — 검토 지적 2026-10-04).
  ['arithmetic', '\\s*[+\\-*/]\\s*\\d'],
  ['?? … ) arithmetic', '\\s*\\?\\?[^}"]*?\\)\\s*[+\\-*/]\\s*\\d'],
];

/**
 * 번들 템플릿 components.json 에서 컴포넌트별 속성 타입을 모은다 — 「컴포넌트 이름 → 속성 → 타입」.
 * 같은 이름을 여러 템플릿이 다르게 적었으면 문자열이 아닌 쪽을 남긴다(어느 템플릿에서든 깨지면 위반).
 */
function discoverComponentPropTypes(): Map<string, Map<string, string>> {
  const types = new Map<string, Map<string, string>>();
  const baseDir = resolve(ROOT, 'templates/_bundled');
  if (!existsSync(baseDir)) return types;
  for (const tpl of readdirSync(baseDir)) {
    const file = resolve(baseDir, tpl, 'components.json');
    if (!existsSync(file)) continue;
    const manifest = JSON.parse(readFileSync(file, 'utf8'));
    for (const list of Object.values(manifest?.components ?? {})) {
      if (!Array.isArray(list)) continue;
      for (const entry of list as Array<{ name?: string; props?: Record<string, { type?: string }> }>) {
        if (!entry?.name) continue;
        const props = types.get(entry.name) ?? new Map<string, string>();
        for (const [prop, meta] of Object.entries(entry.props ?? {})) {
          const type = String(meta?.type ?? '').toLowerCase();
          if (!type || props.get(prop) === 'array') continue;
          if (!props.has(prop) || props.get(prop) === 'string') props.set(prop, type);
        }
        types.set(entry.name, props);
      }
    }
  }
  return types;
}

/** 마크업 태그(kebab)를 컴포넌트 이름(Pascal)으로 — `tab-navigation` → `TabNavigation`. */
const pascalOf = (tag: string): string =>
  tag.split('-').map((part) => (part ? part[0].toUpperCase() + part.slice(1) : part)).join('');

/** 문자열로는 성립하지 않는 컴포넌트 속성 타입. */
const NON_STRING_PROP_TYPES = new Set(['array', 'number', 'object']);

function nonStringUsages(
  parent: string,
  key: string,
  markup: string,
  propTypes: Map<string, Map<string, string>>,
): string[] {
  const anchor = anchorOf(parent, key);
  const why: string[] = [];
  for (const [label, tail] of TAILS) {
    if (new RegExp(anchor + tail).test(markup)) why.push(label);
  }
  if (new RegExp(`data-each\\s*=\\s*"[^"]*?\\bin\\s+[^"]*?${anchor}`).test(markup)) why.push('data-each');
  if (new RegExp(`(parseInt|Number)\\s*\\(\\s*[^)"]*?${anchor}`).test(markup)) why.push('parseInt/Number');
  // 컴포넌트 속성에 그대로 바인딩된 자리 — `<toast toasts="{{_global.toasts}}"/>` 처럼 식이 아니라
  // 컴포넌트가 값을 쓰는 경우다. 문자열이 배열 속성에 들어가면 컴포넌트 안에서 `.map` 이 깨진다
  // (실측 2026-10-04: 화면 채움 상태의 `_global.toasts` 가 「샘플 값」이라 Toast 가 렌더 오류).
  const propBinding = new RegExp(`<([a-z][a-z0-9-]*)\\b[^>]*?\\s([A-Za-z][\\w-]*)="\\{\\{\\s*(?:raw:)?${anchor}\\s*\\}\\}"`, 'g');
  for (const match of markup.matchAll(propBinding)) {
    const type = propTypes.get(pascalOf(match[1]))?.get(match[2]);
    if (type && NON_STRING_PROP_TYPES.has(type)) why.push(`prop:${pascalOf(match[1])}.${match[2]}(${type})`);
  }
  return [...new Set(why)].sort();
}

const SPEC_FILES = discoverSpecFiles();
const LAYOUT_FILES = discoverLayouts();
const PROP_TYPES = discoverComponentPropTypes();

// 모집단이 비면 아래 단언은 조용히 통과한다 — 「위반 0」과 「검사 대상 0」은 다른 명제다.
describe('표본 타입 가드 모집단', () => {
  /** @effects sample_seed_type_guard_population_is_derived_from_repository */
  it('스펙·레이아웃 모집단을 저장소에서 파생하며, 둘 다 비어 있지 않다', () => {
    expect(SPEC_FILES.length).toBeGreaterThanOrEqual(10);
    expect(LAYOUT_FILES.length).toBeGreaterThanOrEqual(300);
    expect(SPEC_FILES).toContain('templates/_bundled/sirsoft-admin_basic/editor-spec/states.json');
    expect(SPEC_FILES).toContain('modules/_bundled/sirsoft-ecommerce/editor-spec.json');
    const seeds = SPEC_FILES.flatMap(collectScalarSeeds);
    expect(seeds.length).toBeGreaterThan(0);
    // 컴포넌트 속성 타입 모집단도 비어 있으면 안 된다 — 비면 속성 바인딩 판정이 조용히 0건이 된다.
    expect(PROP_TYPES.size).toBeGreaterThanOrEqual(50);
    expect(PROP_TYPES.get('Toast')?.get('toasts')).toBe('array');
  });
});

describe('자동 채움 표본의 타입 정합', () => {
  /**
   * @effects sample_seed_type_matches_consumer_expression
   * @effects sample_seed_type_matches_component_prop_type
   */
  it('레이아웃이 배열·수로 읽는 자리에 문자열 표본이 남아 있지 않다', () => {
    const markup = LAYOUT_FILES.map((abs) => readFileSync(abs, 'utf8')).join('\n');
    const offenders = new Set<string>();
    // (부모,키) 쌍으로 묶어 같은 판정을 반복하지 않는다. 키 이름이 마크업에 아예 없으면
    // 정규식을 돌리지 않는다 — 스펙 표본 수백 건 × 정규식 6종을 전 마크업에 그대로 돌리면
    // 기본 테스트 시간 예산을 넘긴다.
    const pairs = new Map<string, Seed[]>();
    for (const specRel of SPEC_FILES) {
      for (const seed of collectScalarSeeds(specRel)) {
        const key = `${seed.parent}\u0000${seed.key}`;
        pairs.set(key, [...(pairs.get(key) ?? []), seed]);
      }
    }
    for (const [, seeds] of pairs) {
      const { parent, key } = seeds[0];
      if (!markup.includes(key)) continue;
      const why = nonStringUsages(parent, key, markup, PROP_TYPES);
      if (why.length === 0) continue;
      for (const seed of seeds) {
        offenders.add(`${seed.parent || 'global'}.${seed.key} [${why.join(',')}] ${seed.spec}`);
      }
    }
    expect([...offenders].sort()).toEqual([]);
  }, 60_000);
});
