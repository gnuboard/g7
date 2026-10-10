/**
 * @file admin-ecommerce-editor-route-state-samples.test.ts
 * @description 쇼핑몰 편집기 화면 상태 표본이 실제 화면 모양을 그리는지
 *
 * 편집기 상태 전수 순회에서 두 상태가 빈 화면을 그렸다.
 *
 *  - 주문 상세 「부분 취소」: 표본 덮어쓰기는 통째 교체인데 주문을 `{is_partially_cancelled: true}` 하나로 덮어,
 *    캔버스가 「주문상품이 없습니다」 와 빈 주문자, 결제 정보로 그려졌다(7.0.11 부터).
 *  - 쿠폰 목록 「화면 채움」: `_global.isPortable` 을 켜서 표를 숨기고 좁은 화면 카드 블록을 열었다. 이 값은 코어도
 *    템플릿도 쓰지 않아 실제 화면에서는 늘 표가 보이고(좁은 폭에서는 DataGrid 가 스스로 쌓인 모양으로 바뀐다), 편집기만
 *    실화면에 없는 분기를 빈 채로 그렸다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const MODULE_ROOT = resolve(__dirname, '../../../..');

type Json = Record<string, any>;

const spec: Json = JSON.parse(readFileSync(join(MODULE_ROOT, 'editor-spec.json'), 'utf8'));

/** 범위와 항목 id 로 상태 항목을 찾는다. */
function stateItem(match: string, id: string): Json | undefined {
  const group = (spec.states?.groups ?? []).find((g: Json) => g.scope?.kind === 'route' && g.scope?.match === match);
  return (group?.items ?? []).find((i: Json) => i.id === id);
}

describe('주문 상세 「부분 취소」 상태 표본', () => {
  /**
   * @scenario target=order_detail_partially_cancelled
   * @effects ecommerce_partially_cancelled_state_keeps_whole_order
   */
  it('기본 주문 표본 전체에 부분 취소 표시를 더한 주문으로 덮는다', () => {
    const item = stateItem('*/admin/ecommerce/orders/:orderNumber', 'partially_cancelled');
    const base = spec.sampleData.byDataSourceId.order.data;
    const order = item?.sampleDataOverrides?.byDataSourceId?.order?.data;

    expect(order?.is_partially_cancelled).toBe(true);
    expect(order?.order_number).toBe(base.order_number);
    expect(Object.keys(order ?? {}).length).toBeGreaterThanOrEqual(Object.keys(base).length);
    expect(order?.options?.length).toBe(base.options.length);
    expect(order?.options?.filter((o: Json) => o.option_status === 'cancelled').length).toBe(1);
    expect(order?.options?.some((o: Json) => o.option_status !== 'cancelled')).toBe(true);
  });
});

describe('쿠폰 목록 「화면 채움」 상태 표본', () => {
  /**
   * @scenario target=coupon_list_screen_filled
   * @effects ecommerce_editor_states_do_not_seed_unset_device_flag
   */
  it('실제 화면이 쓰지 않는 기기 값(isPortable)을 어느 상태도 심지 않는다', () => {
    const seeded = (spec.states?.groups ?? []).flatMap((g: Json) =>
      (g.items ?? [])
        .filter((i: Json) => i.initialState?.global && 'isPortable' in i.initialState.global)
        .map((i: Json) => `${g.scope?.match} :: ${i.id}`),
    );
    expect(seeded).toEqual([]);

    const screenFilled = stateItem('*/admin/ecommerce/promotion-coupons', 'screen_filled');
    expect(screenFilled?.initialState?.global?.issueHistoryCoupon?.id).toBe(1);
  });
});
