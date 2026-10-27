/**
 * @file login-password-disabled.test.tsx
 * @description 비밀번호 로그인 거절·외부 추가 단계 회귀 테스트 (sirsoft-basic, 7.2.0 B1③④)
 *
 * - 회사 계정 로그인이 강제된 계정은 로그인이 403 `errors.code=auth.password_auth_disabled` 로 거절된다. 로그인 칸 위 오류 자리에
 *   서버 안내를 싣고, `errors.redirect_url` 이 있으면 「{method_label}(으)로 로그인」 버튼(이름이 없으면 「회사 계정으로 로그인」)을
 *   보인다. 버튼은 redirect 쿼리를 실어 그 수단으로 같은 탭 이동한다.
 * - 동의·연결 확인 같은 화면이 더 필요하면 409 `errors.code=auth.external_step_required` 이고 `errors.redirect_url` 로 바로 이동한다.
 *
 * 안내 데이터는 오류 문맥의 `error.errors.*` 로만 읽는다 — `error.data` 는 레이아웃 바인딩 경로가 아니다(audit 금지).
 *
 * @vitest-environment jsdom
 * @since 1.3.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { fireEvent, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import loginForm from '../../layouts/partials/auth/_login_form.json';
import loginPage from '../../layouts/auth/login.json';
import koAuth from '../../lang/partial/ko/auth.json';

const apiPost = vi.fn();

vi.mock('@core/api/ApiClient', async () => {
  const actual = await vi.importActual<typeof import('@core/api/ApiClient')>('@core/api/ApiClient');
  const stub = {
    post: (...args: unknown[]) => apiPost(...args),
    get: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    getToken: vi.fn(() => null),
    setToken: vi.fn(),
    removeToken: vi.fn(),
    setLocale: vi.fn(),
  };
  return { ...actual, getApiClient: () => stub, createApiClient: () => stub };
});

/** DOM 에 넘길 속성 — 엔진이 묶는 이벤트 처리기와 접근성 속성만 */
const PASS_PROPS = ['id', 'className', 'role', 'type', 'name', 'href', 'disabled', 'placeholder', 'aria-label', 'onClick', 'onBlur', 'onChange'];

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
    if (tag === 'input') {
      return <input {...dom} defaultValue={props.value} />;
    }
    if (tag === 'form') {
      return (
        <form {...dom} onSubmit={(e) => { e.preventDefault(); props.onSubmit?.(e); }}>{props.children}</form>
      );
    }
    return React.createElement(tag, dom, props.children ?? props.text);
  };
}

/**
 * 시험용 컴포넌트 레지스트리를 만듭니다.
 *
 * @returns 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (tag: string, name: string) => ({ component: element(tag), metadata: { name, type: 'basic' } });
  (registry as any).registry = {
    Div: basic('div', 'Div'),
    Span: basic('span', 'Span'),
    P: basic('p', 'P'),
    Label: basic('label', 'Label'),
    Form: basic('form', 'Form'),
    Button: basic('button', 'Button'),
    Input: basic('input', 'Input'),
    PasswordInput: { component: (p: any) => <input type="password" name={p.name} />, metadata: { name: 'PasswordInput', type: 'basic' } },
    Fragment: { component: ({ children }: { children?: React.ReactNode }) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/**
 * 트리를 문자열로 모읍니다 (주석 제외 — 바인딩 경로만 본다).
 *
 * @param node 노드
 * @returns 바인딩 문자열 목록
 */
function bindingStrings(node: any): string[] {
  if (typeof node === 'string') return [node];
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(bindingStrings);
  return Object.entries(node).flatMap(([key, value]) => (key === 'comment' ? [] : bindingStrings(value)));
}

const layout = {
  version: '1.0.0',
  layout_name: 'auth/login',
  named_actions: (loginPage as any).named_actions,
  components: [loginForm as any],
};

/** 같은 탭 이동(`redirect` 핸들러)의 종착점 — jsdom 의 location 은 잠겨 있어 객체를 바꿔 끼운다. */
const locationAssign = vi.fn();
let originalLocation: Location;

/**
 * 로그인 API 실패를 Axios 모양으로 만듭니다.
 *
 * @param status HTTP 상태
 * @param message 서버 안내
 * @param errors 오류 문맥 `errors`
 * @returns 거절 값
 */
function apiFailure(status: number, message: string, errors: Record<string, unknown>): Error {
  return Object.assign(new Error('Request failed'), { response: { status, data: { success: false, message, errors } } });
}

/**
 * 로그인 폼을 그리고 제출합니다.
 *
 * @param query 주소 쿼리
 * @returns 레이아웃 시험 하네스
 */
async function renderAndSubmit(query: Record<string, string> = {}) {
  const t = createLayoutTest(layout, {
    componentRegistry: setupRegistry(),
    translations: { auth: koAuth },
    queryParams: query,
    // 운영의 전역 변수(template-engine createGlobalVariables) — 하네스 문맥에는 없어 직접 싣는다
    initialData: { $locale: 'ko', $templateId: 'test-template' },
  });
  await t.render();
  globalThis.fetch = vi.fn(async () => ({ ok: true, status: 204, json: async () => ({}), headers: new Headers() })) as unknown as typeof fetch;

  fireEvent.change(document.querySelector('input[name="email"]')!, { target: { value: 'kim@corp.test' } });
  fireEvent.change(document.querySelector('input[name="password"]')!, { target: { value: 'secret-pass' } });
  fireEvent.submit(document.querySelector('form')!);
  await waitFor(() => expect(apiPost).toHaveBeenCalled());
  return t;
}

/**
 * 실패 분기가 전역 상태(`_global.isLoggingIn=false`)를 다 쓰기를 기다렸다가 다시 그립니다.
 *
 * 오류 문구·수단 버튼 상태는 `_global` 에 실린다 — 하네스는 전역 쓰기 뒤 스스로 다시 그리지 않는다.
 *
 * @param t 레이아웃 시험 하네스
 * @returns 완료 약속
 */
async function settleFailure(t: Awaited<ReturnType<typeof renderAndSubmit>>): Promise<void> {
  await waitFor(() => expect(t.getState()._global.isLoggingIn).toBe(false));
  await new Promise((resolve) => setTimeout(resolve, 20));
  await t.rerender();
}

/**
 * 문구가 정확히 같은 버튼을 찾습니다.
 *
 * @param text 버튼 문구
 * @returns 버튼 (없으면 undefined)
 */
function buttonByText(text: string): HTMLButtonElement | undefined {
  return Array.from(document.querySelectorAll('button')).find((b) => b.textContent === text);
}

describe('sirsoft-basic 비밀번호 로그인 거절·외부 추가 단계 (7.2.0 B1)', () => {
  beforeEach(() => {
    setupRegistry();
    apiPost.mockReset();
    locationAssign.mockReset();
    originalLocation = window.location;
    delete (window as any).location;
    (window as any).location = {
      origin: 'http://localhost', protocol: 'http:', host: 'localhost', hostname: 'localhost', port: '',
      pathname: '/login', search: '', hash: '', href: 'http://localhost/login',
      assign: locationAssign, replace: vi.fn(), reload: vi.fn(),
    };
  });

  afterEach(() => {
    (window as any).location = originalLocation;
  });

  /**
   * @scenario response=password_auth_disabled, method_link=labeled
   * @effects password_disabled_shows_inline_notice, password_disabled_offers_method_button
   */
  it('403 auth.password_auth_disabled 면 안내를 싣고 「{method_label}(으)로 로그인」 버튼이 redirect 쿼리를 실어 그 수단으로 보낸다', async () => {
    apiPost.mockRejectedValue(apiFailure(403, '이 계정은 사내 SSO(으)로만 로그인할 수 있습니다.', {
      code: 'auth.password_auth_disabled',
      method_label: { ko: '사내 SSO', en: 'Corp SSO' },
      redirect_url: '/plugins/sirsoft-sso/start/corp',
    }));
    const t = await renderAndSubmit({ redirect: '/mypage' });
    await settleFailure(t);

    await waitFor(() => expect(document.body.textContent).toContain('이 계정은 사내 SSO(으)로만 로그인할 수 있습니다.'));
    await waitFor(() => expect(buttonByText('사내 SSO(으)로 로그인')).toBeDefined());
    expect(buttonByText(koAuth.external.login_with_company)).toBeUndefined();
    expect(locationAssign).not.toHaveBeenCalled();

    fireEvent.click(buttonByText('사내 SSO(으)로 로그인')!);
    await waitFor(() => expect(locationAssign).toHaveBeenCalledTimes(1));
    expect(locationAssign).toHaveBeenCalledWith('/plugins/sirsoft-sso/start/corp?redirect=%2Fmypage');

    t.cleanup();
  });

  /**
   * @scenario response=password_auth_disabled, method_link=unlabeled
   * @effects password_disabled_offers_method_button
   */
  it('수단 이름이 없으면 버튼 문구는 「회사 계정으로 로그인」이다', async () => {
    apiPost.mockRejectedValue(apiFailure(403, '이 계정은 회사 계정으로만 로그인할 수 있습니다.', {
      code: 'auth.password_auth_disabled',
      method_label: null,
      redirect_url: '/plugins/sirsoft-sso/start/corp',
    }));
    const t = await renderAndSubmit();
    await settleFailure(t);

    await waitFor(() => expect(buttonByText(koAuth.external.login_with_company)).toBeDefined());

    fireEvent.click(buttonByText(koAuth.external.login_with_company)!);
    await waitFor(() => expect(locationAssign).toHaveBeenCalledWith('/plugins/sirsoft-sso/start/corp'));

    t.cleanup();
  });

  /**
   * @scenario response=password_auth_disabled, method_link=none
   * @effects password_disabled_shows_inline_notice
   */
  it('이동 경로가 없으면 안내만 싣고 버튼은 없다', async () => {
    apiPost.mockRejectedValue(apiFailure(403, '이 계정은 회사 계정으로만 로그인할 수 있습니다.', {
      code: 'auth.password_auth_disabled',
      method_label: null,
      redirect_url: null,
    }));
    const t = await renderAndSubmit();
    await settleFailure(t);

    await waitFor(() => expect(document.body.textContent).toContain('이 계정은 회사 계정으로만 로그인할 수 있습니다.'));
    expect(buttonByText(koAuth.external.login_with_company)).toBeUndefined();
    expect(Array.from(document.querySelectorAll('button')).some((b) => (b.textContent ?? '').endsWith('(으)로 로그인'))).toBe(false);

    t.cleanup();
  });

  /**
   * @scenario response=external_step_required, method_link=none
   * @effects external_step_redirects_to_server_path
   */
  it('409 auth.external_step_required 면 errors.redirect_url 로 바로 이동한다', async () => {
    apiPost.mockRejectedValue(apiFailure(409, '추가 확인이 필요합니다.', {
      code: 'auth.external_step_required',
      redirect_url: '/plugins/sirsoft-sso/consent/abc',
    }));
    const t = await renderAndSubmit();

    await waitFor(() => expect(locationAssign).toHaveBeenCalledTimes(1));
    expect(locationAssign).toHaveBeenCalledWith('/plugins/sirsoft-sso/consent/abc');
    expect(buttonByText(koAuth.external.login_with_company)).toBeUndefined();

    t.cleanup();
  });

  /**
   * @scenario response=invalid_credentials, method_link=none
   * @effects password_disabled_branch_ignores_other_failures
   */
  it('평범한 401 실패는 안내만 싣고 이동·수단 버튼이 없다', async () => {
    apiPost.mockRejectedValue(apiFailure(401, '이메일 또는 비밀번호가 올바르지 않습니다.', { email: ['이메일 또는 비밀번호가 올바르지 않습니다.'] }));
    const t = await renderAndSubmit();
    await settleFailure(t);

    await waitFor(() => expect(document.body.textContent).toContain('이메일 또는 비밀번호가 올바르지 않습니다.'));
    expect(buttonByText(koAuth.external.login_with_company)).toBeUndefined();
    expect(locationAssign).not.toHaveBeenCalled();

    t.cleanup();
  });

  /**
   * @effects password_disabled_reads_error_errors_only
   */
  it('거절 안내 데이터는 error.errors.* 로만 읽고 error.data 를 쓰지 않는다', () => {
    const bindings = [...bindingStrings(loginForm), ...bindingStrings((loginPage as any).named_actions)];

    expect(bindings.some((s) => s.includes('error.data'))).toBe(false);
    expect(bindings.some((s) => s.includes("error.errors?.code === 'auth.password_auth_disabled'"))).toBe(true);
    expect(bindings.some((s) => s.includes("error.errors?.code === 'auth.external_step_required'"))).toBe(true);
    expect(bindings.some((s) => s.includes('error.errors?.redirect_url'))).toBe(true);
  });
});
