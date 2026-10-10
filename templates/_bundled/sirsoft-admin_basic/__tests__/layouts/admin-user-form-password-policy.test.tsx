/**
 * @file admin-user-form-password-policy.test.tsx
 * @description 관리자 회원 등록·수정 화면의 비밀번호 정책 칸 구조 회귀 테스트 (#736 D11·D19)
 *
 * - 본인 계정의 새 비밀번호를 입력할 때만 「현재 비밀번호」를 묻는다(D19 — 서버도 같은 확인을 한다).
 * - 다른 회원의 비밀번호를 정하면(등록 포함) 그 회원이 다음 로그인 때 바꿔야 한다는 안내를 띄운다(D11).
 * - 켜진 규칙을 한 줄로 안내하되 재사용 금지는 본인일 때만(다른 회원에게 정해 주는 비밀번호는 재사용 검사를 하지 않는다).
 * - 「비밀번호 만료」 개별 설정은 상세와 같은 「비밀번호」 구획에 서고(#599) 등록 기본값은 「따름」이다.
 *
 * @since engine-v1.77.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import form from '../../layouts/admin_user_form.json';
import enAuth from '../../lang/partial/en/auth.json';
import koAuth from '../../lang/partial/ko/auth.json';

function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  return [node, ...children];
}

const nodes = flatten((form as any).slots?.content ?? (form as any).components);
const find = (id: string) => nodes.find((n) => n.id === id);
const SELF = '!!route?.id && route?.id === current_user?.data?.uuid';

describe('관리자 회원 폼 — 비밀번호 정책 (#736)', () => {
  /**
   * @scenario actor=self, password=entered
   * @effects self_change_asks_current_password
   */
  it('현재 비밀번호 칸은 본인 계정 + 새 비밀번호 입력일 때만 보이고 저장에 실린다', () => {
    const field = find('field_current_password');
    expect(field?.if).toBe(`{{${SELF} && !!_local.form?.password}}`);
    const input = flatten(field).find((n) => n.name === 'Input');
    expect(input?.props?.name).toBe('current_password');
    expect(input?.props?.type).toBe('password');
  });

  /**
   * @scenario actor=other, password=entered
   * @effects other_member_assignment_notice
   */
  it('다른 회원(등록 포함)의 비밀번호를 정하면 다음 로그인 때 바꿔야 한다고 안내한다', () => {
    const notice = find('password_admin_assigned_notice');
    expect(notice?.if).toBe(`{{!!_local.form?.password && !(${SELF})}}`);
    expect(JSON.stringify(notice)).toContain('password_policy.admin_assigned_notice');
    expect(JSON.stringify(notice)).toContain('password_policy.create_notice');
  });

  /**
   * @scenario actor=other, password=entered
   * @effects rules_hint_reflects_settings
   */
  it('규칙 안내 줄은 보안 설정 값을 읽고, 재사용 금지는 본인일 때만 붙인다', () => {
    const hint = find('password_rules_hint');
    const json = JSON.stringify(hint);
    expect(json).toContain('_global.settings?.security?.password_min_length');
    expect(json).not.toContain('count=8');
    const reuse = flatten(hint).find((n) => String(n.if).includes('password_reuse_prevention_enabled'));
    expect(String(reuse?.if)).toContain(SELF);
  });

  /**
   * 규칙 안내 줄은 운영자가 다른 회원의 비밀번호를 정하는 화면에도 뜬다. 「your user ID」처럼 읽는 사람 기준으로 쓰면
   * 운영자 자신의 아이디를 넣지 말라는 뜻으로 읽힌다(7.1.0 실측 O-7) — 대상과 무관한 문장이어야 한다.
   *
   * @scenario actor=other, password=entered
   * @effects rules_hint_wording_fits_admin_assignment
   */
  it('아이디 포함 금지 안내는 본인·다른 회원 어느 쪽에도 맞는 문장이다', () => {
    const hint = find('password_rules_hint');
    expect(JSON.stringify(hint)).toContain('auth.password_rules.identifier');

    const en = (enAuth as any).password_rules.identifier as string;
    const ko = (koAuth as any).password_rules.identifier as string;
    expect(en).not.toMatch(/\byour\b/i);
    expect(en).toMatch(/user ID/);
    expect(ko).toContain('아이디(이메일 앞부분)');
    expect(ko).not.toMatch(/본인|내 아이디/);
  });

  /**
   * @scenario actor=other, password=empty
   * @effects expiry_policy_select_in_password_section
   */
  it('「비밀번호 만료」 선택은 비밀번호 구획에 있고 저장 폼에 담기며 등록 기본값은 따름이다', () => {
    const section = find('section_password_info');
    const select = flatten(section).find((n) => n.name === 'Select' && n.props?.name === 'password_expiry_policy');
    expect(select).toBeDefined();
    expect(JSON.stringify(select?.props?.options)).toContain('inherit');
    expect(JSON.stringify(select?.props?.options)).toContain('exempt');
    const init = asArray<any>((form as any).init_actions);
    expect(init.some((a) => a.handler === 'setState' && a.params?.['form.password_expiry_policy'] === 'inherit')).toBe(true);
  });

  /**
   * 수정 화면끼리 SPA 로 옮기면 같은 레이아웃이라 `_local.form` 이 남고, 데이터 소스(`initLocal="form"`)는 회원 기록만
   * 덮어써 비밀번호 칸은 지우지 않는다 — A 회원에 입력하다 만 비밀번호가 B 회원 폼에 남아 그대로 저장되면 B 의 비밀번호가
   * 바뀌고 다음 로그인 때 변경을 요구받는다(#736 S2 브라우저 실측). 수정 모드 진입마다 세 칸을 비운다.
   *
   * @scenario actor=other, password=entered
   * @effects edit_navigation_clears_unsaved_password
   */
  it('수정 화면 진입 때 직전 회원 폼에 남은 비밀번호·확인·현재 비밀번호를 비운다', () => {
    const init = asArray<any>((form as any).init_actions);
    const clear = init.find(
      (a) => a.handler === 'setState' && a.if === '{{!!route?.id}}' && 'form.password' in (a.params ?? {}),
    );
    expect(clear, '수정 모드에서 비밀번호 칸을 비우는 setState 가 없다').toBeDefined();
    expect(clear.params.target ?? clear.target).toBe('local');
    for (const key of ['form.password', 'form.password_confirmation', 'form.current_password']) {
      expect(clear.params[key], `${key} 를 비우지 않는다`).toBe('');
    }
  });
});
