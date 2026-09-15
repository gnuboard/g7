/**
 * @file admin-user-list-bulk-status-result-feedback.test.tsx
 * @description 일괄 탈퇴 결과 안내 검증 (공개이슈 #112 후속)
 *
 * 일괄 탈퇴는 탈퇴 불가 대상(관리자 계정 등)이 섞여 있어도 200 으로 끝난다 —
 * 서버는 건별 실패를 failed_count / failed_reasons 로 보고한다. 화면이 그 값을
 * 읽지 않고 성공 안내만 띄우면, 아무 일도 일어나지 않았는데 처리된 것으로 보인다.
 *
 * @scenario entry_point=admin_bulk_status, target_account=admin, confirm_action=submit
 * @effects bulk_status_reports_failed_count, bulk_status_result_is_reflected_in_feedback
 */

import { describe, it, expect } from 'vitest';
import userListLayout from '../../layouts/admin_user_list.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

type Json = Record<string, any>;

/** 일괄 탈퇴 apiCall 노드를 찾는다. */
function findWithdrawBulkCall(node: unknown): Json | undefined {
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
        found = obj;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };

  walk(node);

  return found;
}

describe('회원 목록 일괄 탈퇴 — 결과 안내', () => {
  const call = findWithdrawBulkCall(userListLayout);

  it('일괄 탈퇴 요청 노드가 존재한다', () => {
    expect(call).toBeDefined();
  });

  it('성공 후속 안내가 failed_count 를 판정에 사용한다', () => {
    const toast = (call!.onSuccess ?? []).find((a: Json) => a.handler === 'toast');

    expect(toast, '성공 후속에 안내 액션이 없습니다.').toBeDefined();

    const serialized = JSON.stringify(toast);

    // 무조건 성공 안내로 되돌아가면(회귀) 이 단언이 깨진다
    expect(serialized).toContain('failed_count');
    expect(serialized).toContain('updated_count');
  });

  it('전건 실패 / 부분 실패 안내 문구가 ko·en 에 정의되어 있다', () => {
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      expect(dict.users.modals.bulk_withdraw_all_failed).toBeTruthy();
      expect(dict.users.modals.bulk_withdraw_partial).toBeTruthy();
    }
  });

  it('안내 문구가 실패 사유 자리를 갖는다', () => {
    // 사유가 빠지면 운영자는 "몇 건 실패" 까지만 알고 원인을 알 수 없다
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      expect(String(dict.users.modals.bulk_withdraw_all_failed)).toContain('{reason}');
      expect(String(dict.users.modals.bulk_withdraw_partial)).toContain('{reason}');
    }
  });
});
