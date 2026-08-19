/**
 * @file admin-user-list-bulk-withdraw-feedback-expression.test.ts
 * @description 일괄 탈퇴 결과 안내 표현식이 실제로 평가되는지 검증 (공개이슈 #112 후속)
 *
 * 구조 단언(노드에 failed_count 가 들어 있다)만으로는 표현식이 런타임에 평가된다는
 * 보장이 없다 — 오타 하나면 undefined 가 되어 안내가 통째로 비고, 예외도 남지 않는다.
 * 레이아웃에 실제로 적힌 문자열을 엔진에 그대로 넣어 세 갈래를 모두 확인한다.
 *
 * @scenario entry_point=admin_bulk_status, target_account=admin, confirm_action=submit
 * @effects bulk_status_result_is_reflected_in_feedback
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import userListLayout from '../../layouts/admin_user_list.json';

type Json = Record<string, any>;

/** 일괄 탈퇴 성공 후속의 안내 액션을 레이아웃에서 찾는다. */
function findWithdrawToast(): Json {
  let found: Json | undefined;

  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) {
      n.forEach(walk);
      return;
    }
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (
        obj.handler === 'apiCall' &&
        String(obj.target ?? '').includes('bulk-status') &&
        obj.params?.body?.status === 'withdrawn'
      ) {
        found = (obj.onSuccess ?? []).find((a: Json) => a.handler === 'toast');
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };

  walk(userListLayout);

  if (!found) throw new Error('일괄 탈퇴 안내 액션을 찾지 못했습니다.');

  return found;
}

const toast = findWithdrawToast();

/**
 * 레이아웃에 적힌 표현식을 주어진 응답으로 평가한다.
 *
 * 하네스에는 번역 사전이 없어 엔진이 `$t:` 접두사를 벗기고 키만 돌려준다 —
 * 여기서 보는 것은 번역 결과가 아니라 **어느 키·파라미터가 선택되는가** 이므로
 * 접두사 유무는 판정에서 제외한다.
 */
function evaluate(expression: string, data: Json): string {
  const resolved = String(dataBindingEngine.resolveBindings(expression, { response: { data } }));

  return resolved.replace(/^\$t:/, '');
}

describe('일괄 탈퇴 결과 안내 표현식', () => {
  it('전건 성공이면 성공 안내', () => {
    const context = { updated_count: 3, failed_count: 0, failed_reasons: [] };

    expect(evaluate(toast.params.type, context)).toBe('success');
    expect(evaluate(toast.params.message, context)).toBe('admin.users.modals.bulk_withdraw_success');
  });

  it('전건 실패면 오류 안내에 사유가 실린다', () => {
    const context = {
      updated_count: 0,
      failed_count: 1,
      failed_reasons: ['관리자 계정은 탈퇴할 수 없습니다.'],
    };

    expect(evaluate(toast.params.type, context)).toBe('error');

    const message = evaluate(toast.params.message, context);

    expect(message).toContain('bulk_withdraw_all_failed');
    expect(message).toContain('관리자 계정은 탈퇴할 수 없습니다.');
  });

  it('부분 실패면 경고 안내에 처리/미처리 인원이 실린다', () => {
    const context = {
      updated_count: 2,
      failed_count: 1,
      failed_reasons: ['관리자 계정은 탈퇴할 수 없습니다.'],
    };

    expect(evaluate(toast.params.type, context)).toBe('warning');

    const message = evaluate(toast.params.message, context);

    expect(message).toContain('bulk_withdraw_partial');
    expect(message).toContain('updated=2');
    expect(message).toContain('failed=1');
    expect(message).toContain('관리자 계정은 탈퇴할 수 없습니다.');
  });

  it('응답에 필드가 없어도 성공 안내로 떨어진다 (구버전 서버 하위호환)', () => {
    expect(evaluate(toast.params.type, {})).toBe('success');
  });
});
