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
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = process.cwd();

const STATE_SPEC_PATHS = [
  'templates/_bundled/sirsoft-basic/editor-spec/states.json',
  'templates/_bundled/sirsoft-admin_basic/editor-spec/states.json',
  'modules/_bundled/sirsoft-ecommerce/editor-spec.json',
  'modules/_bundled/sirsoft-board/editor-spec.json',
  'modules/_bundled/sirsoft-page/editor-spec.json',
  'plugins/_bundled/sirsoft-pay_nicepayments/editor-spec.json',
  'plugins/_bundled/sirsoft-pay_kginicis/editor-spec.json',
  'plugins/_bundled/sirsoft-pay_nhnkcp/editor-spec.json',
  'plugins/_bundled/sirsoft-verification_kginicis/editor-spec.json',
  'plugins/_bundled/sirsoft-gdpr/editor-spec.json',
];

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
  it('각 scope 는 변종 2개 이상이다 — 같은 scope 그룹은 스펙 간 concat 되므로(matchStateItems) 파일이 아닌 scope 기준으로 합산한다', () => {
    const countByScope = new Map<string, number>();
    for (const specPath of STATE_SPEC_PATHS) {
      const spec = load(specPath);
      const groups: StateGroup[] = (spec.states?.groups ?? spec.groups ?? []) as StateGroup[];
      for (const g of groups) {
        const key = `${g.scope?.kind ?? '?'}|${g.scope?.match ?? '?'}`;
        countByScope.set(key, (countByScope.get(key) ?? 0) + (g.items?.length ?? 0));
      }
    }
    const tooFew = [...countByScope.entries()]
      .filter(([, count]) => count < 2)
      .map(([key]) => key);
    expect(tooFew).toEqual([]);
  });
});
