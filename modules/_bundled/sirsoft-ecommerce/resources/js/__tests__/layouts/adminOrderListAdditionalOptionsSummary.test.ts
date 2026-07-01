/**
 * @file adminOrderListAdditionalOptionsSummary.test.ts
 * @description 관리자 주문관리 목록(datagrid) 추가옵션 요약 노출
 *
 * 추가옵션 상품을 고른 주문은 관리자 주문관리 리스트에도 추가옵션이 표시되어야 한다.
 * first_option.additional_options_summary(label + extra_count "외 N건") 를 대표옵션명 아래 표기.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import datagrid from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.json';

const serialize = JSON.stringify(datagrid);

describe('관리자 주문 목록 — 추가옵션 요약 표기', () => {
  it('first_option.additional_options_summary.label 바인딩이 존재한다', () => {
    expect(serialize).toContain('first_option?.additional_options_summary?.label');
  });

  it('"외 N건" 축약(extra_count)을 사용한다 (Q-E2)', () => {
    expect(serialize).toContain('additional_options_summary.extra_count');
    expect(serialize).toContain('외 ');
  });

  it('요약 노드는 additional_options_summary 존재 시에만 렌더(if 가드)된다', () => {
    expect(serialize).toContain('row.first_option?.additional_options_summary');
  });
});
