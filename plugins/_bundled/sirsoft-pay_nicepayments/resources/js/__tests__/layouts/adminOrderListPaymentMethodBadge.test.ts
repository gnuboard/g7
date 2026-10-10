/**
 * @file adminOrderListPaymentMethodBadge.test.ts
 * @description 관리자 주문 목록 테스트 결제 배지 조각이 이커머스 확장점에 자기 배지만 끼우는지 잰다.
 *
 * 종전 조각은 배지 하나를 얹으려고 주문 목록 11개 컬럼 전체를 복사해 덮었고, 결제수단 칸에서 다른 PG 의
 * 테스트 결제 맵까지 읽었다. 이제 컬럼은 이커머스 레이아웃이 단일 출처이고 이 조각은 자기 배지와 자기
 * 데이터 소스만 갖는다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import badge from '../../../extensions/admin_order_list_payment_method_badge.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';

const PLUGIN = 'sirsoft-pay_nicepayments';
const MAP_ID = 'nicepay_test_map';
const OTHER_PG_PATTERN = /kginicis|nhnkcp|tosspayments/;

describe('나이스페이먼츠 — 주문 목록 테스트 결제 배지 조각', () => {
  // @scenario payment_plugin=nicepayments
  // @effects badge_fragment_targets_the_ecommerce_slot_only
  it('이커머스 결제수단 표시 자리에 붙고 컬럼을 복사하지 않는다', () => {
    expect((badge as any).extension_point).toBe('admin_order_list_payment_method_badges');
    expect((badge as any).target_layout).toBeUndefined();
    expect((badge as any).injections).toBeUndefined();
    expect(JSON.stringify(badge)).not.toContain('"columns"');
  });

  // @scenario payment_plugin=nicepayments
  // @effects badge_fragment_reads_only_its_own_test_map
  it('배지는 자기 테스트 결제 맵만 읽고 다른 PG 를 참조하지 않는다', () => {
    const components = (badge as any).components as any[];

    expect(components).toHaveLength(1);
    expect(components[0].if).toBe(`{{${MAP_ID}.data?.[row.order_number] === true}}`);
    expect(components[0].text).toBe(`($t:${PLUGIN}.admin.test_mode_badge)`);
    expect(components[0].props.className).toContain('text-orange-500');
    expect(components[0].props.className).toContain('dark:text-orange-400');
    expect(JSON.stringify(badge)).not.toMatch(OTHER_PG_PATTERN);
  });

  // @scenario payment_plugin=nicepayments
  // @effects badge_fragment_reads_only_its_own_test_map
  it('데이터 소스는 자기 테스트 결제 맵 하나다', () => {
    const sources = (badge as any).data_sources as any[];

    expect(sources.map((source) => source.id)).toEqual([MAP_ID]);
    expect(sources[0].endpoint).toBe(`/api/plugins/${PLUGIN}/admin/orders/test-mode-map`);
    expect(sources[0].loading_strategy).toBe('progressive');
  });

  // @scenario payment_plugin=nicepayments
  // @effects badge_fragment_reads_only_its_own_test_map
  it('배지 문구와 데이터 소스 이름이 ko·en 양쪽에 있다', () => {
    expect((ko as any).admin.test_mode_badge).toBe('테스트 결제');
    expect(typeof (en as any).admin.test_mode_badge).toBe('string');
    expect(typeof (ko as any).editor.data_source[MAP_ID]).toBe('string');
    expect(typeof (en as any).editor.data_source[MAP_ID]).toBe('string');
  });
});
