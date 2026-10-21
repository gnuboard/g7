/**
 * @file coupon-download-modal.test.tsx
 * @description U10 — 쿠폰 다운로드 모달 무한스크롤 회귀 테스트 (MP10 §9, 재설계)
 *
 * 검증 항목 (최종 무한스크롤 설계 기준):
 * - last_page 기반 버튼식 페이지네이션 블록(이전/다음/카운터) 미렌더
 * - 스크롤 영역(max-h-96 overflow-y-auto) 유지
 * - onScroll(type:scroll) + conditions 가드(하단 근접 + !loadingMore + current_page<last_page)
 *   로 다음 페이지 증분 로드
 * - 더보기/스크롤 apiCall 이 per_page=8 + page 파라미터 사용(증분 페이지)
 * - 하단 로딩 인디케이터(loadingMore)
 * - 창 상태는 창 고유 전역 이름 공간 `_global.couponDownloadModal` — 페이지 `_local` 초기 상태에는 두지 않는다(7.1.1).
 *   렌더 축(받기 주소·누른 카드만 진행 중·다시 열기 새 목록)은 src/__tests__/layouts/coupon-download-modal-real-structure.test.tsx
 *
 * @scenario-manifest tests/scenarios/coupon-download-modal-real-structure.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

function loadJson(relPath: string): any {
  const raw = fs.readFileSync(path.resolve(baseDir, relPath), 'utf8');
  return JSON.parse(raw);
}

function serialize(node: any): string {
  return JSON.stringify(node);
}

const modal = loadJson('layouts/partials/shop/_modal_coupon_download.json');
const infoSummary = loadJson('layouts/partials/shop/detail/_info_summary.json');
const showLayout = loadJson('layouts/shop/show.json');

describe('U10 — 다운로드 모달 무한스크롤 (버튼식 페이지네이션 제거)', () => {
  const text = serialize(modal);

  // @scenario source=static
  // @effects infinite_scroll_loads_next_page
  it('이전/다음 페이지 i18n 키 참조가 모달에 없다', () => {
    expect(text).not.toContain('shop.coupon_download.prev_page');
    expect(text).not.toContain('shop.coupon_download.next_page');
  });

  // @scenario source=static
  // @effects infinite_scroll_loads_next_page
  it('스크롤 영역(max-h-96 overflow-y-auto)은 유지된다', () => {
    expect(text).toContain('max-h-96');
    expect(text).toContain('overflow-y-auto');
  });

  // @scenario source=static
  // @effects infinite_scroll_loads_next_page
  it('스크롤 그리드에 onScroll(type:scroll) 액션이 있다', () => {
    expect(text).toContain('"type":"scroll"');
  });

  // @scenario source=static
  // @effects infinite_scroll_loads_next_page
  it('무한스크롤 가드(하단 근접 + loadingMore + current_page<last_page)가 있다', () => {
    expect(text).toContain('scrollHeight');
    expect(text).toContain('couponDownloadModal?.loadingMore');
    expect(text).toContain('current_page');
    expect(text).toContain('last_page');
  });

  // @scenario source=static
  // @effects infinite_scroll_loads_next_page
  it('스크롤 증분 로드 apiCall 이 page 증분(current_page+1)을 사용한다', () => {
    expect(text).toContain('per_page=8');
    expect(text).toContain('current_page ?? 1) + 1');
  });

  // @scenario source=static
  // @effects infinite_scroll_loads_next_page
  it('연속 스크롤 중복 호출 방지를 위해 debounce 가 설정되어 있다', () => {
    expect(text).toContain('"debounce":200');
  });

  // @scenario source=static
  // @effects infinite_scroll_loads_next_page
  it('하단 로딩 인디케이터(loadingMore)가 있다', () => {
    expect(text).toContain('couponDownloadModal?.loadingMore');
    expect(text).toContain('animate-spin');
  });

  // @scenario source=static
  // @effects infinite_scroll_loads_next_page
  it('meta description 이 스크롤로 갱신되었다', () => {
    expect(modal.meta.description).toContain('스크롤');
    expect(modal.meta.description).not.toContain('페이지네이션');
  });
});

describe('U10 — 더보기 액션(배지와 같은 목록을 연다)', () => {
  const text = serialize(infoSummary);

  /*
   * 「+N」 배지는 이 상품에 적용되는 쿠폰(productDownloadableCoupons)으로 계산된다.
   * 더보기가 전체 다운로드 쿠폰을 새로 조회하면 이 상품에 적용되지 않는 쿠폰이 섞여
   * 배지 수와 모달 건수가 어긋나므로, 더보기는 배지와 같은 데이터소스를 그대로 넘긴다.
   * (종전 테스트는 별도 조회 설계를 고정하고 있었다 — 그 설계가 배지 불일치의 원인이었다.)
   */
  // @scenario source=static
  // @effects reopen_shows_fresh_list
  it('더보기가 별도 조회 없이 배지와 같은 데이터소스를 모달에 넘긴다', () => {
    expect(text).toContain('"coupons":"{{productDownloadableCoupons.data ?? null}}"');
    expect(text).not.toContain('per_page=8&page=1');
  });

  // @scenario source=static
  // @effects reopen_shows_fresh_list
  it('더보기가 창 이름 공간을 비운 뒤 로딩 상태를 초기화해 다시 쓴다(7.1.1)', () => {
    expect(text).toContain('"couponDownloadModal":null');
    expect(text).toContain('"loading":false');
    expect(text).toContain('"loadingMore":false');
    expect(text).toContain('"downloadingCouponId":null');
  });

  // @scenario source=static
  // @effects reopen_shows_fresh_list
  it('downloadableCouponsPage setState 가 제거되었다', () => {
    expect(text).not.toContain('downloadableCouponsPage');
  });
});

describe('U10 — show.json 초기 상태 정리', () => {
  const text = serialize(showLayout);

  // @scenario source=static
  // @effects window_state_not_in_page_local
  it('downloadableCouponsPage 초기값이 제거되었다', () => {
    expect(text).not.toContain('downloadableCouponsPage');
  });

  // @scenario source=static
  // @effects window_state_not_in_page_local
  it('창 상태(목록·더보기 중·받는 중)를 페이지 _local 에 두지 않는다 — 창 고유 전역 이름 공간으로 옮겼다(7.1.1)', () => {
    expect(text).not.toContain('downloadingCouponId');
    expect(text).not.toContain('downloadingMore');
    expect(text).not.toContain('"downloadableCoupons"');
  });
});
