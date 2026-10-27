/**
 * @file forgot-password-disabled.test.tsx
 * @description 비밀번호 찾기·재설정의 「비밀번호 인증이 막힌 계정」 안내 회귀 테스트 (sirsoft-basic, 7.2.0 B3)
 *
 * 회사 계정 로그인이 강제된 계정·도메인이면 서버가 비밀번호 찾기(`/api/auth/forgot-password`)·재설정
 * (`/api/auth/reset-password`)·재설정 링크 확인(`/api/auth/validate-reset-token`)을 422 `errors.code=auth.password_auth_disabled`
 * 로 거절한다. 화면은 토스트 대신 조각 `partials/auth/_password_auth_blocked` 로 안내 문구와 그 수단으로 가는 버튼
 * (`errors.redirect_url` 이 있을 때, 「{method_label}(으)로 로그인」)을 보인다. 재설정 화면은 안내가 서면 폼을 숨긴다.
 *
 * 조각은 실제 JSON 을 그 자리에 끼워 그린다(서버가 조각을 푸는 것과 같은 모양). 그 밖의 조각은 표지 칸으로 바꾼다.
 *
 * @vitest-environment jsdom
 * @since 1.3.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { fireEvent, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';
import { ActionDispatcher } from '@/core/template-engine/ActionDispatcher';

import forgotPage from '../../layouts/auth/forgot_password.json';
import resetPage from '../../layouts/auth/reset_password.json';
import blockedPartial from '../../layouts/partials/auth/_password_auth_blocked.json';
import koAuth from '../../lang/partial/ko/auth.json';

/** DOM 에 넘길 속성 */
const PASS_PROPS = ['id', 'className', 'role', 'type', 'name', 'href', 'disabled', 'aria-label', 'onClick', 'onChange'];

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
    H1: basic('h1', 'H1'),
    A: basic('a', 'A'),
    Label: basic('label', 'Label'),
    Form: basic('form', 'Form'),
    Button: basic('button', 'Button'),
    Input: basic('input', 'Input'),
    PasswordInput: { component: (p: any) => <input type="password" name={p.name} onChange={p.onChange} />, metadata: { name: 'PasswordInput', type: 'basic' } },
    Icon: { component: () => null, metadata: { name: 'Icon', type: 'basic' } },
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

const BLOCKED_PARTIAL = 'partials/auth/_password_auth_blocked.json';

/**
 * 안내 조각 참조는 실제 조각 JSON 으로, 그 밖의 조각은 표지 칸으로 바꿉니다.
 *
 * @param node 노드
 * @returns 바꾼 노드
 */
function resolvePartials(node: any): any {
  if (Array.isArray(node)) return node.map(resolvePartials);
  if (!node || typeof node !== 'object') return node;
  if (node.partial === BLOCKED_PARTIAL) return blockedPartial;
  if (node.partial) return { type: 'basic', name: 'Div', props: { id: `partial:${node.partial}` } };
  return { ...node, ...(Array.isArray(node.children) ? { children: node.children.map(resolvePartials) } : {}) };
}

/**
 * 화면에서 안내 조각을 담은 카드를 찾습니다.
 *
 * @param page 화면 JSON
 * @returns 카드 노드
 */
function cardOf(page: any): any {
  return flatten(page).find((n) => Array.isArray(n.children) && n.children.some((c: any) => c.partial === BLOCKED_PARTIAL));
}

const forgotCard = cardOf(forgotPage);
const resetCard = cardOf(resetPage);

/** 같은 탭 이동(`redirect` 핸들러)의 종착점 */
const locationAssign = vi.fn();
let originalLocation: Location;
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

/** 회사 계정 로그인 강제 거절(422) 응답 */
const BLOCKED_BODY = {
  success: false,
  message: '이 계정은 사내 SSO(으)로만 로그인할 수 있습니다.',
  errors: { code: 'auth.password_auth_disabled', method_label: { ko: '사내 SSO', en: 'Corp SSO' }, redirect_url: '/plugins/sirsoft-sso/start/corp' },
};

/**
 * 카드를 그리고 fetch 를 바꿔 끼웁니다.
 *
 * @param card 카드 노드
 * @param response 제출 응답
 * @returns 레이아웃 시험 하네스
 */
async function renderCard(card: any, response: Response) {
  const t = createLayoutTest(
    { version: '1.0.0', layout_name: 'password_auth_blocked_test', components: [resolvePartials(card)] } as any,
    {
      componentRegistry: setupRegistry(),
      translations: { auth: koAuth },
      queryParams: { token: 'tok', email: 'kim@corp.test' },
      // 운영의 전역 변수(template-engine createGlobalVariables) — 하네스 문맥에는 없어 직접 싣는다
      initialData: { $locale: 'ko', $templateId: 'test-template' },
    },
  );
  await t.render();
  fetchMock.mockImplementation(async (url: string) => (String(url).includes('csrf') ? jsonResponse(204, {}) : response));
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  return t;
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
 * 쌓인 토스트를 돌려줍니다 — 엔진 `toast` 는 `_global.toasts` 에 쌓는다.
 *
 * @param t 레이아웃 시험 하네스
 * @returns 토스트 목록 (종류·문구)
 */
function toastsOf(t: { getState: () => { _global: any } }): Array<{ type: string; message: string }> {
  return (t.getState()._global.toasts ?? []).map((toast: any) => ({ type: toast.type, message: toast.message }));
}

/**
 * 안내 조각(data-ref `password_auth_blocked`)을 찾습니다.
 *
 * @returns 안내 요소 (없으면 null)
 */
function blockedNotice(): HTMLElement | null {
  return document.getElementById('password_auth_blocked');
}

describe('sirsoft-basic 비밀번호 찾기·재설정 차단 안내 (7.2.0 B3)', () => {
  beforeEach(() => {
    setupRegistry();
    fetchMock.mockReset();
    locationAssign.mockReset();
    originalLocation = window.location;
    delete (window as any).location;
    (window as any).location = {
      origin: 'http://localhost', protocol: 'http:', host: 'localhost', hostname: 'localhost', port: '',
      pathname: '/forgot-password', search: '', hash: '', href: 'http://localhost/forgot-password',
      assign: locationAssign, replace: vi.fn(), reload: vi.fn(),
    };
  });

  afterEach(() => {
    (window as any).location = originalLocation;
  });

  /**
   * @scenario surface=forgot_password, phase=submit, response=password_auth_disabled
   * @effects forgot_password_blocked_shows_notice, password_blocked_offers_method_button
   */
  it('비밀번호 찾기가 422 auth.password_auth_disabled 면 토스트 대신 안내와 「{method_label}(으)로 로그인」 버튼을 보인다', async () => {
    expect(forgotCard, 'forgot_password.g7.html 에 안내 조각 자리가 없다').toBeDefined();
    const t = await renderCard(forgotCard, jsonResponse(422, BLOCKED_BODY));

    fireEvent.change(document.querySelector('input[name="email"]')!, { target: { value: 'kim@corp.test' } });
    fireEvent.submit(document.querySelector('form')!);

    await waitFor(() => expect(blockedNotice()).not.toBeNull());
    expect(blockedNotice()!.textContent).toContain(BLOCKED_BODY.message);
    expect(toastsOf(t)).toEqual([]);
    expect(fetchMock.mock.calls.some(([url]) => url === '/api/auth/forgot-password')).toBe(true);

    fireEvent.click(buttonByText('사내 SSO(으)로 로그인')!);
    await waitFor(() => expect(locationAssign).toHaveBeenCalledWith('/plugins/sirsoft-sso/start/corp'));

    t.cleanup();
  });

  /**
   * @scenario surface=forgot_password, phase=submit, response=password_auth_disabled
   * @effects forgot_password_blocked_shows_notice
   */
  it('이동 경로가 없으면 안내만 보이고 수단 버튼은 없다', async () => {
    const body = { ...BLOCKED_BODY, errors: { code: 'auth.password_auth_disabled', method_label: null, redirect_url: null } };
    const t = await renderCard(forgotCard, jsonResponse(422, body));

    fireEvent.submit(document.querySelector('form')!);

    await waitFor(() => expect(blockedNotice()).not.toBeNull());
    expect(blockedNotice()!.querySelector('button')).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario surface=forgot_password, phase=submit, response=other_error
   * @effects password_blocked_branch_ignores_other_failures
   */
  it('다른 422(칸 오류)는 지금처럼 토스트로 알리고 안내 조각은 없다', async () => {
    const t = await renderCard(forgotCard, jsonResponse(422, { success: false, message: '이메일 형식이 올바르지 않습니다.', errors: { email: ['이메일 형식이 올바르지 않습니다.'] } }));

    fireEvent.submit(document.querySelector('form')!);

    await waitFor(() => expect(toastsOf(t)).toEqual([{ type: 'error', message: '이메일 형식이 올바르지 않습니다.' }]));
    expect(blockedNotice()).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario surface=reset_password, phase=submit, response=password_auth_disabled
   * @effects reset_password_blocked_hides_form, password_blocked_offers_method_button
   */
  it('재설정 제출이 422 auth.password_auth_disabled 면 폼을 숨기고 안내와 수단 버튼을 보인다', async () => {
    expect(resetCard, 'reset_password.g7.html 에 안내 조각 자리가 없다').toBeDefined();
    const t = await renderCard(resetCard, jsonResponse(422, BLOCKED_BODY));

    expect(document.querySelector('form')).not.toBeNull();
    fireEvent.submit(document.querySelector('form')!);

    await waitFor(() => expect(blockedNotice()).not.toBeNull());
    expect(blockedNotice()!.textContent).toContain(BLOCKED_BODY.message);
    expect(document.querySelector('form')).toBeNull();
    expect(toastsOf(t)).toEqual([]);
    expect(fetchMock.mock.calls.some(([url]) => url === '/api/auth/reset-password')).toBe(true);

    fireEvent.click(buttonByText('사내 SSO(으)로 로그인')!);
    await waitFor(() => expect(locationAssign).toHaveBeenCalledWith('/plugins/sirsoft-sso/start/corp'));

    t.cleanup();
  });

  /**
   * @scenario surface=reset_password, phase=token_validation, response=other_error
   * @effects reset_token_check_blocked_shows_notice
   */
  /**
   * @scenario surface=reset_password, phase=submit, response=other_error
   * @effects password_blocked_branch_ignores_other_failures
   */
  it('재설정 제출의 다른 422(만료된 링크)는 지금처럼 토스트로 알리고 폼을 남긴다', async () => {
    const t = await renderCard(resetCard, jsonResponse(422, { success: false, message: '재설정 링크가 만료되었습니다.', errors: { email: ['재설정 링크가 만료되었습니다.'] } }));

    fireEvent.submit(document.querySelector('form')!);

    await waitFor(() => expect(toastsOf(t)).toEqual([{ type: 'error', message: '재설정 링크가 만료되었습니다.' }]));
    expect(blockedNotice()).toBeNull();
    expect(document.querySelector('form')).not.toBeNull();

    t.cleanup();
  });

  /**
   * 데이터소스 오류 처리기의 문맥은 운영에서 `{error, route, query, auth}` 다(ActionDispatcher setActionExecutor) — 그대로 싣는다.
   *
   * @scenario surface=reset_password, phase=token_validation, response=password_auth_disabled
   * @effects reset_token_check_blocked_shows_notice
   */
  it('재설정 링크 확인이 422 auth.password_auth_disabled 면 「링크 무효」 대신 안내 상태를 세우고, 그 밖의 422 는 링크 무효다', async () => {
    const handler = (resetPage as any).data_sources.find((s: any) => s.id === 'tokenValidation')?.errorHandling?.['422'];
    expect(handler, 'tokenValidation 422 처리기가 없다').toBeDefined();

    /**
     * 처리기를 오류 문맥으로 실행하고 지역 상태 쓰기를 돌려줍니다.
     *
     * @param error 오류 문맥
     * @returns setState 로 쓴 값
     */
    const run = async (error: Record<string, unknown>) => {
      const setState = vi.fn();
      const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
      await dispatcher.createHandler({ ...handler, type: 'click' }, { error, route: {}, query: { token: 'tok' } }, { state: {}, setState })(new Event('init'));
      return setState.mock.calls[0]?.[0];
    };

    const blocked = await run({ status: 422, message: BLOCKED_BODY.message, errors: BLOCKED_BODY.errors });
    // 처리기 문맥에는 `$locale` 이 없다 — 라벨은 `$localized` 로 골라야 버튼이 「회사 계정으로 로그인」으로 떨어지지 않는다
    expect(blocked).toMatchObject({ tokenError: false, passwordAuthBlocked: BLOCKED_BODY.message, passwordAuthUrl: '/plugins/sirsoft-sso/start/corp', passwordAuthLabel: '사내 SSO' });

    const invalid = await run({ status: 422, message: '링크가 만료되었습니다.', errors: { token: ['링크가 만료되었습니다.'] } });
    expect(invalid).toEqual({ tokenError: true, passwordAuthBlocked: null, passwordAuthUrl: null, passwordAuthLabel: null });
  });

  /**
   * @effects password_blocked_reads_error_errors_only
   */
  it('안내 데이터는 error.errors.* 로만 읽고 error.data 를 쓰지 않는다', () => {
    const text = JSON.stringify([forgotPage, resetPage, blockedPartial], (key, value) => (key === 'comment' ? undefined : value));

    expect(text.includes('error.data')).toBe(false);
    expect(text.includes("error.errors?.code === 'auth.password_auth_disabled'")).toBe(true);
  });
});
