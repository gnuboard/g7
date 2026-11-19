/**
 * @file mypage-change-password-member-info-loading.test.tsx
 * @description 비밀번호 변경 화면이 회원 정보(`/api/me`)를 받기 전에 누른 저장을 조용히 삼키지 않는지 (sirsoft-basic, 7.2.0)
 *
 * 화면은 회원 정보를 받기 전에도 그려진다(progressive). 그동안은 비밀번호가 있는지 모르니 현재 비밀번호 칸(필수 입력)이 보이고,
 * 비밀번호 없는 회원이 새 비밀번호만 채워 「비밀번호 변경」을 누르면 브라우저 필수 입력 검사가 제출을 막아 요청도 안내도 없이
 * 끝났다(다시 누르면 정상 — 그사이 회원 정보가 와 칸이 사라진다). 회원 정보가 오기 전에는 저장 버튼을 잠그고 「로딩 중」을 보인다.
 * 받기에 실패하면(`user === null`) 잠그지 않는다 — 버튼이 영영 잠기지 않게.
 *
 * 실제 렌더 도구(createLayoutTest)에 실물 화면 JSON 을 올리고, 필수 입력 속성을 그대로 DOM 에 넘겨 실제 브라우저처럼 버튼을 눌러 본다.
 * 확장점은 확장이 없는 사이트처럼 기본 내용으로, 공용 조각은 빼고 그린다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import changePassword from '../../layouts/mypage/change-password.json';
import koMypage from '../../lang/partial/ko/mypage.json';
import koCommon from '../../lang/partial/ko/common.json';

/** 화면이 보낸 요청 — `apiCall` 은 `fetch` 로 보낸다(렌더 뒤에 바꿔 끼운다) */
const fetchSpy = vi.fn(async () => ({
  ok: true,
  status: 200,
  json: async () => ({ success: true, data: {} }),
  text: async () => '{"success":true,"data":{}}',
  headers: new Headers({ 'Content-Type': 'application/json' }),
}));

/**
 * 비밀번호 변경 요청(`PUT /api/me/password`)만 골라냅니다.
 *
 * @returns 요청 목록 [주소, 옵션]
 */
function passwordPuts(): Array<[string, RequestInit]> {
  return (fetchSpy.mock.calls as unknown as Array<[string, RequestInit]>)
    .filter(([url, init]) => String(url).includes('/api/me/password') && init?.method === 'PUT');
}

/** DOM 에 넘길 속성 — 버튼 잠금·필수 입력처럼 브라우저 동작을 바꾸는 속성과 엔진이 묶는 처리기 */
const PASS_PROPS = ['id', 'className', 'role', 'type', 'name', 'href', 'disabled', 'required', 'onClick'];

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
    if (tag === 'form') {
      return (
        <form {...dom} onSubmit={(e) => { e.preventDefault(); props.onSubmit?.(e); }}>{props.children}</form>
      );
    }
    return React.createElement(tag, dom, props.children ?? props.text);
  };
}

/** 덮어쓰기 전 레지스트리 원본 */
let savedRegistry: unknown = null;

/**
 * 화면이 쓰는 컴포넌트만 등록한 레지스트리를 만듭니다. 비밀번호 칸은 실물처럼 `required` 를 입력에 그대로 싣는다.
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
    PasswordInput: {
      component: (p: any) => <input type="password" name={p.name} required={!!p.required} disabled={!!p.disabled} />,
      metadata: { name: 'PasswordInput', type: 'basic' },
    },
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
 * @param user `user` 데이터소스 값 — 받기 전 `undefined`, 실패 `null`, 받으면 `{ data }`
 * @returns 그려진 화면
 */
async function renderPage(user: unknown): Promise<HTMLElement> {
  const content = (changePassword as any).slots?.content ?? [];
  utils = createLayoutTest(
    { version: '1.0.0', layout_name: 'mypage/change-password', components: withoutExtensions(content) } as any,
    {
      componentRegistry: setupRegistry(),
      translations: { mypage: koMypage, common: koCommon },
      initialState: { _local: { isSubmitting: false, success: false, errors: null } },
      initialData: { user, $locale: 'ko', $templateId: 'test-template' },
    },
  );
  const { container } = await utils.render();
  globalThis.fetch = fetchSpy as unknown as typeof fetch;
  return container;
}

/**
 * 비밀번호 없는 회원이 하듯 새 비밀번호 두 칸만 채우고 「비밀번호 변경」 버튼을 누릅니다(제출 이벤트를 직접 쏘지 않는다 —
 * 브라우저의 필수 입력 검사와 버튼 잠금을 거친다).
 *
 * @param container 그려진 화면
 * @returns 누른 버튼
 */
function fillNewPasswordAndPress(container: HTMLElement): HTMLButtonElement {
  fireEvent.change(container.querySelector('input[name="password"]')!, { target: { value: 'NewPass!2345' } });
  fireEvent.change(container.querySelector('input[name="password_confirmation"]')!, { target: { value: 'NewPass!2345' } });
  const button = container.querySelector('form#form button[type="submit"]') as HTMLButtonElement;
  fireEvent.click(button);
  return button;
}

beforeEach(() => {
  fetchSpy.mockClear();
});

afterEach(() => {
  utils?.cleanup();
  utils = null;
  if (savedRegistry) {
    (ComponentRegistry.getInstance() as any).registry = savedRegistry;
  }
});

const PASSWORDLESS = { has_password: false, email_deliverable: true, can_set_password: true, password_set_blocked_message: null, reauth_provider: null };

describe('비밀번호 변경 — 회원 정보를 받기 전에 누른 저장', () => {
  /**
   * @scenario member_info=loading
   * @effects change_password_submit_locked_until_member_info
   */
  it('회원 정보가 오기 전에는 저장 버튼을 잠그고 「로딩 중」 을 보인다 — 누른 저장이 안내 없이 사라지지 않는다', async () => {
    const container = await renderPage(undefined);
    const button = fillNewPasswordAndPress(container);

    expect(button.disabled).toBe(true);
    expect(container.querySelector('#password_submit_loading')?.textContent).toBe(koCommon.loading);
    expect(button.textContent).not.toContain(koMypage.profile.change_password_submit);
    expect(passwordPuts()).toHaveLength(0);
  });

  /**
   * @scenario member_info=failed
   * @effects change_password_submit_not_stuck_on_member_info_failure
   */
  it('회원 정보 받기가 실패하면(null) 저장 버튼을 잠근 채로 두지 않는다', async () => {
    const container = await renderPage(null);
    const button = container.querySelector('form#form button[type="submit"]') as HTMLButtonElement;

    expect(button.disabled).toBe(false);
    expect(container.querySelector('#password_submit_loading')).toBeNull();
    expect(button.textContent).toContain(koMypage.profile.change_password_submit);
  });

  /**
   * @scenario member_info=loaded
   * @effects change_password_submit_sends_after_member_info
   */
  it('회원 정보를 받으면(비밀번호 없는 회원) 같은 입력으로 누른 저장이 비밀번호 변경 요청으로 나간다', async () => {
    const container = await renderPage({ data: PASSWORDLESS });
    expect(container.querySelector('input[name="current_password"]')).toBeNull();
    expect((container.querySelector('form#form button[type="submit"]') as HTMLButtonElement).disabled).toBe(false);

    fillNewPasswordAndPress(container);

    await waitFor(() => expect(passwordPuts()).toHaveLength(1));
    const body = JSON.parse(String(passwordPuts()[0][1].body));
    expect(body).toMatchObject({ password: 'NewPass!2345', password_confirmation: 'NewPass!2345' });
    expect(body).not.toHaveProperty('current_password');
  });
});
