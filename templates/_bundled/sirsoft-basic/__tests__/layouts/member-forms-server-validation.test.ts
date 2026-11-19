// e2e:allow 폼의 정적 속성(noValidate)만 잰다. 화면 동작(빈 필수 칸 제출 → 칸 아래 서버 오류)은 코어 E2E
// member-fields-signup.spec.ts ① 이 가입 화면에서 잰다.
/**
 * 회원 항목을 그리는 폼은 브라우저 기본 검사를 끄고 서버 오류를 칸 아래에 보인다 (#490).
 *
 * 필수 칸에 브라우저 기본 `required` 가 붙어, 비우고 제출하면 서버의 「칸 아래 오류」 대신 브라우저 말풍선이 먼저 막았다.
 * 그래서 서버 오류 표시로 통일한다 — 가입, 추가 정보 입력, 내 정보 수정 폼에 `noValidate`.
 * 소셜, SSO 가입 창은 `<form>` 이 없어 브라우저 검사가 걸리지 않는다.
 *
 * @scenario-manifest tests/scenarios/member-forms-server-validation.yaml
 */
import { describe, it, expect } from 'vitest';

import registerForm from '../../layouts/partials/auth/_register_form.json';
import requiredInfo from '../../layouts/mypage/required-info.json';
import profileEdit from '../../layouts/partials/mypage/profile/_edit.json';

type Node = { name?: string; dataKey?: string; props?: Record<string, unknown>; [key: string]: unknown };

/**
 * 레이아웃 트리에서 조건에 맞는 첫 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @returns 찾은 노드 또는 null
 */
function find(node: unknown, match: (n: Node) => boolean): Node | null {
  if (!node || typeof node !== 'object') return null;
  if (match(node as Node)) return node as Node;
  for (const value of Object.values(node as Record<string, unknown>)) {
    const found = find(value, match);
    if (found) return found;
  }

  return null;
}

/**
 * 폼 노드를 찾아 noValidate 를 확인한다.
 *
 * @param layout 레이아웃 JSON
 * @param match 폼 노드 조건
 */
function expectNoValidate(layout: unknown, match: (n: Node) => boolean): void {
  const form = find(layout, match);
  expect(form, '폼 노드를 찾지 못했다').not.toBeNull();
  expect(form!.props?.noValidate).toBe(true);
}

describe('회원 항목 폼 — 서버 오류 표시로 통일', () => {
  /**
   * @scenario form=register
   * @effects browser_validation_disabled_on_member_field_forms
   */
  it('가입 폼은 브라우저 기본 검사를 끈다(noValidate)', () => {
    expectNoValidate(registerForm, (n) => n.name === 'Form' && n.props?.id === 'registerForm');
  });

  /**
   * @scenario form=required_info
   * @effects browser_validation_disabled_on_member_field_forms
   */
  it('추가 정보 입력 폼은 브라우저 기본 검사를 끈다(noValidate)', () => {
    expectNoValidate(requiredInfo, (n) => n.name === 'Form' && n.dataKey === 'requiredInfoForm');
  });

  /**
   * @scenario form=profile_edit
   * @effects browser_validation_disabled_on_member_field_forms
   */
  it('내 정보 수정 폼은 브라우저 기본 검사를 끈다(noValidate)', () => {
    expectNoValidate(profileEdit, (n) => n.name === 'Form' && n.props?.id === 'profileEditForm');
  });
});
