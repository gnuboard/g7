/**
 * @file checkoutCashReceiptSlot.test.tsx
 * @description 체크아웃 현금영수증 확장 슬롯 + 환불계좌 입력 필드 구조 검증 (#454 S3).
 *
 * 설계 계약:
 *  - 현금영수증 신청 폼은 템플릿이 슬롯(extension_point)만 열고 이커머스 모듈이 주입한다.
 *    슬롯이 dbank 블록 **내부**에 있으므로 결제수단이 무통장일 때만 렌더된다 → 별도 if 불필요.
 *  - 환불계좌 3필드는 템플릿이 직접 소유한다(PG·현금영수증 프로바이더 비종속, 은행 목록은
 *    이미 템플릿이 가진 paymentSettings 를 재사용). dbank·vbank 양쪽 블록에 존재한다.
 *  - 반응형은 responsive.portable 단독. 신설 노드의 className 에 Tailwind breakpoint(md:/lg:)를
 *    쓰면 레이아웃 편집기의 디바이스 미리보기(overrideWidth)가 깨지므로 금지한다.
 */

import { describe, it, expect } from 'vitest';
import checkoutPaymentJson from '../../layouts/partials/shop/_checkout_payment.json';

/** if 표현식에 해당 결제수단이 걸린 최상위 블록 */
const blockFor = (method: string) =>
  (checkoutPaymentJson.children as any[]).find(
    (c) => typeof c.if === 'string' && c.if.includes(`'${method}'`)
  );

const dbankBlock = blockFor('dbank');
const vbankBlock = blockFor('vbank');

/** 노드 트리 전체를 평탄화 */
function flatten(node: any, out: any[] = []): any[] {
  if (node == null || typeof node !== 'object') return out;
  out.push(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) value.forEach((v) => flatten(v, out));
    else if (value && typeof value === 'object') flatten(value, out);
  }
  return out;
}

const findById = (root: any, id: string) => flatten(root).find((n) => n.id === id);

describe('체크아웃 현금영수증 확장 슬롯', () => {
  it('dbank 블록과 vbank 블록이 존재해야 한다', () => {
    expect(dbankBlock, '무통장 블록').toBeDefined();
    expect(vbankBlock, '가상계좌 블록').toBeDefined();
  });

  it('슬롯은 dbank 블록의 직계 자식이어야 한다 (결제수단 게이트를 블록이 대신함)', () => {
    const slot = (dbankBlock.children as any[]).find(
      (c) => c.type === 'extension_point' && c.name === 'shop_checkout_cash_receipt_slot'
    );
    expect(slot, 'dbank 블록 직계의 현금영수증 슬롯').toBeDefined();
    expect(slot.default).toEqual([]);
  });

  it('슬롯에 별도 if 게이트가 없어야 한다 (dbank 블록 내부이므로 중복)', () => {
    const slot = (dbankBlock.children as any[]).find(
      (c) => c.type === 'extension_point' && c.name === 'shop_checkout_cash_receipt_slot'
    );
    expect(slot.if).toBeUndefined();
  });

  it('vbank 블록에는 현금영수증 슬롯이 없어야 한다 (D4 — 가상계좌는 PG 가 자동 발급)', () => {
    const slots = flatten(vbankBlock).filter((n) => n.type === 'extension_point');
    expect(slots.map((s) => s.name)).not.toContain('shop_checkout_cash_receipt_slot');
  });

  it('템플릿은 현금영수증 도메인 필드를 직접 그리지 않는다 (모듈 소유)', () => {
    const raw = JSON.stringify(checkoutPaymentJson);
    expect(raw).not.toContain('cash_receipt_type');
    expect(raw).not.toContain('cashReceiptIdentifier');
  });
});

describe('환불계좌 입력 필드 (템플릿 직접 소유)', () => {
  const cases: Array<[string, string, any]> = [
    ['dbank', 'checkout_refund_bank_dbank', dbankBlock],
    ['vbank', 'checkout_refund_bank_vbank', vbankBlock],
  ];

  for (const [method, containerId, block] of cases) {
    describe(`${method} 블록`, () => {
      const container = findById(block, containerId);

      it('환불계좌 컨테이너가 존재해야 한다', () => {
        expect(container, `${containerId}`).toBeDefined();
      });

      it('은행 Select + 계좌번호 + 예금주 3필드를 가져야 한다', () => {
        const names = flatten(container)
          .map((n) => n.props?.name)
          .filter(Boolean);
        expect(names).toContain('refund_bank_code');
        expect(names).toContain('refund_bank_account');
        expect(names).toContain('refund_bank_holder');
      });

      it('3필드가 각각 _local.refundBank* 에 setState 해야 한다', () => {
        const targets = flatten(container)
          .flatMap((n) => n.actions ?? [])
          .filter((a: any) => a.handler === 'setState')
          .map((a: any) => Object.keys(a.params).filter((k) => k !== 'target'))
          .flat();
        expect(targets).toEqual(
          expect.arrayContaining(['refundBankCode', 'refundBankAccount', 'refundBankHolder'])
        );
      });

      it('은행 Select 는 paymentSettings 의 banks 를 {value,label} 로 변환해야 한다', () => {
        const select = flatten(container).find((n) => n.name === 'Select');
        expect(select, '은행 Select').toBeDefined();
        // valueKey/labelKey prop 금지 — computed/표현식으로 변환해야 한다
        expect(select.props.valueKey).toBeUndefined();
        expect(select.props.labelKey).toBeUndefined();
        expect(select.props.options).toContain('order_settings?.banks');
        expect(select.props.options).toContain('value:');
        expect(select.props.options).toContain('label:');
      });

      it('신설 노드는 responsive.portable 만 쓰고 md:/lg: breakpoint 를 쓰지 않는다 (§6-0)', () => {
        const nodes = flatten(container);

        const breakpoints = nodes.filter((n) => /(^|\s)(md|lg|xl|2xl):/.test(n.props?.className ?? ''));
        expect(breakpoints, 'Tailwind breakpoint 사용 노드').toEqual([]);

        const responsiveKeys = nodes.flatMap((n) => Object.keys(n.responsive ?? {}));
        expect(responsiveKeys.length, 'responsive 오버라이드가 하나 이상').toBeGreaterThan(0);
        expect([...new Set(responsiveKeys)], 'portable 단독 (mobile/tablet 혼용 금지)').toEqual([
          'portable',
        ]);
      });

      it('2열 그리드가 portable 에서 1열로 접힌다', () => {
        const grid = flatten(container).find((n) => (n.props?.className ?? '').includes('grid-cols-2'));
        expect(grid, '2열 그리드').toBeDefined();
        expect(grid.responsive.portable.props.className).toContain('grid-cols-1');
      });

      it('다크모드 variant 가 함께 지정되어야 한다', () => {
        const withBg = flatten(container).filter((n) =>
          /(^|\s)(bg-|text-|border-)/.test(n.props?.className ?? '')
        );
        expect(withBg.length).toBeGreaterThan(0);
        for (const n of withBg) {
          expect(n.props.className, `dark: variant 누락 → ${n.props.className}`).toContain('dark:');
        }
      });
    });
  }
});
