/**
 * @file admin-reset-password-blocked-render.test.tsx
 * @description 관리자 비밀번호 재설정 링크 검증 결과가 화면 초기화(init)에 지워지지 않는지 렌더로 확인하는 회귀 테스트 (sirsoft-admin_basic 1.2.0)
 *
 * 링크 검증(`tokenValidation`, blocking)의 오류 처리기는 데이터를 받는 자리에서 바로 돌고, 레이아웃 `init` 은 그 뒤에 돈다
 * (엔진 순서: 데이터소스 오류 처리기 → init_actions → 렌더). 그래서 처리기가 넣은 값을 init 이 다시 쓰면 그 값은 화면에 닿지 못한다.
 * 비밀번호 인증이 막힌 계정(422 `auth.password_auth_disabled`)의 안내 문구가 init 의 오류 초기화에 지워지고, 이동 버튼도 그 오류 상자
 * 안에 있어 함께 사라져 제목만 남은 화면이 됐다.
 *
 * 이 파일은 실제 레이아웃 JSON 을 렌더한다 — 처리기가 남긴 `_local` 을 처리기 식 그대로 계산해 심고(오류 문맥은 운영 응답 모양),
 * 실제 init 을 ActionDispatcher 로 돌린 뒤 화면을 본다. 시험 하네스의 데이터소스 실패 기록은 응답의 `errors` 를 싣지 않으므로
 * (`{ message, status }` 만) 처리기 실행 대신 처리기 결과를 심는다.
 *
 * @vitest-environment jsdom
 * @since 1.2.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import resetLayout from '../../layouts/admin_reset_password.json';

type Json = Record<string, any>;
type Common = { className?: string; children?: React.ReactNode; text?: string };

const TestDiv: React.FC<Common & { role?: string }> = ({ className, children, role }) => (
  <div className={className} role={role}>{children}</div>
);
const TestSpan: React.FC<Common> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<Common> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestH1: React.FC<Common> = ({ className, children, text }) => <h1 className={className}>{children || text}</h1>;
const TestA: React.FC<Common & { href?: string }> = ({ className, children, text, href }) => (
  <a className={className} href={href}>{children || text}</a>
);
const TestLabel: React.FC<Common> = ({ className, children, text }) => <label className={className}>{children || text}</label>;
const TestForm: React.FC<Common> = ({ className, children }) => <form className={className}>{children}</form>;
const TestButton: React.FC<Common & { type?: string; disabled?: boolean }> = ({ type, className, disabled, children, text }) => (
  <button type={type as 'button' | 'submit'} className={className} disabled={disabled}>{children || text}</button>
);
const TestInput: React.FC<{ id?: string; type?: string; name?: string; className?: string }> = ({ id, type, name, className }) => (
  <input id={id} type={type} name={name} className={className} readOnly />
);
const TestSelect: React.FC<{ className?: string }> = ({ className }) => <select className={className} />;
const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;
const TestToast: React.FC = () => <div data-testid="toast-host" />;
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

/**
 * 이 화면이 쓰는 기본 컴포넌트를 시험용으로 등록합니다.
 *
 * @returns 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Form: { component: TestForm, metadata: { name: 'Form', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Toast: { component: TestToast, metadata: { name: 'Toast', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
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

/**
 * 컴포넌트 트리의 액션을 하위 액션까지 모두 모읍니다.
 *
 * @param node 컴포넌트(들)
 * @returns 액션 목록
 */
function collectActions(node: any): Json[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(collectActions);
  const nested = (actions: any): Json[] =>
    asArray<Json>(actions).flatMap((a) => [a, ...nested(a.actions), ...nested(a.onSuccess), ...nested(a.onError)]);
  return [...nested(node.actions), ...collectActions(node.children)];
}

/**
 * 링크 검증 데이터소스를 찾습니다.
 *
 * @returns 데이터소스 정의
 */
function tokenSource(): Json {
  const source = asArray<Json>((resetLayout as Json).data_sources).find((s) => s.id === 'tokenValidation');
  expect(source, '링크 검증 데이터소스가 사라졌습니다').toBeDefined();
  return source as Json;
}

/**
 * 링크 검증 오류 처리기를 그대로 계산해 남길 `_local` 을 만듭니다 (setState 만, `if` 가 참인 것만).
 *
 * @param status 응답 상태
 * @param error 오류 문맥 (운영의 데이터소스 오류 문맥과 같은 `{ message, errors }`)
 * @returns 처리기가 남기는 `_local`
 */
function handlerOutput(status: number, error: Json): Json {
  const handling = tokenSource().errorHandling ?? {};
  const scope = {
    error: { status, ...error },
    $localized: (value: any) => (value && typeof value === 'object' ? (value.ko ?? '') : (value ?? '')),
  };
  const local: Json = {};
  for (const action of asArray<Json>(handling[String(status)] ?? handling.default)) {
    if (action.handler !== 'setState') continue;
    if (action.if !== undefined && !evaluate(action.if, scope)) continue;
    for (const [key, value] of Object.entries(action.params ?? {})) {
      if (key === 'target') continue;
      local[key] = typeof value === 'string' && value.startsWith('{{') ? evaluate(value, scope) : value;
    }
  }
  return local;
}

/**
 * 링크 검증 오류 처리기들이 쓰는 `_local` 키 전부.
 *
 * @returns 키 목록
 */
function handlerKeys(): string[] {
  const handling = tokenSource().errorHandling ?? {};
  return Object.values(handling)
    .flatMap((def) => asArray<Json>(def))
    .filter((action) => action.handler === 'setState' && (action.params?.target ?? 'local') === 'local')
    .flatMap((action) => Object.keys(action.params ?? {}).filter((key) => key !== 'target'));
}

/**
 * init 의 setState(local) 가 쓰는 키 전부.
 *
 * @returns 키 목록
 */
function initKeys(): string[] {
  const init = (resetLayout as Json).initActions ?? (resetLayout as Json).init_actions ?? [];
  return asArray<Json>(init)
    .filter((action) => action.handler === 'setState' && (action.params?.target ?? 'local') === 'local')
    .flatMap((action) => Object.keys(action.params ?? {}).filter((key) => key !== 'target'));
}

// 운영 응답 모양 — 소유 도메인 강제가 켜진 뒤 연 재설정 링크(validate-reset-token 422)
const DISABLED = {
  message: '이 계정은 비밀번호로 로그인할 수 없습니다. 모의 회사 계정(으)로 로그인하세요.',
  errors: {
    code: 'auth.password_auth_disabled',
    method_label: { ko: '모의 회사 계정', en: 'Mock company account' },
    redirect_url: '/plugins/sirsoft-sso/login/mock_oidc?guard=admin',
  },
};
const INVALID = { message: '유효하지 않은 재설정 링크입니다.', errors: { token: ['유효하지 않은 재설정 링크입니다.'] } };

let harness: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 처리기 결과를 심고 실제 init 을 돌린 뒤 렌더합니다.
 *
 * @param local 링크 검증 오류 처리기가 남긴 `_local`
 * @returns 렌더 결과 컨테이너
 */
async function renderAfterHandler(local: Json): Promise<HTMLElement> {
  harness = createLayoutTest(resetLayout as any, {
    initialState: { _local: local },
    queryParams: { token: 't'.repeat(64), email: 'p.member@corp720.test' },
    routePath: '/admin/reset-password',
  });
  // 링크 검증 자체는 성공으로 두어 하네스가 처리기를 다시 돌리지 않게 한다(처리기 결과는 위에서 심었다)
  harness.mockApi('tokenValidation', { response: {} });
  const { container } = await harness.render();
  return container;
}

// 렌더러·디스패처가 setState(local) 사이에 창 전역으로 남기는 동기화 값 — 시험마다 새 페이지처럼 비운다(운영은 링크를 새로 열 때마다 새 페이지다)
const LOCAL_SYNC_GLOBALS = [
  '__g7PendingLocalState',
  '__g7ForcedLocalFields',
  '__g7LastSetLocalSnapshot',
  '__g7SetLocalOverrideKeys',
  '__g7SetLocalReplacedKeys',
  '__g7SequenceLocalSync',
  '__g7LayoutContextStack',
];

describe('sirsoft-admin_basic 관리자 비밀번호 재설정 — 링크 검증 결과가 init 뒤에도 남는다', () => {
  beforeEach(() => {
    for (const key of LOCAL_SYNC_GLOBALS) {
      delete (window as any)[key];
    }
    setupRegistry();
  });

  afterEach(() => {
    harness?.cleanup();
    harness = null;
  });

  /**
   * 비밀번호 인증이 막힌 계정이 링크를 열면 안내 문구와 다른 수단 버튼이 보이고 비밀번호 칸은 없다.
   *
   * @scenario surface=reset, step=token_check, response=422_password_auth_disabled
   * @effects reset_token_check_result_survives_init
   */
  it('422 auth.password_auth_disabled — init 뒤에도 안내 문구와 회사 계정 로그인 버튼이 보이고 칸은 없다', async () => {
    const container = await renderAfterHandler(handlerOutput(422, DISABLED));

    expect(container.textContent).toContain(DISABLED.message);
    // 번역 데이터 없이 그리므로 버튼 글은 키로 보인다(수단 이름 치환은 admin-forgot-reset-password-disabled.test.ts 가 식으로 잰다)
    const buttons = Array.from(container.querySelectorAll('button[type="button"]')).filter((b) =>
      b.textContent?.includes('auth.login.external.login_with'),
    );
    expect(buttons).toHaveLength(1);
    expect(buttons[0].textContent).not.toContain('login_with_company');
    expect(container.querySelectorAll('input[type="password"]')).toHaveLength(0);
    expect(container.querySelector('button[type="submit"]')).toBeNull();
    // 「링크 무효」로 접지 않는다
    expect(container.textContent).not.toContain('auth.reset_password.invalid_token');
  });

  /**
   * 수단 이름이 없는 응답이어도 안내와 기본 문구 버튼이 남는다.
   *
   * @scenario surface=reset, step=token_check, response=422_password_auth_disabled
   * @effects reset_token_check_result_survives_init
   */
  it('수단 이름이 없으면 「회사 계정으로 로그인」 기본 문구 버튼이 남는다', async () => {
    const container = await renderAfterHandler(
      handlerOutput(422, { ...DISABLED, errors: { code: DISABLED.errors.code, redirect_url: DISABLED.errors.redirect_url } }),
    );

    expect(container.textContent).toContain(DISABLED.message);
    const buttons = Array.from(container.querySelectorAll('button[type="button"]')).filter((b) =>
      b.textContent?.includes('auth.login.external.login_with_company'),
    );
    expect(buttons).toHaveLength(1);
  });

  /**
   * 잘못된·만료된 링크(다른 422)와 그 밖의 실패는 종전대로 「링크 무효」 안내와 비밀번호 찾기 버튼이다.
   *
   * @scenario surface=reset, step=token_check, response=422_invalid
   * @effects reset_token_check_result_survives_init
   */
  it.each([
    ['422 잘못된 링크', 422, INVALID],
    ['500 그 밖의 실패', 500, { message: 'Server Error' }],
  ])('%s — init 뒤에도 링크 무효 안내와 비밀번호 찾기 버튼이 남는다', async (_name, status, error) => {
    const container = await renderAfterHandler(handlerOutput(status, error));

    expect(container.textContent).toContain('auth.reset_password.invalid_token');
    expect(container.querySelector('a[href="/admin/forgot-password"]')).not.toBeNull();
    expect(container.querySelectorAll('input[type="password"]')).toHaveLength(0);
  });

  /**
   * init 이 링크 검증 처리기가 쓰는 키를 다시 쓰지 않는다 — 두 곳이 같은 키를 쓰면 엔진 실행 순서(처리기 → init)에 따라
   * 처리기 결과가 지워진다. 키가 겹치지 않으면 순서와 무관하게 남는다.
   *
   * @scenario surface=reset, step=token_check, response=422_password_auth_disabled|422_invalid
   * @effects reset_token_check_result_survives_init
   */
  it('init 과 링크 검증 오류 처리기가 같은 상태 키를 쓰지 않는다', () => {
    const shared = handlerKeys().filter((key) => initKeys().includes(key));
    expect(shared).toEqual([]);
  });

  /**
   * 관리자 화면의 요청은 관리자 경로라고 알린다 — 서버가 다른 로그인 수단 주소를 관리자 로그인(`guard=admin`)으로 만든다.
   * 비밀번호 찾기 화면의 `redirect_prefix: admin` 과 같다.
   *
   * @scenario surface=reset, step=token_check|submit, response=422_password_auth_disabled
   * @effects reset_requests_carry_admin_prefix
   */
  it('링크 검증과 재설정 제출이 redirect_prefix=admin 을 싣는다', () => {
    expect(tokenSource().params?.redirect_prefix).toBe('admin');

    const submit = collectActions((resetLayout as Json).components).find(
      (action) => action.handler === 'apiCall' && action.target === '/api/auth/reset-password',
    );
    expect(submit, '재설정 제출 요청을 찾지 못했습니다').toBeDefined();
    expect(submit?.params?.body?.redirect_prefix).toBe('admin');
  });
});
