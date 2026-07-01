/**
 * 주문 도메인 편집기 샘플 데이터 계약 테스트
 *
 * 관리자 주문 화면(주문목록/주문상세/배송정책 폼)은 sirsoft-ecommerce 모듈
 * 레이아웃이며, 편집기 샘플 SSoT 는 모듈 editor-spec(`editor-spec.json`)의
 * `sampleData.byDataSourceId` 다.
 *
 * 실제 Resource shape 대조:
 *  - order               : OrderResource (admin 주문상세 — options[OrderOptionResource]/
 *                          payments[OrderPaymentResource]/promotions snapshot)
 *  - orders              : OrderCollection (data[OrderListResource]/statistics/pagination)
 *  - order_logs          : 활동로그 (data[]/meta, log.changes/bulk_changes)
 *  - extra_fee_templates : 배송정책 추가비용 템플릿 (data[])
 *
 * 바인딩 SSoT: resources/layouts/admin/admin_ecommerce_order_{list,detail}.json,
 *              admin_ecommerce_shipping_policy_form.json (+ partials).
 *
 * 비소비(SSoT 비대상): cartItems/orderData/checkoutData/category/qna 는 유저(basic)
 * 화면 데이터소스라 모듈 레이아웃이 선언하지 않음 → 본 spec 에서 채우지 않음.
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

describe('주문 도메인 편집기 샘플 — sirsoft-ecommerce admin', () => {
  describe('DoD #1 — 스텁 0 (소비 데이터소스)', () => {
    for (const id of ['order', 'orders', 'order_logs', 'extra_fee_templates']) {
      it(`${id} 하위에 "샘플" stub 이 없다`, () => {
        expect(byId[id]).toBeTruthy();
        expect(hasStub(byId[id])).toBe(false);
      });
    }
  });

  describe('order — 관리자 주문상세 (OrderResource)', () => {
    const d = byId.order.data;
    it('options 복수 (OrderOptionResource row.* 필드)', () => {
      expect(d.options.length).toBeGreaterThanOrEqual(2);
      for (const o of d.options) {
        for (const k of [
          'sku',
          'product_name',
          'option_name',
          'unit_price_formatted',
          'subtotal_price_formatted',
          'final_amount_formatted',
          'subtotal_tax_amount_formatted',
          'subtotal_vat_amount_formatted',
          'shipping_policy_name',
          'shipping_type_label',
          'option_status_label',
        ]) {
          expect(o[k], `option.${k}`).toBeTruthy();
        }
        expect(o.mc_unit_price.KRW.formatted).toMatch(/원/);
      }
    });
    it('per-item 할인 분기 — 적용/미적용 공존', () => {
      const disc = d.options.map((o: any) => o.subtotal_discount_amount);
      expect(disc.some((x: number) => x > 0)).toBe(true);
      expect(disc.some((x: number) => x === 0)).toBe(true);
    });
    it('payments 복수 (OrderPaymentResource payment.* 필드)', () => {
      expect(d.payments.length).toBeGreaterThanOrEqual(1);
      for (const p of d.payments) {
        for (const k of ['payment_status_label', 'payment_method_label', 'payment_number', 'paid_amount_formatted', 'requested_at_formatted', 'paid_at_formatted']) {
          expect(p[k], `payment.${k}`).toBeTruthy();
        }
      }
    });
    it('orderer/recipient 정보 채움', () => {
      expect(d.orderer_name).toBeTruthy();
      expect(d.orderer_email).toMatch(/@/);
      expect(d.recipient_address).toBeTruthy();
      expect(d.user_login_id).toBeTruthy();
    });
    it('금액 요약 전 경로 (할인/배송/세금/포인트/총액)', () => {
      for (const k of [
        'subtotal_amount_formatted',
        'total_discount_amount_formatted',
        'total_shipping_amount_formatted',
        'total_product_coupon_discount_amount_formatted',
        'total_code_discount_amount_formatted',
        'total_points_used_amount_formatted',
        'total_tax_amount_formatted',
        'total_vat_amount_formatted',
        'total_paid_amount_formatted',
        'total_amount_formatted',
      ]) {
        expect(d[k], k).toMatch(/원/);
      }
    });
    it('promotions snapshot — 쿠폰/할인코드 공존', () => {
      const pp = d.promotions_applied_snapshot.product_promotions;
      const op = d.promotions_applied_snapshot.order_promotions;
      expect(pp.coupons.length).toBeGreaterThanOrEqual(1);
      expect(pp.discount_codes.length).toBeGreaterThanOrEqual(1);
      expect(op.coupons.length).toBeGreaterThanOrEqual(1);
    });
    it('abilities.can_update true (수정 UI 노출)', () => {
      expect(d.abilities.can_update).toBe(true);
    });
  });

  describe('orders — 관리자 주문목록 (OrderCollection)', () => {
    const d = byId.orders.data;
    it('주문 행 ≥3 + 상태 다양', () => {
      expect(d.data.length).toBeGreaterThanOrEqual(3);
      const statuses = new Set(d.data.map((r: any) => r.order_status));
      expect(statuses.size).toBeGreaterThanOrEqual(3);
    });
    it('각 행 OrderListResource 필드 채움', () => {
      for (const r of d.data) {
        expect(r.order_number).toBeTruthy();
        expect(r.order_status_label).toBeTruthy();
        expect(r.total_amount_formatted).toMatch(/원/);
        expect(r.user.name).toBeTruthy();
        expect(r.user.email).toMatch(/@/);
        expect(r.first_option.product_name).toBeTruthy();
        expect(r.payment.payment_method_label).toBeTruthy();
        expect(r.shipping.shipping_type_label).toBeTruthy();
      }
    });
    it('결제수단 분기 다양 (card/vbank/dbank)', () => {
      const methods = new Set(d.data.map((r: any) => r.payment.payment_method));
      expect(methods.size).toBeGreaterThanOrEqual(2);
    });
    it('국내/해외 배송 국가 분기 공존', () => {
      const countries = new Set(d.data.map((r: any) => r.address.recipient_country_code));
      expect(countries.has('KR')).toBe(true);
    });
    it('statistics 전 경로 + pagination 채움 (DoD #4)', () => {
      const s = d.statistics;
      for (const k of ['pending_payment', 'payment_complete', 'preparing', 'shipping', 'delivered', 'confirmed']) {
        expect(typeof s[k], `statistics.${k}`).toBe('number');
      }
      expect(d.pagination.total).toBe(d.data.length);
    });
  });

  describe('order_logs — 활동 로그', () => {
    const d = byId.order_logs.data;
    it('로그 행 ≥3 + 변경 내역 채움', () => {
      expect(d.data.length).toBeGreaterThanOrEqual(3);
      for (const log of d.data) {
        expect(log.created_at).toBeTruthy();
        expect(log.localized_description).toBeTruthy();
        expect(Array.isArray(log.changes)).toBe(true);
      }
    });
    it('changes 항목 field/old/new 라벨 채움', () => {
      const withChanges = d.data.find((l: any) => l.changes.length > 0);
      expect(withChanges).toBeTruthy();
      const ch = withChanges.changes[0];
      for (const k of ['field', 'label', 'old_label', 'new_label']) {
        expect(ch[k], `change.${k}`).toBeDefined();
      }
    });
    it('actor 분기 — 관리자 + 시스템(null) 공존', () => {
      const actors = d.data.map((l: any) => l.user);
      expect(actors.some((u: any) => u && u.name)).toBe(true);
      expect(actors.some((u: any) => u === null)).toBe(true);
    });
    it('meta pagination 채움', () => {
      expect(d.meta.total).toBe(d.data.length);
    });
  });

  describe('extra_fee_templates — 배송정책 추가비용 템플릿', () => {
    const d = byId.extra_fee_templates.data;
    it('템플릿 ≥3 + 활성/비활성 분기 공존', () => {
      expect(d.data.length).toBeGreaterThanOrEqual(3);
      const active = d.data.map((t: any) => t.is_active);
      expect(active.some((x: boolean) => x === true)).toBe(true);
      expect(active.some((x: boolean) => x === false)).toBe(true);
    });
    it('각 템플릿 region/fee/fee_formatted 채움', () => {
      for (const t of d.data) {
        expect(t.description).toBeTruthy();
        expect(t.region).toBeTruthy();
        expect(typeof t.fee).toBe('number');
        expect(t.fee_formatted).toMatch(/원/);
      }
    });
  });
});
