/**
 * @file adminOrderSendEmailChannelOff.test.ts
 * @description 주문 상세 「고객에게 메일 보내기」 — 메일 채널이 꺼져 있을 때의 422 안내 (#699)
 *
 * 1.3.0 부터 이 메일은 알림 정의 `order_custom_email` 로 보낸다. 메일 채널이 꺼져 있거나 준비되지 않았으면
 * 서버는 아무것도 보내지 않고 422(`messages.orders.email_channel_disabled`, `errors` 없음)로 답한다.
 * 창은 그 문구를 그대로 토스트로 보이고, 입력한 본문을 지우거나 창을 닫지 않아야 한다 — 채널을 켜고 다시
 * 보내면 되기 때문이다.
 *
 * @scenario surface=ecommerce_order_mail
 */

import { describe, it, expect } from 'vitest';
import emailModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_send_email.json';

type Json = Record<string, any>;

/** 트리 전체를 돌며 조건에 맞는 노드를 모두 모은다. */
function collect(node: unknown, match: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) {
      n.forEach(walk);
      return;
    }
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (match(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(node);
  return out;
}

/** `{{ ... }}` 바인딩 식을 주어진 문맥으로 평가한다. */
function evaluate(binding: string, ctx: Record<string, unknown>): any {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(String(binding).trim());
  expect(m, `바인딩 식이 아닙니다: ${binding}`).not.toBeNull();
  const keys = Object.keys(ctx);
  // eslint-disable-next-line @typescript-eslint/no-implied-eval, no-new-func
  const fn = new Function(...keys, `return (${m![1]});`);
  return fn(...keys.map((k) => ctx[k]));
}

const CHANNEL_OFF = '메일 채널이 꺼져 있거나 메일 설정·템플릿이 준비되지 않아 보낼 수 없습니다.';

describe('고객에게 메일 보내기 — 422 (메일 채널 꺼짐)', () => {
  const call = () => collect(emailModal, (n) => n.handler === 'apiCall' && String(n.target).includes('/send-email'))[0];

  /**
   * @effects order_mail_channel_off_422
   */
  it('서버 문구를 그대로 오류 토스트로 보인다 — errors 가 없어도 꼬리표가 붙지 않는다', () => {
    const toast = collect(call().onError, (n) => n.handler === 'toast')[0];
    expect(toast.params.type).toBe('error');
    expect(evaluate(toast.params.message, { error: { message: CHANNEL_OFF } })).toBe(CHANNEL_OFF);
    // 500(발송 예외)은 원인을 괄호로 덧붙인다 — 422 와 구분된다
    expect(evaluate(toast.params.message, { error: { message: '실패', errors: { detail: 'smtp' } } })).toBe('실패 (smtp)');
  });

  /**
   * @effects order_mail_channel_off_422
   */
  it('실패하면 전송 중 표시만 풀고, 쓴 본문을 비우거나 창을 닫지 않는다', () => {
    const onError = call().onError as Json[];
    const reset = collect(onError, (n) => n.handler === 'setState')[0];
    expect(reset.params.target).toBe('$parent._local');
    expect(reset.params.isSending).toBe(false);
    expect('emailMessage' in reset.params).toBe(false);
    expect(collect(onError, (n) => n.handler === 'closeModal')).toEqual([]);
  });
});
