/**
 * @file mp11ProductNotice.test.tsx
 * @description A9 상품정보제공고시 무한스크롤/토글/드롭다운 레이아웃 회귀 테스트
 *
 * 검증 항목:
 * - 고시 무한스크롤 onSuccess 바인딩 response.data.data (배열) 정정
 * - 공통정보(A10) 패널 동일 정정 (동반)
 * - 고시 목록 인라인 활성 토글 (PATCH toggle-active)
 * - 상품폼 고시 드롭다운 active_only=true 필터
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const layoutsDir = path.resolve(__dirname, '../../../../resources/layouts/admin');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(layoutsDir, relPath), 'utf8'));
}

function collectNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (!Array.isArray(node) && predicate(node)) acc.push(node);
  for (const key of Object.keys(node)) collectNodes(node[key], predicate, acc);
  return acc;
}

const noticePanel = loadJson('partials/admin_ecommerce_product_notice_index/_panel_list.json');
const commonInfoPanel = loadJson('partials/admin_ecommerce_product_common_info_index/_panel_list.json');
const productForm = loadJson('admin_ecommerce_product_form.json');
const extraFeeModal = loadJson('partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template.json');
const couponUsageConditions = loadJson(
  'partials/admin_ecommerce_promotion_coupon_form/_partial_usage_conditions.json',
);

function appendNodes(layout: any) {
  return collectNodes(layout, (n) => n.handler === 'appendDataSource');
}

describe('A9 무한스크롤 바인딩 정정', () => {
  it('고시 패널 appendDataSource 가 response.data.data 를 추가한다', () => {
    const appends = appendNodes(noticePanel);
    expect(appends.length).toBeGreaterThanOrEqual(1);
    expect(appends[0].params.newData).toBe('{{response.data.data}}');
  });

  it('고시 hasMore 가 백엔드 pagination.has_more_pages 를 본다', () => {
    const setStates = collectNodes(
      noticePanel,
      (n) => n.handler === 'setState' && n.params && 'infiniteScroll.hasMore' in n.params,
    );
    expect(setStates.length).toBeGreaterThanOrEqual(1);
    expect(setStates[0].params['infiniteScroll.hasMore']).toContain('pagination?.has_more_pages');
  });

  it('공통정보(A10) 패널도 동일하게 정정된다', () => {
    const appends = appendNodes(commonInfoPanel);
    expect(appends.length).toBeGreaterThanOrEqual(1);
    expect(appends[0].params.newData).toBe('{{response.data.data}}');
  });
});

describe('무한스크롤 로딩 인디케이터 가시성(scrollIntoView) 유지 + 서버 페이지네이션 기반 정지', () => {
  // 무한스크롤 4개 화면. scrollIntoView 는 다음 페이지 로딩 시 로딩 인디케이터를
  // 컨테이너 안에서 보이도록 스크롤하는 의도된 UX 기능 — 반드시 존재해야 한다.
  // 추가 호출 정지는 length>=20 휴리스틱이 아니라 서버 응답 has_more_pages 로 판정한다.
  const infiniteScrollPanels: Array<[string, any]> = [
    ['고시 패널', noticePanel],
    ['공통정보 패널', commonInfoPanel],
    ['배송정책 추가배송비 모달', extraFeeModal],
    ['쿠폰 폼 상품검색', couponUsageConditions],
  ];

  it.each(infiniteScrollPanels)('%s 에 로딩 인디케이터 scrollIntoView 가 존재한다', (_label, panel) => {
    const scrollIntoViews = collectNodes(panel, (n) => n.handler === 'scrollIntoView');
    expect(scrollIntoViews.length).toBeGreaterThanOrEqual(1);
    // 컨테이너 내부 스크롤(scrollContainer 지정) — 브라우저 전체 스크롤 이동 방지
    expect(scrollIntoViews[0].params.scrollContainer).toBeTruthy();
    expect(scrollIntoViews[0].params.block).toBe('end');
  });

  it.each(infiniteScrollPanels)('%s 는 scroll 이벤트 트리거를 유지한다', (_label, panel) => {
    const scrollTriggers = collectNodes(panel, (n) => n.type === 'scroll' && n.handler);
    expect(scrollTriggers.length).toBeGreaterThanOrEqual(1);
  });

  // 고시/공통정보/추가배송비 모달: 스크롤 가드가 length>=20 휴리스틱이 아니라
  // 서버 응답의 has_more_pages 로 더 불러올지 판정한다 (빈 페이지 추가 호출 차단).
  const totalGuardedPanels: Array<[string, any]> = [
    ['고시 패널', noticePanel],
    ['공통정보 패널', commonInfoPanel],
    ['배송정책 추가배송비 모달', extraFeeModal],
  ];

  it.each(totalGuardedPanels)('%s 의 스크롤 가드가 서버 has_more_pages 기반으로 정지한다', (_label, panel) => {
    // 스크롤 가드 switch 는 _global.infiniteScroll.hasMore 로 추가 로딩 여부를 게이트한다.
    const switches = collectNodes(
      panel,
      (n) =>
        n.handler === 'switch' &&
        typeof n.params?.value === 'string' &&
        n.params.value.includes('scrollHeight'),
    );
    expect(switches.length).toBeGreaterThanOrEqual(1);
    expect(switches[0].params.value).toContain('infiniteScroll.hasMore');
    // length>=20 휴리스틱 잔재가 없어야 한다
    expect(switches[0].params.value).not.toContain('>= 20');

    // hasMore 는 서버 응답 pagination.has_more_pages 로 채워져야 한다 (휴리스틱 아님).
    const hasMoreSetters = collectNodes(
      panel,
      (n) =>
        n.handler === 'setState' &&
        typeof n.params?.['infiniteScroll.hasMore'] === 'string',
    );
    expect(hasMoreSetters.length).toBeGreaterThanOrEqual(1);
    expect(hasMoreSetters[0].params['infiniteScroll.hasMore']).toContain('has_more_pages');
  });
});

describe('A9 고시 목록 활성 토글', () => {
  it('Toggle 이 PATCH toggle-active 를 호출한다', () => {
    const toggleCalls = collectNodes(
      noticePanel,
      (n) =>
        n.handler === 'apiCall' &&
        typeof n.target === 'string' &&
        n.target.includes('/product-notice-templates/') &&
        n.target.includes('/toggle-active'),
    );
    expect(toggleCalls.length).toBe(1);
    expect(toggleCalls[0].params.method).toBe('PATCH');
    expect(toggleCalls[0].stopPropagation).toBe(true);
  });

  it('Toggle 은 can_update 권한이 없으면 비활성화된다', () => {
    const toggles = collectNodes(noticePanel, (n) => n.name === 'Toggle');
    expect(toggles.length).toBe(1);
    expect(toggles[0].props.disabled).toContain('abilities?.can_update');
  });
});

describe('A9 상품폼 고시 드롭다운 active_only', () => {
  it('notice_templates 데이터소스에 active_only=true 필터가 적용된다', () => {
    const ds = (productForm.data_sources ?? []).find((d: any) => d.id === 'notice_templates');
    expect(ds).toBeTruthy();
    expect(ds.params?.active_only).toBe(true);
  });
});
