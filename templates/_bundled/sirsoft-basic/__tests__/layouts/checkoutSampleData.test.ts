/**
 * 체크아웃(주문서) 편집기 샘플 데이터 계약 테스트
 *
 * 사용자 화면 `/shop/checkout` 은 sirsoft-basic 템플릿 레이아웃이며, 캔버스가
 * 의존하는 세 데이터소스(`checkoutData` / `userAddresses` / `paymentSettings`)는
 * 템플릿 레이아웃(`layouts/shop/checkout.json`)에 선언되어 출처(`__source`)가
 * 템플릿이다. 따라서 편집기 샘플은 **템플릿** editor-spec(`editor-spec/sampleData.json`)
 * 의 `byDataSourceId` 가 SSoT 다(모듈 editor-spec 아님).
 *
 * 주문서 작성 화면 샘플이 단일 상품뿐이고 적용 쿠폰·할인·
 * 배송지 목록·받는사람·배송 메모·결제수단 샘플이 비어 있던 결함의 회귀 방지.
 *
 * 바인딩 SSoT: templates/_bundled/sirsoft-basic/layouts/shop/checkout.json
 *              (+ partials/shop/_checkout_*.json)
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

describe('checkout 편집기 샘플 데이터 — sirsoft-basic 템플릿', () => {
  describe('checkoutData', () => {
    const data = byId.checkoutData?.data;

    it('주문 상품이 2건 이상이다 (단일 상품 결함 회귀 방지)', () => {
      expect(Array.isArray(data.items)).toBe(true);
      expect(data.items.length).toBeGreaterThanOrEqual(2);
    });

    it('각 상품이 _checkout_items 바인딩 경로를 채운다', () => {
      for (const item of data.items) {
        expect(item.product_option_id).toBeTypeOf('number');
        expect(item.quantity).toBeTypeOf('number');
        expect(item.product?.name).toBeTruthy();
        expect(item.product?.sales_status).toBe('on_sale');
        expect(item.product_option?.option_values_localized).toBeTypeOf('object');
        expect(item.product_option?.multi_currency_selling_price?.KRW?.formatted).toBeTruthy();
        expect(item.multi_currency_subtotal?.KRW?.formatted).toBeTruthy();
        expect(item.multi_currency_final_amount?.KRW?.formatted).toBeTruthy();
        expect(Array.isArray(item.available_coupons)).toBe(true);
      }
    });

    it('적용된 상품 쿠폰 + 사용 가능 쿠폰 샘플이 존재한다', () => {
      const discounted = data.items.find((i: any) => (i.product_coupon_discount_amount ?? 0) > 0);
      expect(discounted).toBeTruthy();
      expect(discounted.available_coupons.length).toBeGreaterThan(0);
      const productCoupons = data.calculation?.promotions?.product_promotions?.coupons ?? [];
      expect(productCoupons.length).toBeGreaterThan(0);
      expect(
        productCoupons[0].applied_items.some(
          (ai: any) => ai.product_option_id === discounted.product_option_id,
        ),
      ).toBe(true);
    });

    it('주문/배송 쿠폰 사용 가능 목록에 두 target_type 이 모두 있다', () => {
      const coupons = data.available_coupons ?? [];
      expect(coupons.some((c: any) => c.target_type === 'order_amount')).toBe(true);
      expect(coupons.some((c: any) => c.target_type === 'shipping_fee')).toBe(true);
      for (const c of coupons) {
        expect(c.target_type_short_label).toBeTruthy();
        expect(c.localized_name).toBeTruthy();
        expect(c.benefit_formatted).toBeTruthy();
      }
    });

    it('적용된 주문/배송 쿠폰이 promotions.order_promotions 에 존재한다', () => {
      const orderCoupons = data.calculation?.promotions?.order_promotions?.coupons ?? [];
      expect(orderCoupons.some((c: any) => c.target_type === 'order_amount')).toBe(true);
      expect(orderCoupons.some((c: any) => c.target_type === 'shipping_fee')).toBe(true);
    });

    it('summary.multi_currency.KRW 가 요약/총액 바인딩 경로를 채운다', () => {
      const krw = data.calculation?.summary?.multi_currency?.KRW;
      expect(krw).toBeTruthy();
      for (const key of [
        'subtotal_formatted',
        'total_discount_formatted',
        'total_shipping_formatted',
        'payment_amount_formatted',
        'final_amount_formatted',
      ]) {
        expect(krw[key]).toBeTruthy();
      }
    });

    it('마일리지 잔액/최대 사용액 샘플이 양수다', () => {
      expect(data.mileage?.balance).toBeGreaterThan(0);
      expect(data.mileage?.balance_formatted).toBeTruthy();
      expect(data.mileage?.max_usable).toBeGreaterThan(0);
    });
  });

  describe('userAddresses', () => {
    const addresses = byId.userAddresses?.data?.addresses?.data;

    it('저장된 배송지 목록이 2건 이상이다 (배송지 목록 샘플 결함 회귀 방지)', () => {
      expect(Array.isArray(addresses)).toBe(true);
      expect(addresses.length).toBeGreaterThanOrEqual(2);
    });

    it('각 배송지가 _checkout_shipping 바인딩 경로를 채운다 (받는사람 정보 포함)', () => {
      for (const addr of addresses) {
        expect(addr.id).toBeTypeOf('number');
        expect(addr.name).toBeTypeOf('object'); // $localized(addr.name)
        expect(addr.recipient_name).toBeTruthy();
        expect(addr.recipient_phone).toBeTruthy();
        expect(addr.zipcode).toBeTruthy();
        expect(addr.address).toBeTruthy();
        expect(addr.country_code).toBeTruthy();
      }
      expect(addresses.filter((a: any) => a.is_default).length).toBe(1);
    });
  });

  describe('paymentSettings', () => {
    const settings = byId.paymentSettings?.data?.order_settings;

    it('결제수단 목록에 card/vbank/dbank 가 모두 활성으로 존재한다 (결제수단 샘플 결함 회귀 방지)', () => {
      const methods = settings?.payment_methods ?? [];
      const activeIds = methods.filter((m: any) => m.is_active).map((m: any) => m.id);
      expect(activeIds).toContain('card');
      expect(activeIds).toContain('vbank');
      expect(activeIds).toContain('dbank');
      for (const m of methods) {
        expect(m._cached_name).toBeTypeOf('object'); // $localized
        expect(m._cached_icon).toBeTruthy();
      }
    });

    it('무통장입금 은행 계좌 목록이 채워진다', () => {
      const banks = settings?.bank_accounts ?? [];
      expect(banks.length).toBeGreaterThanOrEqual(1);
      for (const b of banks) {
        expect(b.bank_code).toBeTruthy();
        expect(b.bank_name).toBeTypeOf('object'); // $localized
        expect(b.account_number).toBeTruthy();
        expect(b.account_holder).toBeTruthy();
        expect(b.is_active).toBe(true);
      }
    });

    it('가상계좌/무통장 입금 기한 안내 일수가 정의된다', () => {
      expect(settings?.vbank_due_days).toBeGreaterThan(0);
      expect(settings?.dbank_due_days).toBeGreaterThan(0);
    });
  });

  describe('sampleGlobal — currentUser + _local baseline', () => {
    const globalPath = path.join(
      REPO_ROOT,
      'templates/_bundled/sirsoft-basic/editor-spec/sampleGlobal.json',
    );
    const g = JSON.parse(fs.readFileSync(globalPath, 'utf-8'));

    it('주문자 연락처 표시용 phone 이 시드된다', () => {
      expect(g.currentUser?.phone).toBeTruthy();
    });

    it('_local baseline 이 배송지 폼(받는분/주소/배송메모)을 시드한다 — 전 상태 공통, 운영 무영향', () => {
      // sampleGlobal._local 은 applyInitialPatch 의 localBaseline 으로 흐른다(PreviewCanvas).
      // 모든 페이지 상태에 공통 적용되므로 상태별 중복 시드가 불필요하다.
      const local = g._local;
      expect(local).toBeTruthy();
      expect(local.selectedAddressId).toBe(1);
      const shipping = local.shipping;
      expect(shipping).toBeTruthy();
      expect(shipping.recipient_name).toBeTruthy();
      expect(shipping.recipient_phone).toBeTruthy();
      expect(shipping.zipcode).toBeTruthy();
      expect(shipping.address).toBeTruthy();
      expect(shipping.country_code).toBe('KR');
      expect(local.shippingMemo).toBeTruthy();
    });
  });

  describe('회귀 가드 — 레이아웃 initLocal 오염 방지', () => {
    it('checkout.json initLocal 은 샘플 배송지를 담지 않는다 (운영 사용자 폼 오염 방지)', () => {
      // 편집기 시뮬레이션 데이터는 isEditMode 게이트 안(sampleGlobal._local)에서만 흐른다.
      // 레이아웃 initLocal 은 운영 환경에도 적용되므로 샘플 배송지를 넣으면 실사용자
      // 주문서에 가짜 주소가 깔린다(위반). 빈/null 상태를 보장한다.
      const checkoutPath = path.join(
        REPO_ROOT,
        'templates/_bundled/sirsoft-basic/layouts/shop/checkout.json',
      );
      const checkout = JSON.parse(fs.readFileSync(checkoutPath, 'utf-8'));
      const il = checkout.initLocal ?? {};
      expect(il.shipping).toBeUndefined();
      expect(il.shippingMemo).toBeUndefined();
      expect(il.selectedAddressId ?? null).toBeNull();
    });
  });
});
