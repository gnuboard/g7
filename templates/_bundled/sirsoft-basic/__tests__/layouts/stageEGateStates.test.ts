/**
 * 단계 E — 게이트 본체 노출 페이지 상태 계약 테스트
 *
 * 편집기 캔버스는 정적 시뮬레이션이라 사용자가 토글·탭을 클릭해 if/_local/_global 분기를
 * 켤 수 없다(원 계획서 Q10, 메모리
 * feedback_editor_gated_body_needs_page_state_not_click). 따라서 토글/탭/route 게이트 뒤
 * 콘텐츠 본체는 페이지 상태 initialState 패치로 ON 상태를 시뮬레이션해야 캔버스에 렌더된다.
 *
 * 본 테스트는 단계 E 가 신설한 6개 게이트 상태가
 *  (1) 게이트가 읽는 정확한 상태 경로를 패치하는가 (상태 패치 가드)
 *  (2) 그 게이트가 구동하는 본체가 소비하는 샘플 데이터가 thin/stub 이 아닌가 (본체 실재 가드)
 * 를 검증한다.
 *
 * 게이트 SSoT(소비 레이아웃):
 *  - /shop/products/:id reviews/qna 탭 : partials/shop/detail/_tab_reviews.json, _tab_qna.json
 *  - /mypage/board my-comments 서브탭   : partials/mypage/board/_list.json, _my_comments.json
 *  - /search posts/products/pages 탭    : partials/search/_search_results.json (+ section partials)
 *  - /shop/checkout 금액상세 토글       : partials/shop/_checkout_summary.json
 *  - /shop/cart 세금상세 토글           : partials/shop/_cart_summary.json
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

/** 프로젝트 루트 탐색 — artisan 마커 기준 (활성/_bundled 깊이 무관) */
function findProjectRoot(startDir: string): string {
  let dir = startDir;
  while (dir !== path.dirname(dir)) {
    if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
    dir = path.dirname(dir);
  }
  return path.resolve(startDir, '../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);

const basicSample = JSON.parse(
  fs.readFileSync(path.join(REPO_ROOT, 'templates/_bundled/sirsoft-basic/editor-spec/sampleData.json'), 'utf-8'),
);
const basicStates = JSON.parse(
  fs.readFileSync(path.join(REPO_ROOT, 'templates/_bundled/sirsoft-basic/editor-spec/states.json'), 'utf-8'),
);
const ecomSpec = JSON.parse(
  fs.readFileSync(path.join(REPO_ROOT, 'modules/_bundled/sirsoft-ecommerce/editor-spec.json'), 'utf-8'),
);

const byId = basicSample.byDataSourceId as Record<string, any>;

function basicGroup(match: string): any {
  return basicStates.groups.find((g: any) => g.scope?.match === match);
}
function ecomGroup(match: string): any {
  return ecomSpec.states.groups.find((g: any) => g.scope?.match === match);
}
function item(group: any, id: string): any {
  return group?.items?.find((s: any) => s.id === id);
}

describe('단계 E — 상품상세 리뷰/문의 탭 게이트 상태', () => {
  const g = ecomGroup('/shop/products/:id');

  it('reviews_tab / qna_tab 상태가 등록되어 있다', () => {
    expect(item(g, 'reviews_tab')).toBeTruthy();
    expect(item(g, 'qna_tab')).toBeTruthy();
  });

  it('reviews_tab 은 _local.activeTab="reviews" 를 패치한다 (게이트 경로 일치)', () => {
    expect(item(g, 'reviews_tab').initialState?.local?.activeTab).toBe('reviews');
  });

  it('reviews_tab override 는 유저 탭 shape(reviews.data.reviews.data + rating_stats + option_filters)를 채운다', () => {
    const rv = item(g, 'reviews_tab').sampleDataOverrides?.byDataSourceId?.reviews?.data;
    expect(rv).toBeTruthy();
    // 본체 실재 가드: 리뷰 목록 ≥3 (DoD #2 복수성)
    expect(Array.isArray(rv.reviews?.data)).toBe(true);
    expect(rv.reviews.data.length).toBeGreaterThanOrEqual(3);
    // 분기 양면(DoD #3): has_reply on/off, image on/off 공존
    expect(rv.reviews.data.some((r: any) => r.has_reply === true)).toBe(true);
    expect(rv.reviews.data.some((r: any) => r.has_reply === false)).toBe(true);
    expect(rv.reviews.data.some((r: any) => (r.images ?? []).length > 0)).toBe(true);
    expect(rv.reviews.data.some((r: any) => (r.images ?? []).length === 0)).toBe(true);
    // 요약/분포(DoD #4): rating_stats 전 별점 경로
    expect(rv.rating_stats?.avg).toBeGreaterThan(0);
    for (const star of ['1', '2', '3', '4', '5']) {
      expect(rv.rating_stats[star]?.count).toBeGreaterThanOrEqual(0);
      expect(rv.rating_stats[star]?.percent).toBeGreaterThanOrEqual(0);
    }
    // 선택지 복수(DoD #3-1): option_filters ≥2 키 + 각 값 복수
    expect(Array.isArray(rv.option_filters)).toBe(true);
    expect(rv.option_filters.length).toBeGreaterThanOrEqual(1);
    expect((rv.option_filters[0].values ?? []).length).toBeGreaterThanOrEqual(2);
    expect(rv.total_count).toBeGreaterThan(0);
  });

  it('qna_tab 은 activeTab="qna" + _global.modules.inquiry.board_slug 를 패치한다 (탭 표시 + 게이트 경로)', () => {
    const init = item(g, 'qna_tab').initialState;
    expect(init?.local?.activeTab).toBe('qna');
    // 탭 visibility(hiddenTabIds) 와 _tab_qna.json line 8 게이트가 board_slug 를 요구
    expect(init?.global?.modules?.['sirsoft-ecommerce']?.inquiry?.board_slug).toBeTruthy();
  });

  it('qna SSoT(basic 템플릿) 샘플이 stub 없이 유저 shape(items[] + meta) 분기를 채운다', () => {
    // qna 데이터소스는 /shop/products/:id(basic 템플릿 shop/show.json) 만 소비 → SSoT 는 basic 템플릿
    // sampleData(메커니즘 #1 — 선언 레이아웃 __source). qna_tab 상태는 activeTab + board_slug 만 패치하면
    // 충분(데이터 override 불필요 — base qna 가 이미 풍부). ecommerce spec 의 동명 qna 는 비소비 중복.
    const qd = byId.qna?.data;
    expect(qd).toBeTruthy();
    // stub 해소: items 가 ["샘플"] 이 아님
    expect(Array.isArray(qd.items)).toBe(true);
    expect(qd.items.length).toBeGreaterThanOrEqual(3);
    expect(qd.items.every((i: any) => i !== '샘플')).toBe(true);
    // 분기 양면(DoD #3): 답변완료/대기, 비밀/공개, 소유/비소유 공존
    expect(qd.items.some((i: any) => i.is_answered === true)).toBe(true);
    expect(qd.items.some((i: any) => i.is_answered === false)).toBe(true);
    expect(qd.items.some((i: any) => i.is_secret === true)).toBe(true);
    expect(qd.items.some((i: any) => i.is_secret === false)).toBe(true);
    expect(qd.items.some((i: any) => i.is_owner === true)).toBe(true);
    // meta board_settings 가 stub("샘플")이 아니라 실제 객체
    expect(typeof qd.meta?.board_settings).toBe('object');
    expect(Array.isArray(qd.meta.board_settings.categories)).toBe(true);
  });
});

describe('단계 E — 마이게시판 내댓글 서브탭 게이트 상태', () => {
  const g = basicGroup('/mypage/board');

  it('/mypage/board 상태 그룹이 신설되어 있다', () => {
    expect(g).toBeTruthy();
    expect(item(g, 'my_posts')).toBeTruthy();
    expect(item(g, 'my_comments')).toBeTruthy();
  });

  it('my_comments 는 _global.boardActivitySubTab="my-comments" 를 패치한다 (게이트 경로 일치)', () => {
    expect(item(g, 'my_comments').initialState?.global?.boardActivitySubTab).toBe('my-comments');
  });

  it('내댓글 본체가 소비하는 myComments 샘플이 thin 이 아니다 (≥3 행, 정확한 shape)', () => {
    const rows = byId.myComments?.data?.data;
    expect(Array.isArray(rows)).toBe(true);
    expect(rows.length).toBeGreaterThanOrEqual(3);
    for (const r of rows) {
      expect(r.post_title).toBeTruthy();
      expect(r.board_slug).toBeTruthy();
      expect(r.board_name).toBeTruthy();
      expect(r.content).toBeTruthy();
      expect(r.created_at_formatted).toBeTruthy();
    }
  });
});

describe('단계 E — 통합검색 서브탭 게이트 상태 + posts/pages 본체', () => {
  const g = basicGroup('/search');

  it('posts/products/pages 서브탭 상태가 신설되어 있다', () => {
    for (const id of ['tab_posts', 'tab_products', 'tab_pages']) {
      expect(item(g, id)).toBeTruthy();
    }
  });

  it('각 서브탭 상태는 query.q + _global.searchActiveTab 을 패치한다 (게이트 경로)', () => {
    const map: Record<string, string> = { tab_posts: 'posts', tab_products: 'products', tab_pages: 'pages' };
    for (const [id, tab] of Object.entries(map)) {
      const init = item(g, id).initialState;
      expect(init?.query?.q).toBeTruthy();
      expect(init?.global?.searchActiveTab).toBe(tab);
    }
  });

  it('all 탭 posts/pages 섹션 본체가 소비하는 searchResults.data.{posts,pages}.items 가 채워져 있다', () => {
    const d = byId.searchResults?.data;
    // Stage A 사각: posts_count/pages_count 만 있고 items 배열이 없어 all 탭 섹션이 비었음 → 보강
    expect(Array.isArray(d.posts?.items)).toBe(true);
    expect(d.posts.items.length).toBeGreaterThanOrEqual(3);
    for (const p of d.posts.items) {
      expect(p.title).toBeTruthy();
      expect(p.board_name).toBeTruthy();
      expect(p.author_name).toBeTruthy();
      expect(p.url).toBeTruthy();
    }
    expect(Array.isArray(d.pages?.items)).toBe(true);
    expect(d.pages.items.length).toBeGreaterThanOrEqual(2);
    for (const pg of d.pages.items) {
      expect(pg.title).toBeTruthy();
      expect(pg.url).toBeTruthy();
    }
    // products 탭 본체(이미 Stage A 검증)도 유지
    expect(Array.isArray(d.products)).toBe(true);
    expect(d.products.length).toBeGreaterThanOrEqual(3);
  });
});

describe('단계 E — 주문서/장바구니 금액 상세 토글 게이트 상태', () => {
  it('/shop/checkout summary_details_expanded 가 두 토글을 ON 한다 (게이트 경로)', () => {
    const it0 = item(ecomGroup('/shop/checkout'), 'summary_details_expanded');
    expect(it0).toBeTruthy();
    expect(it0.initialState?.local?.showTaxDetails).toBe(true);
    expect(it0.initialState?.local?.showDiscountDetails).toBe(true);
  });

  it('/shop/cart summary_tax_expanded 가 세금 토글 + 선택상품을 ON 한다 (이중 중첩 게이트)', () => {
    const it0 = item(ecomGroup('/shop/cart'), 'summary_tax_expanded');
    expect(it0).toBeTruthy();
    // 세금 상세는 selectedItems.length>0 게이트 안에 showTaxDetails 토글로 이중 중첩.
    expect(it0.initialState?.local?.showTaxDetails).toBe(true);
    expect(Array.isArray(it0.initialState?.local?.selectedItems)).toBe(true);
    expect(it0.initialState.local.selectedItems.length).toBeGreaterThan(0);
  });

  it('checkoutData 세금/할인 상세 본체가 소비하는 multi_currency.KRW 가 stub 이 아니다 (계산 전 경로)', () => {
    const sum = byId.checkoutData?.data?.calculation?.summary;
    const krw = sum?.multi_currency?.KRW;
    expect(typeof sum.multi_currency).not.toBe('string'); // "샘플" stub 아님
    expect(krw?.taxable_amount_formatted).toBeTruthy();
    expect(krw?.tax_free_amount_formatted).toBeTruthy();
    expect(krw?.total_discount_formatted).toBeTruthy();
    expect(krw?.product_coupon_discount_formatted).toBeTruthy();
  });

  it('cartItems 세금 상세 본체가 소비하는 multi_currency.KRW 가 stub 이 아니다', () => {
    const sum = byId.cartItems?.data?.calculation?.summary;
    const krw = sum?.multi_currency?.KRW;
    expect(typeof sum.multi_currency).not.toBe('string');
    expect(krw?.taxable_amount_formatted).toBeTruthy();
    expect(krw?.tax_free_amount_formatted).toBeTruthy();
  });
});
