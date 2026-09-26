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
];

function nonStringUsages(parent: string, key: string, markup: string): string[] {
  const anchor = anchorOf(parent, key);
  const why: string[] = [];
  for (const [label, tail] of TAILS) {
    if (new RegExp(anchor + tail).test(markup)) why.push(label);
  }
  if (new RegExp(`data-each\\s*=\\s*"[^"]*?\\bin\\s+[^"]*?${anchor}`).test(markup)) why.push('data-each');
  if (new RegExp(`(parseInt|Number)\\s*\\(\\s*[^)"]*?${anchor}`).test(markup)) why.push('parseInt/Number');
  return why.sort();
}

const SPEC_FILES = discoverSpecFiles();
const LAYOUT_FILES = discoverLayouts();

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
  });
});

describe('자동 채움 표본의 타입 정합', () => {
  /** @effects sample_seed_type_matches_consumer_expression */
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
      const why = nonStringUsages(parent, key, markup);
      if (why.length === 0) continue;
      for (const seed of seeds) {
        offenders.add(`${seed.parent || 'global'}.${seed.key} [${why.join(',')}] ${seed.spec}`);
      }
    }
    expect([...offenders].sort()).toEqual([]);
  }, 60_000);
});
