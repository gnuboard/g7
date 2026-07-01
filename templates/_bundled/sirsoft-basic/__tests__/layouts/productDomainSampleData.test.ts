/**
 * 상품 도메인 편집기 샘플 데이터 계약 테스트
 *
 * 사용자 상품 화면(상세/목록/카테고리/통합검색/찜)은 sirsoft-basic 템플릿
 * 레이아웃이며, 캔버스가 의존하는 데이터소스의 출처(`__source`)가 템플릿이다.
 * 따라서 편집기 샘플은 **템플릿** editor-spec(`editor-spec/sampleData.json`)의
 * `byDataSourceId` 가 SSoT 다(모듈 editor-spec 아님 — 엔드포인트는 이커머스 모듈이지만
 * 데이터소스를 선언한 레이아웃이 템플릿 소유).
 *
 * 실제 Resource shape 대조:
 *  - product            : PublicProductResource
 *  - products/popular/recent/new/wishlist.item.product : ProductListResource (ProductCard 소비)
 *  - reviews            : ProductReviewResource + rating_stats/option_filters/total_count 봉투
 *  - qna                : ProductInquiryService 목록 item shape
 *  - productDownloadableCoupons : 다운로드 가능 쿠폰
 *  - category/categories: PublicCategoryDetailResource / 트리
 *  - searchResults      : 통합검색 (상품 highlight + posts/pages)
 *
 * 바인딩 SSoT: templates/_bundled/sirsoft-basic/layouts/shop/{show,index,category}.json,
 *              search/index.json, mypage/wishlist.json (+ 관련 partials)
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
const SAMPLE_PATH = path.join(
  REPO_ROOT,
  'templates/_bundled/sirsoft-basic/editor-spec/sampleData.json',
);

const sample = JSON.parse(fs.readFileSync(SAMPLE_PATH, 'utf-8'));
const byId = sample.byDataSourceId as Record<string, any>;

/** 스텁 leaf 검출 — DoD #1 (스텁 0) */
function hasStub(node: unknown): boolean {
  if (node === '샘플') return true;
  if (Array.isArray(node)) {
    if (node.length === 1 && node[0] === '샘플') return true;
    return node.some(hasStub);
  }
  if (node && typeof node === 'object') {
    return Object.values(node).some(hasStub);
  }
  return false;
}

describe('상품 도메인 편집기 샘플 — sirsoft-basic 템플릿', () => {
  describe('DoD #1 — 스텁 0', () => {
    for (const id of [
      'product',
      'products',
      'reviews',
      'qna',
      'productDownloadableCoupons',
      'category',
      'categories',
      'wishlist',
      'popularProducts',
      'recentProducts',
      'newProducts',
      'searchResults',
    ]) {
      it(`${id} 하위에 "샘플" 스텁 leaf 가 없다`, () => {
        expect(byId[id]).toBeTruthy();
        expect(hasStub(byId[id])).toBe(false);
      });
    }
  });

  describe('product — PublicProductResource 상세 바인딩', () => {
    const d = byId.product?.data;

    it('핵심 표시 경로(이름/가격/할인/통화/상태/브랜드)를 채운다', () => {
      expect(d.name_localized).toBeTruthy();
      expect(d.selling_price_formatted).toBeTruthy();
      expect(d.list_price_formatted).toBeTruthy();
      expect(d.discount_rate).toBeGreaterThan(0); // 할인 분기 ON
      expect(d.multi_currency_selling_price?.KRW?.formatted).toBeTruthy();
      expect(d.multi_currency_list_price?.KRW?.formatted).toBeTruthy();
      expect(d.sales_status).toBe('on_sale');
      expect(d.brand_name).toBeTruthy();
    });

    it('이미지 갤러리(ProductImageViewer)가 download_url 을 가진 3건 이상', () => {
      expect(Array.isArray(d.images)).toBe(true);
      expect(d.images.length).toBeGreaterThanOrEqual(3);
      for (const img of d.images) expect(img.download_url).toBeTruthy();
      expect(d.images.some((i: any) => i.is_thumbnail)).toBe(true);
    });

    it('옵션 분기(has_options) ON — option_groups + options 복수', () => {
      expect(d.has_options).toBe(true);
      expect(d.option_groups.length).toBeGreaterThanOrEqual(2);
      for (const g of d.option_groups) {
        expect(g.name_localized).toBeTruthy();
        expect(Array.isArray(g.values_localized)).toBe(true);
        expect(g.values_localized.length).toBeGreaterThan(0);
      }
      expect(d.options.length).toBeGreaterThanOrEqual(3);
      for (const o of d.options) {
        expect(o.option_name_localized).toBeTruthy();
        expect(o.selling_price_formatted).toBeTruthy();
      }
      // 분기 양면: 기본 옵션 + 안전재고 미만 옵션 공존
      expect(d.options.some((o: any) => o.is_default)).toBe(true);
      expect(d.options.some((o: any) => o.is_below_safe_stock)).toBe(true);
    });

    it('추가옵션/라벨/배송정책/고시/공통정보 등 분기 영역을 모두 채운다', () => {
      expect(d.additional_options.length).toBeGreaterThanOrEqual(1);
      expect(d.labels.length).toBeGreaterThanOrEqual(1);
      for (const l of d.labels) {
        expect(l.name).toBeTruthy();
        expect(l.color).toBeTruthy();
      }
      expect(d.shipping_policy?.name).toBeTruthy();
      expect(d.shipping_policy?.fee_summary).toBeTruthy();
      expect(d.shipping_policy?.free_threshold_formatted).toBeTruthy();
      expect(d.notice?.template_name).toBeTruthy();
      expect(d.notice?.values.length).toBeGreaterThanOrEqual(3);
      for (const v of d.notice.values) {
        expect(v.label).toBeTruthy();
        expect(v.value).toBeTruthy();
      }
      expect(d.common_info?.content).toBeTruthy();
      expect(['html', 'text']).toContain(d.common_info?.content_mode);
    });
  });

  describe('reviews — 봉투 + ProductReviewResource', () => {
    const r = byId.reviews?.data;

    it('reviews.reviews.data 가 3건 이상이며 바인딩 경로를 채운다', () => {
      expect(r.reviews.data.length).toBeGreaterThanOrEqual(3);
      for (const rv of r.reviews.data) {
        expect(rv.user?.name).toBeTruthy();
        expect(rv.rating).toBeGreaterThanOrEqual(1);
        expect(rv.content).toBeTruthy();
        expect(rv.created_at).toBeTruthy();
        expect(typeof rv.has_reply).toBe('boolean');
        expect(Array.isArray(rv.images)).toBe(true);
      }
      expect(r.reviews.meta?.current_page).toBeTypeOf('number');
      expect(r.reviews.meta?.last_page).toBeTypeOf('number');
    });

    it('분기 양면 — 답변완료(has_reply)+사진리뷰(image_count>0) / 미답변+사진없음 공존', () => {
      expect(r.reviews.data.some((rv: any) => rv.has_reply && rv.image_count > 0)).toBe(true);
      expect(r.reviews.data.some((rv: any) => !rv.has_reply && (rv.image_count ?? 0) === 0)).toBe(true);
    });

    it('rating_stats 전 별점(1~5) + avg + total_count 가 채워진다', () => {
      for (const k of ['1', '2', '3', '4', '5']) {
        expect(r.rating_stats[k]).toBeTruthy();
        expect(r.rating_stats[k].count).toBeTypeOf('number');
        expect(r.rating_stats[k].percent).toBeTypeOf('number');
      }
      expect(r.rating_stats.avg).toBeGreaterThan(0);
      expect(r.total_count).toBeGreaterThan(0);
    });

    it('option_filters 가 복수 선택지를 제공한다', () => {
      expect(r.option_filters.length).toBeGreaterThanOrEqual(1);
      for (const f of r.option_filters) {
        expect(f.key).toBeTruthy();
        expect(f.values.length).toBeGreaterThan(0);
        for (const v of f.values) {
          expect(v.value).toBeTruthy();
          expect(v.count).toBeTypeOf('number');
        }
      }
    });
  });

  describe('qna — ProductInquiryService 목록 item', () => {
    const q = byId.qna?.data;

    it('items 3건 이상 + 분기(비밀글/공개, 답변완료/미답변, 내글/타인글) 공존', () => {
      expect(q.items.length).toBeGreaterThanOrEqual(3);
      expect(q.items.some((i: any) => i.is_secret)).toBe(true);
      expect(q.items.some((i: any) => !i.is_secret)).toBe(true);
      expect(q.items.some((i: any) => i.is_answered)).toBe(true);
      expect(q.items.some((i: any) => !i.is_answered)).toBe(true);
      expect(q.items.some((i: any) => i.is_owner)).toBe(true);
      // 답변완료 항목은 reply.content 를 갖는다
      const answered = q.items.find((i: any) => i.is_answered);
      expect(answered.reply?.content).toBeTruthy();
    });

    it('board_settings 카테고리 선택지 복수 + abilities/meta 채움', () => {
      expect(q.meta.board_settings.categories.length).toBeGreaterThanOrEqual(2);
      expect(q.meta.inquiry_available).toBe(true);
      expect(q.meta.abilities).toBeTruthy();
      expect(q.meta.total).toBeTypeOf('number');
    });
  });

  describe('productDownloadableCoupons — 다운로드 쿠폰', () => {
    const c = byId.productDownloadableCoupons?.data?.data;

    it('쿠폰 3건 이상 + 분기(다운로드함/안함) 공존 + 혜택 표기', () => {
      expect(c.length).toBeGreaterThanOrEqual(3);
      expect(c.some((x: any) => x.is_downloaded)).toBe(true);
      expect(c.some((x: any) => !x.is_downloaded)).toBe(true);
      for (const x of c) {
        expect(x.coupon_id).toBeTypeOf('number');
        expect(x.localized_name).toBeTruthy();
        expect(x.benefit_formatted).toBeTruthy();
      }
    });
  });

  describe('products / popularProducts / wishlist — ProductCard 카드 shape', () => {
    function assertCard(p: any) {
      expect(p.name_localized).toBeTruthy();
      expect(p.thumbnail_url).toBeTruthy();
      expect(p.selling_price_formatted).toBeTruthy();
      expect(p.list_price_formatted).toBeTruthy();
      expect(p.multi_currency_selling_price?.KRW?.formatted).toBeTruthy();
      expect(p.sales_status).toBeTruthy();
      expect(p.sales_status_label).toBeTruthy();
      expect(p.rating_avg).toBeTypeOf('number');
      expect(p.review_count).toBeTypeOf('number');
    }

    it('products 목록 3건 이상 + pagination + 분기(할인/정가, 라벨유무, 판매중/품절) 공존', () => {
      const list = byId.products.data.data;
      expect(list.length).toBeGreaterThanOrEqual(3);
      list.forEach(assertCard);
      expect(byId.products.data.pagination?.last_page).toBeGreaterThan(1);
      expect(list.some((p: any) => p.discount_rate > 0)).toBe(true);
      expect(list.some((p: any) => p.discount_rate === 0)).toBe(true);
      expect(list.some((p: any) => (p.labels ?? []).length > 0)).toBe(true);
      expect(list.some((p: any) => p.sales_status === 'sold_out')).toBe(true);
      expect(list.some((p: any) => p.sales_status === 'on_sale')).toBe(true);
    });

    it('popularProducts 가 카드 shape 3건 이상', () => {
      expect(byId.popularProducts.data.length).toBeGreaterThanOrEqual(3);
      byId.popularProducts.data.forEach(assertCard);
    });

    it('wishlist 항목 3건 이상 + item.product 가 카드 shape', () => {
      const items = byId.wishlist.data.data;
      expect(items.length).toBeGreaterThanOrEqual(3);
      for (const it of items) {
        expect(it.id).toBeTypeOf('number');
        assertCard(it.product);
      }
      expect(byId.wishlist.data.pagination?.total).toBeGreaterThan(0);
    });
  });

  describe('category / categories — 카테고리 트리/상세', () => {
    it('category 상세가 breadcrumb + children(3+) + 배너 이미지를 채운다', () => {
      const c = byId.category.data;
      expect(c.name_localized).toBeTruthy();
      expect(c.breadcrumb.length).toBeGreaterThanOrEqual(2);
      expect(c.children.length).toBeGreaterThanOrEqual(3);
      for (const ch of c.children) {
        expect(ch.name_localized).toBeTruthy();
        expect(ch.slug).toBeTruthy();
      }
      expect(c.images?.[0]?.download_url).toBeTruthy();
    });

    it('categories 트리가 3+ 루트 + 다단계 children(자식의 자식)을 가진다', () => {
      const tree = byId.categories.data;
      expect(tree.length).toBeGreaterThanOrEqual(3);
      const root = tree.find((c: any) => (c.children ?? []).length > 0);
      expect(root).toBeTruthy();
      const lvl2 = root.children.find((c: any) => (c.children ?? []).length > 0);
      expect(lvl2).toBeTruthy(); // 3레벨 이상 존재
      for (const c of tree) {
        expect(c.name_localized).toBeTruthy();
        expect(c.slug).toBeTruthy();
      }
    });
  });

  describe('searchResults — 통합검색 (상품 highlight + posts/pages)', () => {
    const s = byId.searchResults.data;

    it('상품 결과 3건 + highlight + 타입별 count', () => {
      expect(s.products.length).toBeGreaterThanOrEqual(3);
      expect(s.products.some((p: any) => /<mark>/.test(p.name_highlighted ?? ''))).toBe(true);
      expect(s.products_count).toBeGreaterThan(0);
      expect(s.posts_count).toBeGreaterThan(0);
      expect(s.total).toBeGreaterThan(0);
    });

    it('posts/pages 결과도 채워져 통합검색 탭이 비지 않는다', () => {
      expect(s.posts.items.length).toBeGreaterThanOrEqual(1);
      for (const p of s.posts.items) {
        expect(p.board_name).toBeTruthy();
        expect(p.title).toBeTruthy();
        expect(p.url).toBeTruthy();
      }
      expect(s.pages.items.length).toBeGreaterThanOrEqual(1);
    });

    it('/search with_results 상태 키워드(query.q)가 샘플 결과 키워드와 일치한다', () => {
      // Chrome MCP 실측(2026-06-03): with_results.query.q 가 "샘플"이라 검색결과 화면 헤더에
      // 비현실적 "샘플" 키워드가 노출됐다. searchResults.keyword(티셔츠)와 일치시켜 일관성 확보.
      const statesPath = path.join(
        REPO_ROOT,
        'templates/_bundled/sirsoft-basic/editor-spec/states.json',
      );
      const states = JSON.parse(fs.readFileSync(statesPath, 'utf-8'));
      const grp = states.groups.find((g: any) => g.scope?.match === '/search');
      const wr = grp.items.find((i: any) => i.id === 'with_results');
      expect(wr.initialState.query.q).toBe('티셔츠');
      expect(wr.initialState.query.q).toBe(s.keyword);
    });
  });
});
