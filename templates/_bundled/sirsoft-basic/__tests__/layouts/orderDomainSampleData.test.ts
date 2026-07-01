/**
 * 주문 도메인 편집기 샘플 데이터 계약 테스트
 *
 * 사용자 주문 화면(장바구니/주문완료/주문내역/주문상세)은 sirsoft-basic 템플릿
 * 레이아웃이며, 데이터소스를 선언한 레이아웃 소유 확장이 템플릿이므로 편집기 샘플
 * SSoT 는 템플릿 editor-spec(`editor-spec/sampleData.json`)의 `byDataSourceId` 다.
 *
 * 실제 Resource shape 대조:
 *  - cartItems  : CartItemResource[] + OrderCalculationResult(items/summary/promotions)
 *  - orderData  : OrderResource (주문완료 — card 기본, options/payment/shipping_address)
 *  - order      : OrderResource (유저 주문상세 — options/shippings/payments/promotions snapshot)
 *  - orders     : UserOrderCollection (data[]/statistics/pagination, UserOrderListResource 행)
 *
 * 바인딩 SSoT: layouts/shop/{cart,order_complete}.json,
 *              layouts/mypage/orders.json, mypage/orders/show.json (+ partials).
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
  return path.resolve(startDir, '../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);
const SAMPLE_PATH = path.join(REPO_ROOT, 'templates/_bundled/sirsoft-basic/editor-spec/sampleData.json');
const STATES_PATH = path.join(REPO_ROOT, 'modules/_bundled/sirsoft-ecommerce/editor-spec.json');

const sample = JSON.parse(fs.readFileSync(SAMPLE_PATH, 'utf-8'));
const byId = sample.byDataSourceId as Record<string, any>;
const ecomSpec = JSON.parse(fs.readFileSync(STATES_PATH, 'utf-8'));

function hasStub(node: unknown): boolean {
  if (node === '샘플') return true;
  if (Array.isArray(node)) {
    if (node.length === 1 && node[0] === '샘플') return true;
    return node.some(hasStub);
  }
  if (node && typeof node === 'object') return Object.values(node).some(hasStub);
  return false;
}

function get(obj: any, dotted: string): any {
  return dotted.split('.').reduce((o, k) => (o == null ? o : o[k]), obj);
}

describe('주문 도메인 편집기 샘플 — sirsoft-basic 템플릿', () => {
  describe('DoD #1 — 스텁 0', () => {
    for (const id of ['cartItems', 'orderData', 'order', 'orders']) {
      it(`${id} 하위에 "샘플" stub 이 없다`, () => {
        expect(byId[id]).toBeTruthy();
        expect(hasStub(byId[id])).toBe(false);
      });
    }
  });

  describe('cartItems — 장바구니 (CartItemResource + calculation)', () => {
    const c = byId.cartItems.data;
    it('복수 아이템 ≥3 (DoD #2)', () => {
      expect(c.items.length).toBeGreaterThanOrEqual(3);
      expect(c.item_count).toBe(c.items.length);
    });
    it('분기 양면 — 재고있음 + 품절 공존 (DoD #3)', () => {
      const statuses = c.items.map((i: any) => i.product.sales_status);
      expect(statuses).toContain('on_sale');
      expect(statuses).toContain('discontinued');
      const stocks = c.items.map((i: any) => i.product_option.stock_quantity);
      expect(stocks.some((s: number) => s > 0)).toBe(true);
      expect(stocks.some((s: number) => s === 0)).toBe(true);
    });
    it('각 아이템 바인딩 필드 충족 (product/option/subtotal/mc)', () => {
      for (const it of c.items) {
        expect(it.product.name).toBeTruthy();
        expect(it.product_option.selling_price_formatted).toMatch(/원/);
        expect(it.product_option.multi_currency_selling_price.KRW.formatted).toMatch(/원/);
        expect(it.subtotal_formatted).toMatch(/원/);
        expect(it.multi_currency_subtotal.KRW.formatted).toMatch(/원/);
      }
    });
    it('calculation.summary 전 경로 채움 (DoD #4)', () => {
      const s = c.calculation.summary;
      for (const k of [
        'subtotal_formatted',
        'product_coupon_discount_formatted',
        'code_discount_formatted',
        'order_coupon_discount_formatted',
        'discount_formatted',
        'base_shipping_total_formatted',
        'shipping_fee_formatted',
        'shipping_discount_formatted',
        'final_amount_formatted',
        'payment_amount_formatted',
        'mileage_formatted',
      ]) {
        expect(s[k], `summary.${k}`).toBeTruthy();
      }
      expect(s.multi_currency.USD.final_amount_formatted).toMatch(/\$/);
    });
    it('calculation.items 배송정책 채움 (단독배송비 분기)', () => {
      const policies = c.calculation.items.map((i: any) => i.applied_shipping_policy.standalone_shipping_amount);
      expect(policies.some((p: number) => p > 0)).toBe(true);
      expect(policies.some((p: number) => p === 0)).toBe(true);
    });
  });

  describe('orderData — 주문완료 (OrderResource, card 기본)', () => {
    const d = byId.orderData.data;
    it('결제수단 card + 카드 정보 채움', () => {
      expect(d.payment.payment_method).toBe('card');
      expect(d.payment.card_name).toBeTruthy();
      expect(d.payment.card_number_masked).toMatch(/\*/);
    });
    it('주문상품 options 복수 + mc 가격', () => {
      expect(d.options.length).toBeGreaterThanOrEqual(2);
      for (const o of d.options) {
        expect(o.product_name).toBeTruthy();
        expect(o.mc_unit_price.KRW.formatted).toMatch(/원/);
        expect(o.mc_subtotal_price.KRW.formatted).toMatch(/원/);
      }
    });
    it('배송지 국내 주소 채움', () => {
      expect(d.shipping_address.recipient_name).toBeTruthy();
      expect(d.shipping_address.recipient_country_code).toBe('KR');
      expect(d.shipping_address.zipcode).toBeTruthy();
      expect(d.shipping_address.address).toContain('서울');
    });
    it('금액 요약 전 경로 (subtotal/shipping/discount/total + mc)', () => {
      for (const k of ['subtotal_amount_formatted', 'total_shipping_amount_formatted', 'total_discount_amount_formatted', 'total_amount_formatted']) {
        expect(d[k]).toMatch(/원/);
      }
      expect(d.mc_total_amount.KRW.formatted).toMatch(/원/);
      expect(d.mc_total_amount.USD.formatted).toMatch(/\$/);
    });
  });

  describe('order — 유저 주문상세 (OrderResource)', () => {
    const d = byId.order.data;
    it('order_status delivered (리뷰 작성 버튼 분기 ON)', () => {
      expect(['delivered', 'confirmed']).toContain(d.order_status);
    });
    it('options 복수 + 할인 적용/미적용 분기', () => {
      expect(d.options.length).toBeGreaterThanOrEqual(2);
      const discounts = d.options.map((o: any) => o.subtotal_discount_amount);
      expect(discounts.some((x: number) => x > 0)).toBe(true);
      expect(discounts.some((x: number) => x === 0)).toBe(true);
    });
    it('shippings 복수 + 배송정책 스냅샷', () => {
      expect(d.shippings.length).toBeGreaterThanOrEqual(2);
      for (const s of d.shippings) {
        expect(s.delivery_policy_snapshot.policy_name).toBeTruthy();
        expect(s.tracking_number).toBeTruthy();
      }
    });
    it('payments 채움 + 결제수단 라벨', () => {
      expect(d.payments.length).toBeGreaterThanOrEqual(1);
      expect(d.payment.payment_method_label).toBeTruthy();
    });
    it('promotions snapshot — 상품/주문 쿠폰 + 할인코드 공존 (분기)', () => {
      const pp = d.promotions_applied_snapshot.product_promotions;
      const op = d.promotions_applied_snapshot.order_promotions;
      expect(pp.coupons.length).toBeGreaterThanOrEqual(1);
      expect(pp.discount_codes.length).toBeGreaterThanOrEqual(1);
      expect(op.coupons.length).toBeGreaterThanOrEqual(1);
    });
    it('금액 요약 전 경로 (할인/배송/쿠폰/포인트/총액)', () => {
      for (const k of [
        'subtotal_amount_formatted',
        'total_discount_amount_formatted',
        'total_shipping_amount_formatted',
        'total_product_coupon_discount_amount_formatted',
        'total_order_coupon_discount_amount_formatted',
        'total_code_discount_amount_formatted',
        'total_points_used_amount_formatted',
        'total_amount_formatted',
      ]) {
        expect(d[k], k).toMatch(/원/);
      }
    });
  });

  describe('orders — 유저 주문내역 (UserOrderCollection)', () => {
    const d = byId.orders.data;
    it('주문 행 ≥3 + 상태 다양 (DoD #2/#3)', () => {
      expect(d.data.length).toBeGreaterThanOrEqual(3);
      const statuses = new Set(d.data.map((o: any) => o.status));
      expect(statuses.size).toBeGreaterThanOrEqual(3);
    });
    it('각 행 item + 금액 채움', () => {
      for (const o of d.data) {
        expect(o.order_number).toBeTruthy();
        expect(o.status_label).toBeTruthy();
        expect(o.total_amount_formatted).toMatch(/원/);
        expect(o.items.length).toBeGreaterThanOrEqual(1);
        expect(o.items[0].product_name).toBeTruthy();
      }
    });
    it('statistics 전 상태 경로 채움 (DoD #4)', () => {
      const s = d.statistics;
      for (const k of ['pending_payment', 'payment_complete', 'preparing', 'shipping', 'delivered', 'confirmed']) {
        expect(typeof s[k], `statistics.${k}`).toBe('number');
      }
    });
    it('pagination 채움', () => {
      expect(d.pagination.total).toBe(d.data.length);
    });
  });
});

describe('주문완료 페이지 상태 override 충실 shape', () => {
  function findGroup(match: string) {
    return ecomSpec.states.groups.find((g: any) => g.scope?.match === match);
  }

  describe('/shop/orders/:id/complete — vbank/dbank override = base 충실 shape', () => {
    const g = findGroup('/shop/orders/:id/complete');
    for (const variant of ['vbank', 'dbank']) {
      it(`${variant} override 는 base 와 동일 충실 shape (options 복수 + 무스텁)`, () => {
        const item = g.items.find((s: any) => s.id === variant);
        const od = item.sampleDataOverrides.byDataSourceId.orderData;
        expect(hasStub(od)).toBe(false);
        expect(od.data.options.length).toBeGreaterThanOrEqual(2);
        expect(od.data.payment.payment_method).toBe(variant);
        expect(od.data.shipping_address.recipient_name).toBeTruthy();
        expect(od.data.total_amount_formatted).toMatch(/원/);
      });
    }
    it('vbank — 가상계좌 정보 채움', () => {
      const od = g.items.find((s: any) => s.id === 'vbank').sampleDataOverrides.byDataSourceId.orderData;
      expect(od.data.payment.vbank_name).toBeTruthy();
      expect(od.data.payment.vbank_number).toBeTruthy();
      expect(od.data.payment.vbank_due_at).toBeTruthy();
    });
    it('dbank — 무통장입금 계좌 채움', () => {
      const od = g.items.find((s: any) => s.id === 'dbank').sampleDataOverrides.byDataSourceId.orderData;
      expect(od.data.payment.dbank_name).toBeTruthy();
      expect(od.data.payment.dbank_account).toBeTruthy();
      expect(od.data.payment.dbank_holder).toBeTruthy();
    });
  });

  describe('/shop/cart — empty_cart override = 실제 shape 빈 상태', () => {
    const g = findGroup('/shop/cart');
    it('empty_cart 은 items 빈 배열 + item_count 0 + 무스텁', () => {
      const ci = g.items.find((s: any) => s.id === 'empty_cart').sampleDataOverrides.byDataSourceId.cartItems;
      expect(hasStub(ci)).toBe(false);
      expect(ci.data.items).toEqual([]);
      expect(ci.data.item_count).toBe(0);
      expect(ci.data.calculation.summary.final_amount).toBe(0);
    });
  });
});
