/**
 * ActionDispatcher 동적 핸들러 이름 해석 테스트
 *
 * 액션 `handler` 가 `{{...}}` 바인딩이면 executeAction 이 컨텍스트로 먼저 해석한 뒤
 * 라우팅하는지 검증. 백엔드 응답이 호출할 핸들러 풀네임을 내려주는 provider-agnostic
 * 디스패치(결제 진입 `handler: "{{response.data.pg_payment_handler}}"`)의 회귀 잠금.
 *
 * 검증 축:
 *  (a) `{{response.data.pg_payment_handler}}` 해석 → 등록 핸들러 호출 (+ params 전달)
 *  (b) 빌트인(navigate 등)·리터럴 커스텀 핸들러는 `{{` 미포함 → 해석 분기 미진입
 *  (c) nested(conditions/sequence) 컨텍스트에서도 동일 적용
 *  (d) 프리뷰 모드: 해석 결과가 PREVIEW_SUPPRESSED_HANDLERS 면 억제 (해석 후 판정)
 *  (e) 프리뷰 모드: 해석 결과가 미등록 핸들러면 graceful skip (throw 안 함)
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

// AuthManager mock
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn().mockResolvedValue({ id: 1 }),
      logout: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

// ApiClient mock
vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

describe('ActionDispatcher - 동적 핸들러 이름 해석', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    mockNavigate = vi.fn();
    dispatcher = new ActionDispatcher({ navigate: mockNavigate });
    Logger.getInstance().setDebug(false);
  });

  const baseContext = () => ({ data: {}, state: {}, setState: vi.fn() });

  describe('(a) 응답 바인딩 핸들러명 해석', () => {
    it('handler 가 `{{response.data.pg_payment_handler}}` 면 응답값이 가리키는 등록 핸들러를 호출', async () => {
      const paymentHandler = vi.fn().mockResolvedValue({ ok: true });
      dispatcher.registerHandler('sirsoft-pay_kginicis.requestPayment', paymentHandler);

      const action: ActionDefinition = {
        type: 'click',
        handler: '{{response.data.pg_payment_handler}}',
        params: { pgPaymentData: '{{response.data.pg_payment_data}}' },
      };

      const context = {
        ...baseContext(),
        data: {
          response: {
            data: {
              pg_payment_handler: 'sirsoft-pay_kginicis.requestPayment',
              pg_payment_data: { order_number: 'ORD-1', amount: 1000 },
            },
          },
        },
      };

      await dispatcher.executeAction(action, context);

      expect(paymentHandler).toHaveBeenCalledTimes(1);
      // params 의 pgPaymentData 도 응답값으로 해석되어 전달되었는지 (resolveParams)
      const callArg = paymentHandler.mock.calls[0][0];
      expect(callArg?.params?.pgPaymentData).toEqual({ order_number: 'ORD-1', amount: 1000 });
    });

    it('provider 가 다른 핸들러명을 내려주면 그 핸들러로 라우팅 (provider-agnostic)', async () => {
      const handlerA = vi.fn().mockResolvedValue({ ok: 'A' });
      const handlerB = vi.fn().mockResolvedValue({ ok: 'B' });
      dispatcher.registerHandler('vendor-a.pay', handlerA);
      dispatcher.registerHandler('vendor-b.checkout', handlerB);

      const action: ActionDefinition = {
        type: 'click',
        handler: '{{response.data.pg_payment_handler}}',
      };

      await dispatcher.executeAction(action, {
        ...baseContext(),
        data: { response: { data: { pg_payment_handler: 'vendor-b.checkout' } } },
      });

      expect(handlerA).not.toHaveBeenCalled();
      expect(handlerB).toHaveBeenCalledTimes(1);
    });
  });

  describe('(b) 리터럴 핸들러는 해석 분기 미진입', () => {
    it('빌트인 navigate 는 `{{` 미포함 → 그대로 라우팅', async () => {
      const contextNavigate = vi.fn();
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        target: '/admin/dashboard',
      };

      await dispatcher.executeAction(action, { ...baseContext(), navigate: contextNavigate });
      expect(contextNavigate).toHaveBeenCalledWith('/admin/dashboard', { replace: false });
    });

    it('리터럴 커스텀 핸들러명(dotted)도 그대로 호출 — `{{` 없으면 바인딩 평가 안 함', async () => {
      const handler = vi.fn().mockResolvedValue({ ok: true });
      dispatcher.registerHandler('sirsoft-pay_kginicis.requestPayment', handler);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'sirsoft-pay_kginicis.requestPayment',
      };

      await dispatcher.executeAction(action, baseContext());
      expect(handler).toHaveBeenCalledTimes(1);
    });
  });

  describe('(c) nested(conditions/sequence) 컨텍스트 적용', () => {
    it('conditions then 분기의 동적 핸들러도 해석되어 호출', async () => {
      const paymentHandler = vi.fn().mockResolvedValue({ ok: true });
      dispatcher.registerHandler('sirsoft-pay_kginicis.requestPayment', paymentHandler);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'conditions',
        conditions: [
          {
            if: '{{response.data.requires_pg_payment && response.data.pg_payment_handler}}',
            then: {
              handler: '{{response.data.pg_payment_handler}}',
              params: { pgPaymentData: '{{response.data.pg_payment_data}}' },
            },
          },
        ],
      };

      await dispatcher.executeAction(action, {
        ...baseContext(),
        data: {
          response: {
            data: {
              requires_pg_payment: true,
              pg_payment_handler: 'sirsoft-pay_kginicis.requestPayment',
              pg_payment_data: { order_number: 'ORD-2' },
            },
          },
        },
      });

      expect(paymentHandler).toHaveBeenCalledTimes(1);
    });

    it('sequence 의 동적 핸들러도 해석되어 호출', async () => {
      const paymentHandler = vi.fn().mockResolvedValue({ ok: true });
      dispatcher.registerHandler('vendor-x.pay', paymentHandler);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'sequence',
        params: {
          actions: [{ handler: '{{response.data.pg_payment_handler}}' }],
        },
      };

      await dispatcher.executeAction(action, {
        ...baseContext(),
        data: { response: { data: { pg_payment_handler: 'vendor-x.pay' } } },
      });

      expect(paymentHandler).toHaveBeenCalledTimes(1);
    });
  });

  describe('(d) 프리뷰 모드 — 해석 후 억제 판정', () => {
    it('동적 핸들러가 navigate 로 해석되면 프리뷰 모드에서 억제 (해석 후 PREVIEW_SUPPRESSED 판정)', async () => {
      const contextNavigate = vi.fn();
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: '{{response.data.handler_name}}',
        target: '/somewhere',
      };

      await dispatcher.executeAction(action, {
        ...baseContext(),
        navigate: contextNavigate,
        data: { response: { data: { handler_name: 'navigate' } } },
      });

      // 해석 결과 navigate 가 억제 집합에 있으므로 실행 안 됨
      expect(contextNavigate).not.toHaveBeenCalled();
    });
  });

  describe('(e) 프리뷰 모드 — 미등록 해석 결과 graceful skip', () => {
    it('해석 결과가 미등록 핸들러면 throw 하지 않고 success 반환', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: '{{response.data.pg_payment_handler}}',
      };

      const result = await dispatcher.executeAction(action, {
        ...baseContext(),
        data: { response: { data: { pg_payment_handler: 'vendor-unregistered.pay' } } },
      });

      expect(result?.success).toBeTruthy();
    });
  });
});
