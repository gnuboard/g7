/**
 * @file marketingRegisterCheckboxBoolean.test.ts
 * @description 회원가입 마케팅 동의 체크박스 — 표시값을 가입 폼 상태에서 boolean 으로 직접 읽는다 (#599 validate P7)
 *
 * 가입 폼 조각의 동의 체크박스 4종은 `name` 자동바인딩에 기대면서 change 액션으로도 같은 폼 키를
 * 따로 썼다. 자동바인딩은 저장값이 boolean 일 때만 checked 로 붙고(아니면 value 바인딩으로 떨어져
 * 빈 문자열이 전송된다), 전체 동의가 채널 동의를 함께 켜는 것처럼 **다른 체크박스가 쓴 값**은
 * 표시가 따라오지 않을 수 있다. 회원정보 수정 조각과 같은 규칙(autoBinding:false + checked 바인딩 +
 * change 액션)으로 맞춘다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import registerExt from '../../../extensions/user-marketing-register.json';

type Json = Record<string, any>;

function findAll(node: unknown, pred: (n: Json) => boolean, acc: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((item) => findAll(item, pred, acc));
    return acc;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (pred(obj)) acc.push(obj);
    Object.values(obj).forEach((v) => findAll(v, pred, acc));
  }
  return acc;
}

const checkboxes = findAll(registerExt, (n) => n.name === 'Checkbox');

describe('회원가입 마케팅 동의 체크박스', () => {
  /**
   * 4종 모두 자동바인딩을 끄고 checked 를 명시 바인딩하며 change 액션으로 값을 쓴다.
   *
   * @scenario case=register_consents
   * @effects marketing_register_checkbox_binds_boolean_explicitly
   */
  it('자동바인딩을 끄고 checked·change 를 명시한다', () => {
    expect(checkboxes).toHaveLength(4);

    for (const cb of checkboxes) {
      expect(cb.props.autoBinding, cb.props.name).toBe(false);
      expect(String(cb.props.checked ?? ''), cb.props.name).toMatch(/^\{\{!!_local\.registerForm\?\./);
      expect(actionsOf(cb).some((a: Json) => a.type === 'change'), cb.props.name).toBe(true);
    }
  });

  /**
   * 채널 체크박스의 표시값은 전체 동의가 켠 값(registerForm.agree_{채널})을 따라간다.
   *
   * @scenario case=channel_after_global_consent
   * @effects marketing_register_checkbox_binds_boolean_explicitly
   */
  it('채널 체크박스는 전체 동의가 켠 값을 보여 준다', () => {
    const channel = checkboxes.find((cb) => String(cb.props.name).includes('channel.key'))!;
    const expr = String(channel.props.checked).replace(/^\{\{|\}\}$/g, '');

    expect(
      dataBindingEngine.evaluateExpression(expr, {
        _local: { registerForm: { agree_marketing_consent: true, agree_email_subscription: true } },
        channel: { key: 'email_subscription' },
      } as any),
    ).toBe(true);
    expect(
      dataBindingEngine.evaluateExpression(expr, { _local: { registerForm: {} }, channel: { key: 'email_subscription' } } as any),
    ).toBe(false);
  });
});
