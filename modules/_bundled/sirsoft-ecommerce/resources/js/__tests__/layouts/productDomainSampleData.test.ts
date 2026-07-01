/**
 * 상품 도메인 편집기 샘플 데이터 계약 테스트 — sirsoft-ecommerce (admin)
 *
 * 관리자 상품 화면(상품 등록/수정 폼, 상품 목록, 리뷰/쿠폰 관리 등)은 sirsoft-ecommerce
 * 모듈 레이아웃이며, 데이터소스 출처(__source)가 모듈이다. 따라서 편집기 샘플은
 * **모듈** editor-spec(`editor-spec.json` 의 `sampleData.byDataSourceId`)이 SSoT 다.
 *
 * 실제 admin Resource shape 대조:
 *  - product           : edit-seed (product_code/sales_product_code/abilities/images/created_at/updated_at)
 *  - products          : ProductListResource rows + admin abilities + pagination
 *  - copy_source       : name.ko / product_code / selling_price / thumbnail_url / images[]
 *  - categories        : CategoryResource 트리 (data.data[].children[])
 *  - brands            : BrandResource (name 다국어 + localized_name + description)
 *  - product_labels    : ProductLabelResource (name 다국어 + color)
 *  - notice_templates  : ProductNoticeTemplateResource
 *  - common_infos      : ProductCommonInfoResource (is_default 분기 + content_mode 분기)
 *  - shipping_policies : ShippingPolicyResource (is_default 분기 + country_settings)
 *  - activity_logs     : ActivityLogResource (changes 있음/없음 분기)
 *  - reviews           : admin 리뷰 그리드 (data.data + meta)
 *  - coupons           : admin 쿠폰 그리드 (data.data + pagination)
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

function findProjectRoot(startDir: string): string {
  let dir = startDir;
  while (dir !== path.dirname(dir)) {
    if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
    dir = path.dirname(dir);
  }
  return path.resolve(startDir, '../../../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);
const spec = JSON.parse(
  fs.readFileSync(path.join(REPO_ROOT, 'modules/_bundled/sirsoft-ecommerce/editor-spec.json'), 'utf-8'),
);
const byId = spec.sampleData.byDataSourceId as Record<string, any>;

function hasStub(node: unknown): boolean {
  if (node === '샘플') return true;
  if (Array.isArray(node)) {
    if (node.length === 1 && node[0] === '샘플') return true;
    return node.some(hasStub);
  }
  if (node && typeof node === 'object') return Object.values(node).some(hasStub);
  return false;
}

describe('상품 도메인 편집기 샘플 — sirsoft-ecommerce admin', () => {
  describe('DoD #1 — 스텁 0', () => {
    for (const id of [
      'product',
      'products',
      'copy_source',
      'categories',
      'brands',
      'product_labels',
      'notice_templates',
      'common_infos',
      'commonInfos',
      'shipping_policies',
      'activity_logs',
      'reviews',
      'coupons',
    ]) {
      it(`${id} 하위에 "샘플" 스텁 leaf 가 없다`, () => {
        expect(byId[id]).toBeTruthy();
        expect(hasStub(byId[id])).toBe(false);
      });
    }
  });

  describe('product — edit-seed 표시 경로', () => {
    const d = byId.product.data;
    it('product_code/sales_product_code/abilities/이미지(3+)/일시를 채운다', () => {
      expect(d.product_code).toBeTruthy();
      expect(d.sales_product_code).toBeTruthy();
      expect(d.abilities?.can_update).toBe(true);
      expect(d.images.length).toBeGreaterThanOrEqual(3);
      for (const img of d.images) expect(img.download_url).toBeTruthy();
      expect(d.created_at).toBeTruthy();
      expect(d.updated_at).toBeTruthy();
    });
  });

  describe('products — admin 상품 목록 그리드', () => {
    const data = byId.products.data;
    it('3건 이상 + pagination + abilities + 분기(할인/정가, 노출/미노출, 판매중/품절)', () => {
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      expect(data.pagination?.last_page).toBeGreaterThan(1);
      expect(data.abilities?.can_create).toBe(true);
      expect(data.data.some((p: any) => p.discount_rate > 0)).toBe(true);
      expect(data.data.some((p: any) => p.discount_rate === 0)).toBe(true);
      expect(data.data.some((p: any) => p.display_status === 'hidden')).toBe(true);
      expect(data.data.some((p: any) => p.sales_status === 'sold_out')).toBe(true);
      for (const p of data.data) {
        expect(p.name_localized).toBeTruthy();
        expect(p.selling_price_formatted).toBeTruthy();
        expect(p.product_code).toBeTruthy();
        expect(p.abilities).toBeTruthy();
      }
    });
  });

  describe('copy_source — 복사 원본 미리보기', () => {
    const d = byId.copy_source.data;
    it('name.ko / product_code / selling_price / thumbnail_url / images 채움', () => {
      expect(d.name?.ko).toBeTruthy();
      expect(d.product_code).toBeTruthy();
      expect(d.selling_price).toBeGreaterThan(0);
      expect(d.thumbnail_url).toBeTruthy();
      expect(d.images.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('categories — admin 트리', () => {
    const data = byId.categories.data;
    it('3+ 루트 + 다단계 children + name 다국어/localized_name', () => {
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      const root = data.data.find((c: any) => (c.children ?? []).length > 0);
      expect(root).toBeTruthy();
      const lvl2 = root.children.find((c: any) => (c.children ?? []).length > 0);
      expect(lvl2).toBeTruthy();
      for (const c of data.data) {
        expect(c.name?.ko).toBeTruthy();
        expect(c.localized_name).toBeTruthy();
        expect(c.slug).toBeTruthy();
      }
    });
  });

  describe('brands — name 다국어 + description', () => {
    const data = byId.brands.data;
    it('3건 + 분기(활성/비활성, description 유무) + name 다국어', () => {
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      expect(data.data.some((b: any) => b.is_active)).toBe(true);
      expect(data.data.some((b: any) => !b.is_active)).toBe(true);
      expect(data.data.some((b: any) => b.description)).toBe(true);
      expect(data.data.some((b: any) => !b.description)).toBe(true);
      for (const b of data.data) {
        expect(b.name?.ko).toBeTruthy();
        expect(b.localized_name).toBeTruthy();
      }
    });
  });

  describe('product_labels — name 다국어 + color', () => {
    const data = byId.product_labels.data;
    it('3건 + 분기(활성/비활성) + color', () => {
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      expect(data.data.some((l: any) => l.is_active)).toBe(true);
      expect(data.data.some((l: any) => !l.is_active)).toBe(true);
      for (const l of data.data) {
        expect(l.name?.ko).toBeTruthy();
        expect(l.color).toMatch(/^#/);
      }
    });
  });

  describe('notice_templates / common_infos — 선택지 복수 + 분기', () => {
    it('notice_templates 3건 + 활성/비활성 분기', () => {
      const data = byId.notice_templates.data;
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      expect(data.data.some((t: any) => t.is_active)).toBe(true);
      expect(data.data.some((t: any) => !t.is_active)).toBe(true);
      for (const t of data.data) expect(t.localized_name).toBeTruthy();
    });
    it('common_infos 3건 + is_default 분기 + content_mode(html/text) 분기', () => {
      const data = byId.common_infos.data;
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      expect(data.data.filter((c: any) => c.is_default).length).toBe(1);
      expect(data.data.some((c: any) => c.content_mode === 'html')).toBe(true);
      expect(data.data.some((c: any) => c.content_mode === 'text')).toBe(true);
      for (const c of data.data) {
        expect(c.localized_name).toBeTruthy();
        expect(c.content?.ko).toBeTruthy();
      }
    });
  });

  describe('shipping_policies — is_default 분기 + country_settings', () => {
    const data = byId.shipping_policies.data;
    it('3건 + is_default 단일 + fee_summary + country_settings 채움', () => {
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      expect(data.data.filter((p: any) => p.is_default).length).toBe(1);
      for (const p of data.data) {
        expect(p.name_localized).toBeTruthy();
        expect(p.fee_summary).toBeTruthy();
        expect(Array.isArray(p.country_settings)).toBe(true);
        expect(p.country_settings.length).toBeGreaterThanOrEqual(1);
        for (const cs of p.country_settings) {
          expect(cs.country_code).toBeTruthy();
          expect(cs.charge_policy).toBeTruthy();
        }
      }
      // 복수 국가 정책 존재 (단일/다국 공존)
      expect(data.data.some((p: any) => p.country_settings.length > 1)).toBe(true);
    });
  });

  describe('activity_logs — changes 분기', () => {
    const data = byId.activity_logs.data;
    it('3건 + meta + changes 있음/없음 공존 + old_label/new_label', () => {
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      expect(data.meta?.total).toBeTypeOf('number');
      expect(data.data.some((l: any) => l.has_changes && Array.isArray(l.changes))).toBe(true);
      expect(data.data.some((l: any) => !l.has_changes)).toBe(true);
      const withChanges = data.data.find((l: any) => l.has_changes);
      for (const c of withChanges.changes) {
        expect(c.label).toBeTruthy();
        expect(c.old_label !== undefined).toBe(true);
        expect(c.new_label !== undefined).toBe(true);
      }
      for (const l of data.data) {
        expect(l.localized_description).toBeTruthy();
        expect(l.actor_name).toBeTruthy();
        expect(l.created_at).toBeTruthy();
      }
    });
  });

  describe('reviews — admin 그리드 (data.data + meta)', () => {
    const data = byId.reviews.data;
    it('3건 + meta + 분기(노출/숨김, 답변/미답변, 사진유무)', () => {
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      expect(data.meta?.current_page).toBeTypeOf('number');
      expect(data.data.some((r: any) => r.status === 'visible')).toBe(true);
      expect(data.data.some((r: any) => r.status === 'hidden')).toBe(true);
      expect(data.data.some((r: any) => r.has_reply)).toBe(true);
      expect(data.data.some((r: any) => !r.has_reply)).toBe(true);
      for (const r of data.data) {
        expect(r.user?.name).toBeTruthy();
        expect(r.product?.name).toBeTruthy();
        expect(r.rating).toBeGreaterThanOrEqual(1);
      }
    });
  });

  describe('/shop/products/:id 상태 override — 통째 교체이므로 충실 shape 유지', () => {
    // Chrome MCP 실측 회귀(2026-06-03): 비-기본 상태 override 가 빈약 stub(name:"샘플 상품 1",
    // price, stock_status, images:[], options:[])이라 캔버스가 붕괴(277자)했다. override 는
    // base sampleData 를 "통째 교체"(pageStateSimulator.resolveSampleOverride)하므로
    // 누락 필드는 undefined → 렌더 붕괴. base 와 동일 충실 shape 를 유지해야 한다.
    const group = spec.states.groups.find(
      (g: any) => g.scope?.kind === 'route' && g.scope?.match === '/shop/products/:id',
    );
    const PRODUCT_EP = '/api/modules/sirsoft-ecommerce/products/*';
    const stateOf = (id: string) =>
      group?.items.find((i: any) => i.id === id)?.sampleDataOverrides?.byEndpointPattern?.[PRODUCT_EP]?.data;

    it('상태 그룹과 재고 5종 + 탭 게이트 2종(reviews/qna)이 존재한다', () => {
      expect(group).toBeTruthy();
      const ids = group.items.map((i: any) => i.id);
      // 재고 상태 5종 (Stage A) + 단계 E 탭 게이트 상태 2종(reviews_tab/qna_tab)
      expect(ids).toEqual([
        'in_stock',
        'out_of_stock',
        'sold_out',
        'suspended',
        'coming_soon',
        'reviews_tab',
        'qna_tab',
      ]);
    });

    for (const [id, expectStatus] of [
      ['out_of_stock', 'on_sale'],
      ['sold_out', 'sold_out'],
      ['suspended', 'suspended'],
      ['coming_soon', 'coming_soon'],
    ] as const) {
      it(`${id} override 가 충실 product shape + sales_status='${expectStatus}'`, () => {
        const p = stateOf(id);
        expect(p).toBeTruthy();
        // 빈약 stub 회귀 가드: 실제 필드명만 — price/stock_status 같은 가짜 필드 금지
        expect(p.price).toBeUndefined();
        expect(p.stock_status).toBeUndefined();
        // 충실 shape (base 복제) — 캔버스 붕괴 회귀 가드
        expect(p.name_localized).toBe('베이직 오버핏 코튼 티셔츠');
        expect(p.sales_status).toBe(expectStatus);
        expect(p.selling_price_formatted).toBeTruthy();
        expect(p.multi_currency_selling_price?.KRW?.formatted).toBeTruthy();
        expect(p.options.length).toBeGreaterThanOrEqual(3);
        expect(p.labels.length).toBeGreaterThanOrEqual(1);
        expect(p.shipping_policy?.fee_summary).toBeTruthy();
        expect(p.notice?.values.length).toBeGreaterThanOrEqual(3);
        // stub 명 회귀 가드
        expect(JSON.stringify(p)).not.toContain('샘플 상품');
      });
    }

    it('품절/일시품절 상태는 재고 0 (분기 반영)', () => {
      expect(stateOf('out_of_stock').stock_quantity).toBe(0);
      expect(stateOf('sold_out').stock_quantity).toBe(0);
    });
  });

  describe('coupons — admin 그리드 (data.data + pagination)', () => {
    const data = byId.coupons.data;
    it('3건 + pagination + abilities + 분기(활성/비활성, 주문/배송 target)', () => {
      expect(data.data.length).toBeGreaterThanOrEqual(3);
      expect(data.pagination?.last_page).toBeGreaterThan(1);
      expect(data.abilities?.can_create).toBe(true);
      expect(data.data.some((c: any) => c.is_active)).toBe(true);
      expect(data.data.some((c: any) => !c.is_active)).toBe(true);
      expect(data.data.some((c: any) => c.target_type === 'order_amount')).toBe(true);
      expect(data.data.some((c: any) => c.target_type === 'shipping_fee')).toBe(true);
      for (const c of data.data) {
        expect(c.localized_name).toBeTruthy();
        expect(c.benefit_formatted).toBeTruthy();
      }
    });
  });
});
