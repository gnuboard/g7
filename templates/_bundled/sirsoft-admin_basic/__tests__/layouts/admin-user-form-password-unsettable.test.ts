/**
 * @file admin-user-form-password-unsettable.test.ts
 * @description 관리자 회원 수정 — 비밀번호를 만들 수 없는 회원 안내와 저장 403 문구 (sirsoft-admin_basic, 7.2.0)
 *
 * - 비밀번호 인증 정책(회사 계정 로그인 강제, IdP 가 이 사이트 비밀번호를 허용하지 않음 등)이 비밀번호 설정을 막는 회원에게 수정 화면이
 *   비밀번호 칸과 「비밀번호 처음 설정 → 본인 확인 시작」을 보이고, 본인 확인(외부 화면 왕복)까지 마친 뒤 저장이 403 으로 거절됐다.
 *   회원 상세 응답의 `password_status.can_set=false`·`set_blocked_message` 로 칸·규칙·본인 확인 자리 대신 서버 문구를 보인다.
 * - 저장이 403 이면 「권한이 없습니다」 대신 서버가 준 문구(비밀번호 인증 정책 거절 문구 등)를 보인다 — 서버 문구가 없을 때만 공통 문구.
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import userFormLayout from '../../layouts/admin_user_form.json';

type Json = Record<string, any>;

/**
 * 트리를 평탄화합니다 (자식·슬롯·모달 포함).
 *
 * @param node 노드
 * @returns 노드 목록
 */
function flatten(node: any): Json[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  const modals = Array.isArray(node.modals) ? node.modals.flatMap(flatten) : [];
  return [node, ...children, ...slots, ...modals];
}

/**
 * 액션을 평탄화합니다 (성공·실패·코드별 처리 포함).
 *
 * @param actions 액션(들)
 * @returns 액션 목록
 */
function flattenActions(actions: any): Json[] {
  if (!actions) return [];
  return asArray<Json>(actions).flatMap((a) => [
    a,
    ...flattenActions(a.actions),
    ...flattenActions(a.onSuccess),
    ...flattenActions(a.onError),
    ...Object.values(a.errorHandling ?? {}).flatMap(flattenActions),
    ...asArray<Json>(a.conditions).flatMap((c) => [...flattenActions(c.then), ...flattenActions(c.actions)]),
  ]);
}

/**
 * `{{식}}` 을 주어진 값으로 계산합니다.
 *
 * @param expr 식 문자열
 * @param scope 계산에 쓸 값
 * @returns 계산 결과
 */
function evaluate(expr: string, scope: Json): any {
  const body = String(expr).replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
}

const nodes = flatten(userFormLayout);
const byId = (id: string): Json | undefined => nodes.find((n) => n.id === id);
const BLOCKED = '회사 계정으로 로그인하는 회원은 이 사이트 비밀번호를 만들 수 없습니다.';

/**
 * 수정 화면 계산 범위를 만듭니다.
 *
 * @param passwordStatus 회원 상세의 `password_status`
 * @param form `_local.form`
 * @param self 자기 계정 수정이면 true
 * @returns 범위
 */
function editScope(passwordStatus: Json | undefined, form: Json = {}, self = true): Json {
  return {
    route: { id: 'u-1' },
    current_user: { data: { uuid: self ? 'u-1' : 'admin-1', email_deliverable: false } },
    user: { data: { has_password: false, email_deliverable: false, password_status: passwordStatus } },
    _local: { form, errors: null },
    _global: { settings: { security: {} } },
  };
}

describe('관리자 회원 수정 — 비밀번호를 만들 수 없는 회원', () => {
  /**
   * @scenario can_set=false|true|absent, mode=edit
   * @effects admin_form_hides_password_fields_when_unsettable
   */
  it.each([
    ['막힘', false, { has_password: false, can_set: false, set_blocked_message: BLOCKED }],
    ['허용', true, { has_password: false, can_set: true, set_blocked_message: null }],
    ['판정 없음(이전 응답)', true, { has_password: false }],
  ])('%s — 비밀번호 두 칸·규칙 안내 보임=%s', (_label, visible, status) => {
    for (const id of ['field_password_group', 'password_rules_hint']) {
      const node = byId(id);
      expect(node, id).toBeDefined();
      expect(Boolean(evaluate(node?.if, editScope(status))), id).toBe(visible);
    }
  });

  /**
   * @scenario can_set=false, mode=edit|create
   * @effects admin_form_shows_server_unsettable_notice
   */
  it('막힌 회원이면 서버 문구 안내를 보이고, 추가 모드·허용 회원에게는 보이지 않는다', () => {
    const notice = byId('password_unsettable_notice');
    expect(notice).toBeDefined();
    expect(Boolean(evaluate(notice?.if, editScope({ can_set: false, set_blocked_message: BLOCKED })))).toBe(true);
    expect(Boolean(evaluate(notice?.if, editScope({ can_set: true })))).toBe(false);
    expect(Boolean(evaluate(notice?.if, { ...editScope({ can_set: false }), route: {} }))).toBe(false);

    const texts = flatten(notice).map((n) => n.text).filter(Boolean);
    expect(texts.map((t) => evaluate(t, editScope({ can_set: false, set_blocked_message: BLOCKED })))).toContain(BLOCKED);
  });

  /**
   * @scenario can_set=false, mode=edit, self=true, typed_password=yes
   * @effects admin_form_hides_reauth_when_unsettable
   */
  it('막힌 회원이면 이미 친 값이 있어도 본인 확인 자리·현재 비밀번호 칸·관리자 지정 안내를 내지 않는다', () => {
    const scope = editScope({ can_set: false, set_blocked_message: BLOCKED }, { password: 'Typed!123' });
    for (const id of ['password_reauth_verification', 'field_current_password', 'password_admin_assigned_notice']) {
      const node = byId(id);
      expect(node, id).toBeDefined();
      expect(Boolean(evaluate(node?.if, scope)), id).toBe(false);
    }
    // 허용 회원은 지금처럼 본인 확인 자리를 연다
    expect(Boolean(evaluate(byId('password_reauth_verification')?.if, editScope({ can_set: true }, { password: 'Typed!123' })))).toBe(true);
  });
});

describe('관리자 회원 수정 — 저장 403 문구', () => {
  const saves = flattenActions(nodes.flatMap((n) => asArray<Json>(n.actions)))
    .filter((a) => a.handler === 'apiCall' && String(a.target ?? '').includes('/api/admin/users'));

  /**
   * @scenario response=403, server_message=present|absent
   * @effects admin_save_403_shows_server_message
   */
  it('저장 요청 두 곳(저장 버튼·탈퇴 확인) 모두 403 이면 서버 문구를, 없을 때만 「권한이 없습니다」 를 보인다', () => {
    expect(saves.length).toBeGreaterThanOrEqual(2);
    for (const save of saves) {
      const forbidden = flattenActions(save.errorHandling?.['403']).find((a) => a.handler === 'toast');
      expect(forbidden, String(save.target)).toBeDefined();
      expect(evaluate(forbidden?.params?.message, { error: { message: BLOCKED } })).toBe(BLOCKED);
      expect(evaluate(forbidden?.params?.message, { error: {} })).toBe('$t:auth.permission_denied');
    }
  });
});
