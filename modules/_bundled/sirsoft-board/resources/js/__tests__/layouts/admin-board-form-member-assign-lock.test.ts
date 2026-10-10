/**
 * @file admin-board-form-member-assign-lock.test.ts
 * @description 게시판 생성·복사 폼 — 담당자를 지정할 수 없는 액터에게 이유를 보이고 명단 칸을 잠그는지 (7.1.0 전수 실측 O-K4-3)
 *
 * 새 게시판의 기본 ACL 로 게시판 권한을 받지 못하는 위임 관리자가 관리자·스텝을 지정하면 저장이 권한 부여
 * 상한(403)으로 거부된다. 종전 생성 폼은 그런 계정에게도 본인을 관리자로 미리 채워, 그대로 저장하면 403 이
 * 났다. 서버(form-data)는 이제 명단을 비우고 `_meta.can_assign_board_members=false` 를 주며, 화면은 이 값으로
 * 안내 상자를 보이고 두 명단 칸을 잠근다. 값이 true(지정 가능)거나 null(수정 모드)이면 종전과 같다.
 */

import { describe, it, expect } from 'vitest';
import permissionsTab from '../../../layouts/admin/partials/admin_board_form/_tab_permissions.json';
import koForm from '../../../lang/partial/ko/admin/form.json';
import enForm from '../../../lang/partial/en/admin/form.json';
import jaForm from '../../../../../../../lang-packs/_bundled/g7-module-sirsoft-board-ja/frontend/partial/admin/form.json';

/** 객체 트리에서 id 로 노드를 찾는다 */
function findById(node: unknown, id: string): any {
  if (Array.isArray(node)) {
    for (const v of node) {
      const hit = findById(v, id);
      if (hit) return hit;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    if ((node as any).id === id) return node;
    for (const v of Object.values(node)) {
      const hit = findById(v, id);
      if (hit) return hit;
    }
  }
  return null;
}

/** `{{ ... }}` 표현식을 주어진 이름들로 평가한다 */
function evaluate(raw: string, scope: Record<string, unknown>): unknown {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

/** 폼 메타 값에 따른 평가 범위 */
function scopeWith(canAssign: boolean | null | undefined, isReadOnly = false) {
  return {
    _local: { form: { _meta: canAssign === undefined ? {} : { can_assign_board_members: canAssign } } },
    _computed: { isReadOnly },
  };
}

describe('게시판 폼 권한 탭 — 담당자 지정 불가 안내와 명단 칸 잠금', () => {
  /**
   * 지정할 수 없는 액터(false)에게는 안내 상자가 보이고, 안내 문구 키를 쓴다.
   *
   * @scenario member_assign=denied
   *
   * @effects assign_denied_notice_shown
   */
  it('지정할 수 없으면 안내 상자가 보인다', () => {
    const notice = findById(permissionsTab, 'permissions_members_assign_denied');
    expect(notice, '안내 상자가 없습니다').toBeTruthy();
    expect(evaluate(notice.if, scopeWith(false))).toBe(true);
    expect(JSON.stringify(notice)).toContain('sirsoft-board.admin.form.permissions.members_assign_denied');
  });

  /**
   * 지정할 수 없는 액터(false)에게는 관리자·스텝 두 명단 칸이 잠긴다.
   *
   * @scenario member_assign=denied
   *
   * @effects member_inputs_locked_when_denied
   */
  it('지정할 수 없으면 관리자·스텝 칸이 잠긴다', () => {
    for (const id of ['permissions_managers_taginput', 'permissions_steps_taginput']) {
      const input = findById(permissionsTab, id);
      expect(input, id).toBeTruthy();
      expect(evaluate(input.props.disabled, scopeWith(false)), id).toBe(true);
    }
  });

  /**
   * 지정할 수 있거나(true) 수정 모드(null·없음)면 안내가 없고 칸도 열려 있다(읽기 전용이면 종전대로 잠김).
   *
   * @scenario member_assign=allowed
   *
   * @effects member_inputs_open_when_allowed
   */
  it('지정할 수 있거나 수정 모드면 종전과 같다', () => {
    const notice = findById(permissionsTab, 'permissions_members_assign_denied');
    for (const value of [true, null, undefined]) {
      expect(evaluate(notice.if, scopeWith(value as boolean | null | undefined))).toBe(false);
      for (const id of ['permissions_managers_taginput', 'permissions_steps_taginput']) {
        const input = findById(permissionsTab, id);
        expect(evaluate(input.props.disabled, scopeWith(value as boolean | null | undefined)), id).toBe(false);
        expect(evaluate(input.props.disabled, scopeWith(value as boolean | null | undefined, true)), id).toBe(true);
      }
    }
  });

  /**
   * 안내 문구는 「본인만 관리자로 지정할 수 있고 그대로 저장된다」를 알린다 — 새 게시판에 한해 생성자 본인 지정을
   * 허용한 뒤(K4F-2, 결정 2026-10-08) 서버는 본인 한 명을 채워 보내고, 종전 문구의 「지정할 수 없다·생성을 요청하라」
   * 는 사실과 달라졌다.
   *
   * @scenario member_assign=denied
   *
   * @effects assign_denied_notice_explains_self_only
   */
  it('안내 문구가 본인만 관리자로 둘 수 있음을 ko/en/ja 로 알린다', () => {
    const ko = (koForm as any).permissions.members_assign_denied as string;
    const en = (enForm as any).permissions.members_assign_denied as string;
    const ja = (jaForm as any).permissions.members_assign_denied as string;
    expect(ko).toContain('본인만');
    expect(ko).not.toContain('요청하세요');
    expect(en.toLowerCase()).toContain('only name yourself');
    expect(ja).toContain('本人のみ');
  });
});
