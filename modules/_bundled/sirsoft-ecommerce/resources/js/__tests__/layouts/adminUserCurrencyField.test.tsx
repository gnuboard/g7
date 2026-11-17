// e2e:allow 레이아웃·화면 동작 변경 없음 — 관리자 회원 폼 저장 본문(#916 의 fields_version 덧붙임)에 맞춰 정적 잠금 단언만 고친다. 저장 본문 화면 동작은 admin_basic Vitest admin-user-form-member-fields 가 잠근다
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * 관리자 회원 수정 폼의 저장 배선 잠금 테스트 (코어 저장 통합, 결정 2026-09-28)
 *
 * 폼은 코어 회원 저장 API 하나만 부르고, 결제 통화와 배송국가는 그 요청 본문에 실려 간다. 두 칸은 회원 항목(core.user)으로
 * 선언돼 있어 관리자 템플릿의 공용 조각이 「확장 항목」 구획에 그리고(이커머스의 주입 조각은 없다), 코어가 선언대로 검증해
 * 이커머스 핸들러로 저장한다. 모듈 전용 엔드포인트 호출이 폼에 남아 있으면 안 된다.
 * 칸의 선택지, 잠금, 오류 키는 PHP 테스트(`UserPreferenceFieldSetTest`, `UserPreferenceCoreSaveTest`)가 잰다.
 */
describe('admin_user_form.json — 코어 회원 저장 한 번에 통화까지 (코어 저장 통합)', () => {
  const formPath = path.resolve(
    __dirname,
    '../../../../../../../templates/_bundled/sirsoft-admin_basic/layouts/admin_user_form.json',
  );
  const formRaw = fs.readFileSync(formPath, 'utf-8');
  const form = JSON.parse(formRaw);

  /** 트리에서 apiCall 액션을 모두 모은다. */
  const collectApiCalls = (node: unknown, acc: any[] = []): any[] => {
    if (Array.isArray(node)) {
      node.forEach((n) => collectApiCalls(n, acc));
      return acc;
    }
    if (node && typeof node === 'object') {
      const obj = node as Record<string, any>;
      if (obj.handler === 'apiCall') acc.push(obj);
      Object.values(obj).forEach((v) => collectApiCalls(v, acc));
    }
    return acc;
  };

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects form_calls_only_core_save_api
   */
  it('저장 흐름에 이커머스 전용 엔드포인트 호출이 없다', () => {
    expect(formRaw).not.toContain('/api/modules/sirsoft-ecommerce/admin/users/');
    const moduleCalls = collectApiCalls(form).filter((c) => String(c.target ?? '').includes('/api/modules/'));
    expect(moduleCalls).toEqual([]);
  });

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects form_field_rides_core_save_body
   */
  it('회원 저장 요청은 폼 전체(_local.form)를 본문으로 보낸다 — 확장 항목 칸 값이 함께 실린다', () => {
    const saveCalls = collectApiCalls(form).filter((c) => String(c.target ?? '').includes('/api/admin/users'));
    expect(saveCalls.length).toBeGreaterThan(0);
    // 본문은 폼 전체이거나, 폼 전체를 바탕으로 코어 항목 집합 판(`fields_version`)만 덧붙인 것이어야 한다(#916).
    // 폼에서 칸을 골라 담는 본문으로 바뀌면 확장 항목 칸 값이 빠지므로 막는다.
    for (const call of saveCalls) {
      expect(call.params?.body).toMatch(/^\{\{(_local\.form|Object\.assign\(\{\}, _local\.form, [^}]*fields_version[\s\S]*\))\}\}$/);
    }
  });
});
