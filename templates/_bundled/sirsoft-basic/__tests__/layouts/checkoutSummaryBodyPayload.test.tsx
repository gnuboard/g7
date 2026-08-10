/**
 * @file checkoutSummaryBodyPayload.test.tsx
 * @description 주문 생성 POST body 의 확장 병합 칸(checkoutExtraPayload) 계약 검증.
 *
 * 배경 (#454 S3):
 *   슬롯에 주입된 모듈 폼이 _local 에 값을 써도, body 가 템플릿 소유 리터럴 객체이면
 *   그 값은 서버에 도달하지 않는다. 모듈은 body 줄을 추가할 수단이 없다
 *   (결제 버튼 id 부재 → overlay 불가 / inject_props 는 actions[].params.body 미도달).
 *   → body 를 통짜 표현식으로 두고 확장 병합 칸 1개를 연다.
 *
 * 평가 경로 변화:
 *   전환 전 = ActionDispatcher.resolveParams 가 body 를 재귀하며 키마다 evaluateExpression
 *   전환 후 = body 가 단일 문자열 → evaluateExpression 1회
 *   → 두 경로의 산출값 동등성을 고정한다 (특히 dbank 삼항 · guest_lookup_password null 분기 ·
 *     expected_total_amount 숫자형 · shipping_memo custom 분기).
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import checkoutSummaryJson from '../../layouts/partials/shop/_checkout_summary.json';
import checkoutPaymentJson from '../../layouts/partials/shop/_checkout_payment.json';
import checkoutJson from '../../layouts/shop/checkout.json';

/** 객체 트리에서 조건을 만족하는 첫 노드를 깊이우선 탐색 */
function findNode(node: any, predicate: (n: any) => boolean): any {
  if (node == null || typeof node !== 'object') return undefined;
  if (predicate(node)) return node;
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        const found = findNode(item, predicate);
        if (found !== undefined) return found;
      }
    } else if (value && typeof value === 'object') {
      const found = findNode(value, predicate);
      if (found !== undefined) return found;
    }
  }
  return undefined;
}

/** 주문 생성 POST apiCall 노드 */
const orderCall = findNode(
  checkoutSummaryJson,
  (n) =>
    n.handler === 'apiCall' &&
    typeof n.target === 'string' &&
    n.target.includes('/user/orders') &&
    n.params?.method === 'POST'
);

const engine = new DataBindingEngine();

/** body 가 통짜 표현식일 때 실제 런타임 평가 (resolveParams 의 문자열 분기와 동일) */
function evalBody(ctx: Record<string, any>): Record<string, any> {
  const raw: string = orderCall.params.body;
  return engine.evaluateExpression(raw.slice(2, -2), ctx);
}

/** 전환 이전 동작을 재현하는 기준 payload — 이 테스트가 진실의 기준(SSoT)이다. */
function expectedLegacy(ctx: any): Record<string, any> {
  const { checkoutData, _computed, _local, _global } = ctx;
  return {
    temp_order_id: checkoutData?.data?.temp_order_id,
    orderer: _computed?.ordererDefaults,
    shipping: _local?.shipping,
    payment_method: _computed?.selectedCorePaymentMethod,
    shipping_memo:
      _local?.shippingMemo === 'custom' ? _local?.shippingMemoCustom : _local?.shippingMemo,
    depositor_name: _local?.depositorName ?? _computed?.ordererDefaults?.name ?? '',
    dbank:
      _computed?.selectedCorePaymentMethod === 'dbank'
        ? {
            bank_code: _local?.selectedDbank?.bank_code,
            account_number: _local?.selectedDbank?.account_number,
            account_holder: _local?.selectedDbank?.account_holder,
          }
        : null,
    expected_total_amount: checkoutData?.data?.calculation?.summary?.final_amount ?? 0,
    save_shipping_address: _local?.saveShippingAddress ?? false,
    guest_lookup_password: _global?.currentUser?.uuid ? null : (_local?.guestLookupPassword ?? ''),
    guest_lookup_password_confirmation: _global?.currentUser?.uuid
      ? null
      : (_local?.guestLookupPasswordConfirmation ?? ''),
    refund_bank: _local?.refundBankCode
      ? {
          bank_code: _local?.refundBankCode,
          account_number: _local?.refundBankAccount ?? null,
          holder: _local?.refundBankHolder ?? null,
        }
      : null,
  };
}

const LEGACY_KEYS = Object.keys(expectedLegacy({}));

const baseCtx = (over: Record<string, any> = {}) => ({
  checkoutData: {
    data: {
      temp_order_id: 'TMP-1',
      calculation: { summary: { final_amount: 53000 } },
    },
  },
  _computed: {
    ordererDefaults: { name: '홍길동', phone: '01012345678', email: 'a@b.c' },
    selectedPaymentMethod: 'dbank',
    selectedCorePaymentMethod: 'dbank',
  },
  _local: {
    shipping: { recipient_name: '홍길동' },
    shippingMemo: 'custom',
    shippingMemoCustom: '문 앞에 두세요',
    selectedDbank: { bank_code: '004', account_number: '110-123', account_holder: '시르소프트' },
    saveShippingAddress: true,
    guestLookupPassword: 'pw12345678',
    guestLookupPasswordConfirmation: 'pw12345678',
  },
  _global: { currentUser: null },
  ...over,
});

describe('주문 생성 body — 확장 병합 칸 계약', () => {
  it('주문 생성 POST apiCall 노드가 존재해야 한다', () => {
    expect(orderCall, '주문 생성 POST apiCall 노드').toBeDefined();
  });

  it('body 는 통짜 표현식이어야 한다 (확장 칸 spread 를 담기 위함)', () => {
    expect(typeof orderCall.params.body).toBe('string');
    expect(orderCall.params.body).toContain('checkoutExtraPayload');
  });

  describe('전환 동등성 — 기존 키 산출값 보존', () => {
    const cases: Array<[string, Record<string, any>]> = [
      ['dbank + 비회원 (기본)', baseCtx()],
      [
        'card + 회원 — dbank=null · guest 비밀번호 null 분기',
        baseCtx({
          _computed: {
            ordererDefaults: { name: '김철수', phone: '', email: '' },
            selectedPaymentMethod: 'card',
            selectedCorePaymentMethod: 'card',
          },
          _global: { currentUser: { uuid: 'user-uuid-1' } },
        }),
      ],
      [
        'shipping_memo 비-custom 분기',
        baseCtx({ _local: { ...baseCtx()._local, shippingMemo: 'door', shippingMemoCustom: '무시됨' } }),
      ],
      [
        'depositor_name 미입력 → ordererDefaults.name 폴백',
        baseCtx({ _local: { ...baseCtx()._local, depositorName: undefined } }),
      ],
      [
        '초기 진입 (빈 _local · calculation 없음)',
        {
          checkoutData: { data: { temp_order_id: null, calculation: null } },
          _computed: { ordererDefaults: undefined, selectedPaymentMethod: 'vbank', selectedCorePaymentMethod: 'vbank' },
          _local: {},
          _global: {},
        },
      ],
    ];

    for (const [name, ctx] of cases) {
      it(`[${name}] 통짜 평가 결과가 전환 전 산출값과 같다`, () => {
        expect(evalBody(ctx)).toEqual(expectedLegacy(ctx));
      });
    }

    it('expected_total_amount 는 숫자형을 보존한다 (문자열 보간 금지)', () => {
      expect(typeof evalBody(baseCtx()).expected_total_amount).toBe('number');
    });
  });

  describe('확장 병합 칸 (checkoutExtraPayload)', () => {
    it('모듈 비활성(칸 미주입) 시 payload 가 전환 전과 키·값 모두 동일하다', () => {
      const ctx = baseCtx();
      const body = evalBody(ctx);
      expect(Object.keys(body).sort()).toEqual(LEGACY_KEYS.sort());
      expect(body).toEqual(expectedLegacy(ctx));
    });

    it('칸에 기입한 키가 payload 에 편입되고 기존 키는 불변이다', () => {
      const ctx = baseCtx();
      (ctx._local as Record<string, any>).checkoutExtraPayload = {
        cash_receipt_requested: true,
        cash_receipt_type: 'income',
        cash_receipt_identifier_type: 'phone',
        cash_receipt_identifier: '01012345678',
      };

      const body = evalBody(ctx);
      expect(body.cash_receipt_requested).toBe(true);
      expect(body.cash_receipt_type).toBe('income');
      expect(body.cash_receipt_identifier_type).toBe('phone');
      expect(body.cash_receipt_identifier).toBe('01012345678');

      for (const key of LEGACY_KEYS) {
        expect(body[key]).toEqual(expectedLegacy(ctx)[key]);
      }
    });

    it('확장 칸은 기존 키를 덮어쓸 수 없어야 한다 — spread 가 뒤에 오므로 덮어쓰기가 가능함을 명시 고정', () => {
      const ctx = baseCtx();
      (ctx._local as Record<string, any>).checkoutExtraPayload = { payment_method: 'HIJACKED' };
      // 현재 계약: 확장 칸이 뒤에 spread 되므로 덮어쓴다.
      // 이 동작을 '알고 있는 것'으로 고정한다 — 모듈은 자기 네임스페이스 키만 써야 한다.
      expect(evalBody(ctx).payment_method).toBe('HIJACKED');
    });
  });

  describe('환불계좌 (템플릿 직접 소유 — 확장 칸 미경유)', () => {
    it('미입력 시 refund_bank 는 null 이다', () => {
      expect(evalBody(baseCtx()).refund_bank).toBeNull();
    });

    it('입력 시 3필드가 그대로 실린다', () => {
      const ctx = baseCtx({
        _local: {
          ...baseCtx()._local,
          refundBankCode: '004',
          refundBankAccount: '110-123-456789',
          refundBankHolder: '홍길동',
        },
      });
      expect(evalBody(ctx).refund_bank).toEqual({
        bank_code: '004',
        account_number: '110-123-456789',
        holder: '홍길동',
      });
    });
  });

  // 결제수단 전환 시 이전 수단이 소유하던 입력값이 남아 payload 에 실리던 결함의 회귀 가드.
  // 확장 슬롯(현금영수증)과 환불계좌 블록은 결제수단 조건부로 언마운트되지만 _local 값은 남는다.
  // 무통장에서 입력 → 카드로 전환 시 그 값이 그대로 주문 생성 POST 에 실렸다.
  describe('결제수단 전환 — 이전 수단 소유 상태 초기화', () => {
    it('결제수단 선택 액션이 확장 칸과 환불계좌를 함께 비운다', () => {
      const methodSelect = findNode(
        checkoutPaymentJson,
        (n: any) =>
          Array.isArray(n?.actions) &&
          n.actions.some(
            (a: any) =>
              a?.type === 'click' &&
              JSON.stringify(a).includes('_local.paymentMethod'),
          ),
      );
      expect(methodSelect, '결제수단 선택 액션 노드를 찾지 못했다').toBeDefined();

      const clickAction = methodSelect.actions.find((a: any) => a?.type === 'click');
      const inner = clickAction?.params?.actions ?? [];
      const targets = inner.map((a: any) => a?.params?.target);

      // 결제수단 자체는 설정하고
      expect(targets).toContain('_local.paymentMethod');
      // 이전 수단이 소유하던 상태는 비운다
      expect(targets).toContain('_local.checkoutExtraPayload');
      expect(targets).toContain('_local.refundBankCode');
      expect(targets).toContain('_local.refundBankAccount');
      expect(targets).toContain('_local.refundBankHolder');
    });

    it('초기화된 상태로 조립한 payload 에는 이전 수단의 값이 남지 않는다', () => {
      // 무통장에서 현금영수증·환불계좌를 입력한 뒤 카드로 전환된 직후의 _local 상태
      const ctx = baseCtx({
        _local: {
          ...baseCtx()._local,
          paymentMethod: 'card',
          checkoutExtraPayload: {},
          refundBankCode: '',
          refundBankAccount: '',
          refundBankHolder: '',
        },
      });

      const body = evalBody(ctx);

      expect(body.refund_bank).toBeNull();
      expect(body.cash_receipt_requested).toBeUndefined();
      expect(body.cash_receipt_identifier).toBeUndefined();
    });
  });

  // ─────────────────────────────────────────────────────────────
  // #454 — 플러그인 결제수단(toss_*)의 코어 결제수단 전송
  //
  // 유저 표면에서는 "토스 가상계좌"(toss_virtual_account) 라는 독립 결제수단이지만,
  // 주문 레코드에는 코어 PaymentMethodEnum 값(vbank)으로 저장되어야 한다. 코어 enum 은
  // toss_* 를 거부하므로(422), body 의 payment_method 는 선택 결제수단의 core_payment_method
  // 로 번역해 전송한다. toss_* 선택값 자체는 _local.paymentMethod 에 남아 SDK 호출 시 참조된다.
  // ─────────────────────────────────────────────────────────────
  describe('플러그인 결제수단 → 코어 결제수단 전송 (#454)', () => {
    it('body 의 payment_method 는 _computed.selectedCorePaymentMethod 를 사용한다', () => {
      const ctx = baseCtx({
        _computed: {
          ordererDefaults: { name: '홍길동', phone: '01012345678', email: 'a@b.c' },
          selectedPaymentMethod: 'toss_virtual_account',
          selectedCorePaymentMethod: 'vbank',
        },
      });
      // toss_virtual_account 를 골랐어도 서버에는 코어 값 vbank 가 나가야 한다.
      expect(evalBody(ctx).payment_method).toBe('vbank');
    });

    it('코어 매핑이 없는 결제수단은 raw id 를 그대로 전송한다 (dbank·KG 무영향)', () => {
      const ctx = baseCtx({
        _computed: {
          ordererDefaults: { name: '홍길동', phone: '01012345678', email: 'a@b.c' },
          selectedPaymentMethod: 'dbank',
          selectedCorePaymentMethod: 'dbank',
        },
      });
      expect(evalBody(ctx).payment_method).toBe('dbank');
    });

    it('dbank 분기(계좌 정보)는 코어 값 기준으로 판정된다', () => {
      const ctx = baseCtx({
        _computed: {
          ordererDefaults: { name: '홍길동', phone: '01012345678', email: 'a@b.c' },
          selectedPaymentMethod: 'dbank',
          selectedCorePaymentMethod: 'dbank',
        },
      });
      expect(evalBody(ctx).dbank).toEqual({
        bank_code: '004',
        account_number: '110-123',
        account_holder: '시르소프트',
      });
    });

    it('토스 결제수단 선택 시 dbank 분기는 null (계좌 정보 미포함)', () => {
      const ctx = baseCtx({
        _computed: {
          ordererDefaults: { name: '홍길동', phone: '01012345678', email: 'a@b.c' },
          selectedPaymentMethod: 'toss_virtual_account',
          selectedCorePaymentMethod: 'vbank',
        },
      });
      expect(evalBody(ctx).dbank).toBeNull();
    });
  });

  // selectedCorePaymentMethod computed 자체를 checkout.json 원문에서 평가한다.
  // body 테스트는 이 값을 주입받아 검증하므로, computed 가 실제로 카탈로그의
  // core_payment_method 를 해석하는지는 여기서 고정한다.
  describe('selectedCorePaymentMethod computed (checkout.json 원문 평가)', () => {
    const coreExpr: string = (checkoutJson as any).computed.selectedCorePaymentMethod;

    /** 모듈이 병합 시 보존하는 결제수단 카탈로그 (core_payment_method 포함) */
    const catalogCtx = (selected?: string) => ({
      _local: selected ? { paymentMethod: selected } : {},
      paymentSettings: {
        data: {
          order_settings: {
            payment_methods: [
              { id: 'dbank', is_active: true },
              { id: 'toss_card', is_active: true, core_payment_method: 'card' },
              { id: 'toss_virtual_account', is_active: true, core_payment_method: 'vbank' },
              { id: 'toss_transfer', is_active: true, core_payment_method: 'bank' },
              // KG 는 core_payment_method 미선언 (인터셉터 방식) — raw id 폴백 대상
              { id: 'kginicis_japan_paypay', is_active: true },
            ],
          },
        },
      },
    });

    const evalCore = (ctx: Record<string, any>) =>
      engine.evaluateExpression(coreExpr.slice(2, -2), ctx);

    it.each([
      ['toss_virtual_account', 'vbank'],
      ['toss_transfer', 'bank'],
      ['toss_card', 'card'],
    ])('%s → 코어 %s 로 번역된다', (selected, expected) => {
      expect(evalCore(catalogCtx(selected))).toBe(expected);
    });

    it('dbank(코어 매핑 미선언)는 raw id 그대로', () => {
      expect(evalCore(catalogCtx('dbank'))).toBe('dbank');
    });

    it('KG 결제수단(코어 매핑 미선언)은 raw id 그대로 — 인터셉터 방식 무영향', () => {
      expect(evalCore(catalogCtx('kginicis_japan_paypay'))).toBe('kginicis_japan_paypay');
    });

    it('미선택 시 첫 활성 결제수단으로 폴백한다', () => {
      expect(evalCore(catalogCtx())).toBe('dbank');
    });

    it('카탈로그가 비어도 dbank 로 폴백한다 (초기 진입)', () => {
      expect(evalCore({ _local: {}, paymentSettings: undefined })).toBe('dbank');
    });
  });
});
