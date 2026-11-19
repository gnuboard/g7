/**
 * @file mypage-change-password-availability.test.tsx
 * @description 마이페이지 비밀번호 변경 화면이 비밀번호를 만들 수 없는 회원·재확인 수단을 미리 알리는지 (sirsoft-basic, 7.2.0)
 *
 * - 비밀번호 인증 정책(회사 계정 로그인 강제, IdP 가 이 사이트 비밀번호를 허용하지 않음 등)이 비밀번호 설정을 막는 회원에게 화면이
 *   「비밀번호를 처음 설정합니다」와 새 비밀번호 칸을 보이고, 저장하면 본인 확인(외부 화면 왕복)까지 시킨 뒤 403 으로 거절했다.
 *   회원 응답(`/api/me`)의 `can_set_password=false`·`password_set_blocked_message` 로 칸·처음 설정 안내·본인 확인 자리 대신 서버 문구를
 *   보이고 내 정보로 돌아가는 길을 둔다.
 * - 메일을 받을 수 없는 비밀번호 없는 회원의 본인 확인 자리 기본 안내는 코어가 실제로 고를 재확인 수단(`reauth_provider`)을 따른다 —
 *   수단이 있으면(회사 계정 다시 로그인 등) 「저장하면 그 방법으로 확인」, 없으면 지금처럼 관리자 문의.
 *
 * 실제 렌더 도구(createLayoutTest)에 실물 화면 JSON 을 올려 그린다. 확장점은 확장이 없는 사이트처럼 기본 내용으로, 공용 조각
 * (`partials/auth/_password_auth_blocked`)은 빼고 그린다(그 조각은 저장 403 뒤에만 보인다).
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import changePassword from '../../layouts/mypage/change-password.json';
import koMypage from '../../lang/partial/ko/mypage.json';

/** DOM 에 넘길 속성 */
const PASS_PROPS = ['id', 'className', 'role', 'type', 'name', 'href'];

/**
 * 태그 하나를 그리는 시험용 컴포넌트를 만듭니다.
 *
 * @param tag HTML 태그
 * @returns 컴포넌트
 */
function element(tag: string): React.FC<any> {
  return (props: any) => {
    const dom: Record<string, unknown> = {};
    for (const key of PASS_PROPS) {
      if (props[key] !== undefined) dom[key] = props[key];
    }
    return React.createElement(tag, dom, props.children ?? props.text);
  };
}

/** 덮어쓰기 전 레지스트리 원본 */
let savedRegistry: unknown = null;

/**
 * 화면이 쓰는 컴포넌트만 등록한 레지스트리를 만듭니다.
 *
 * @returns 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  savedRegistry ??= (registry as any).registry;
  const basic = (tag: string, name: string) => ({ component: element(tag), metadata: { name, type: 'basic' } });
  (registry as any).registry = {
    Div: basic('div', 'Div'),
    Span: basic('span', 'Span'),
    P: basic('p', 'P'),
    H1: basic('h1', 'H1'),
    A: basic('a', 'A'),
    Label: basic('label', 'Label'),
    Form: basic('form', 'Form'),
    Button: basic('button', 'Button'),
    PasswordInput: { component: (p: any) => <input type="password" name={p.name} />, metadata: { name: 'PasswordInput', type: 'basic' } },
    Icon: { component: () => null, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: ({ children }: { children?: React.ReactNode }) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/**
 * 확장이 없는 사이트처럼 트리를 바꿉니다 — 확장점은 기본 내용으로, 공용 조각 참조는 뺀다.
 *
 * @param node 노드
 * @returns 바꾼 노드 (빼면 null)
 */
function withoutExtensions(node: any): any {
  if (Array.isArray(node)) return node.map(withoutExtensions).filter((n) => n !== null);
  if (!node || typeof node !== 'object') return node;
  if (typeof node.partial === 'string') return null;
  if (node.type === 'extension_point') {
    return { id: node.id, type: 'basic', name: 'Div', children: withoutExtensions(node.default ?? []) };
  }
  const copy = { ...node };
  if (Array.isArray(node.children)) copy.children = withoutExtensions(node.children);
  return copy;
}

let utils: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 비밀번호 변경 화면을 그립니다.
 *
 * @param user `/api/me` 응답의 data
 * @returns 그려진 화면
 */
async function renderPage(user: Record<string, unknown>): Promise<HTMLElement> {
  const content = (changePassword as any).slots?.content ?? [];
  utils = createLayoutTest(
    { version: '1.0.0', layout_name: 'mypage/change-password', components: withoutExtensions(content) } as any,
    {
      componentRegistry: setupRegistry(),
      translations: { mypage: koMypage },
      initialState: { _local: { isSubmitting: false, success: false, errors: null } },
      initialData: { user: { data: user }, $locale: 'ko', $templateId: 'test-template' },
    },
  );
  const { container } = await utils.render();
  return container;
}

afterEach(() => {
  utils?.cleanup();
  utils = null;
  if (savedRegistry) {
    (ComponentRegistry.getInstance() as any).registry = savedRegistry;
  }
});

const BLOCKED = '회사 계정으로 로그인하는 회원은 이 사이트 비밀번호를 만들 수 없습니다.';

describe('비밀번호 변경 — 비밀번호를 만들 수 없는 회원', () => {
  /**
   * @scenario can_set_password=false, has_password=false, email_deliverable=false
   * @effects change_password_shows_unsettable_notice
   */
  it('회원 응답이 비밀번호를 만들 수 없다고 하면 칸·처음 설정 안내·본인 확인 자리 대신 서버 문구와 돌아가는 길을 보인다', async () => {
    const container = await renderPage({
      has_password: false, email_deliverable: false, can_set_password: false, password_set_blocked_message: BLOCKED, reauth_provider: 'sirsoft-sso',
    });

    expect(container.textContent).toContain(BLOCKED);
    expect(container.querySelector('#password_set_unavailable')).not.toBeNull();
    expect(container.querySelector('#password_blocked_back')).not.toBeNull();
    expect(container.querySelector('input[name="password"]')).toBeNull();
    expect(container.querySelector('#password_first_time_notice')).toBeNull();
    expect(container.querySelector('#password_reauth_section')).toBeNull();
  });

  /**
   * @scenario can_set_password=false, has_password=true, email_deliverable=true
   * @effects change_password_shows_unsettable_notice
   */
  it('비밀번호가 있는 회원도 막혀 있으면 현재 비밀번호 칸까지 숨기고 서버 문구를 보인다', async () => {
    const container = await renderPage({
      has_password: true, email_deliverable: true, can_set_password: false, password_set_blocked_message: BLOCKED, reauth_provider: null,
    });

    expect(container.textContent).toContain(BLOCKED);
    expect(container.querySelector('input[name="current_password"]')).toBeNull();
    expect(container.querySelector('input[name="password"]')).toBeNull();
  });

  /**
   * @scenario can_set_password=true, has_password=false, email_deliverable=true
   * @effects change_password_form_unchanged_when_settable
   */
  it('만들 수 있는 회원은 지금처럼 처음 설정 안내와 새 비밀번호 칸을 보인다', async () => {
    const container = await renderPage({
      has_password: false, email_deliverable: true, can_set_password: true, password_set_blocked_message: null, reauth_provider: null,
    });

    expect(container.querySelector('#password_first_time_notice')).not.toBeNull();
    expect(container.querySelector('input[name="password"]')).not.toBeNull();
    expect(container.querySelector('#password_set_unavailable')).toBeNull();
    expect(container.querySelector('#password_reauth_section')).toBeNull();
  });
});

describe('비밀번호 변경 — 메일을 받을 수 없는 회원의 본인 확인 안내', () => {
  /**
   * @scenario can_set_password=true, email_deliverable=false, reauth_provider=external
   * @effects change_password_reauth_notice_follows_actual_method
   */
  it('코어가 고를 재확인 수단이 있으면(회사 계정 다시 로그인 등) 관리자 문의 대신 「저장하면 그 방법으로 확인」 안내를 보인다', async () => {
    const container = await renderPage({
      has_password: false, email_deliverable: false, can_set_password: true, password_set_blocked_message: null, reauth_provider: 'sirsoft-sso',
    });

    expect(container.querySelector('#password_reauth_other_notice')).not.toBeNull();
    expect(container.textContent).not.toContain(koMypage.profile.change_password_reauth_unavailable);
    expect(container.querySelector('input[name="password"]')).not.toBeNull();
  });

  /**
   * @scenario can_set_password=true, email_deliverable=false, reauth_provider=none
   * @effects change_password_reauth_notice_follows_actual_method
   */
  it('쓸 수 있는 재확인 수단이 없으면 지금처럼 관리자 문의 안내를 보인다', async () => {
    const container = await renderPage({
      has_password: false, email_deliverable: false, can_set_password: true, password_set_blocked_message: null, reauth_provider: null,
    });

    expect(container.textContent).toContain(koMypage.profile.change_password_reauth_unavailable);
    expect(container.querySelector('#password_reauth_other_notice')).toBeNull();
  });
});
