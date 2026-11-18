/**
 * @file loading-negative-state.test.tsx
 * @description 마케팅 동의 이력 안내 — 받는 중에는 「없음」류 안내를 보이지 않는다
 *
 * 화면이 먼저 그려진 뒤 받는 데이터소스(progressive — 전략을 적지 않으면 이 값이다)는 받기 전 값이
 * `undefined` 다. 「없음」 안내의 조건이 `(ds?.data ?? []).length === 0` 처럼 빈 값을 0건으로 메우면
 * 받는 중에도 참이 되어, 데이터가 있는데도 「없습니다」가 먼저 보였다가 바뀐다. 안내 조건은
 * 데이터소스 값이 있을 때만(`ds !== undefined && …`) 참이어야 한다.
 *
 * 각 화면의 실제 레이아웃 JSON 에서 안내 노드를 꺼내 하네스로 렌더한다:
 *
 *  1. 받는 중(값이 `undefined`) — 안내가 없다.
 *  2. 받은 뒤 0건(또는 「없음」 상태) — 안내가 보인다.
 *  3. 받은 뒤 있음 — 안내가 없다(조건이 계산값에 기대는 화면은 생략).
 *  4. 전수 — 이 확장의 레이아웃에서 받는 중에 참이 되는 「없음」류 안내가 없다(엔진의 실제 조건 평가기로 잰다).
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { evaluateIfCondition } from '@core/template-engine/helpers/RenderHelpers';

type Node = Record<string, any>;

/** 확장 루트 */
const PKG_ROOT = path.resolve(__dirname, '../../../..');

interface StateCase {
  /** 화면 이름(시험 제목용) */
  screen: string;
  /** 확장 루트 기준 레이아웃 JSON 경로 */
  file: string;
  /** 안내 노드 id(없으면 condIncludes 로 찾는다) */
  nodeId?: string;
  /** 안내 노드 `if` 에 들어 있는 문자열 */
  condIncludes?: string;
  /** 받는 데이터소스 */
  source: string;
  /** 안내 문구 다국어 키 */
  key: string;
  /** 받은 뒤 「없음」 상태의 데이터소스 값 */
  empty: unknown;
  /** 받은 뒤 「있음」 상태의 데이터소스 값(생략하면 3번 시험을 건너뛴다) */
  rows?: unknown;
  /** 받은 뒤 화면 상태(_local) — 폼이 데이터소스에서 채워지는 화면 */
  emptyLocal?: Record<string, any>;
  rowsLocal?: Record<string, any>;
  /** 렌더 컨텍스트에 함께 싣는 값(확장점 props·부모 상태 등) */
  context?: Record<string, any>;
  /**
   * 받기에 실패할 수 있는 소스(오류 처리·대체값 없음)인지. 처음 받기에 실패하면 엔진이 값을 `null` 로 둔다 —
   * 그때 「없음」 안내는 숨고, failNodeId 가 있으면 그 노드가 실패 안내(failKey)를 보인다.
   */
  failure?: boolean;
  failNodeId?: string;
  failKey?: string;
  /** 실패 상태의 값(기본 null) — 화면 상태 맵처럼 항목 단위로 실패를 적는 곳 */
  failValue?: unknown;
}

const CASES: StateCase[] = [
  {
    screen: '관리자 회원 상세 동의 이력',
    file: 'resources/extensions/user-marketing-detail.json',
    condIncludes: '!user?.data?.consent_histories || user?.data?.consent_histories?.length === 0',
    failure: true,
    source: 'user',
    key: 'sirsoft-marketing.no_consent_history',
    empty: {
      data: {
        consent_histories: []
      }
    },
    rows: {
      data: {
        consent_histories: [
          {
            id: 1
          }
        ]
      }
    }
  }
];

/** 이 확장 밖(호스트 화면)에서 받는 데이터소스 — 확장 주입 파일이 읽는다 */
const EXTERNAL_PROGRESSIVE = [
  'user'
];

/**
 * 트리에서 조건에 맞는 노드를 모두 찾는다.
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @param out 결과 배열
 * @returns 찾은 노드 목록
 */
function findAll(node: any, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, predicate, out));
    return out;
  }
  if (predicate(node)) out.push(node);
  for (const value of Object.values(node)) findAll(value, predicate, out);
  return out;
}

/**
 * 레이아웃 JSON 을 읽는다.
 *
 * @param file 확장 루트 기준 경로
 * @returns JSON
 */
function readLayout(file: string): Node {
  return JSON.parse(fs.readFileSync(path.join(PKG_ROOT, file), 'utf8'));
}

/**
 * 안내 노드 하나를 꺼낸다.
 *
 * @param c 화면
 * @returns 노드
 */
function nodeOf(c: StateCase): Node {
  const layout = readLayout(c.file);
  if (c.nodeId) {
    const byId = findAll(layout, (n) => n.id === c.nodeId);
    expect(byId).toHaveLength(1);
    return byId[0];
  }
  // 조건 원문이 같은 노드를 먼저 찾는다 — 받는 중 조건(`ds !== undefined && (…)`)으로 감싼 뒤에도 같은 노드다
  const unwrap = (cond: string) => cond.trim().replace(/^\{\{\s*[\w$]+ !== undefined && (?:[\w$]+ !== null && )?\(([\s\S]*)\)\s*\}\}$/, '{{$1}}');
  const exact = findAll(layout, (n) => typeof n.if === 'string' && unwrap(n.if) === `{{${c.condIncludes}}}`);
  if (exact.length === 1) return exact[0];
  const found = findAll(layout, (n) => typeof n.if === 'string' && n.if.includes(c.condIncludes ?? '\u0000'));
  expect(found).toHaveLength(1);
  return found[0];
}

/**
 * 점 경로 다국어 키를 중첩 객체로 만든다. 값은 키를 알아볼 수 있는 표지 문자열이다.
 *
 * @param keys 다국어 키 목록
 * @returns 번역 사전
 */
function translationsFor(keys: string[]): Record<string, any> {
  const root: Record<string, any> = {};
  for (const key of keys) {
    const parts = key.split('.');
    let cursor = root;
    parts.slice(0, -1).forEach((part) => {
      cursor[part] = cursor[part] ?? {};
      cursor = cursor[part];
    });
    cursor[parts[parts.length - 1]] = `표지:${key}`;
  }
  return root;
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
});

/**
 * 노드 안의 컴포넌트를 글자만 그리는 스텁으로 등록한다 — 조건(if)과 문구 해석은 엔진이 한다.
 *
 * @param node 노드
 */
function registerStubs(node: Node): void {
  for (const n of findAll(node, (x) => typeof x.name === 'string' && typeof x.type === 'string')) {
    if (n.name === 'DataGrid') continue;
    registry.register(n.type, n.name, ({ children, text, label, title }: any) =>
      React.createElement('div', null, children ?? text ?? label ?? title ?? null)
    );
  }
}

/**
 * 안내 노드를 데이터소스 값과 함께 렌더하고 안내 문구가 보이는지 돌려준다.
 *
 * @param c 화면
 * @param value 데이터소스 값 — `undefined` 는 「받는 중」(엔진이 화면을 먼저 그릴 때 넣는 값)
 * @param local 화면 상태
 * @returns 안내 문구 노출 여부
 */
async function shows(c: StateCase, value: unknown, local?: Record<string, any>): Promise<boolean> {
  return (await textOf(c, value, local)).includes(`표지:${c.key}`);
}

/**
 * 안내 노드(또는 nodeId 를 바꾼 사례)를 렌더하고 글자를 돌려준다.
 *
 * @param c 화면
 * @param value 데이터소스 값
 * @param local 화면 상태
 * @returns 렌더된 글자
 */
async function textOf(c: StateCase, value: unknown, local?: Record<string, any>): Promise<string> {
  const { meta, ...node } = nodeOf(c);
  registerStubs(node);
  const utils = createLayoutTest(
    { version: '1.0.0', layout_name: `test/loading-state/${c.source}`, components: [node] } as any,
    {
      componentRegistry: registry as any,
      locale: 'ko',
      translations: translationsFor([c.key, ...(c.failKey ? [c.failKey] : [])]),
      initialState: local ? { _local: local } : undefined,
      initialData: { ...(c.context ?? {}), [c.source]: value, $templateId: 'test-template', $locale: 'ko' },
    }
  );
  const { container } = await utils.render();
  const text = container.textContent ?? '';
  utils.cleanup();
  return text;
}

describe('마케팅 — 받는 중에는 「없음」류 안내가 없다', () => {
  /**
   * @scenario load_state=loading
   * @effects loading_hides_negative_state
   */
  it.each(CASES)('$screen: 받는 중에는 안내가 없다', async (c) => {
    expect(await shows(c, undefined)).toBe(false);
  });

  /**
   * @scenario load_state=loaded_empty
   * @effects loaded_empty_shows_negative_state
   */
  it.each(CASES)('$screen: 받은 뒤 없으면 안내가 보인다', async (c) => {
    expect(await shows(c, c.empty, c.emptyLocal)).toBe(true);
  });

  /**
   * @scenario load_state=loaded_rows
   * @effects loaded_rows_hide_negative_state
   */
  it.each(CASES.filter((c) => c.rows !== undefined))('$screen: 받은 뒤 있으면 안내가 없다', async (c) => {
    expect(await shows(c, c.rows, c.rowsLocal)).toBe(false);
  });

  /**
   * 받기에 실패하면(값 `null` — 처음 받기, 다른 요청으로 다시 받기) 「없음」 안내를 보이지 않는다.
   *
   * @scenario load_state=error
   * @effects error_hides_negative_state
   */
  it.each(CASES.filter((c) => c.failure))('$screen: 받기에 실패하면 「없음」 안내가 없다', async (c) => {
    expect(await shows(c, c.failValue !== undefined ? c.failValue : null)).toBe(false);
  });

});

describe('마케팅 — 전수', () => {
  /** 「없음」류 안내 문구 키 */
  const NEGATIVE = /(empty|no_data|no_items|no_results|no_[a-z_]+|not_found|nothing|none_|_none\b|missing|pending|deleted|not_provided|not_set|unregistered|not_enrolled)/i;

  /** 받는 중에 참이어도 되는 안내 — 이유를 적는다 */
  const ALLOW = new Map<string, string>([]);

  /**
   * 디렉터리 아래 JSON 을 모두 모은다.
   *
   * @param dir 디렉터리
   * @param out 결과
   * @returns 파일 경로 목록
   */
  function walk(dir: string, out: string[] = []): string[] {
    if (!fs.existsSync(dir)) return out;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full, out);
      else if (entry.name.endsWith('.json')) out.push(full);
    }
    return out;
  }

  const files = [...walk(path.join(PKG_ROOT, 'resources/layouts')), ...walk(path.join(PKG_ROOT, 'resources/extensions'))];
  const rel = (f: string) => path.relative(PKG_ROOT, f).split(path.sep).join('/');
  const jsonOf = new Map(files.map((f) => [rel(f), JSON.parse(fs.readFileSync(f, 'utf8'))]));

  /**
   * 화면이 먼저 그려진 뒤 받는 데이터소스 — 그 파일이 속한 레이아웃(조각은 `partials/<레이아웃>/`)의 선언으로 정한다.
   * initLocal 로 화면 상태를 채우는 데이터소스는 그 상태 키(`_local.<키>`)도 받는 중에 비어 있다.
   *
   * @param file 확장 루트 기준 경로
   * @returns 받는 중에 비어 있는 식별자 목록과 화면 상태 키 목록
   */
  function loadingScope(file: string): { ids: string[]; localKeys: string[]; unhandled: string[] } {
    const owners: Node[] = [jsonOf.get(file) as Node];
    const m = file.match(/^(.*)\/partials\/([^/]+)\//);
    if (m) {
      const parent = jsonOf.get(`${m[1]}/${m[2]}.json`);
      if (parent) owners.push(parent);
      else for (const j of jsonOf.values()) owners.push(j); // 공용 조각 — 확장 전체 선언
    }
    if (file.startsWith('resources/extensions/')) {
      // 확장 주입 파일은 같은 호스트 화면에 함께 주입된 다른 주입 파일의 데이터소스도 읽는다
      for (const [f, j] of jsonOf) if (f.startsWith('resources/extensions/')) owners.push(j);
    }
    const ids = new Set<string>(file.startsWith('resources/extensions/') ? EXTERNAL_PROGRESSIVE : []);
    const localKeys = new Set<string>();
    const unhandled = new Set<string>();
    for (const owner of owners) {
      for (const ds of findAll(owner, (n) => Array.isArray(n.data_sources)).flatMap((n) => n.data_sources)) {
        if (!ds?.id || (ds.loading_strategy ?? 'progressive') === 'blocking') continue;
        ids.add(ds.id);
        // 받기에 실패하면 엔진은 값을 null 로 둔다(처음 받기, 그리고 다른 요청으로 다시 받기 — 성공하면 오류 기록을 지운다).
        // 오류 처리 기본값·대체값·onError 가 있으면 그쪽이 화면을 맡는다
        if (!ds.fallback && !ds.onError && !ds.errorHandling?.default) unhandled.add(ds.id);
        const il = ds.initLocal ?? ds.init_local;
        if (typeof il === 'string' && il) localKeys.add(il);
        else if (il && typeof il === 'object') Object.keys(il).filter((k) => !k.startsWith('_')).forEach((k) => localKeys.add(k.split('.')[0]));
      }
    }
    return { ids: [...ids], localKeys: [...localKeys], unhandled: [...unhandled] };
  }

  /**
   * 받는 중 컨텍스트에서 조건을 엔진으로 평가한다 — 데이터소스는 없고 화면 상태는 비어 있다.
   *
   * @param cond 조건
   * @returns 참이면 true
   */
  function trueWhileLoading(cond: string, failed: string[] = []): boolean {
    const ctx: any = { _global: {}, _local: {}, _computed: {}, query: {}, route: {}, $parent: { _local: {}, _global: {} } };
    for (const id of failed) ctx[id] = null;
    return evaluateIfCondition(cond, ctx, dataBindingEngine);
  }

  /**
   * 노드가 직접 그리는 글자 — 자기 조건(if)을 가진 자식·반복 자식은 따로 평가하므로 뺀다.
   *
   * @param node 노드
   * @returns 글자·속성을 이은 문자열
   */
  function ownText(node: Node): string {
    const { children, ...rest } = node;
    const own = (Array.isArray(children) ? children : []).filter((c: any) => c && typeof c === 'object' && !c.if && !c.iteration);
    return JSON.stringify(rest.text ?? '') + JSON.stringify(rest.props ?? {}) + own.map(ownText).join('');
  }

  /**
   * 받는 중에 참이 되는 「없음」류 안내 노드를 모은다. 위쪽 조건 중 받는 데이터에 기대는 것이 거짓이면 숨겨진 것이다.
   *
   * @scenario load_state=loading
   * @effects every_negative_state_gated
   */
  it('받는 중에 참이 되는 「없음」류 안내가 없다', () => {
    const offenders: string[] = [];
    let checked = 0;
    for (const [file, json] of jsonOf) {
      const { ids, localKeys, unhandled } = loadingScope(file);
      const dependsOnLoading = (cond: string) =>
        ids.some((id) => new RegExp(`(^|[^\\w.$])${id.replace(/\$/g, '\\$')}\\b`).test(cond)) ||
        localKeys.some((k) => cond.includes(`_local.${k}`) || cond.includes(`_local?.${k}`));
      const visit = (node: any, chain: string[]) => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) return node.forEach((c) => visit(c, chain));
        if (node.iteration) return; // 반복 항목은 받기 전에 그려지지 않는다
        let next = chain;
        if (typeof node.if === 'string' && node.if.trim().startsWith('{{')) {
          next = [...chain, node.if];
          if (dependsOnLoading(node.if)) {
            const keys = (ownText(node).match(/\$t:[\w.-]+|\$t\('[\w.-]+'\)/g) ?? []).filter((k) => NEGATIVE.test(k));
            if (keys.length) {
              checked += 1;
              const ancestorsShown = chain.filter(dependsOnLoading).every((c) => trueWhileLoading(c));
              const label = `${file} ${node.id ?? node.name}`;
              if (ancestorsShown && trueWhileLoading(node.if) && !ALLOW.has(label)) offenders.push(`${label} ${node.if}`);
              // 받기 실패(null) — 오류 처리가 없는 소스가 실패했을 때도 「없음」이 보이면 거짓 안내다
              const failed = unhandled.filter((id) => new RegExp(`(^|[^\\w.$])${id}\\b`).test(node.if) || chain.some((c) => c.includes(id)));
              if (failed.length) {
                const shownOnFailure = chain.filter(dependsOnLoading).every((c) => trueWhileLoading(c, failed)) && trueWhileLoading(node.if, failed);
                if (shownOnFailure && !ALLOW.has(`${label} (실패)`)) offenders.push(`${label} (받기 실패 null 에서도 보임) ${node.if}`);
              }
            }
          }
        }
        for (const [k, v] of Object.entries(node)) if (k !== 'data_sources' && k !== 'if') visit(v, next);
      };
      visit(json, []);
    }
    expect(checked).toBeGreaterThan(0);
    expect(offenders).toEqual([]);
  });
});

describe('마케팅 — 받는 중 노출 전수', () => {
  /**
   * 화면이 먼저 그려진 뒤 받는 데이터(progressive·background·전략 미지정 소스, 그 initGlobal/initLocal 키,
   * 로그인 사용자 `_global.currentUser`)를 모두 `undefined` 로 둔 「받는 중」 상태에서, 받은 뒤에만 뜻이 있는
   * 요소가 보이면 안 된다(#915). 엔진의 실제 조건 평가기로 트리를 내려가며 조상이 보이는 노드만 본다.
   *
   *  1. 보이는 노드의 `if` 가 받는 데이터에 기대면서 참이다 — 받는 중 값을 「없음」·「비회원」·「미동의」로 읽은 것.
   *     식에 `=== undefined` 가 있으면(받는 중 표시를 일부러 그린 것) 뺀다.
   *  1-2. 조건이 여러 데이터에 기대면, 하나가 먼저 와 나머지 마디가 참이 된 순간에도 보이면 안 된다 — 받는 데이터 하나만
   *     기대는 마디가 받는 중에 참이면(`X?.data?.y !== true`) 그 데이터는 `X !== undefined &&` 로 기다려야 한다.
   *  1-1. 회원/비회원 분기(`!_global.currentUser…`)는 조상과 무관하게 받는 중 가드(`_global.currentUser !== undefined &&`)가 있어야 한다.
   *  2. 보이는 글자가 받는 데이터를 `?? 0` 으로 메운 숫자를 그리는데, 자신·조상에 그 소스를 기다리는
   *     `blur_until_loaded` 가 없다 — 받기 전 「0」이 먼저 보인다.
   */
  /** 받는 중에 보여도 되는 노드 — `파일#노드` → 사유 */
  const EXPOSE_ALLOW = new Map<string, string>([]);
  /** 이 패키지 밖(호스트 화면)에서 받는 데이터소스 id — 확장 주입 파일·외부 베이스를 잇는 레이아웃이 읽는다 */
  const HOST_IDS: string[] = ['user', 'current_user'];
  /** 이 패키지 밖에서 받는 `_global` 키 */
  const HOST_GLOBALS: string[] = ['currentUser'];
  const ROOTS: string[] = ['resources/layouts', 'resources/extensions'];

  const exposeFiles: string[] = [];
  const collect = (dir: string) => {
    if (!fs.existsSync(dir)) return;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      if (e.isDirectory()) { if (e.name !== '__tests__' && e.name !== 'node_modules') collect(full); }
      else if (e.name.endsWith('.json')) exposeFiles.push(full);
    }
  };
  ROOTS.forEach((r) => collect(path.join(PKG_ROOT, r)));
  const relOf = (f: string) => path.relative(PKG_ROOT, f).split(path.sep).join('/');
  const layouts = new Map<string, Node>(exposeFiles.map((f) => [relOf(f), JSON.parse(fs.readFileSync(f, 'utf8'))]));

  /** 조각 경로(예: partials 아래 조각 파일)를 패키지 기준 경로로 바꾼다 */
  const resolvePartial = (from: string, ref: string): string | null => {
    for (const root of ROOTS) {
      const cand = `${root}/${ref}`.replace(/\/+/g, '/');
      if (layouts.has(cand)) return cand;
    }
    const sibling = path.posix.join(path.posix.dirname(from), ref);
    return layouts.has(sibling) ? sibling : null;
  };
  /** extends 베이스 이름을 패키지 기준 경로로 바꾼다 */
  const resolveBase = (name: string): string | null => {
    for (const root of ROOTS) for (const cand of [`${root}/${name}.json`, `${root}/admin/${name}.json`]) if (layouts.has(cand)) return cand;
    return null;
  };

  // 조각 → 그 조각을 넣는 파일(모달로 넣은 것은 따로 표시 — 열기 전에는 그려지지 않는다)
  const includers = new Map<string, Set<string>>();
  const modalPartials = new Set<string>();
  for (const [file, json] of layouts) {
    const visitRefs = (node: any, inModals: boolean) => {
      if (!node || typeof node !== 'object') return;
      if (Array.isArray(node)) return node.forEach((c) => visitRefs(c, inModals));
      if (typeof node.partial === 'string') {
        const target = resolvePartial(file, node.partial);
        if (target) {
          if (!includers.has(target)) includers.set(target, new Set());
          includers.get(target)!.add(file);
          if (inModals) modalPartials.add(target);
        }
      }
      for (const [k, v] of Object.entries(node)) if (k !== 'data_sources') visitRefs(v, inModals || k === 'modals');
    };
    visitRefs(json, false);
  }

  type Scope = { ids: Set<string>; globals: Set<string>; locals: Set<string>; computed: Map<string, string> };
  /** `_global.x`·`_local.x` 를 채우는 데이터소스 id — 흐림이 그 소스를 기다리는지 볼 때 쓴다 */
  const ORIGIN = new Map<string, Set<string>>();
  /** 이름 또는 그 이름을 채우는 소스가 집합에 있는지 — `_local.form` 은 그것을 채우는 소스가 오면 함께 온다 */
  const within = (set: Set<string>, r: string) => set.has(r) || [...(ORIGIN.get(r) ?? [])].some((id) => set.has(id));
  const ownScope = (json: Node): Scope => {
    const s: Scope = { ids: new Set(), globals: new Set(), locals: new Set(), computed: new Map() };
    // 계산값(_computed.x)은 받는 데이터로 만든 식이다 — 조건에서 쓰이면 식을 펼쳐 같은 규칙으로 본다
    for (const holder of findAll(json, (n) => n.computed && typeof n.computed === 'object' && !Array.isArray(n.computed))) {
      for (const [k, v] of Object.entries(holder.computed)) if (typeof v === 'string' && v.trim().startsWith('{{')) s.computed.set(k, v.trim().slice(2, -2));
    }
    for (const ds of findAll(json, (n) => Array.isArray(n.data_sources)).flatMap((n) => n.data_sources)) {
      if (!ds?.id || ds.loading_strategy === 'blocking') continue;
      s.ids.add(ds.id);
      const ig = ds.initGlobal ?? ds.init_global;
      const keep = (kind: 'globals' | 'locals', key: string) => {
        s[kind].add(key);
        const ref = `${kind === 'globals' ? '_global' : '_local'}.${key}`;
        if (!ORIGIN.has(ref)) ORIGIN.set(ref, new Set());
        ORIGIN.get(ref)!.add(ds.id);
      };
      if (typeof ig === 'string' && ig) keep('globals', ig.split('.')[0]);
      else if (ig && typeof ig === 'object') Object.keys(ig).forEach((k) => keep('globals', k.split('.')[0]));
      const il = ds.initLocal ?? ds.init_local;
      if (typeof il === 'string' && il) keep('locals', il.split('.')[0]);
      else if (il && typeof il === 'object') Object.keys(il).filter((k) => !k.startsWith('_')).forEach((k) => keep('locals', k.split('.')[0]));
    }
    return s;
  };
  const merge = (a: Scope, b: Scope) => {
    b.ids.forEach((x) => a.ids.add(x)); b.globals.forEach((x) => a.globals.add(x)); b.locals.forEach((x) => a.locals.add(x));
    b.computed.forEach((v, k) => { if (!a.computed.has(k)) a.computed.set(k, v); });
  };
  const cloneScope = (s: Scope): Scope => ({ ids: new Set(s.ids), globals: new Set(s.globals), locals: new Set(s.locals), computed: new Map(s.computed) });
  /** 식 안의 `_computed.x` 를 그 계산식으로 바꾼다(계산값이 계산값을 읽는 경우까지 세 번) */
  const expandComputed = (expr: string, s: Scope): string => {
    let out = expr;
    for (let i = 0; i < 3; i++) {
      const next = out.replace(/(\$|_)computed\??\.(\w+)\b/g, (m, _p, k) => (s.computed.has(k) ? `(${s.computed.get(k)})` : m));
      if (next === out) break;
      out = next;
    }
    return out;
  };
  const scopeCache = new Map<string, Scope>();
  /**
   * 파일이 그려질 때 받는 중인 데이터 — 자기 선언 + extends 베이스 + 그 조각을 넣는 모든 파일의 선언.
   * 넣는 파일을 찾지 못한 조각은 패키지 전체 선언을 쓴다(넓게 잡아 놓치지 않는다).
   */
  const scopeOf = (file: string, seen = new Set<string>()): Scope => {
    if (scopeCache.has(file)) return scopeCache.get(file)!;
    const s = ownScope(layouts.get(file) as Node);
    HOST_GLOBALS.forEach((g) => s.globals.add(g));
    if (seen.has(file)) return s;
    seen.add(file);
    const json = layouts.get(file) as Node;
    if (typeof json.extends === 'string') {
      const base = resolveBase(json.extends);
      if (base) merge(s, scopeOf(base, seen));
      else HOST_IDS.forEach((x) => s.ids.add(x));
    }
    if (/(^|\/)extensions\//.test(file)) {
      HOST_IDS.forEach((x) => s.ids.add(x));
      for (const [f, j] of layouts) if (/(^|\/)extensions\//.test(f)) merge(s, ownScope(j));
    }
    const inc = includers.get(file);
    if (inc && inc.size) inc.forEach((f) => merge(s, scopeOf(f, seen)));
    else if (/\/partials\//.test(file)) for (const j of layouts.values()) merge(s, ownScope(j));
    scopeCache.set(file, s);
    return s;
  };

  const esc = (x: string) => x.replace(/[$.*+?^(){}|[\]\\]/g, '\\$&');
  /** 식이 받는 데이터를 읽는지 — 읽는 이름 목록 */
  const loadingRefs = (raw: string, s: Scope): string[] => {
    // 따옴표 안 글자('schedules' 같은 탭 이름)는 소스 이름이 아니다 — $t: 키·{{ }} 밖 글자도 빼고 식만 본다
    const expr = (raw.match(/\{\{[\s\S]*?\}\}/g)?.join(' ') ?? raw).replace(/'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"/g, "''");
    const hits: string[] = [];
    s.ids.forEach((id) => { if (new RegExp(`(^|[^\\w.$])${esc(id)}\\b`).test(expr)) hits.push(id); });
    s.globals.forEach((g) => { if (new RegExp(`_global\\??\\.${esc(g)}\\b`).test(expr)) hits.push(`_global.${g}`); });
    s.locals.forEach((l) => { if (new RegExp(`_local\\??\\.${esc(l)}\\b`).test(expr)) hits.push(`_local.${l}`); });
    return hits;
  };
  /** 받는 중 컨텍스트에서 조건을 엔진으로 평가한다 — 받는 데이터는 모두 undefined, 화면 상태는 비어 있다 */
  const trueWhileLoadingIf = (cond: string): boolean => {
    const ctx: any = { _global: {}, _local: {}, _computed: {}, query: {}, route: {}, $parent: { _local: {}, _global: {} } };
    try { return evaluateIfCondition(cond, ctx, dataBindingEngine); } catch { return false; }
  };
  /** 식을 최상위 연산자(`||` 또는 `&&`)로 나눈다 — 괄호·대괄호·중괄호·따옴표 안은 나누지 않는다 */
  const splitTop = (expr: string, op: '||' | '&&'): string[] => {
    const parts: string[] = [];
    let depth = 0, quote = '', cur = '';
    for (let i = 0; i < expr.length; i++) {
      const ch = expr[i];
      if (quote) { cur += ch; if (ch === quote && expr[i - 1] !== '\\') quote = ''; continue; }
      if (ch === '"' || ch === "'" || ch === '`') { quote = ch; cur += ch; continue; }
      if ('([{'.includes(ch)) depth++;
      if (')]}'.includes(ch)) depth--;
      if (depth === 0 && expr.startsWith(op, i)) { parts.push(cur.trim()); cur = ''; i += op.length - 1; continue; }
      cur += ch;
    }
    parts.push(cur.trim());
    return parts.filter(Boolean);
  };
  /** 최상위 삼항이 있는지 */
  const hasTopTernary = (expr: string): boolean => {
    let depth = 0, quote = '';
    for (let i = 0; i < expr.length; i++) {
      const ch = expr[i];
      if (quote) { if (ch === quote && expr[i - 1] !== '\\') quote = ''; continue; }
      if (ch === '"' || ch === "'" || ch === '`') { quote = ch; continue; }
      if ('([{'.includes(ch)) depth++;
      if (')]}'.includes(ch)) depth--;
      if (depth === 0 && ch === '?' && expr[i + 1] !== '.' && expr[i + 1] !== '?' && expr[i - 1] !== '?') return true;
    }
    return false;
  };
  const evalExpr = (expr: string) => trueWhileLoadingIf(`{{${expr}}}`);
  /** 화면이 아닌 키 — 동작(클릭·응답 처리)·생애주기·계산값·데이터소스·모달의 조건은 그리기와 무관하다 */
  const SKIP_KEYS = new Set(['data_sources', 'modals', 'if', 'text', 'actions', 'lifecycle', 'init_actions', 'onSuccess', 'onError', 'computed', 'named_actions', 'errorHandling', 'defines']);
  /** 주소 상태(query·route)를 읽는 식 — 그 주소로 들어오면 참이 될 수 있다 */
  const URL_STATE = /(^|[^\w.$])(query|route)\??\.\w/;
  /**
   * 조건을 마디(disjunct → conjunct)로 나눠, 받는 데이터 하나만 기대는 마디가 받는 중에 거짓이면 그 데이터는
   * 「그 마디가 기다린다」고 본다. 기다리지 않는 받는 데이터가 받는 중에 참이 되는 마디가 있으면 —
   * 다른 데이터가 먼저 와 나머지 마디가 참이 된 순간 노드가 보인다(제보된 쿠키 배너 결함의 꼴).
   *
   * @returns 모든 갈래에서 기다리는 데이터 목록, 받는 중에 참이 되는 마디 목록
   */
  /** 식 전체를 감싼 괄호를 벗긴다 */
  const unwrapParens = (expr: string): string => {
    let e = expr.trim();
    for (;;) {
      if (!e.startsWith('(') || !e.endsWith(')')) return e;
      let depth = 0, quote = '', closesAtEnd = true;
      for (let i = 0; i < e.length; i++) {
        const ch = e[i];
        if (quote) { if (ch === quote && e[i - 1] !== '\\') quote = ''; continue; }
        if (ch === '"' || ch === "'" || ch === '`') { quote = ch; continue; }
        if (ch === '(') depth++;
        if (ch === ')') { depth--; if (depth === 0 && i < e.length - 1) { closesAtEnd = false; break; } }
      }
      if (!closesAtEnd) return e;
      e = e.slice(1, -1).trim();
    }
  };
  const analyzeCond = (cond: string, scope: Scope, ready: Set<string>): { required: Set<string>; partial: string[] } => {
    const body = unwrapParens(cond.trim().replace(/^\{\{/, '').replace(/\}\}$/, ''));
    if (hasTopTernary(body)) return { required: new Set(), partial: [] };
    const disjuncts = splitTop(body, '||');
    let required: Set<string> | null = null;
    const partial: string[] = [];
    for (const d of disjuncts) {
      const conjuncts = splitTop(unwrapParens(d), '&&').map(unwrapParens);
      const waits = new Set<string>();
      for (const c of conjuncts) {
        const refs = loadingRefs(c, scope);
        if (refs.length === 1 && !evalExpr(c)) waits.add(refs[0]);
        // 여러 소스의 존재를 함께 확인하는 마디(`(a ?? b) != null`)는 그 소스들을 모두 기다린다
        else if (refs.length > 1 && !evalExpr(c) && /(!==?\s*(undefined|null))|(!=\s*null)/.test(c)) refs.forEach((r) => waits.add(r));
      }
      // 받는 데이터 없이 거짓인 마디가 있으면 이 갈래는 받는 중과 무관하게 닫혀 있다
      const closed = conjuncts.some((c) => !loadingRefs(c, scope).length && !URL_STATE.test(c) && !evalExpr(c));
      if (!closed) {
        for (const c of conjuncts) {
          const refs = loadingRefs(c, scope);
          if (!refs.length || /===\s*undefined/.test(c)) continue;
          const nested = !hasTopTernary(c) && (splitTop(c, '||').length > 1 || splitTop(c, '&&').length > 1);
          if (nested) {
            // 마디 안에 다시 갈래·마디가 있으면 안쪽까지 같은 규칙으로 본다
            const sub = analyzeCond(`{{${c}}}`, scope, new Set([...ready, ...waits]));
            partial.push(...sub.partial);
            if (!evalExpr(c)) sub.required.forEach((r) => waits.add(r));
            continue;
          }
          const pend = refs.filter((r) => !within(ready, r) && !within(waits, r) && r !== '_global.currentUser');
          if (pend.length && evalExpr(c)) partial.push(`${pend.join(',')} ⇐ ${c}`);
        }
      }
      required = required === null ? waits : new Set([...required].filter((x) => waits.has(x)));
    }
    return { required: required ?? new Set(), partial };
  };
  /** 노드의 흐림이 소스 id 를 기다리는지 */
  const blurWaits = (node: Node, id: string): boolean => {
    const b = node.blur_until_loaded;
    if (!b) return false;
    if (b === true || typeof b === 'string') return true;
    if (typeof b === 'object' && b.enabled) {
      if (!b.data_sources) return true;
      const list = Array.isArray(b.data_sources) ? b.data_sources : [b.data_sources];
      return [...(ORIGIN.get(id) ?? [])].some((o) => list.includes(o)) || list.includes(id.replace(/^_(global|local)\./, '')) || list.includes(id);
    }
    return false;
  };

  /**
   * @scenario load_state=loading
   * @effects loading_hides_post_load_only_state, loading_blurs_zero_counts
   */
  it('받는 중에 받은 뒤에만 뜻이 있는 요소가 보이지 않고 숫자는 흐려진다', () => {
    const offenders: string[] = [];
    let checked = 0;
    const visit = (node: any, file: string, scope: Scope, blurChain: Node[], depth: number, ready: Set<string>) => {
      if (!node || typeof node !== 'object' || depth > 40) return;
      if (Array.isArray(node)) return node.forEach((c) => visit(c, file, scope, blurChain, depth, ready));
      if (node.iteration) return; // 반복 항목은 받기 전에 그려지지 않는다
      if (node.name === 'Modal') return; // 모달은 열기 전에 그려지지 않는다
      const label = `${file}#${node.id ?? node.name ?? '?'}`;
      let nextReady = ready;
      const blurChainHere = node.blur_until_loaded ? [...blurChain, node] : blurChain;
      if (typeof node.if === 'string' && node.if.trim().startsWith('{{')) {
        const cond = expandComputed(node.if, scope);
        const refs = loadingRefs(cond, scope);
        if (!trueWhileLoadingIf(cond)) {
          // 받는 데이터에 기대지 않는 거짓 조건 — 숨김(탭·펼침처럼 화면 상태로 여는 영역 포함, 열기 전에는 그려지지 않는다).
          // 받는 데이터에 기대면 그 데이터가 온 뒤 보이므로, 조건이 기다리는 데이터는 「받은 것」으로 치고 자손을 계속 본다.
          if (!refs.length) {
            // 주소(query·route)로 정해지는 갈래(`?tab=privacy` 탭 등)는 그 주소로 바로 들어오면 첫 화면이다 — 계속 본다
            if (URL_STATE.test(cond)) {
              for (const [k, v] of Object.entries(node)) if (!SKIP_KEYS.has(k)) visit(v, file, scope, node.blur_until_loaded ? [...blurChain, node] : blurChain, depth + 1, ready);
              if (typeof node.partial === 'string') { const t = resolvePartial(file, node.partial); if (t) { const s = cloneScope(scope); merge(s, ownScope(layouts.get(t) as Node)); visit((layouts.get(t) as Node).components ?? layouts.get(t), t, s, blurChain, depth + 1, ready); } }
            }
            return;
          }
          // 받는 동안 흐림이 기다리는 데이터는 흐린 채 보인다 — 계획 목표(그리지 않거나 흐린다)를 지킨 것으로 본다
          const blurred = refs.filter((r) => blurChainHere.some((n) => blurWaits(n, r)));
          const { required, partial } = analyzeCond(cond, scope, new Set([...ready, ...blurred]));
          if (partial.length) {
            checked += 1;
            if (!EXPOSE_ALLOW.has(label)) offenders.push(`[부분도착] ${label} {${partial.join(' | ')}} ${node.if}`);
          }
          nextReady = new Set([...ready, ...required]);
        } else {
          const pending = refs.filter((r) => !within(ready, r) && !blurChainHere.some((n) => blurWaits(n, r)));
          // 받는 데이터와 무관한 갈래가 참이면(`탭이 dimensions 가 아님` 등) 받는 중과 상관없이 보이는 노드다
          const body = unwrapParens(cond.trim().replace(/^\{\{/, '').replace(/\}\}$/, ''));
          // 주소 갈래는 그 주소로 들어오면 거짓일 수 있으므로 「무관」이 아니다. 받는 데이터를 읽는 갈래는 주소 마디를 참으로 두고
          // 나머지 마디가 받는 중에 모두 참이면 보인다(`?tab=dimensions&dimension=x` 로 들어온 받는 중).
          const disj = hasTopTernary(body) ? [body] : splitTop(body, '||');
          const independent = disj.some((d) => !loadingRefs(d, scope).length && !URL_STATE.test(d) && evalExpr(d));
          const showsWhilePending = disj.some((d) => {
            if (!pending.some((r) => loadingRefs(d, scope).includes(r))) return false;
            const cs = hasTopTernary(d) ? [d] : splitTop(unwrapParens(d), '&&').map(unwrapParens);
            return cs.every((c) => (URL_STATE.test(c) && !loadingRefs(c, scope).length) || evalExpr(c));
          });
          if (pending.length && !independent && showsWhilePending) {
            checked += 1;
            if (!/===\s*undefined/.test(cond) && !EXPOSE_ALLOW.has(label)) offenders.push(`[노출] ${label} (${pending.join(',')}) ${node.if}`);
          }
        }
      }
      if (typeof node.if === 'string' && /(^|[^!])!\s*_global\??\.currentUser\b/.test(node.if) && !/currentUser\s*[!=]==\s*undefined/.test(node.if)
        && scope.globals.has('currentUser') && !EXPOSE_ALLOW.has(label)) {
        // 회원/비회원 분기 — 조상이 다른 데이터를 받은 뒤 보여도 로그인 사용자는 아직 받는 중일 수 있다.
        // 받는 중(undefined)을 비회원으로 읽지 않도록 `_global.currentUser !== undefined &&` 가드가 있어야 한다.
        checked += 1;
        if (!offenders.some((o) => o.startsWith(`[노출] ${label} `) && o.endsWith(node.if))) offenders.push(`[회원분기] ${label} ${node.if}`);
      }
      const chain = node.blur_until_loaded ? [...blurChain, node] : blurChain;
      // `?? 0` 뿐 아니라 받는 데이터를 `?? []` 로 메운 목록의 개수(`(… ?? []).length`)도 받기 전 「0건」 을 그린다
      if (typeof node.text === 'string' && /\?\?\s*0\b|\?\?\s*\[\]\s*\)\s*\.length\b/.test(expandComputed(node.text, scope))) {
        const refs = loadingRefs(expandComputed(node.text, scope), scope);
        if (refs.length) {
          checked += 1;
          const unblurred = refs.filter((r) => !within(nextReady, r) && !chain.some((n) => blurWaits(n, r)));
          if (unblurred.length && !EXPOSE_ALLOW.has(label)) offenders.push(`[숫자] ${label} ${unblurred.join(',')} ${node.text}`);
        }
      }
      if (typeof node.partial === 'string') {
        // 조각은 넣는 자리의 조상 조건·흐림 아래에서 그려진다 — 그 자리에서 펼친다
        const target = resolvePartial(file, node.partial);
        if (target) {
          const inner = layouts.get(target) as Node;
          const s = cloneScope(scope);
          merge(s, ownScope(inner));
          visit(inner.components ?? inner, target, s, chain, depth + 1, nextReady);
        }
      }
      for (const [k, v] of Object.entries(node)) if (!SKIP_KEYS.has(k)) visit(v, file, scope, chain, depth + 1, nextReady);
    };
    for (const [file, json] of layouts) {
      if (includers.get(file)?.size) continue; // 넣는 파일 쪽에서 펼친다
      visit(json.components ?? json, file, scopeOf(file), [], 0, new Set());
    }
    expect(layouts.size).toBeGreaterThan(0);
    expect(offenders).toEqual([]);
  });
});
