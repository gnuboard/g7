// e2e:allow Vitest 단위 시험 — 동의 모달의 브라우저 종단은 tests/Playwright/specs/sso-oidc-mock.spec.ts·sso-saml-mock.spec.ts, 오류 토스트·연결 확인 모달은 F1 Chrome MCP 계획서 §10 M1(W40)
/**
 * @file sso-modals.test.tsx
 * @description 로그인 화면 SSO 흐름 조각(사용자·관리자)의 구운 JSON 계약 — 오류 토스트, 동의 모달, 연결 확인 모달.
 *
 * 렌더 대신 구운 레이아웃 JSON 을 읽어 주입 자리·조건·요청 주소·인계 코드 실행(W39)을 단언하고, 모든 `$t:` 키가 ko/en 에 있는지 본다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../../../..');
const EXT = path.join(ROOT, 'resources/extensions');
const load = (name: string): any => JSON.parse(fs.readFileSync(path.join(EXT, `${name}.json`), 'utf8'));
const lang = (locale: string): any => JSON.parse(fs.readFileSync(path.join(ROOT, `resources/lang/${locale}.json`), 'utf8'));

/**
 * 노드·액션 트리를 평탄화합니다.
 *
 * @param node 노드
 * @returns 객체 목록
 */
function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  return [node, ...Object.values(node).flatMap(flatten)];
}

/**
 * 플러그인 문구 키를 모두 모읍니다 (`$t:` 토큰과 `$t('…')` 호출의 고정 키).
 *
 * @param json 구운 JSON
 * @returns 키 목록 (플러그인 접두 제외)
 */
function pluginKeys(json: any): string[] {
  const text = JSON.stringify(json);
  const tokens = [...text.matchAll(/\$t:(?:defer:)?sirsoft-sso\.([a-z0-9_.]+)/g)].map((m) => m[1]);
  const calls = [...text.matchAll(/\$t\('sirsoft-sso\.([a-z0-9_.]+[a-z0-9_])'\)/g)].map((m) => m[1]);

  return [...new Set([...tokens, ...calls])];
}

/**
 * 점 경로로 값을 꺼냅니다.
 *
 * @param obj 객체
 * @param key 점 경로
 * @returns 값
 */
function dig(obj: any, key: string): unknown {
  return key.split('.').reduce((acc, part) => (acc == null ? undefined : acc[part]), obj);
}

/**
 * 조각 JSON 의 문자열 전부를 이어 붙입니다 (주소·핸들러 검색용).
 *
 * @param json 구운 JSON
 * @returns 문자열
 */
const text = (json: any): string => JSON.stringify(json);

const SURFACES = [
  { name: 'auth-login-sso-modals', layout: 'auth/login', guard: 'user', resultAction: 'applyLoginResult', screen: '/login', template: 'sirsoft-basic' },
  { name: 'admin-login-sso-modals', layout: 'admin_login', guard: 'admin', resultAction: 'applyAdminLoginResult', screen: '/admin/login', template: 'sirsoft-admin_basic' },
];

/**
 * 대상 템플릿이 제공하는 컴포넌트 이름을 모읍니다 (basic·composite·layout).
 *
 * @param template 템플릿 식별자
 * @returns 컴포넌트 이름 집합
 */
function templateComponents(template: string): Set<string> {
  const manifest = JSON.parse(fs.readFileSync(path.resolve(ROOT, `../../../templates/_bundled/${template}/components.json`), 'utf8'));
  return new Set(Object.values(manifest.components ?? {}).flatMap((list: any) => (list ?? []).map((c: any) => c.name)));
}

describe('로그인 화면 SSO 흐름 조각', () => {
  /**
   * @scenario surface=user|admin, locale=ko|en
   * @effects extension_i18n_keys_present
   */
  it.each(SURFACES)('$name 의 문구 키가 ko·en 에 모두 있다', ({ name }) => {
    const keys = pluginKeys(load(name));
    expect(keys.length).toBeGreaterThan(10);

    const missing = keys.filter((key) => typeof dig(lang('ko'), key) !== 'string' || typeof dig(lang('en'), key) !== 'string');
    expect(missing).toEqual([]);
  });

  /**
   * 템플릿에 없는 컴포넌트는 오류 없이 렌더에서 빠진다(M1 실측 — dl/dt/dd 로 회원이 될 이름·이메일이 안 보였다).
   *
   * @scenario surface=user|admin
   * @effects every_component_exists_in_target_template
   */
  it.each(SURFACES)('$name 이 쓰는 컴포넌트는 모두 $template 에 있다', ({ name, template }) => {
    const provided = templateComponents(template);
    const used = new Set(
      flatten(load(name))
        .filter((n) => ['basic', 'composite', 'layout'].includes(n.type) && typeof n.name === 'string' && !n.name.includes('.'))
        .map((n) => n.name),
    );

    expect([...used].filter((c) => !provided.has(c)).sort()).toEqual([]);
  });

  /**
   * @scenario surface=user|admin
   * @effects injected_into_login_layout
   */
  it.each(SURFACES)('$name 은 $layout 화면에 끼운다', ({ name, layout }) => {
    const json = load(name);
    expect(json.target_layout ?? json.targetLayout ?? json.layout).toBe(layout);
  });

  /**
   * @scenario surface=user|admin, query=sso_error|sso_pending|sso_link
   * @effects error_toast, pending_fetch, link_fetch
   */
  it.each(SURFACES)('$name 은 세 쿼리를 받아 처리한다', ({ name }) => {
    const all = text(load(name));
    expect(all).toContain('query.sso_error');
    expect(all).toContain('/api/plugins/sirsoft-sso/pending/{{query.sso_pending}}');
    expect(all).toContain('/api/plugins/sirsoft-sso/link/{{query.sso_link}}');
    expect(all).toContain('/api/plugins/sirsoft-sso/pending/{{query.sso_pending}}/complete');
    expect(all).toContain('/api/plugins/sirsoft-sso/link/{{query.sso_link}}/verify');
    expect(all).toContain('/api/plugins/sirsoft-sso/link/{{query.sso_link}}/email');
  });

  /**
   * @scenario surface=user|admin, completion=consent|link
   * @effects handoff_code_runs_login_handoff_action, routed_to_login_result
   */
  it.each(SURFACES)('$name 은 완료 응답의 handoff_code 로 loginHandoff 를 실행하고 $resultAction 으로 넘긴다', ({ name, guard, resultAction, screen }) => {
    const nodes = flatten(load(name));
    const handoffs = nodes.filter((n) => n.handler === 'loginHandoff');
    expect(handoffs).toHaveLength(2);

    for (const action of handoffs) {
      expect(action.target).toBe(guard);
      expect(text(action)).toContain('response.data?.handoff_code');
      expect(text(action)).toContain(resultAction);
      expect(text(action)).toContain(screen);
    }
  });

  /**
   * @scenario surface=user|admin, global_state=writes
   * @effects only_sso_prefixed_globals
   */
  it.each(SURFACES)('$name 은 화면 주인의 전역 상태를 쓰지 않는다', ({ name }) => {
    const all = text(load(name));
    for (const hostKey of ['isLoggingIn', 'loginError', 'loginErrors', 'currentUser']) {
      expect(all).not.toMatch(new RegExp(`"${hostKey}"\\s*:`));
    }
  });

  /**
   * @scenario surface=user, modal=consent
   * @effects submit_needs_both_consents
   */
  it('동의 모달의 시작 단추는 두 동의가 모두 있어야 켜진다', () => {
    const submit = flatten(load('auth-login-sso-modals')).find((n) => n.props?.['data-ref'] === 'sso_consent_submit' || n.ref === 'sso_consent_submit' || n.id === 'sso_consent_submit');
    expect(submit).toBeTruthy();
    const disabled = text(submit);
    expect(disabled).toContain('agree_terms');
    expect(disabled).toContain('agree_privacy');
  });
});
