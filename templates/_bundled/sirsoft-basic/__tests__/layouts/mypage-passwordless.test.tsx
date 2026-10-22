/**
 * @file mypage-passwordless.test.tsx
 * @description 비밀번호 없는 회원의 마이페이지 화면 회귀 테스트 (sirsoft-basic, #40)
 *
 * 소셜 로그인 같은 외부 인증으로만 가입한 회원은 비밀번호가 없다(`has_password === false`).
 * - 비밀번호 변경 화면은 현재 비밀번호 칸 없이 「처음 설정」 안내와 새 비밀번호 칸만 보이고, 요청에 현재 비밀번호 키를 싣지 않는다.
 * - 정보 수정 진입 관문은 비밀번호 대신 이메일 인증번호(코어 본인인증 `sensitive_action`)로 확인하고, 받은 토큰을
 *   비밀번호 확인 API 에 보낸다.
 *
 * @vitest-environment jsdom
 * @since 1.3.0
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import changePassword from '../../layouts/mypage/change-password.json';
import verifySection from '../../layouts/partials/mypage/profile/_password_verify_section.json';

type Common = { className?: string; children?: React.ReactNode; text?: string };

const Box: React.FC<Common & { role?: string }> = ({ className, children, text, role }) => <div className={className} role={role}>{children || text}</div>;
const PasswordInput: React.FC<{ name?: string }> = ({ name }) => <input type="password" name={name} />;
const Input: React.FC<{ name?: string; type?: string }> = ({ name, type }) => <input type={type} name={name} />;
const Button: React.FC<Common & { type?: string; disabled?: boolean }> = ({ type, children, text, disabled }) => (
  <button type={type as 'button' | 'submit'} disabled={disabled}>{children || text}</button>
);
const Form: React.FC<Common> = ({ className, children }) => <form className={className}>{children}</form>;
const Icon: React.FC = () => null;

/**
 * 시험용 컴포넌트 레지스트리를 만듭니다.
 *
 * @returns 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (component: React.FC<any>, name: string) => ({ component, metadata: { name, type: 'basic' } });
  (registry as any).registry = {
    Div: basic(Box, 'Div'),
    Span: basic(Box, 'Span'),
    P: basic(Box, 'P'),
    H1: basic(Box, 'H1'),
    H2: basic(Box, 'H2'),
    Label: basic(Box, 'Label'),
    Form: basic(Form, 'Form'),
    Button: basic(Button, 'Button'),
    Input: basic(Input, 'Input'),
    PasswordInput: basic(PasswordInput, 'PasswordInput'),
    Icon: basic(Icon, 'Icon'),
    Fragment: { component: ({ children }: { children?: React.ReactNode }) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/**
 * 트리를 평탄화합니다.
 *
 * @param node 노드
 * @returns 노드 목록
 */
function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  return [node, ...children, ...slots];
}

/**
 * 액션을 평탄화합니다.
 *
 * @param actions 액션(들)
 * @returns 액션 목록
 */
function flattenActions(actions: any): any[] {
  if (!actions) return [];
  return asArray<any>(actions).flatMap((a) => [a, ...flattenActions(a.actions), ...flattenActions(a.onSuccess), ...flattenActions(a.onError)]);
}

/** 비밀번호 변경 화면을 단독 레이아웃으로 렌더할 모양 — 슬롯 내용만 그린다. */
const changePasswordLayout = {
  version: '1.0.0',
  layout_name: 'mypage/change-password',
  components: asArray<any>((changePassword as any).slots?.content),
};

describe('sirsoft-basic 비밀번호 없는 회원 마이페이지 (#40)', () => {
  beforeEach(() => {
    setupRegistry();
  });

  /**
   * @scenario has_password=false|true, surface=change_password
   * @effects change_password_hides_current_for_passwordless, change_password_keeps_current_for_holder
   */
  it.each([
    [false, 0, true],
    [true, 1, false],
  ])('has_password=%s 면 현재 비밀번호 칸 %i개, 처음 설정 안내 노출=%s', async (hasPassword, currentCount, notice) => {
    const t = createLayoutTest(changePasswordLayout, {
      componentRegistry: setupRegistry(),
      initialData: { user: { data: { has_password: hasPassword } } },
    });
    await t.render();

    expect(document.querySelectorAll('input[name="current_password"]').length).toBe(currentCount);
    expect((document.body.textContent ?? '').includes('change_password_first_time_notice')).toBe(notice);

    t.cleanup();
  });

  /**
   * @scenario has_password=false, surface=change_password
   * @effects change_password_omits_current_key_for_passwordless
   */
  it('비밀번호 없는 회원은 요청에 현재 비밀번호 키를 싣지 않는다(값 undefined → 키 미전송)', () => {
    const form = flatten(changePassword).find((n) => n.name === 'Form');
    const api = flattenActions(form?.actions).find((a) => a.handler === 'apiCall');
    const current = String(api?.params?.body?.current_password);

    expect(current).toContain('has_password');
    expect(current).toContain(': undefined');
    expect(JSON.stringify({ current_password: undefined, password: 'x' })).toBe('{"password":"x"}');
  });

  /**
   * @scenario has_password=false, surface=profile_edit_gate
   * @effects profile_gate_uses_email_code_for_passwordless
   */
  it('정보 수정 관문은 비밀번호 없는 회원에게 인증번호 받기·확인을 보이고 토큰을 비밀번호 확인 API 에 보낸다', () => {
    const nodes = flatten(verifySection);
    const passwordForm = nodes.find((n) => n.props?.id === 'verifyPasswordForm' || n.id === 'verifyPasswordForm');
    const codeSection = nodes.find((n) => n.id === 'reauth_email_code_section');

    expect(String(passwordForm?.if)).toContain('has_password) !== false');
    expect(String(codeSection?.if)).toContain('has_password) === false');

    const actions = flattenActions(flatten(codeSection).flatMap((n) => asArray<any>(n.actions)));
    const request = actions.find((a) => a.handler === 'apiCall' && a.target === '/api/identity/challenges');
    expect(request?.params?.body).toEqual({ purpose: 'sensitive_action', provider_id: 'g7:core.mail' });

    const verify = actions.find((a) => a.handler === 'apiCall' && String(a.target).includes('/verify'));
    const gate = flattenActions(verify?.onSuccess).find((a) => a.handler === 'apiCall' && a.target === '/api/me/verify-password');
    expect(gate?.params?.body?.verification_token).toBe('{{response.data?.verification_token}}');
    const passed = flattenActions(gate?.onSuccess).find((a) => a.handler === 'setState');
    expect(passed?.params?.isPasswordVerified).toBe(true);
  });

  /**
   * @scenario has_password=false|true, surface=profile_edit_gate
   * @effects profile_gate_heading_follows_password_presence
   */
  it('정보 수정 관문 제목·부제는 비밀번호 없는 회원에게 「본인 확인」으로, 비밀번호 회원에게는 종전 「비밀번호 확인」으로 보인다', () => {
    const nodes = flatten(verifySection);
    const textOf = (key: string) => nodes.filter((n) => String(n.text ?? '').includes(key));

    for (const key of ['verify_password_title', 'verify_password_message']) {
      const [node] = textOf(`mypage.profile.${key}`);
      expect(node, key).toBeTruthy();
      expect(String(node.if), key).toContain('has_password) !== false');
    }
    for (const key of ['verify_identity_title', 'verify_identity_message']) {
      const [node] = textOf(`mypage.profile.${key}`);
      expect(node, key).toBeTruthy();
      expect(String(node.if), key).toContain('has_password) === false');
    }
  });

  /**
   * @scenario has_password=false, email_deliverable=false|true, surface=profile_edit_gate
   * @effects profile_gate_offers_extension_for_undeliverable_email
   */
  it('메일을 받을 수 없는 회원(대체 이메일)은 인증번호 칸 대신 확장점 `mypage_reauth_methods` 를 본다', () => {
    const nodes = flatten(verifySection);
    const codeSection = nodes.find((n) => n.id === 'reauth_email_code_section');
    const alternative = nodes.find((n) => n.id === 'reauth_alternative_section');

    expect(String(codeSection?.if)).toContain('email_deliverable) !== false');
    expect(String(alternative?.if)).toContain('has_password) === false');
    expect(String(alternative?.if)).toContain('email_deliverable) === false');

    const slot = flatten(alternative).find((n) => n.type === 'extension_point');
    expect(slot?.name).toBe('mypage_reauth_methods');
    expect(JSON.stringify(slot?.default ?? slot?.children ?? [])).toContain('reauth_unavailable');
  });

  /**
   * @scenario has_password=false, email_deliverable=false, surface=change_password
   * @effects change_password_offers_extension_for_undeliverable_email
   */
  it('비밀번호 첫 설정 화면은 메일을 받을 수 없는 회원에게만 확장점 `mypage_change_password_reauth` 를 보인다', () => {
    const section = flatten(changePassword).find((n) => n.id === 'password_reauth_section');

    // 확장점 기본 내용은 서버가 레이아웃을 풀 때 자식으로 채운다 — 여기서는 노출 조건과 자리만 잰다.
    expect(String(section?.if)).toContain('has_password) === false');
    expect(String(section?.if)).toContain('email_deliverable) === false');
    expect(String(section?.if)).toContain('!_local?.success');

    const slot = flatten(section).find((n) => n.type === 'extension_point');
    expect(slot?.name).toBe('mypage_change_password_reauth');
    expect(JSON.stringify(slot)).toContain('change_password_reauth_unavailable');
  });
});
