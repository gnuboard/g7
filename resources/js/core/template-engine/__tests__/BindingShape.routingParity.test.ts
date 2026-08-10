/**
 * 판정기 통일 라우팅 diff 하네스
 *
 * 종전 엔진에는 "이 문자열이 단일 바인딩인가" 를 판정하는 구현이 4종, "이 식이 복잡한
 * 표현식인가" 를 판정하는 정규식이 6종 있었고 서로 인식 문자 집합이 달랐다. 그래서
 * **같은 식이 prop 에서는 undefined 인데 `if` 에서는 정상** 같은 비대칭이 생겼다.
 *
 * `BindingShape` 로 판정을 통일하면 일부 식의 라우팅이 바뀐다. 이 테스트는 저장소의
 * 모든 레이아웃 JSON 에서 단일 바인딩을 수집해 **구 방언들과 신 정본의 판정을 대조**하고,
 * 판정이 바뀌는 식 전체를 소속 파일과 함께 스냅샷으로 고정한다. 목록에 항목이
 * 늘어나면(=새 레이아웃이 라우팅 변경 대상 식을 도입하면) 이 테스트가 깨진다.
 *
 * @since engine-v1.55.0
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { hasPipes } from '../PipeRegistry';
import {
  classifyExpression,
  extractSingleBinding as canonicalExtract,
  isComplexExpression as canonicalIsComplex,
  LITERALS,
} from '../BindingShape';

const REPO_ROOT = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..', '..', '..', '..', '..',
);

const LAYOUT_ROOTS = [
  'resources/layouts',
  'templates/_bundled',
  'modules/_bundled',
  'plugins/_bundled',
];

// ── 구 방언 (교체 대상) ────────────────────────────────────────────────

/** DataBindingEngine.resolveBindings 의 isExpression — 단일 `|` 제외, `||` 만 인식 */
const oldComplexResolveBindings = (e: string) =>
  /[?:&!+\-*/<>=()]/.test(e) || /\|\|/.test(e) || /\[['"]/.test(e);

/** DataBindingEngine 의 isBaseExpression (파이프 base / resolveObject) */
const oldComplexDataBindingEngine = (e: string) =>
  /[?:|&!+\-*/<>=()]/.test(e) || /\[['"]/.test(e);

/** DynamicRenderer / ConditionEvaluator — 가장 좁은 방언 */
const oldComplexNarrow = (e: string) => /[?:|&!+\-*/<>=()]/.test(e);

/** RenderHelpers — 최대집합 (신 정본의 출처) */
const oldComplexRenderHelpers = (e: string) => /[?:|&!+\-*/<>=()[\]{}]/.test(e);

const OLD_COMPLEX_DIALECTS: Record<string, (e: string) => boolean> = {
  'DataBindingEngine.resolveBindings': oldComplexResolveBindings,
  'DataBindingEngine.isBaseExpression': oldComplexDataBindingEngine,
  'DynamicRenderer/ConditionEvaluator': oldComplexNarrow,
  'RenderHelpers': oldComplexRenderHelpers,
};

/** 액션/데이터소스/TemplateApp 의 greedy 단일 바인딩 정규식 */
const oldExtractGreedy = (v: string): string | null => {
  const m = v.match(/^\{\{(.+)\}\}$/);
  return m ? m[1].trim() : null;
};

/** G7CoreGlobals 의 `}` 불허 정규식 */
const oldExtractNoBrace = (v: string): string | null => {
  const m = v.match(/^\{\{([^}]+)\}\}$/);
  return m ? m[1].trim() : null;
};

// ── 레이아웃 수집 ──────────────────────────────────────────────────────

interface Occurrence {
  file: string;
  jsonPath: string;
  value: string;
}

function walkFiles(dir: string, acc: string[] = []): string[] {
  let entries: fs.Dirent[];
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch {
    return acc;
  }
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === 'vendor') continue;
      walkFiles(full, acc);
      continue;
    }
    if (entry.name.endsWith('.json') && full.replace(/\\/g, '/').includes('/layouts/')) {
      acc.push(full);
    }
  }
  return acc;
}

function collectStrings(node: unknown, segs: (string | number)[], file: string, out: Occurrence[]): void {
  if (typeof node === 'string') {
    if (node.includes('{{')) {
      out.push({ file, jsonPath: segs.join('.'), value: node });
    }
    return;
  }
  if (Array.isArray(node)) {
    node.forEach((v, i) => collectStrings(v, segs.concat(i), file, out));
    return;
  }
  if (node && typeof node === 'object') {
    for (const [k, v] of Object.entries(node)) collectStrings(v, segs.concat(k), file, out);
  }
}

function collectOccurrences(): Occurrence[] {
  const out: Occurrence[] = [];
  for (const root of LAYOUT_ROOTS) {
    for (const file of walkFiles(path.join(REPO_ROOT, root))) {
      let json: unknown;
      try {
        json = JSON.parse(fs.readFileSync(file, 'utf8'));
      } catch {
        continue;
      }
      const relative = path.relative(REPO_ROOT, file).replace(/\\/g, '/');
      collectStrings(json, [], relative, out);
    }
  }
  return out;
}

const occurrences = collectOccurrences();

describe('BindingShape 라우팅 diff 하네스', () => {
  it('레이아웃 모집단을 실제로 수집한다 (하네스 자체 회귀 방지)', () => {
    const files = new Set(occurrences.map((o) => o.file));
    expect(files.size).toBeGreaterThan(100);
    expect(occurrences.length).toBeGreaterThan(1000);
  });

  it('단일 바인딩 판정: 구 정규식과 신 정본이 갈리는 총량', () => {
    const values = new Set<string>();
    for (const occ of occurrences) {
      const canonical = canonicalExtract(occ.value);
      if (canonical === oldExtractGreedy(occ.value) && canonical === oldExtractNoBrace(occ.value)) continue;
      values.add(occ.value);
    }
    // 대부분은 greedy 정규식(`^\{\{(.+)\}\}$`)이 `"{{a}}-{{b}}"` 같은 보간 문자열을
    // 단일 바인딩으로 오판하던 경우다. 총량만 고정하고, 실제 피해가 나는 지점
    // (액션 params / 데이터소스 params)은 아래에서 따로 목록화한다.
    expect(values.size).toMatchSnapshot();
  });

  it('단일 바인딩 판정: greedy 정규식이 실제로 쓰이는 지점(액션/데이터소스 params)의 갈리는 값', () => {
    const isGreedySite = (jsonPath: string) =>
      /(^|\.)data_sources\./.test(jsonPath) ||
      (/\.params(\.|$)/.test(jsonPath) &&
        (/(^|\.)actions?\./.test(jsonPath) || /(^|\.)init_actions\./.test(jsonPath) || /\.on[A-Z]/.test(jsonPath)));

    const diffs = new Map<string, { files: Set<string>; greedy: string | null; canonical: string | null }>();
    for (const occ of occurrences) {
      if (!isGreedySite(occ.jsonPath)) continue;
      const canonical = canonicalExtract(occ.value);
      const greedy = oldExtractGreedy(occ.value);
      if (canonical === greedy) continue;

      const entry = diffs.get(occ.value) ?? { files: new Set<string>(), greedy, canonical };
      entry.files.add(`${occ.file} :: ${occ.jsonPath}`);
      diffs.set(occ.value, entry);
    }

    const report = [...diffs.entries()]
      .sort((a, b) => a[0].localeCompare(b[0]))
      .map(([value, e]) => ({
        value,
        greedy: e.greedy,
        canonical: e.canonical,
        sites: [...e.files].sort(),
      }));

    expect(report).toMatchSnapshot();
  });

  it('표현식/경로 판정: 구 방언과 신 정본이 갈리는 식 목록', () => {
    const diffs = new Map<string, { files: Set<string>; old: Record<string, boolean>; canonical: boolean }>();

    for (const occ of occurrences) {
      const inner = canonicalExtract(occ.value);
      if (inner === null) continue;
      // 파이프·리터럴·빈 식은 복잡식 판정에 도달하지 않는다.
      const shape = classifyExpression(inner);
      if (shape === 'pipe' || shape === 'literal' || shape === 'empty') continue;

      const canonical = canonicalIsComplex(inner);
      const old: Record<string, boolean> = {};
      let diverges = false;
      for (const [name, fn] of Object.entries(OLD_COMPLEX_DIALECTS)) {
        old[name] = fn(inner);
        if (old[name] !== canonical) diverges = true;
      }
      if (!diverges) continue;

      const entry = diffs.get(inner) ?? { files: new Set<string>(), old, canonical };
      entry.files.add(occ.file);
      diffs.set(inner, entry);
    }

    const report = [...diffs.entries()]
      .sort((a, b) => a[0].localeCompare(b[0]))
      .map(([expr, e]) => ({
        expr,
        canonical: e.canonical,
        old: e.old,
        files: [...e.files].sort(),
      }));

    expect(report).toMatchSnapshot();
  });

  it('판정이 갈리는 식의 분류별 건수 요약', () => {
    let quotedBracket = 0;
    let numericIndex = 0;
    let other = 0;
    const seen = new Set<string>();

    for (const occ of occurrences) {
      const inner = canonicalExtract(occ.value);
      if (inner === null || seen.has(inner)) continue;
      const shape = classifyExpression(inner);
      if (shape === 'pipe' || shape === 'literal' || shape === 'empty') continue;
      const canonical = canonicalIsComplex(inner);
      const diverges = Object.values(OLD_COMPLEX_DIALECTS).some((fn) => fn(inner) !== canonical);
      if (!diverges) continue;
      seen.add(inner);

      if (/\[\s*['"]/.test(inner)) quotedBracket++;
      else if (/\[\s*\d+\s*\]/.test(inner)) numericIndex++;
      else other++;
    }

    expect({ quotedBracket, numericIndex, other, total: seen.size }).toMatchSnapshot();
  });

  it('빈 바인딩(`{{}}`)은 저장소에 존재하지 않는다', () => {
    const empties = occurrences.filter((o) => {
      const inner = canonicalExtract(o.value);
      return inner !== null && classifyExpression(inner) === 'empty';
    });
    expect(empties.map((o) => `${o.file} :: ${o.jsonPath}`)).toEqual([]);
  });

  it('파이프 식은 파이프로 분류된다 (분류 순서 회귀 방지)', () => {
    const pipeExprs = occurrences
      .map((o) => canonicalExtract(o.value))
      .filter((e): e is string => e !== null)
      .filter((e) => hasPipes(e));
    expect(pipeExprs.length).toBeGreaterThan(0);
    for (const e of pipeExprs) {
      expect(classifyExpression(e)).toBe('pipe');
    }
  });

  it('리터럴 식은 경로로 해석되지 않는다', () => {
    for (const literal of LITERALS.keys()) {
      expect(classifyExpression(literal)).toBe('literal');
    }
  });
});
