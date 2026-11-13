/**
 * @file admin-login-discovery.test.ts
 * @description 관리자 로그인 수단 판정·자동 이동·외부 인증 안내 회귀 테스트 (sirsoft-admin_basic, 7.2.0 A1t ③)
 *
 * - 이메일 칸을 벗어날 때(값이 바뀐 경우만) `POST /api/auth/admin/login/discover` 로 수단을 묻고, `external` 이면
 *   `_local.discovery` 로 비밀번호 칸·로그인 버튼을 숨기고 「… 계정으로 계속」 버튼을 보인다.
 * - 사이트가 관리자 자동 이동(`_global.appConfig.auth.auto_redirect.admin`)을 켰으면 init 이 그리로 보낸다. 다만 `local`·`login_handoff`·
 *   `sso_error`·`sso_pending`·`sso_link` 쿼리로 돌아온 화면은 보내지 않는다(무한 왕복 방지). 그 사이트에는 `?local=1` 링크가 있다.
 * - 로그인 실패 403 `auth.password_auth_disabled` 는 그 수단으로 가는 버튼, 409 `auth.external_step_required` 는 이동.
 * - 식별자 칸 type 은 `_global.appConfig.auth.login_identifier.admin` 을 따른다.
 * - 안내 데이터는 오류 문맥의 `error.errors.*` 로만 읽는다(`error.data` 는 바인딩 경로가 아니다 — audit 규정).
 *
 * @since 1.2.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import adminLogin from '../../layouts/admin_login.json';
import authKo from '../../lang/partial/ko/auth.json';
import authEn from '../../lang/partial/en/auth.json';

type Json = Record<string, any>;

/**
 * 트리를 평탄화합니다 (자식·슬롯·모달 포함).
 *
 * @param node 노드
 * @returns 노드 목록
 */
function flatten(node: any): Json[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  const modals = Array.isArray(node.modals) ? node.modals.flatMap(flatten) : [];
  return [node, ...children, ...slots, ...modals];
}

/**
 * 액션을 평탄화합니다 — 원소 하나짜리 배열은 객체로 접혀 굽히므로(D67) asArray 로 정규화한다.
 *
 * @param actions 액션(들)
 * @returns 액션 목록
 */
function flattenActions(actions: any): Json[] {
  if (!actions) return [];
  return asArray<Json>(actions).flatMap((a) => [
    a,
    ...flattenActions(a.actions),
    ...flattenActions(a.onSuccess),
    ...flattenActions(a.onError),
  ]);
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
 * dot 경로로 lang 값을 읽습니다.
 *
 * @param lang lang JSON
 * @param path 경로
 * @returns 값
 */
function langAt(lang: Json, path: string): unknown {
  return path.split('.').reduce<any>((acc, key) => (acc == null ? acc : acc[key]), lang);
}

const layout = adminLogin as Json;
const nodes = flatten(layout.components);
const byId = (id: string): Json | undefined => nodes.find((n) => n.id === id);
const form = byId('login_form_component');
const formActions = flattenActions(form?.actions);
const login = formActions.find((a) => a.handler === 'login');
const loginErrors = asArray<Json>(login?.onError);
const emailInput = byId('login_email_input');
const blur = asArray<Json>(emailInput?.actions).find((a) => a.event === 'onBlur');
const discover = flattenActions(blur).find((a) => a.handler === 'apiCall');
const autoRedirect = asArray<Json>(layout.init_actions).find((a) => a.handler === 'redirect');
const $t = (key: string): string => `$t:${key}`;

describe('sirsoft-admin_basic 관리자 로그인 수단 판정 (A1t)', () => {
  /**
   * @scenario action=email_blur, value=changed|same|empty
   * @effects email_blur_requests_admin_discovery
   */
  it('이메일 칸을 벗어날 때 값이 바뀐 경우에만 관리자 판정 API 를 부른다', () => {
    expect(blur?.handler).toBe('sequence');
    const scope = (value: string, discoveryFor?: string) => ({ $event: { target: { value } }, _global: { adminLoginDiscoveryFor: discoveryFor } });

    expect(Boolean(evaluate(blur?.if, scope('admin@corp.example')))).toBe(true);
    expect(Boolean(evaluate(blur?.if, scope('admin@corp.example', 'admin@corp.example')))).toBe(false);
    expect(Boolean(evaluate(blur?.if, scope('')))).toBe(false);

    const remember = asArray<Json>(blur?.actions).find((a) => a.handler === 'setState');
    expect(remember?.params).toEqual({ target: 'global', adminLoginDiscoveryFor: '{{$event.target.value}}' });

    expect(discover?.target).toBe('/api/auth/admin/login/discover');
    expect(discover?.params?.method).toBe('POST');
    expect(discover?.params?.body).toEqual({ identifier: '{{$event.target.value}}' });
  });

  /**
   * @scenario action=email_blur, response=external|password|error
   * @effects external_discovery_hides_password_and_offers_continue
   */
  it.each([
    ['external', { data: { method: 'external', label: { ko: '사내 계정', en: 'Corp' }, redirect_url: '/sso/start/corp' } }, true],
    ['password', { data: { method: 'password', label: null, redirect_url: null } }, false],
  ])('판정 %s — 비밀번호 칸과 「… 계정으로 계속」 이 맞바뀐다', (_kind, response, external) => {
    const stored = evaluate(asArray<Json>(discover?.onSuccess)[0]?.params?.adminLoginDiscovery, { response });
    expect(stored).toEqual(external ? response.data : null);

    const scope = { _local: {}, _global: { adminLoginDiscovery: stored } };
    for (const id of ['login_password_field', 'login_forgot_password', 'login_submit_button']) {
      expect(Boolean(evaluate(byId(id)?.if, scope)), id).toBe(!external);
    }
    expect(Boolean(evaluate(byId('login_discovery_step')?.if, scope))).toBe(external);

    // 판정 실패는 지금 화면(비밀번호 로그인) 그대로
    expect(asArray<Json>(discover?.onError)[0]?.params).toEqual({ target: 'global', adminLoginDiscovery: null });
  });

  /**
   * @scenario action=continue_click, redirect=with|without
   * @effects external_discovery_hides_password_and_offers_continue
   */
  it('「… 계정으로 계속」 은 판정이 준 주소로 가며 가려던 곳(redirect)을 넘기고, 「다른 이메일로 로그인」 은 판정을 지운다', () => {
    const go = asArray<Json>(byId('login_discovery_continue')?.actions)[0];
    const discovery = { method: 'external', label: { ko: '사내 계정' }, redirect_url: '/sso/start/corp' };

    expect(go?.handler).toBe('redirect');
    expect(evaluate(go?.params?.url, { _global: { adminLoginDiscovery: discovery }, query: {} })).toBe('/sso/start/corp');
    expect(evaluate(go?.params?.url, { _global: { adminLoginDiscovery: discovery }, query: { redirect: '/admin/users' } })).toBe(
      '/sso/start/corp?redirect=%2Fadmin%2Fusers',
    );
    expect(
      evaluate(go?.params?.url, { _global: { adminLoginDiscovery: { ...discovery, redirect_url: '/sso/start?idp=1' } }, query: { redirect: '/admin' } }),
    ).toBe('/sso/start?idp=1&redirect=%2Fadmin');

    // 수단 이름은 `$localized` 로 읽는다 — 확장이 ko 만 주면 다른 로케일도 그 이름으로 폴백한다(오류 안내 `method_label` 과 같은 처리)
    const labels = asArray<Json>(byId('login_discovery_continue')?.children);
    const withLabel = labels.find((c) => String(c.text).includes('continue_with|method='));
    const withoutLabel = labels.find((c) => String(c.text).endsWith('continue_with_company'));
    const localized = (locale: string) => (value: any) => (value && typeof value === 'object' ? (value[locale] ?? value.ko ?? '') : (value ?? ''));
    for (const locale of ['ko', 'en', 'ja']) {
      const scope = { _global: { adminLoginDiscovery: discovery }, $locale: locale, $localized: localized(locale) };
      expect(Boolean(evaluate(withLabel?.if, scope))).toBe(true);
      expect(Boolean(evaluate(withoutLabel?.if, scope))).toBe(false);
      expect(evaluate(String(withLabel?.text).split('method=')[1], scope)).toBe('사내 계정');
    }
    const noLabel = { _global: { adminLoginDiscovery: { ...discovery, label: null } }, $locale: 'ko', $localized: localized('ko') };
    expect(Boolean(evaluate(withLabel?.if, noLabel))).toBe(false);
    expect(Boolean(evaluate(withoutLabel?.if, noLabel))).toBe(true);

    const reset = asArray<Json>(byId('login_discovery_reset')?.actions)[0];
    expect(reset?.params).toEqual({ target: 'global', adminLoginDiscovery: null, adminLoginDiscoveryFor: null });
  });

  /**
   * @scenario entry=plain|local|login_handoff|sso_error|sso_pending|sso_link, auto_redirect=on|off
   * @effects auto_redirect_skipped_for_bypass_queries
   */
  it.each([
    [{}, true],
    [{ local: '1' }, false],
    [{ login_handoff: 'abc' }, false],
    [{ sso_error: 'denied' }, false],
    [{ sso_pending: '1' }, false],
    [{ sso_link: '1' }, false],
  ])('자동 이동이 켜진 사이트에서 query=%j 이면 이동=%s', (query, moves) => {
    const appConfig = { auth: { auto_redirect: { admin: '/sso/start/corp' } } };
    expect(Boolean(evaluate(autoRedirect?.if, { _global: { appConfig }, query }))).toBe(moves);
    // 꺼진 사이트는 어떤 쿼리든 이동하지 않는다
    expect(Boolean(evaluate(autoRedirect?.if, { _global: { appConfig: { auth: { auto_redirect: { admin: null } } } }, query }))).toBe(false);
  });

  /**
   * @scenario entry=plain, auto_redirect=on, redirect=with|without
   * @effects auto_redirect_skipped_for_bypass_queries
   */
  it('자동 이동은 기록을 바꾸지 않고(replace) 가려던 곳을 넘긴다', () => {
    const _global = { appConfig: { auth: { auto_redirect: { admin: '/sso/start/corp' } } } };
    expect(autoRedirect?.params?.replace).toBe(true);
    expect(evaluate(autoRedirect?.params?.url, { _global, query: {} })).toBe('/sso/start/corp');
    expect(evaluate(autoRedirect?.params?.url, { _global, query: { redirect: '/admin/settings' } })).toBe(
      '/sso/start/corp?redirect=%2Fadmin%2Fsettings',
    );
  });

  /**
   * @scenario surface=login_card, auto_redirect=on|off
   * @effects local_link_when_auto_redirect
   */
  it('자동 이동이 켜진 사이트에만 비밀번호 로그인으로 오는 ?local=1 링크가 있다', () => {
    const container = byId('login_local_link_container');
    const link = byId('login_local_link');

    expect(Boolean(evaluate(container?.if, { _global: { appConfig: { auth: { auto_redirect: { admin: '/sso/start/corp' } } } } }))).toBe(true);
    expect(Boolean(evaluate(container?.if, { _global: { appConfig: { auth: { auto_redirect: { admin: null } } } } }))).toBe(false);
    expect(Boolean(evaluate(container?.if, { _global: {} }))).toBe(false);
    expect(link?.props?.href).toBe('/admin/login?local=1');
    expect(link?.text).toBe('$t:auth.login.external.other_method');
  });

  /**
   * @scenario action=submit, response=403_password_auth_disabled
   * @effects password_auth_disabled_offers_alternate_button
   */
  it('403 auth.password_auth_disabled — 그 수단으로 가는 버튼(loginAlternate*)을 보인다', () => {
    const error = {
      message: '회사 계정으로 로그인해야 합니다.',
      errors: { code: 'auth.password_auth_disabled', redirect_url: '/sso/start/corp', method_label: { ko: '사내 계정', en: 'Corp' } },
    };
    const alternate = loginErrors.find((a) => typeof a.if === 'string' && a.if.includes('auth.password_auth_disabled'));

    expect(Boolean(evaluate(alternate?.if, { error }))).toBe(true);
    expect(Boolean(evaluate(alternate?.if, { error: { message: 'x', errors: { email: ['틀림'] } } }))).toBe(false);
    expect(evaluate(alternate?.params?.loginAlternateUrl, { error })).toBe('/sso/start/corp');
    // 실패 갈래 문맥에는 `$locale` 이 없다 — 엔진이 늘 주는 `$localized` 로 고른다
    const $localized = (value: any) => (value && typeof value === 'object' ? (value.ko ?? '') : (value ?? ''));
    expect(evaluate(alternate?.params?.loginAlternateLabel, { error, $localized })).toBe('사내 계정');
    expect(evaluate(alternate?.params?.loginAlternateLabel, { error: { errors: { code: 'auth.password_auth_disabled' } }, $localized })).toBeNull();

    const button = byId('login_alternate_button');
    expect(Boolean(evaluate(button?.if, { _local: { loginAlternateUrl: '/sso/start/corp' } }))).toBe(true);
    expect(Boolean(evaluate(button?.if, { _local: { loginAlternateUrl: null } }))).toBe(false);
    const go = asArray<Json>(button?.actions)[0];
    expect(evaluate(go?.params?.url, { _local: { loginAlternateUrl: '/sso/start/corp' }, query: { redirect: '/admin/users' } })).toBe(
      '/sso/start/corp?redirect=%2Fadmin%2Fusers',
    );

    // 다시 제출하면 지난 버튼을 지운다
    const start = asArray<Json>(form?.actions)[0]?.actions?.[0];
    expect(start?.params).toMatchObject({ loginAlternateUrl: null, loginAlternateLabel: null });
  });

  /**
   * @scenario action=submit, response=409_external_step_required
   * @effects external_step_required_redirects
   */
  it('409 auth.external_step_required — 서버가 준 화면으로 이동한다', () => {
    const step = loginErrors.find((a) => a.handler === 'redirect');
    const error = { message: '추가 확인이 필요합니다.', errors: { code: 'auth.external_step_required', redirect_url: '/sso/consent' } };

    expect(Boolean(evaluate(step?.if, { error }))).toBe(true);
    expect(Boolean(evaluate(step?.if, { error: { errors: { code: 'auth.external_step_required' } } }))).toBe(false);
    expect(Boolean(evaluate(step?.if, { error: { errors: { code: 'auth.password_auth_disabled', redirect_url: '/x' } } }))).toBe(false);
    expect(evaluate(step?.params?.url, { error })).toBe('/sso/consent');
  });

  /**
   * @scenario surface=email_field, login_identifier=email|email_or_username
   * @effects identifier_field_type_follows_config
   */
  it.each([
    ['email_or_username', 'text', '$t:auth.login.external.identifier'],
    ['email', 'email', '$t:auth.login.email'],
  ])('식별자 설정 %s — 칸 type=%s, 라벨=%s', (identifier, type, label) => {
    const scope = { _global: { appConfig: { auth: { login_identifier: { admin: identifier } } } }, $t };
    expect(evaluate(emailInput?.props?.type, scope)).toBe(type);
    expect(evaluate(byId('login_email_label')?.text, scope)).toBe(label);
    // 칸 이름은 그대로 email — 요청 본문 키가 바뀌지 않는다
    expect(emailInput?.props?.name).toBe('email');
  });

  /**
   * @scenario surface=whole_layout
   * @effects guidance_reads_error_errors_only
   */
  it('안내 데이터는 error.errors 로만 읽고 error.data 를 쓰지 않는다', () => {
    const source = JSON.stringify(layout);
    expect(source).not.toMatch(/\berror\??\.data\b/);
    expect(source).toContain("error.errors?.code === 'auth.password_auth_disabled'");
  });

  /**
   * @scenario locale=ko|en
   * @effects login_external_lang_keys_present
   */
  it.each([
    ['ko', authKo],
    ['en', authEn],
  ])('%s lang 에 외부 인증 안내 문구 키가 있다', (_locale, lang) => {
    for (const key of [
      'login.external.identifier',
      'login.external.continue_with',
      'login.external.continue_with_company',
      'login.external.use_other_email',
      'login.external.login_with',
      'login.external.login_with_company',
      'login.external.other_method',
    ]) {
      expect(typeof langAt(lang as Json, key), key).toBe('string');
    }
  });

  /**
   * 이메일 칸을 벗어나 판정 요청이 진행 중일 때 비밀번호를 입력하면, 판정 응답의 `set-state target="local"` 이 요청 때의
   * `_local` 스냅숏으로 폼 바인딩(`data-key="loginForm"`) 저장소를 덮어 화면의 비밀번호 칸이 비었다(상태에는 남음 —
   * 브라우저 실측 2026-10-24, 제출은 required 로 막힘). 판정 상태는 폼 저장소 밖(`_global`)에 둔다.
   *
   * @scenario action=email_blur, response=external|password|error
   * @effects discovery_state_never_touches_form_store
   */
  it('[case:state-setstate-56] 판정 요청·응답은 _local(폼 바인딩 저장소)에 쓰지 않고 _global 에만 쓴다 — 요청 중 입력한 비밀번호 보존', () => {
    const writes = flattenActions([blur]).filter((a) => a.handler === 'setState');
    expect(writes.length).toBeGreaterThanOrEqual(3);
    for (const w of writes) {
      expect(w.params?.target, JSON.stringify(w.params)).toBe('global');
    }
    // 화면에 들어올 때 지난 판정을 비운다(전역이라 SPA 이동 뒤에도 남는다)
    const init = asArray<Json>((layout as Json).init_actions);
    const reset = flattenActions(init).find((a) => a.handler === 'setState' && Object.prototype.hasOwnProperty.call(a.params ?? {}, 'adminLoginDiscovery'));
    expect(reset?.params).toMatchObject({ target: 'global', adminLoginDiscovery: null, adminLoginDiscoveryFor: null });
  });

  /**
   * @scenario discovery=external, label=present|absent
   * @effects discovered_notice_shown
   */
  it('외부 수단 판정 단계에는 왜 비밀번호 칸이 사라졌는지 알리는 안내가 있고, 이름 유무에 따라 문구를 고른다(디자인 Ⓐ1 discovered)', () => {
    const step = byId('login_discovery_step');
    const notice = flatten(step).find((n) => n.id === 'login_discovery_notice');
    expect(notice, '판정 안내가 없다').toBeDefined();
    const s = JSON.stringify(notice);
    expect(s).toContain($t('auth.login.external.discovered_notice') + '|method=');
    expect(s).toContain($t('auth.login.external.discovered_notice_company'));
    for (const lang of [authKo, authEn]) {
      expect(langAt(lang, 'login.external.discovered_notice')).toEqual(expect.stringContaining('{{method}}'));
      expect(langAt(lang, 'login.external.discovered_notice_company')).toEqual(expect.any(String));
    }
  });

  /**
   * @scenario query=local|none
   * @effects local_notice_shown_on_local_entry
   */
  it('비상 계정 진입(?local=1)이면 비밀번호 화면임을 알리는 안내가 보인다(디자인 Ⓐ1 local)', () => {
    const notice = byId('login_local_notice');
    expect(notice, '비상 계정 안내가 없다').toBeDefined();
    expect(evaluate(String(notice?.if), { query: { local: '1' } })).toBe(true);
    expect(evaluate(String(notice?.if), { query: {} })).toBe(false);
    expect(JSON.stringify(notice)).toContain($t('auth.login.external.local_notice'));
    expect(langAt(authKo, 'login.external.local_notice')).toEqual(expect.any(String));
    expect(langAt(authEn, 'login.external.local_notice')).toEqual(expect.any(String));
  });
});
