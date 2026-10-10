/**
 * stateItemHasEffect.test.ts — states 변종이 실제 시뮬레이션 효과를 갖는지 정적 가드
 *
 *
 * 배경: 캔버스 상태 토글(PageStateSwitcher)은 변종 item 의 `initialState` /
 * `sampleDataOverrides` / `formErrors` 중 하나로 시뮬레이션을 건다. 비-default item 이
 * 이 셋을 모두 갖지 않으면 default 와 동일한 baseline 을 렌더해 **토글해도 캔버스가
 * 변하지 않는다**. Chrome MCP 실측으로 6개 변종(order_detail 탭/main-banner/검색/주문완료
 * vbank·dbank/popular)이 이 결함이었음을 확인하고 수단을 교정하거나 미등록 처리했다.
 *
 * 본 테스트는 모든 비-default states item 이 시뮬레이션 수단을 최소 1개 보유함을 가드한다.
 * (default item 은 baseline 그대로가 정상이므로 면제.)
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';

const ROOT = process.cwd();

/**
 * 모집단은 저장소에서 파생한다 — 손으로 적은 목록을 쓰지 않는다.
 *
 * 종전에는 10개 경로를 리터럴로 열거했다. 그 사이 states 를 가진 확장이 19종으로 늘었는데
 * (편집기 상태 전수 보강), 목록에 없는 9종은 죽은 변종을 심어도 이 가드가 보지 못했다.
 * 확장이 하나 늘 때마다 목록을 손으로 고쳐야 하는 가드는 고쳐지지 않는다.
 *
 * 번들 확장의 매니페스트(`editor-spec.json`)를 훑어, `$include.states` 가 있으면 그 조각
 * 파일을, 없으면 매니페스트 자신을 states 소유 파일로 본다(제품이 스펙을 조립하는 방식과 같다).
 */
function discoverStateSpecPaths(): string[] {
  const found: string[] = [];
  for (const base of ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled']) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const manifestRel = `${base}/${ext}/editor-spec.json`;
      const manifestAbs = resolve(ROOT, manifestRel);
      if (!existsSync(manifestAbs)) continue;
      const manifest = JSON.parse(readFileSync(manifestAbs, 'utf8'));
      const includeRel: string | undefined = manifest?.$include?.states;
      const rel = includeRel
        ? join(dirname(manifestRel), includeRel).split('\\').join('/')
        : manifestRel;
      if (!existsSync(resolve(ROOT, rel))) continue;
      const spec = JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));
      if (((spec.states?.groups ?? spec.groups ?? []) as unknown[]).length > 0) found.push(rel);
    }
  }
  return found.sort();
}

const STATE_SPEC_PATHS = discoverStateSpecPaths();

// 모집단이 비면 아래 단언은 조용히 통과한다 — 「위반 0」과 「검사 대상 0」은 다른 명제다.
describe('states 가드 모집단', () => {
  /** @effects state_effect_guard_population_is_derived_from_repository */
  it('번들 확장에서 states 보유 스펙을 파생하며, 비어 있지 않다', () => {
    expect(STATE_SPEC_PATHS.length).toBeGreaterThanOrEqual(10);
    // 리터럴 열거였을 때 빠져 있던 확장이 실제로 모집단에 든다.
    expect(STATE_SPEC_PATHS).toContain('plugins/_bundled/sirsoft-message_bizppurio/editor-spec.json');
    expect(STATE_SPEC_PATHS).toContain('plugins/_bundled/sirsoft-tosspayments/editor-spec.json');
  });
});

interface StateItem {
  id: string;
  default?: boolean;
  initialState?: { local?: unknown; global?: unknown; query?: unknown; route?: unknown };
  sampleDataOverrides?: unknown;
  formErrors?: unknown;
}
interface StateGroup {
  scope?: { kind?: string; match?: string };
  items?: StateItem[];
}

function load(rel: string): { states?: { groups?: StateGroup[] }; groups?: StateGroup[] } {
  return JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));
}

function isNonEmptyObject(v: unknown): boolean {
  return !!v && typeof v === 'object' && !Array.isArray(v) && Object.keys(v as object).length > 0;
}

/** item 이 baseline 과 다른 결과를 만들 수 있는 시뮬레이션 수단을 갖는가. */
function hasSimulationEffect(item: StateItem): boolean {
  const is = item.initialState;
  const hasPatch =
    !!is &&
    (isNonEmptyObject(is.local) ||
      isNonEmptyObject(is.global) ||
      isNonEmptyObject(is.query) ||
      isNonEmptyObject(is.route));
  return hasPatch || isNonEmptyObject(item.sampleDataOverrides) || isNonEmptyObject(item.formErrors);
}

describe.each(STATE_SPEC_PATHS)('states 변종 효과 보유: %s', (specPath) => {
  const spec = load(specPath);
  const groups: StateGroup[] = (spec.states?.groups ?? spec.groups ?? []) as StateGroup[];

  /** @effects scope_with_no_effect_variant_is_rejected */
  it('모든 비-default 변종이 시뮬레이션 수단(initialState/sampleDataOverrides/formErrors)을 보유한다', () => {
    const offenders: string[] = [];
    for (const g of groups) {
      const items = g.items ?? [];
      // 패칭 default 예외: 그룹의 default item 이 patch 를 가지면(관례가 뒤집힌 그룹 —
      // 예: /shop/guest/orders 의 guest 가 currentUser:null 패치, member 는 baseline 유지),
      // 비-default 의 baseline-유지(빈 패치)는 "default patch 를 벗는" 명확한 효과로 인정한다.
      const defaultItem = items.find((it) => it.default === true);
      const defaultHasPatch = !!defaultItem && hasSimulationEffect(defaultItem);
      for (const item of items) {
        if (item.default === true) continue;
        if (!hasSimulationEffect(item) && !defaultHasPatch) {
          offenders.push(`${g.scope?.match ?? '?'} :: ${item.id}`);
        }
      }
    }
    expect(offenders).toEqual([]);
  });

});

describe('states 그룹 변종 수 (scope 합산)', () => {
  // 종전 규칙은 "각 scope 는 변종 2개 이상"이었다. 그 규칙이 막으려던 것은 **고를 것이 하나뿐인
  // 토글**이고, 실제로 PageStateSwitcher 는 항목이 1개 이하면 토글을 숨긴다.
  //
  // 그런데 상태에는 두 쓰임이 있다. ⓐ 갈라 보는 변종(탭·검증 실패·0건 — 토글이 목적) ⓑ 시드
  // (창 본문·폼 입력칸을 채우는 것 자체가 목적 — 고를 대안이 없다). ⓑ 는 캔버스가
  // `activeStateId` 로 시뮬레이션을 걸므로 토글이 숨어도 **시드는 적용된다**
  // (PreviewCanvas 의 activeState 도출은 availableStates.length 와 무관).
  // 목록 행 클릭이 담는 `_global.*` 를 읽는 창은 시드가 없으면 빈 껍데기로 열리므로,
  // 변종 하나를 억지로 더 만드는 것이 오히려 죽은 항목을 낳는다.
  //
  // 그래서 규칙을 "1개짜리 scope 는 그 항목이 시뮬레이션 수단을 가져야 한다"로 바꾼다 —
  // 원래 막으려던 것(효과 없는 죽은 변종)은 그대로 막고, 시드 목적의 단독 그룹만 허용한다.
  /** @effects single_variant_scope_allowed_when_it_carries_simulation_effect */
  it('변종이 하나뿐인 scope 는 그 항목이 시뮬레이션 수단을 보유한다 (시드 목적 그룹) — 같은 scope 그룹은 스펙 간 concat 되므로(matchStateItems) 파일이 아닌 scope 기준으로 합산한다', () => {
    const countByScope = new Map<string, number>();
    const itemsByScope = new Map<string, StateItem[]>();
    for (const specPath of STATE_SPEC_PATHS) {
      const spec = load(specPath);
      const groups: StateGroup[] = (spec.states?.groups ?? spec.groups ?? []) as StateGroup[];
      for (const g of groups) {
        const key = `${g.scope?.kind ?? '?'}|${g.scope?.match ?? '?'}`;
        const items = g.items ?? [];
        countByScope.set(key, (countByScope.get(key) ?? 0) + items.length);
        itemsByScope.set(key, [...(itemsByScope.get(key) ?? []), ...items]);
      }
    }
    // 항목이 아예 없는 scope, 또는 단독 항목이 효과 없는 scope = 캔버스에 아무 일도 하지 않는다.
    const dead = [...countByScope.entries()]
      .filter(([key, count]) => count === 0 || (count === 1 && !hasSimulationEffect(itemsByScope.get(key)![0])))
      .map(([key]) => key);
    expect(dead).toEqual([]);
  });
});
