// e2e:allow 조각 렌더 회귀(엔진 createLayoutTest) — 회원·비회원 주문 상세 브라우저 실측은 g7_2 W3 최종 리허설 몫(7.1.0 출시 최종 수정, 결정 2026-10-10)
/**
 * @file userOrderShowVbankGuide.test.tsx
 * @description 토스페이먼츠 가상계좌 주문의 주문 상세 결제 정보 카드에 입금 안내가 보이는지
 *
 * 결함(7.0.11 부터): 토스 가상계좌 주문의 주문 상세에는 「결제 방법: 가상계좌」만 있고 은행·계좌번호·예금주·입금 금액·
 * 입금 기한이 없었다. KG이니시스·NHN KCP·나이스페이먼츠는 결제 정보 카드 슬롯 조각이 그 안내를 그린다.
 * 같은 조각(user_order_show)이 같은 슬롯으로 회원 주문 상세·비회원 주문 상세·신청서 상세에 붙으므로 한 블록으로 모두 덮는다.
 * 비회원 경로에서도 깨지지 않도록 이 블록은 주문 데이터만 읽고 인증이 필요한 데이터 소스를 쓰지 않는다.
 *
 * @scenario-manifest tests/scenarios/user-order-show-vbank-guide.yaml
 */

import React from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../../../../../resources/js/core/template-engine/ComponentRegistry';
import extension from '../../../extensions/user_order_show.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';

type LayoutTest = ReturnType<typeof createLayoutTest>;

/** 조각이 쓰는 기본 컴포넌트 이름 → HTML 태그 */
const TAGS: Record<string, string> = { Div: 'div', Span: 'span', P: 'p', A: 'a', Icon: 'i' };

let utils: LayoutTest | null = null;

/**
 * 기본 컴포넌트를 HTML 태그로 등록한다.
 *
 * @returns 컴포넌트 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const entries: Record<string, unknown> = {};
  for (const [name, tag] of Object.entries(TAGS)) {
    entries[name] = {
      component: ({ text, children, className, href, 'data-testid': testId }: any) => React.createElement(tag, { className, href, 'data-testid': testId }, children ?? text),
      metadata: { name, type: 'basic' },
    };
  }
  entries.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
  (registry as any).registry = entries;

  return registry;
}

/**
 * 토스 가상계좌 주문 데이터를 만든다.
 *
 * @param payment 덮어쓸 결제 값
 * @param order 덮어쓸 주문 값
 * @returns 주문 데이터 소스의 data
 */
function tossVbankOrder(payment: Record<string, unknown> = {}, order: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    order_number: 'ORD-T',
    order_status: 'pending_payment',
    total_due_amount_formatted: '14,000원',
    total_amount_formatted: '15,000원',
    ...order,
    payment: {
      pg_provider: 'tosspayments',
      payment_method: 'vbank',
      payment_status: 'waiting_deposit',
      vbank_name: '우리은행',
      vbank_number: '12345678901299',
      vbank_holder: '홍길동',
      vbank_due_at_formatted: '2026-10-12 23:59',
      paid_at: null,
      ...payment,
    },
  };
}

/**
 * 주어진 주문으로 조각 전체를 렌더한다.
 *
 * @param order 주문 데이터 소스의 data
 * @param locale 화면 언어
 * @returns 렌더된 컨테이너
 */
async function mount(order: Record<string, unknown>, locale: 'ko' | 'en' = 'ko'): Promise<HTMLElement> {
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale }) };
  utils = createLayoutTest(
    { version: '1.0.0', layout_name: 'toss_user_order_show_test', components: (extension as any).components } as any,
    {
      initialData: { order: { data: order } },
      translations: { 'sirsoft-tosspayments': locale === 'en' ? en : ko },
      locale,
      componentRegistry: setupRegistry(),
    } as any,
  );
  const { container } = await utils.render();

  return container;
}

/** 입금 안내 블록 */
const guide = (c: HTMLElement) => c.querySelector('[data-testid="toss-vbank-guide"]');

afterEach(() => {
  utils?.cleanup();
  utils = null;
  (ComponentRegistry.getInstance() as any).registry = {};
  delete (window as any).__templateApp;
});

describe('토스 가상계좌 입금 안내 — 주문 상세 결제 정보 카드', () => {
  // @scenario payment_state=waiting_deposit, viewer=member
  // @effects guide_shows_account_amount_due
  it('입금 대기 주문은 은행·계좌번호·예금주·입금 금액(결제예정금액)·입금 기한과 자동 취소 경고를 보인다', async () => {
    const container = await mount(tossVbankOrder());
    const block = guide(container);

    expect(block, '토스 가상계좌 입금 안내 블록이 없다').not.toBeNull();
    const text = block!.textContent ?? '';
    for (const value of ['우리은행', '12345678901299', '홍길동', '14,000원', '2026-10-12 23:59']) expect(text).toContain(value);
    expect(text).toContain(ko.user.vbank_title);
    expect(text).toContain(ko.user.vbank_notice);
  });

  // @scenario payment_state=waiting_deposit, viewer=guest
  // @effects guest_guide_falls_back_to_order_amount
  it('비회원 응답처럼 결제예정금액이 없으면 주문금액으로 대신한다', async () => {
    const container = await mount(tossVbankOrder({}, { total_due_amount_formatted: undefined }));

    expect(guide(container)?.textContent ?? '').toContain('15,000원');
  });

  // @scenario payment_state=paid, viewer=member
  // @effects warning_hidden_after_deposit
  it('입금을 마친 주문은 계좌는 보이되 자동 취소 경고는 감춘다', async () => {
    const container = await mount(tossVbankOrder({ payment_status: 'paid', paid_at: '2026-10-10 10:00:00' }, { order_status: 'payment_complete' }));
    const text = guide(container)?.textContent ?? '';

    expect(text).toContain('12345678901299');
    expect(text).not.toContain(ko.user.vbank_notice);
  });

  // @scenario payment_state=cancelled, viewer=member
  // @effects guide_hidden_for_cancelled_or_other_pg
  it('취소된 주문·취소된 결제·다른 결제대행사·가상계좌가 아닌 결제에는 그리지 않는다', async () => {
    const cases = [
      tossVbankOrder({}, { order_status: 'cancelled' }),
      tossVbankOrder({ payment_status: 'cancelled' }),
      tossVbankOrder({ pg_provider: 'nhnkcp' }),
      tossVbankOrder({ payment_method: 'card', vbank_number: null }),
    ];
    for (const order of cases) {
      const container = await mount(order);
      expect(guide(container), JSON.stringify(order.payment)).toBeNull();
      utils?.cleanup();
      utils = null;
    }
  });

  // @scenario payment_state=paid, viewer=guest
  // @effects warning_hidden_after_deposit, guest_guide_falls_back_to_order_amount
  it('비회원 응답(결제예정금액 없음)도 입금 뒤에는 경고만 감추고 주문금액을 보인다', async () => {
    const container = await mount(tossVbankOrder({ payment_status: 'paid', paid_at: '2026-10-10 10:00:00' }, { order_status: 'payment_complete', total_due_amount_formatted: undefined }));
    const text = guide(container)?.textContent ?? '';

    expect(text).toContain('15,000원');
    expect(text).not.toContain(ko.user.vbank_notice);
  });

  // @scenario payment_state=cancelled, viewer=guest
  // @effects guide_hidden_for_cancelled_or_other_pg
  it('비회원 응답도 취소된 주문에는 계좌를 보이지 않는다', async () => {
    const container = await mount(tossVbankOrder({}, { order_status: 'cancelled', total_due_amount_formatted: undefined }));

    expect(guide(container)).toBeNull();
  });

  // @scenario payment_state=waiting_deposit, viewer=member
  // @effects guide_text_translated
  it('화면 언어(en)로 제목과 경고가 나온다', async () => {
    const container = await mount(tossVbankOrder(), 'en');
    const text = guide(container)?.textContent ?? '';

    expect(text).toContain(en.user.vbank_title);
    expect(text).toContain(en.user.vbank_notice);
  });

  // @scenario payment_state=waiting_deposit, viewer=guest
  // @effects guide_uses_order_data_only
  it('조각은 인증이 필요한 데이터 소스를 두지 않는다 — 비회원 상세에서도 로그인 이동을 부르지 않는다', () => {
    const sources = ((extension as any).data_sources ?? []) as Array<{ auth_mode?: string; auth_required?: boolean }>;

    expect(sources.filter((s) => s.auth_mode === 'required' || s.auth_required === true)).toEqual([]);
  });
});
