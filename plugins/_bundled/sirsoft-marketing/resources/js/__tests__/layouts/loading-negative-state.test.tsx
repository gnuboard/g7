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
