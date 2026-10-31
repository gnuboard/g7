/**
 * @file mypage-reauth-external.test.tsx
 * @description 정보 수정 재확인 관문의 수단 선택·외부 재확인 복귀 회귀 테스트 (sirsoft-basic, 7.2.0 B6)
 *
 * 비밀번호 없는 회원의 정보 수정 관문(`partials/mypage/profile/_password_verify_section`)은 재확인 요청
 * (`POST /api/identity/challenges`)에 수단(`provider_id`)을 싣지 않는다 — 서버가 그 회원에게 쓸 수 있는 수단을 고른다(K17).
 * 응답이 `render_hint=external_redirect` 면 `redirect_url` 로 같은 탭 이동(회사 계정 다시 로그인 등)하고, 아니면 지금처럼
 * 인증번호 칸을 연다.
 *
 * 외부 수단에서 돌아오면 `/mypage/profile/edit?verification_token=…` 이다. 화면 진입(`mypage/profile-edit` `<init>`)이 그 토큰을
 * 비밀번호 확인 API(`/api/me/verify-password`)에 실어 재확인을 마치고(`_local.isPasswordVerified=true`) 주소에서 토큰을 지운다.
 *
 * @vitest-environment jsdom
 * @since 1.3.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { fireEvent, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';
import { ActionDispatcher } from '@/core/template-engine/ActionDispatcher';

import verifySection from '../../layouts/partials/mypage/profile/_password_verify_section.json';
import profileEditPage from '../../layouts/mypage/profile-edit.json';
import koMypage from '../../lang/partial/ko/mypage.json';

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
    H2: basic('h2', 'H2'),
    A: basic('a', 'A'),
    Label: basic('label', 'Label'),
    Form: basic('form', 'Form'),
    Button: basic('button', 'Button'),
    Input: basic('input', 'Input'),
    PasswordInput: { component: (p: any) => <input type="password" name={p.name} />, metadata: { name: 'PasswordInput', type: 'basic' } },
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
  return [node, ...children];
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

/** 외부 재확인에서 돌아왔을 때의 진입 시퀀스 */
const returnSequence = asArray<any>((profileEditPage as any).init_actions).find((a) => String(a.if ?? '').includes('verification_token'));

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

/**
 * 경로별 응답을 정해 fetch 를 바꿔 끼웁니다.
 *
 * @param routes 경로 → 응답
 */
function routeFetch(routes: Record<string, () => Response>): void {
  fetchMock.mockImplementation(async (url: string) => {
    const hit = Object.entries(routes).find(([path]) => url === path);
    return hit ? hit[1]() : jsonResponse(204, {});
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
}

/**
 * 특정 경로 호출만 골라냅니다.
 *
 * @param path 경로
 * @returns 호출 인자 목록
 */
function callsTo(path: string): Array<[string, RequestInit]> {
  return fetchMock.mock.calls.filter(([url]) => url === path) as Array<[string, RequestInit]>;
}

/**
 * 비밀번호 없는 회원의 관문을 그립니다.
 *
 * @param emailDeliverable 가입 이메일로 메일을 받을 수 있는지 (false = 대체 이메일 회원)
 * @returns 레이아웃 시험 하네스
 */
async function renderGate(emailDeliverable = true) {
  const t = createLayoutTest(
    { version: '1.0.0', layout_name: 'mypage/profile-edit', components: [verifySection as any] } as any,
    {
      componentRegistry: setupRegistry(),
      translations: { mypage: koMypage },
      initialState: { _local: { isPasswordVerified: false } },
      initialData: { user: { data: { has_password: false, email_deliverable: emailDeliverable } }, $locale: 'ko', $templateId: 'test-template' },
    },
  );
  await t.render();
  return t;
}

describe('sirsoft-basic 정보 수정 재확인 수단 선택 (7.2.0 B6)', () => {
  beforeEach(() => {
    setupRegistry();
    fetchMock.mockReset();
    locationAssign.mockReset();
    originalLocation = window.location;
    delete (window as any).location;
    (window as any).location = {
      origin: 'http://localhost', protocol: 'http:', host: 'localhost', hostname: 'localhost', port: '',
      pathname: '/mypage/profile/edit', search: '', hash: '', href: 'http://localhost/mypage/profile/edit',
      assign: locationAssign, replace: vi.fn(), reload: vi.fn(),
    };
  });

  afterEach(() => {
    (window as any).location = originalLocation;
    vi.restoreAllMocks();
  });

  /**
   * @effects reauth_challenge_omits_provider
   */
  it('재확인 요청 본문에 provider_id 가 없고(수단은 서버가 고른다) 외부 수단이 돌려보낼 정보 수정 화면 경로를 싣는다', () => {
    const codeSection = flatten(verifySection).find((n) => n.id === 'reauth_email_code_section');
    const request = flattenActions(flatten(codeSection).flatMap((n) => asArray<any>(n.actions)))
      .find((a) => a.handler === 'apiCall' && a.target === '/api/identity/challenges');

    expect(request?.params?.body).toEqual({ purpose: 'sensitive_action', return_path: '/mypage/profile/edit' });
    expect(JSON.stringify(verifySection, (key, value) => (key === 'comment' ? undefined : value))).not.toContain('g7:core.mail');
  });

  /**
   * @scenario phase=request, outcome=external_redirect, email_deliverable=true
   * @effects reauth_external_redirects
   */
  it('응답이 render_hint=external_redirect 면 redirect_url 로 같은 탭 이동하고 인증번호 칸을 열지 않는다', async () => {
    const t = await renderGate();
    routeFetch({
      '/api/identity/challenges': () => jsonResponse(201, {
        success: true,
        data: { id: 'ch-1', provider_id: 'sirsoft-sso:corp', render_hint: 'external_redirect', redirect_url: '/plugins/sirsoft-sso/reauth/ch-1', expires_at: '2026-10-22T10:00:00+09:00' },
      }),
    });

    fireEvent.click(document.getElementById('reauth_request_code_button')!);

    await waitFor(() => expect(locationAssign).toHaveBeenCalledWith('/plugins/sirsoft-sso/reauth/ch-1'));
    const [[, init]] = callsTo('/api/identity/challenges');
    expect(JSON.parse(String(init.body))).toEqual({ purpose: 'sensitive_action', return_path: '/mypage/profile/edit' });
    expect(document.querySelector('input[name="reauth_code"]')).toBeNull();
    expect(t.getState()._global.toasts ?? []).toEqual([]);

    t.cleanup();
  });

  /**
   * @scenario phase=request, outcome=external_redirect_without_url, email_deliverable=true
   * @effects reauth_external_without_url_releases_button
   */
  it('external_redirect 인데 갈 주소가 없으면 이동하지 않고 버튼을 다시 열어 안내를 싣는다(버튼 고착 방지)', async () => {
    const t = await renderGate();
    routeFetch({
      '/api/identity/challenges': () => jsonResponse(201, {
        success: true,
        data: { id: 'ch-3', provider_id: 'sirsoft-sso:corp', render_hint: 'external_redirect', redirect_url: null, expires_at: '2026-10-22T10:00:00+09:00' },
      }),
    });

    const button = document.getElementById('reauth_request_code_button') as HTMLButtonElement;
    fireEvent.click(button);

    const alertText = () => Array.from(document.querySelectorAll('[role="alert"]')).map((el) => el.textContent ?? '');
    await waitFor(() => expect(alertText()).toContain(koMypage.profile.reauth_external_failed));
    expect((document.getElementById('reauth_request_code_button') as HTMLButtonElement).disabled).toBe(false);
    expect(locationAssign).not.toHaveBeenCalled();
    expect(document.querySelector('input[name="reauth_code"]')).toBeNull();
    expect(koMypage.profile.reauth_external_failed).toBeTruthy();

    t.cleanup();
  });

  /**
   * @scenario phase=request, outcome=text_code, email_deliverable=true
   * @effects reauth_text_code_keeps_code_flow
   */
  it('그 밖의 응답(text_code)이면 지금처럼 인증번호 칸을 열고 「보냈습니다」 안내를 띄운다', async () => {
    const t = await renderGate();
    routeFetch({
      '/api/identity/challenges': () => jsonResponse(201, {
        success: true,
        data: { id: 'ch-2', provider_id: 'g7:core.mail', render_hint: 'text_code', redirect_url: null, expires_at: '2026-10-22T10:00:00+09:00' },
      }),
    });

    fireEvent.click(document.getElementById('reauth_request_code_button')!);

    await waitFor(() => expect(document.querySelector('input[name="reauth_code"]')).not.toBeNull());
    expect(locationAssign).not.toHaveBeenCalled();
    // 하네스의 ActionDispatcher 에는 번역 엔진이 묶이지 않아 토스트 문구가 키 그대로 쌓인다(운영은 handleToast 가 푼다)
    expect((t.getState()._global.toasts ?? []).map((toast: any) => toast.message)).toEqual(['$t:mypage.profile.reauth_code_sent']);
    expect(koMypage.profile.reauth_code_sent).toBeTruthy();

    t.cleanup();
  });

  /**
   * 메일을 받을 수 없는 회원(대체 이메일 — 이메일을 주지 않는 회사 계정 등)도 서버 판정 요청에 닿아야 한다(W17·B6).
   * 수정 전에는 그 요청이 `email_deliverable !== false` 칸에만 있어, 확장점(소셜 로그인)만 보이고 회사 계정 재확인에 닿지 못했다.
   *
   * @scenario phase=request, outcome=external_redirect, email_deliverable=false
   * @effects reauth_mailless_reaches_server_choice
   */
  it('메일을 받을 수 없는 회원도 「본인 확인 계속하기」로 서버 판정을 받고 외부 수단이면 그 화면으로 간다', async () => {
    const t = await renderGate(false);
    routeFetch({
      '/api/identity/challenges': () => jsonResponse(201, {
        success: true,
        data: { id: 'ch-9', provider_id: 'sirsoft-sso:corp', render_hint: 'external_redirect', redirect_url: '/plugins/sirsoft-sso/reauth/ch-9', expires_at: '2026-10-25T23:00:00+09:00' },
      }),
    });

    await waitFor(() => expect(document.getElementById('reauth_continue_button'), '메일 없는 회원 칸에 서버 판정 요청 버튼이 없다').not.toBeNull());
    const button = document.getElementById('reauth_continue_button');
    // 확장 수단(소셜 로그인 등)은 그 아래 보조 수단으로 남는다
    const alternative = flatten(verifySection).find((n) => n.id === 'reauth_alternative_section');
    expect(flatten(alternative).some((n) => n.name === 'mypage_reauth_methods')).toBe(true);

    fireEvent.click(button!);

    await waitFor(() => expect(locationAssign).toHaveBeenCalledWith('/plugins/sirsoft-sso/reauth/ch-9'));
    const [[, init]] = callsTo('/api/identity/challenges');
    expect(JSON.parse(String(init.body))).toEqual({ purpose: 'sensitive_action', return_path: '/mypage/profile/edit' });

    t.cleanup();
  });

  /**
   * 메일 없는 회원의 요청도 같은 복귀 경로를 싣는다 — 확장이 이 화면(레이아웃 이름 `mypage/profile-edit`)에 붙이는 재확인 결과 안내
   * (예: 회사 계정 재확인 실패 `?sso_error=` 토스트)가 두 갈래 모두에서 같은 화면으로 돌아와야 보인다.
   *
   * @effects reauth_mailless_reaches_server_choice
   */
  it('메일 없는 회원 칸의 요청도 정보 수정 화면(mypage/profile-edit)으로 돌아오는 경로를 싣는다', () => {
    const alternative = flatten(verifySection).find((n) => n.id === 'reauth_alternative_section');
    const request = flattenActions(flatten(alternative).flatMap((n) => asArray<any>(n.actions)))
      .find((a) => a.handler === 'apiCall' && a.target === '/api/identity/challenges');

    expect(request?.params?.body).toEqual({ purpose: 'sensitive_action', return_path: '/mypage/profile/edit' });
    expect((profileEditPage as any).layout_name).toBe('mypage/profile-edit');
  });

  /**
   * @scenario phase=request, outcome=text_code, email_deliverable=false
   * @effects reauth_mailless_code_flow
   */
  it('메일을 받을 수 없는 회원에게 서버가 인증번호 수단을 고르면 인증번호 칸을 열되 「이메일로 보냈습니다」 라고 하지 않는다', async () => {
    const t = await renderGate(false);
    routeFetch({
      '/api/identity/challenges': () => jsonResponse(201, {
        success: true,
        data: { id: 'ch-10', provider_id: 'zz:sms', render_hint: 'text_code', redirect_url: null, expires_at: '2026-10-25T23:00:00+09:00' },
      }),
    });

    await waitFor(() => expect(document.getElementById('reauth_continue_button')).not.toBeNull());
    fireEvent.click(document.getElementById('reauth_continue_button')!);

    await waitFor(() => expect(document.querySelector('input[name="reauth_code"]')).not.toBeNull());
    expect(locationAssign).not.toHaveBeenCalled();
    expect((t.getState()._global.toasts ?? []).map((toast: any) => toast.message)).toEqual(['$t:mypage.profile.reauth_code_sent_generic']);
    expect(koMypage.profile.reauth_code_sent_generic).toBeTruthy();
    // 「가입한 이메일로 인증번호를 받아」 안내는 메일을 받을 수 있는 회원에게만
    expect(document.body.textContent).not.toContain(koMypage.profile.reauth_notice);

    t.cleanup();
  });

  /**
   * @scenario phase=request, outcome=failed, email_deliverable=false
   * @effects reauth_mailless_failure_shows_error
   */
  it('메일을 받을 수 없는 회원의 서버 판정이 실패하면 버튼을 다시 열고 서버 안내를 싣는다', async () => {
    const t = await renderGate(false);
    routeFetch({
      '/api/identity/challenges': () => jsonResponse(422, { success: false, message: '사용할 수 있는 본인 확인 수단이 없습니다.' }),
    });

    await waitFor(() => expect(document.getElementById('reauth_continue_button')).not.toBeNull());
    fireEvent.click(document.getElementById('reauth_continue_button')!);

    const alertText = () => Array.from(document.querySelectorAll('[role="alert"]')).map((el) => el.textContent ?? '');
    await waitFor(() => expect(alertText()).toContain('사용할 수 있는 본인 확인 수단이 없습니다.'));
    expect((document.getElementById('reauth_continue_button') as HTMLButtonElement).disabled).toBe(false);

    t.cleanup();
  });

  /**
   * @scenario phase=return, outcome=verified, email_deliverable=true
   * @effects reauth_return_completes_gate
   */
  it('verification_token 을 들고 돌아오면 진입 시 그 토큰으로 재확인을 마치고 주소에서 토큰을 지운다', async () => {
    expect(returnSequence, 'profile-edit.g7.html <init> 에 verification_token 복귀 시퀀스가 없다').toBeDefined();
    routeFetch({ '/api/me/verify-password': () => jsonResponse(200, { success: true, data: { verified: true } }) });
    const replaceState = vi.spyOn(window.history, 'replaceState').mockImplementation(() => undefined);

    const setState = vi.fn();
    const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    const context = { _local: { isPasswordVerified: false }, _global: {}, query: { verification_token: 'vt-1' }, route: { path: '/mypage/profile/edit' } };
    await dispatcher.createHandler({ ...returnSequence, type: 'click' }, context, { state: context._local, setState })(new Event('init'));

    const [[, init]] = callsTo('/api/me/verify-password');
    expect(init.method).toBe('POST');
    expect(JSON.parse(String(init.body))).toEqual({ verification_token: 'vt-1' });
    expect(setState.mock.calls.some(([updates]) => updates?.isPasswordVerified === true)).toBe(true);
    expect(replaceState).toHaveBeenCalledWith(null, '', '/mypage/profile/edit');
  });

  /**
   * @scenario phase=return, outcome=rejected, email_deliverable=true
   * @effects reauth_return_failure_shows_error
   */
  it('돌아온 토큰이 거절되면 관문에 머물며 서버 안내를 싣는다', async () => {
    routeFetch({ '/api/me/verify-password': () => jsonResponse(422, { success: false, message: '확인 토큰이 만료되었습니다.', errors: { verification_token: ['확인 토큰이 만료되었습니다.'] } }) });
    vi.spyOn(window.history, 'replaceState').mockImplementation(() => undefined);

    const setState = vi.fn();
    const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    const context = { _local: { isPasswordVerified: false }, _global: {}, query: { verification_token: 'vt-expired' }, route: { path: '/mypage/profile/edit' } };
    await dispatcher.createHandler({ ...returnSequence, type: 'click' }, context, { state: context._local, setState })(new Event('init'));

    expect(setState.mock.calls.some(([updates]) => updates?.isPasswordVerified === true)).toBe(false);
    expect(setState.mock.calls.some(([updates]) => updates?.reauthError === '확인 토큰이 만료되었습니다.')).toBe(true);
  });

  /**
   * @effects reauth_return_only_with_token
   */
  it('토큰 없이 들어오면 재확인 API 를 부르지 않는다', () => {
    expect(returnSequence?.if).toBe('{{!!query?.verification_token}}');
  });
});
