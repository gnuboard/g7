/**
 * @file coupon-download-modal.test.tsx
 * @description U10 — 쿠폰 다운로드 모달 무한스크롤 회귀 테스트 (MP10 §9, 재설계)
 *
 * 검증 항목 (최종 무한스크롤 설계 기준):
 * - last_page 기반 버튼식 페이지네이션 블록(이전/다음/카운터) 미렌더
 * - 스크롤 영역(max-h-96 overflow-y-auto) 유지
 * - onScroll(type:scroll) + conditions 가드(하단 근접 + !downloadingMore + current_page<last_page)
 *   로 다음 페이지 증분 로드
 * - 더보기/스크롤 apiCall 이 per_page=8 + page 파라미터 사용(증분 페이지)
 * - 하단 로딩 인디케이터(downloadingMore)
 * - show.json 초기 상태에 downloadingMore/downloadingCouponId 명시, downloadableCouponsPage 제거
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

  it('이전/다음 페이지 i18n 키 참조가 모달에 없다', () => {
    expect(text).not.toContain('shop.coupon_download.prev_page');
    expect(text).not.toContain('shop.coupon_download.next_page');
  });

  it('스크롤 영역(max-h-96 overflow-y-auto)은 유지된다', () => {
    expect(text).toContain('max-h-96');
    expect(text).toContain('overflow-y-auto');
  });

  it('스크롤 그리드에 onScroll(type:scroll) 액션이 있다', () => {
    expect(text).toContain('"type":"scroll"');
  });

  it('무한스크롤 가드(하단 근접 + downloadingMore + current_page<last_page)가 있다', () => {
    expect(text).toContain('scrollHeight');
    expect(text).toContain('downloadingMore');
    expect(text).toContain('current_page');
    expect(text).toContain('last_page');
  });

  it('스크롤 증분 로드 apiCall 이 page 증분(current_page+1)을 사용한다', () => {
    expect(text).toContain('per_page=8');
    expect(text).toContain('current_page ?? 1) + 1');
  });

  it('연속 스크롤 중복 호출 방지를 위해 debounce 가 설정되어 있다', () => {
    expect(text).toContain('"debounce":200');
  });

  it('하단 로딩 인디케이터(downloadingMore)가 있다', () => {
    expect(text).toContain('downloadingMore');
    expect(text).toContain('animate-spin');
  });

  it('meta description 이 스크롤로 갱신되었다', () => {
    expect(modal.meta.description).toContain('스크롤');
    expect(modal.meta.description).not.toContain('페이지네이션');
  });
});

describe('U10 — 더보기 액션(배지와 같은 목록을 연다)', () => {
  const text = serialize(infoSummary);

  // 종전에는 더보기가 전체 다운로드 쿠폰을 **다시 조회**했다(`per_page=8&page=1`). 그러면
  // 「+N」 배지는 *이 상품에 적용되는* 쿠폰 수로 세는데 모달은 전체를 보여 주게 되어, 배지 수와
  // 모달 건수가 어긋난다. 지금은 배지가 세는 것과 같은 데이터소스를 그대로 모달에 넘긴다
  // (CLAUDE.md "같은 화면의 개수 배지와 그 배지가 여는 목록은 같은 스코프의 같은 데이터소스에서").
  it('더보기가 배지와 같은 데이터소스를 모달에 넘긴다 (다시 조회하지 않는다)', () => {
    expect(text).toContain('{{productDownloadableCoupons.data}}');
    expect(text).not.toContain('per_page=8&page=1');
  });

  it('더보기가 downloadingMore 를 초기화한다', () => {
    expect(text).toContain('downloadingMore');
  });

  it('downloadableCouponsPage setState 가 제거되었다', () => {
    expect(text).not.toContain('downloadableCouponsPage');
  });
});

describe('U10 — show.json 초기 상태 정리', () => {
  const text = serialize(showLayout);

  it('downloadableCouponsPage 초기값이 제거되었다', () => {
    expect(text).not.toContain('downloadableCouponsPage');
  });

  it('downloadingCouponId 초기값이 명시되었다', () => {
    expect(text).toContain('downloadingCouponId');
  });

  it('downloadingMore 초기값이 명시되었다', () => {
    expect(text).toContain('downloadingMore');
  });
});
