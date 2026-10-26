/**
 * @file login-discovery.test.tsx
 * @description 로그인 화면 「이메일 먼저 입력」·식별자 칸 회귀 테스트 (sirsoft-basic, 7.2.0 B1①②)
 *
 * 이메일 칸을 벗어날 때 값이 바뀌었으면 `POST /api/auth/login/discover` 로 그 식별자의 로그인 수단을 묻는다.
 * 응답이 외부 수단(`method=external`)이면 `_local.discovery` 에 `{label, redirect_url}` 을 담고 비밀번호 칸과
 * 로그인 버튼 대신 「{label}(으)로 계속」(redirect 쿼리를 실어 그 수단으로 이동)과 「다른 이메일로 로그인」(판정 초기화)을
 * 보인다. 실패나 `password` 면 지금 화면 그대로다.
 *
 * 식별자 칸은 `_global.appConfig.auth.login_identifier.user` 가 `email_or_username` 이면 `type=text` 와
 * 「이메일 또는 사내 아이디」 라벨, 아니면 `type=email` 이다.
 *
 * 하네스의 `getState()` 는 DOM 이벤트로 바뀐 컴포넌트 지역 상태를 따라가지 않는다 — 판정 결과는 화면(DOM)으로 잰다.
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

/** DOM 에 넘길 속성 — 엔진이 묶는 이벤트 처리기와 접근성 속성만 */
const PASS_PROPS = ['id', 'className', 'role', 'type', 'name', 'href', 'disabled', 'readOnly', 'placeholder', 'aria-label', 'onClick', 'onBlur', 'onChange'];

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

const layout = {
  version: '1.0.0',
  layout_name: 'auth/login',
  named_actions: (loginPage as any).named_actions,
  components: [loginForm as any],
};

/** 같은 탭 이동(`redirect` 핸들러)의 종착점 — jsdom 의 location 은 잠겨 있어 객체를 바꿔 끼운다. */
const locationAssign = vi.fn();
const locationReplace = vi.fn();
let originalLocation: Location;

/** 발견 API 응답을 흉내 내는 fetch */
const fetchMock = vi.fn();

/**
 * fetch 응답 객체를 만듭니다.
 *
 * @param status HTTP 상태
 * @param body 응답 본문
 * @returns 응답
 */
function jsonResponse(status: number, body: unknown): Response {
  return { ok: status < 400, status, statusText: '', json: async () => body, headers: new Headers() } as unknown as Response;
}

/**
 * 로그인 폼을 그리고 fetch 를 바꿔 끼웁니다.
 *
 * @param options 초기 전역 상태와 주소 쿼리, 화면 로케일
 * @returns 레이아웃 시험 하네스
 */
async function renderLogin(options: { global?: Record<string, any>; query?: Record<string, string>; locale?: string } = {}) {
  const t = createLayoutTest(layout, {
    componentRegistry: setupRegistry(),
    translations: { auth: koAuth },
    initialState: { _global: options.global ?? {} },
    queryParams: options.query ?? {},
    // 운영의 전역 변수(template-engine createGlobalVariables) — 하네스 문맥에는 없어 직접 싣는다
    initialData: { $locale: options.locale ?? 'ko', $templateId: 'test-template' },
  });
  await t.render();
  // 하네스가 데이터소스용 fetch 를 심은 뒤 바꿔 끼운다 — cleanup 이 원래 fetch 로 되돌린다
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  return t;
}

/**
 * 발견 API 호출만 골라냅니다 (CSRF 쿠키 선행 요청 제외).
 *
 * @returns 호출 인자 목록
 */
function discoverCalls(): Array<[string, RequestInit]> {
  return fetchMock.mock.calls.filter(([url]) => url === '/api/auth/login/discover') as Array<[string, RequestInit]>;
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

/**
 * 이메일 칸에 값을 넣고 칸을 벗어납니다.
 *
 * @param value 입력할 식별자
 */
function typeAndLeave(value: string): void {
  const input = document.querySelector('input[name="email"]') as HTMLInputElement;
  fireEvent.change(input, { target: { value } });
  fireEvent.blur(input);
}

/**
 * 비동기 처리(요청·상태 반영)가 끝나기를 잠깐 기다립니다 — 「일어나지 않음」을 잴 때 쓴다.
 *
 * @returns 완료 약속
 */
function settle(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 30));
}

describe('sirsoft-basic 로그인 이메일 먼저 입력 (7.2.0 B1)', () => {
  beforeEach(() => {
    setupRegistry();
    fetchMock.mockReset();
    locationAssign.mockReset();
    locationReplace.mockReset();
    originalLocation = window.location;
    delete (window as any).location;
    (window as any).location = {
      origin: 'http://localhost', protocol: 'http:', host: 'localhost', hostname: 'localhost', port: '',
      pathname: '/login', search: '', hash: '', href: 'http://localhost/login',
      assign: locationAssign, replace: locationReplace, reload: vi.fn(),
    };
  });

  afterEach(() => {
    (window as any).location = originalLocation;
  });

  /**
   * @scenario identifier_mode=email, discovery=none
   * @effects identifier_field_is_email_by_default
   */
  it('식별자 설정이 없으면 이메일 칸은 type=email 이고 라벨은 「이메일」이다', async () => {
    const t = await renderLogin();
    const input = document.querySelector('input[name="email"]') as HTMLInputElement;

    expect(input.getAttribute('type')).toBe('email');
    expect(input.getAttribute('aria-label')).toBe(koAuth.email);
    expect(document.querySelector('label')?.textContent).toBe(koAuth.email);
    expect(document.body.textContent).not.toContain(koAuth.external.identifier);

    t.cleanup();
  });

  /**
   * @scenario identifier_mode=email_or_username, discovery=none
   * @effects identifier_field_accepts_username
   */
  it('login_identifier.user=email_or_username 이면 type=text 와 「이메일 또는 사내 아이디」 라벨이다', async () => {
    const t = await renderLogin({ global: { appConfig: { auth: { login_identifier: { user: 'email_or_username' } } } } });
    const input = document.querySelector('input[name="email"]') as HTMLInputElement;

    expect(input.getAttribute('type')).toBe('text');
    expect(input.getAttribute('aria-label')).toBe(koAuth.external.identifier);
    expect(input.getAttribute('placeholder')).toBe(koAuth.external.identifier_placeholder);
    expect(document.querySelector('label')?.textContent).toBe(koAuth.external.identifier);

    t.cleanup();
  });

  /**
   * @scenario identifier_mode=email, discovery=external
   * @effects discovery_external_swaps_password_for_continue, discovery_continue_carries_redirect, discovered_notice_shown
   */
  it('외부 수단 판정이면 비밀번호 칸·로그인 버튼 대신 「{label}(으)로 계속」을 보이고 누르면 redirect 쿼리를 실어 이동한다', async () => {
    fetchMock.mockImplementation(async () => jsonResponse(200, {
      success: true,
      data: { method: 'external', label: { ko: '사내 SSO', en: 'Corp SSO' }, redirect_url: '/plugins/sirsoft-sso/start/corp' },
    }));
    const t = await renderLogin({ query: { redirect: '/mypage/orders' } });

    typeAndLeave('kim@corp.test');
    await waitFor(() => expect(buttonByText('사내 SSO(으)로 계속'), '「사내 SSO(으)로 계속」 버튼이 없다').toBeDefined());

    const [[url, init]] = discoverCalls();
    expect(url).toBe('/api/auth/login/discover');
    expect(init.method).toBe('POST');
    expect(JSON.parse(String(init.body))).toEqual({ identifier: 'kim@corp.test' });

    expect(document.querySelector('input[name="password"]')).toBeNull();
    expect(document.querySelector('button[type="submit"]')).toBeNull();
    expect(buttonByText(koAuth.external.use_other_email)).toBeDefined();
    // 왜 비밀번호 칸이 사라졌는지 안내한다(디자인 ②)
    expect(document.body.textContent).toContain(koAuth.external.discovered_notice.replace('{{method}}', '사내 SSO'));

    fireEvent.click(buttonByText('사내 SSO(으)로 계속')!);
    await waitFor(() => expect(locationAssign).toHaveBeenCalledTimes(1));
    expect(locationAssign).toHaveBeenCalledWith('/plugins/sirsoft-sso/start/corp?redirect=%2Fmypage%2Forders');

    t.cleanup();
  });

  /**
   * 수단 이름은 `$localized` 로 읽는다 — 확장이 일부 로케일만 주면 다른 로케일 화면도 그 이름으로 폴백한다(오류 안내 `method_label` 과 같은 처리).
   *
   * @scenario identifier_mode=email, discovery=external
   * @effects discovery_label_falls_back_across_locales
   */
  it('수단 이름에 화면 로케일이 없으면 있는 이름으로 폴백해 「{label}(으)로 계속」을 보인다', async () => {
    fetchMock.mockImplementation(async () => jsonResponse(200, {
      success: true,
      data: { method: 'external', label: { ko: '사내 SSO' }, redirect_url: '/plugins/sirsoft-sso/start/corp' },
    }));
    const t = await renderLogin({ locale: 'en' });

    typeAndLeave('kim@corp.test');
    await waitFor(() => expect(buttonByText('사내 SSO(으)로 계속'), '폴백 이름 버튼이 없다').toBeDefined());
    expect(buttonByText(koAuth.external.continue_with_company)).toBeUndefined();

    t.cleanup();
  });

  /**
   * @scenario identifier_mode=email, discovery=external
   * @effects discovery_continue_carries_redirect
   */
  it('수단 이름이 없으면 「회사 계정으로 계속」이고, 이동 주소에 쿼리가 이미 있으면 & 로 redirect 를 잇는다', async () => {
    fetchMock.mockImplementation(async () => jsonResponse(200, {
      success: true,
      data: { method: 'external', label: null, redirect_url: '/plugins/sirsoft-sso/start/corp?hint=kim' },
    }));
    const t = await renderLogin({ query: { redirect: '/checkout' } });

    typeAndLeave('kim@corp.test');
    await waitFor(() => expect(buttonByText(koAuth.external.continue_with_company)).toBeDefined());
    expect(document.body.textContent).toContain(koAuth.external.discovered_notice_company);

    fireEvent.click(buttonByText(koAuth.external.continue_with_company)!);
    await waitFor(() => expect(locationAssign).toHaveBeenCalledTimes(1));
    expect(locationAssign).toHaveBeenCalledWith('/plugins/sirsoft-sso/start/corp?hint=kim&redirect=%2Fcheckout');

    t.cleanup();
  });

  /**
   * @scenario identifier_mode=email, discovery=external
   * @effects discovery_reset_restores_password_step
   */
  it('「다른 이메일로 로그인」을 누르면 판정을 지우고 비밀번호 칸·로그인 버튼이 돌아오며, 같은 값으로 다시 벗어나면 다시 묻는다', async () => {
    fetchMock.mockImplementation(async () => jsonResponse(200, {
      success: true,
      data: { method: 'external', label: { ko: '사내 SSO' }, redirect_url: '/plugins/sirsoft-sso/start/corp' },
    }));
    const t = await renderLogin();

    typeAndLeave('kim@corp.test');
    await waitFor(() => expect(buttonByText(koAuth.external.use_other_email)).toBeDefined());

    fireEvent.click(buttonByText(koAuth.external.use_other_email)!);
    await waitFor(() => expect(document.querySelector('input[name="password"]')).not.toBeNull());
    expect(document.querySelector('button[type="submit"]')).not.toBeNull();
    expect(buttonByText('사내 SSO(으)로 계속')).toBeUndefined();

    // 판정 기준값(discoveryFor)도 지워졌다 — 같은 주소로 다시 벗어나면 다시 묻는다
    typeAndLeave('kim@corp.test');
    await waitFor(() => expect(discoverCalls().length).toBe(2));

    t.cleanup();
  });

  /**
   * @scenario identifier_mode=email, discovery=failed
   * @effects discovery_password_keeps_current_screen
   */
  /**
   * @scenario identifier_mode=email, discovery=password
   * @effects discovery_password_keeps_current_screen
   */
  it.each([
    ['password 판정', () => jsonResponse(200, { success: true, data: { method: 'password', label: null, redirect_url: null } })],
    ['요청 실패(500)', () => jsonResponse(500, { success: false, message: 'Server Error' })],
  ])('%s 이면 지금 화면(비밀번호 칸·로그인 버튼) 그대로다', async (_label, response) => {
    fetchMock.mockImplementation(async () => response());
    const t = await renderLogin();

    typeAndLeave('lee@example.test');
    await waitFor(() => expect(discoverCalls().length).toBe(1));
    await settle();

    expect(document.querySelector('input[name="password"]')).not.toBeNull();
    expect(document.querySelector('button[type="submit"]')).not.toBeNull();
    expect(buttonByText(koAuth.external.use_other_email)).toBeUndefined();
    expect(locationAssign).not.toHaveBeenCalled();

    t.cleanup();
  });

  /**
   * @scenario identifier_mode=email, discovery=password
   * @effects discovery_skips_unchanged_value
   */
  it('값이 바뀌지 않은 채 다시 칸을 벗어나거나 빈 칸이면 묻지 않고, 값이 바뀌면 다시 묻는다', async () => {
    fetchMock.mockImplementation(async () => jsonResponse(200, { success: true, data: { method: 'password', label: null, redirect_url: null } }));
    const t = await renderLogin();

    typeAndLeave('');
    await settle();
    expect(discoverCalls().length).toBe(0);

    typeAndLeave('lee@example.test');
    await waitFor(() => expect(discoverCalls().length).toBe(1));

    fireEvent.blur(document.querySelector('input[name="email"]') as HTMLInputElement);
    await settle();
    expect(discoverCalls().length).toBe(1);

    typeAndLeave('park@example.test');
    await waitFor(() => expect(discoverCalls().length).toBe(2));
    expect(JSON.parse(String(discoverCalls()[1][1].body))).toEqual({ identifier: 'park@example.test' });

    t.cleanup();
  });
});
