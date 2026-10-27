/**
 * @file admin-reauth-external.test.ts
 * @description 관리자 재확인 관문의 수단 선택 회귀 테스트 (sirsoft-admin_basic, 7.2.0 A7t·K17)
 *
 * 비밀번호 없는 관리자의 재확인(앱 키 재생성 창 `_modal_password_confirm`, 자기 정보 수정의 비밀번호 첫 설정 `admin_user_form`)은
 * 수단을 화면이 고정하지 않는다 — challenge 요청 본문에 `provider_id` 를 싣지 않아 서버(K17)가 이 관리자에게 쓸 수 있는 수단을 고른다.
 * 응답 `render_hint=external_redirect` 면 그 주소로 가고(회사 계정 다시 로그인 등), 아니면 종전 인증번호 흐름이다.
 * 외부 수단에서 돌아오면 `admin_user_form` init 이 `query.verification_token` 을 `form.verification_token` 에 싣는다.
 * 요청 본문의 `return_path` 가 외부 수단이 돌려보낼 화면이다(같은 출처 경로 — 코어가 검사한다). 앱 키 창은 설정 화면 init 이
 * `reauth=app_key` 를 보고 창을 다시 열어 토큰을 담아 두고, 재생성은 관리자가 버튼을 눌러야 나간다.
 * 외부 수단인데 갈 주소가 없으면 진행 표시를 풀고 안내한다(버튼 고착 방지).
 *
 * @since 1.2.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import userFormLayout from '../../layouts/admin_user_form.json';
import passwordConfirmModal from '../../layouts/partials/admin_settings/_modal_password_confirm.json';
import settingsLayout from '../../layouts/admin_settings.json';
import securityTab from '../../layouts/partials/admin_settings/_tab_security.json';

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
 * 한 화면 영역 안의 재확인 시작 요청(`POST /api/identity/challenges`)을 찾습니다.
 *
 * @param root 영역 노드
 * @returns 요청 액션
 */
function challengeRequest(root: Json | undefined): Json | undefined {
  return flattenActions(flatten(root).flatMap((n) => asArray(n.actions))).find((a) => a.target === '/api/identity/challenges');
}

const EXTERNAL = { data: { id: 'ch-1', render_hint: 'external_redirect', redirect_url: '/plugins/sirsoft-sso/login/corp?ticket=t1' } };
const MAIL = { data: { id: 'ch-2', render_hint: 'code_input' } };

const modalNodes = flatten(passwordConfirmModal);
const appKeySection = modalNodes.find((n) => n.id === 'app_key_reauth_section');
const appKeyRequest = challengeRequest(appKeySection);

const formNodes = flatten(userFormLayout);
const selfSection = formNodes.find((n) => n.id === 'password_reauth_verification');
const selfRequest = challengeRequest(selfSection);

/**
 * 관리자 본인 데이터(`current_user`·`user`)를 만듭니다 — 안내·토스트가 메일을 받을 수 있는지로 갈린다.
 *
 * @param emailDeliverable 가입 이메일로 메일을 받을 수 있는지 (false = 대체 이메일 관리자)
 * @returns 식 계산 범위에 넣을 값
 */
function self(emailDeliverable: boolean): Json {
  const data = { uuid: 'me-uuid', has_password: false, email_deliverable: emailDeliverable };
  return { current_user: { data }, user: { data } };
}

/**
 * 성공 갈래에서 응답별로 실행되는 액션을 고릅니다.
 *
 * @param request 요청 액션
 * @param response 응답
 * @param emailDeliverable 관리자가 메일을 받을 수 있는지 (기본 true)
 * @returns 실행되는 액션
 */
function firingOnSuccess(request: Json | undefined, response: Json, emailDeliverable = true): Json[] {
  return asArray<Json>(request?.onSuccess).filter((a) => a.if === undefined || Boolean(evaluate(a.if, { response, ...self(emailDeliverable) })));
}

describe('sirsoft-admin_basic 관리자 재확인 수단 선택 (A7t)', () => {
  /**
   * @scenario surface=app_key_modal|admin_self_update
   * @effects challenge_request_lets_server_pick_provider
   */
  it.each([
    ['app_key_modal', () => appKeyRequest, passwordConfirmModal, '/admin/settings?tab=security&reauth=app_key'],
    ['admin_self_update', () => selfRequest, userFormLayout, '/admin/users/me-uuid/edit'],
  ])('%s — challenge 요청 본문에 provider_id 가 없고(서버가 수단을 고른다) 돌아올 화면 return_path 를 싣는다', (_surface, get, layout, returnPath) => {
    const request = get();
    expect(request?.params?.method).toBe('POST');
    expect(Object.keys(request?.params?.body ?? {}).sort()).toEqual(['purpose', 'return_path']);
    expect(request?.params?.body?.purpose).toBe('sensitive_action');
    const raw = String(request?.params?.body?.return_path ?? '');
    expect(raw.startsWith('{{') ? evaluate(raw, { route: { id: 'me-uuid' } }) : raw).toBe(returnPath);
    expect(Object.keys(request?.params?.body ?? {})).not.toContain('provider_id');
    // 메일 제공자를 고정하던 흔적이 레이아웃 어디에도 없다
    expect(JSON.stringify(layout)).not.toContain('g7:core.mail');
  });

  /**
   * @scenario surface=app_key_modal|admin_self_update, render_hint=external_redirect
   * @effects external_redirect_hint_redirects
   */
  it.each([
    ['app_key_modal', () => appKeyRequest],
    ['admin_self_update', () => selfRequest],
  ])('%s — 응답 render_hint=external_redirect 면 그 주소로 가고 인증번호 칸을 열지 않는다', (_surface, get) => {
    const fired = firingOnSuccess(get(), EXTERNAL);
    const redirect = fired.find((a) => a.handler === 'redirect');

    expect(redirect).toBeDefined();
    expect(evaluate(redirect?.params?.url, { response: EXTERNAL })).toBe(EXTERNAL.data.redirect_url);
    expect(fired.some((a) => a.handler === 'toast')).toBe(false);
    expect(fired.some((a) => a.handler === 'setState')).toBe(false);

    // 주소가 비어 있으면 이동하지 않는다
    const noUrl = { data: { ...EXTERNAL.data, redirect_url: null } };
    expect(firingOnSuccess(get(), noUrl).some((a) => a.handler === 'redirect')).toBe(false);
  });

  /**
   * @scenario surface=app_key_modal|admin_self_update, render_hint=external_redirect, redirect_url=missing
   * @effects external_redirect_without_url_releases_button
   */
  it.each([
    ['app_key_modal', () => appKeyRequest, 'isRequestingAppKeyCode', 'global', 'admin.settings.modals.reauth_external_failed'],
    ['admin_self_update', () => selfRequest, 'isRequestingReauthCode', 'local', 'admin.users.form.password_policy.reauth_external_failed'],
  ])('%s — external_redirect 인데 주소가 없으면 진행 표시를 풀고 오류 안내를 띄운다(버튼 고착 방지)', (_surface, get, flag, target, key) => {
    const noUrl = { data: { ...EXTERNAL.data, redirect_url: null } };
    const fired = firingOnSuccess(get(), noUrl);
    const release = fired.find((a) => a.handler === 'setState');

    expect(release?.params?.target).toBe(target);
    expect(release?.params?.[flag]).toBe(false);
    expect(fired.find((a) => a.handler === 'toast')?.params).toMatchObject({ type: 'error', message: `$t:${key}` });
  });

  /**
   * @scenario surface=app_key_modal|admin_self_update, render_hint=code_input
   * @effects code_hint_keeps_verification_code_flow
   */
  it.each([
    ['app_key_modal', () => appKeyRequest, 'appKeyReauthChallengeId', 'global'],
    ['admin_self_update', () => selfRequest, 'reauthChallengeId', 'local'],
  ])('%s — 그 밖의 응답은 종전 인증번호 흐름(요청 번호 저장 + 안내 토스트)이다', (_surface, get, key, target) => {
    const fired = firingOnSuccess(get(), MAIL);
    const store = fired.find((a) => a.handler === 'setState');

    expect(fired.some((a) => a.handler === 'redirect')).toBe(false);
    expect(store?.params?.target).toBe(target);
    expect(evaluate(store?.params?.[key], { response: MAIL })).toBe('ch-2');
    expect(fired.find((a) => a.handler === 'toast')?.params?.type).toBe('info');
  });

  /**
   * 메일을 받을 수 없는 관리자(대체 이메일 — 이메일을 주지 않는 회사 계정 등)에게 「이메일로 받은 인증번호」 안내·「인증번호 받기」·
   * 「이메일로 보냈습니다」 를 보이지 않는다. 서버가 회사 계정 재확인을 고르면 버튼을 눌러 이동하므로 문구가 실제와 달랐다.
   *
   * @scenario surface=app_key_modal|admin_self_update, render_hint=code_input, email_deliverable=false
   * @effects mailless_admin_sees_generic_wording
   */
  it.each([
    ['app_key_modal', () => appKeyRequest, () => appKeySection, 'admin.settings.modals', '_global.appKeyReauthChallengeId'],
    ['admin_self_update', () => selfRequest, () => selfSection, 'admin.users.form.password_policy', '_local.reauthChallengeId'],
  ])('%s — 메일을 받을 수 없는 관리자에게는 메일 전제 안내·버튼·토스트 대신 일반 문구를 쓴다', (_surface, get, section, prefix, challengeKey) => {
    // 토스트: 메일 받는 관리자는 「이메일로 보냈습니다」, 아니면 「보냈습니다」
    expect(firingOnSuccess(get(), MAIL, true).filter((a) => a.handler === 'toast').map((a) => a.params?.message)).toEqual([`$t:${prefix}.reauth_code_sent`]);
    expect(firingOnSuccess(get(), MAIL, false).filter((a) => a.handler === 'toast').map((a) => a.params?.message)).toEqual([`$t:${prefix}.reauth_code_sent_generic`]);

    // 안내 문단: 메일 여부로 갈린다
    const nodes = flatten(section());
    const visibleNotices = (emailDeliverable: boolean) => nodes
      .filter((n) => typeof n.text === 'string' && /reauth_notice/.test(n.text))
      .filter((n) => n.if === undefined || Boolean(evaluate(n.if, self(emailDeliverable))))
      .map((n) => n.text);
    expect(visibleNotices(true)).toEqual([`$t:${prefix}.reauth_notice`]);
    expect(visibleNotices(false)).toEqual([`$t:${prefix}.reauth_notice_other`]);

    // 버튼 글자: 요청 전이면 메일 관리자는 「인증번호 받기」, 아니면 「본인 확인 시작」
    const label = nodes.find((n) => typeof n.text === 'string' && n.text.includes('reauth_request_code'));
    const [scopeKey, field] = challengeKey.split('.');
    const labelScope = (emailDeliverable: boolean) => ({ ...self(emailDeliverable), [scopeKey]: { [field]: null } });
    expect(evaluate(label?.text, labelScope(true))).toBe(`$t:${prefix}.reauth_request_code`);
    expect(evaluate(label?.text, labelScope(false))).toBe(`$t:${prefix}.reauth_start`);
  });

  /**
   * @scenario surface=admin_self_update, return=with_token|without_token, mode=edit|create
   * @effects returned_verification_token_loaded_into_form, returned_verification_token_removed_from_url
   */
  it('외부 수단에서 돌아온 query.verification_token 을 수정 모드 init 이 form.verification_token 에 싣고 주소에서 지운다', () => {
    const init = asArray<Json>((userFormLayout as Json).init_actions);
    const resume = init.find((a) => a.handler === 'sequence' && String(a.if ?? '').includes('query?.verification_token'));
    const steps = asArray<Json>(resume?.actions);
    const load = steps.find((a) => a.params?.['form.verification_token'] !== undefined);

    expect(load?.params?.target).toBe('local');
    expect(load?.params?.['form.verification_token']).toBe('{{query.verification_token}}');
    expect(Boolean(evaluate(resume?.if, { route: { id: 'me' }, query: { verification_token: 'vt-1' } }))).toBe(true);
    expect(Boolean(evaluate(resume?.if, { route: { id: 'me' }, query: {} }))).toBe(false);
    expect(Boolean(evaluate(resume?.if, { route: {}, query: { verification_token: 'vt-1' } }))).toBe(false);

    // 한 번 쓰는 토큰은 싣은 뒤 주소에서 지운다(회원 정보 수정·앱 키 창과 같은 정리 — 이력·새로고침으로 다시 실리지 않게)
    const cleanup = steps.find((a) => a.handler === 'replaceUrl');
    expect(steps.indexOf(cleanup as Json)).toBeGreaterThan(steps.indexOf(load as Json));
    expect(evaluate(cleanup?.params?.path, { route: { id: 'me' } })).toBe('/admin/users/me/edit');
    expect(JSON.stringify(cleanup?.params ?? {})).not.toContain('verification_token');

    // 진입마다 비밀번호 칸을 비우는 init 보다 뒤에 있어 그 정리가 토큰을 지우지 않는다
    const clear = init.findIndex((a) => a.params?.['form.password'] === '' && a.if === '{{!!route?.id}}');
    expect(init.indexOf(resume as Json)).toBeGreaterThan(clear);

    // 토큰이 실려 있으면 「확인됨」 안내가 보이고 인증번호 받기 버튼 묶음은 숨는다
    const verified = flatten(selfSection).find((n) => n.text === '$t:admin.users.form.password_policy.reauth_verified');
    expect(Boolean(evaluate(verified?.if, { _local: { form: { verification_token: 'vt-1' } } }))).toBe(true);
  });

  describe('앱 키 재생성 창 — 외부 수단에서 돌아온 뒤', () => {
    const init = asArray<Json>((settingsLayout as Json).init_actions);
    const resume = init.find((a) => a.handler === 'sequence' && String(a.if ?? '').includes("query.reauth === 'app_key'"));
    const steps = asArray<Json>(resume?.actions);
    const regenerate = modalNodes.find((n) => n.id === 'app_key_reauth_regenerate_button');
    const regenerateActions = flattenActions(asArray<Json>(regenerate?.actions));
    const regenerateSteps = asArray<Json>(asArray<Json>(regenerate?.actions)[0]?.actions);

    /**
     * @scenario surface=admin_settings, return=with_token_app_key|token_only|reauth_only
     * @effects app_key_return_reopens_modal_with_token
     */
    it('설정 화면 init 이 verification_token + reauth=app_key 면 토큰을 담고 주소에서 지운 뒤 창을 다시 연다', () => {
      expect(resume, '설정 화면 init 에 앱 키 복귀 처리가 없다').toBeDefined();
      expect(Boolean(evaluate(resume?.if, { query: { verification_token: 'vt-1', reauth: 'app_key' } }))).toBe(true);
      expect(Boolean(evaluate(resume?.if, { query: { verification_token: 'vt-1' } }))).toBe(false);
      expect(Boolean(evaluate(resume?.if, { query: { reauth: 'app_key' } }))).toBe(false);

      const store = steps.find((a) => a.handler === 'setState');
      expect(store?.params?.target).toBe('global');
      expect(store?.params?.appKeyReauthToken).toBe('{{query.verification_token}}');

      const clean = steps.find((a) => a.handler === 'replaceUrl');
      expect(clean?.params?.path).toBe('/admin/settings');
      expect(clean?.params?.query).toEqual({ tab: 'security' });

      const open = steps.find((a) => a.handler === 'openModal');
      expect(open?.target).toBe('settings_password_confirm_modal');
      // 담기 → 주소 정리 → 창 열기 순서
      expect(steps.indexOf(store as Json)).toBeLessThan(steps.indexOf(clean as Json));
      expect(steps.indexOf(clean as Json)).toBeLessThan(steps.indexOf(open as Json));
    });

    /**
     * @scenario surface=admin_settings, return=with_token_app_key
     * @effects app_key_return_never_auto_regenerates
     */
    it('돌아와도 재생성은 자동으로 나가지 않는다 — init 어디에도 재생성 요청이 없다', () => {
      expect(resume).toBeDefined();
      expect(JSON.stringify(init)).not.toContain('regenerate-app-key');
    });

    /**
     * @scenario surface=app_key_modal, token=present|absent
     * @effects app_key_token_enables_regenerate_without_code
     */
    it('토큰이 있으면 「본인 확인을 마쳤습니다」 안내 + 인증번호 받기 숨김 + [재생성] 이 인증번호 없이 열린다', () => {
      const verified = modalNodes.find((n) => n.id === 'app_key_reauth_verified');
      expect(verified?.text).toBe('$t:admin.settings.modals.reauth_verified');
      expect(Boolean(evaluate(verified?.if, { _global: { appKeyReauthToken: 'vt-1' } }))).toBe(true);
      expect(Boolean(evaluate(verified?.if, { _global: {} }))).toBe(false);

      const requestButton = asArray<Json>(appKeySection?.children).find((n) => flattenActions(asArray(n.actions)).some((a) => a.target === '/api/identity/challenges'));
      expect(Boolean(evaluate(requestButton?.if, { _global: { appKeyReauthToken: 'vt-1' } }))).toBe(false);
      expect(Boolean(evaluate(requestButton?.if, { _global: {} }))).toBe(true);

      const disabled = (g: Json) => Boolean(evaluate(regenerate?.props?.disabled, { _global: g }));
      expect(disabled({ appKeyReauthToken: 'vt-1' })).toBe(false);
      expect(disabled({ appKeyReauthToken: 'vt-1', isRegeneratingKey: true })).toBe(true);
      expect(disabled({})).toBe(true);
      expect(disabled({ appKeyReauthChallengeId: 'ch-2', appKeyReauthCode: '123456' })).toBe(false);
    });

    /**
     * @scenario surface=app_key_modal, token=present|absent
     * @effects app_key_token_regenerates_with_verification_token
     */
    it('[재생성] — 토큰이 있으면 인증번호 확인 없이 verification_token 으로 재생성하고, 없으면 종전 인증번호 확인을 거친다', () => {
      const direct = regenerateSteps.find((a) => a.target === '/api/admin/settings/regenerate-app-key');
      const verify = regenerateSteps.find((a) => String(a.target ?? '').includes('/api/identity/challenges/'));

      expect(Boolean(evaluate(direct?.if, { _global: { appKeyReauthToken: 'vt-1' } }))).toBe(true);
      expect(Boolean(evaluate(verify?.if, { _global: { appKeyReauthToken: 'vt-1' } }))).toBe(false);
      expect(Boolean(evaluate(direct?.if, { _global: {} }))).toBe(false);
      expect(Boolean(evaluate(verify?.if, { _global: {} }))).toBe(true);
      expect(direct?.params?.body).toEqual({ verification_token: '{{_global.appKeyReauthToken}}' });

      // 토큰은 한 번만 쓰인다 — 성공·실패 모두 비운다
      for (const branch of [direct?.onSuccess, direct?.onError]) {
        const reset = asArray<Json>(branch).find((a) => a.handler === 'setState');
        expect(reset?.params?.appKeyReauthToken).toBeNull();
        expect(reset?.params?.isRegeneratingKey).toBe(false);
      }
      expect(regenerateActions.some((a) => a.handler === 'closeModal')).toBe(true);
    });

    /**
     * @scenario surface=app_key_modal, close=cancel|unmount
     * @effects app_key_token_cleared_on_close
     */
    it('[취소]는 받아 온 토큰을 지우고, 보안 탭 버튼으로 창을 열 때도 먼저 비운다(버튼으로 연 창은 늘 처음부터)', () => {
      const cancel = flattenActions(modalNodes.flatMap((n) => asArray(n.actions)))
        .find((a) => a.handler === 'setState' && a.params?.appKeyPassword === '' && a.params?.appKeyReauthChallengeId === null);
      expect(cancel?.params?.appKeyReauthToken).toBeNull();

      const tabNodes = flatten(securityTab);
      const opener = flattenActions(tabNodes.flatMap((n) => asArray(n.actions)));
      const open = opener.find((a) => a.handler === 'openModal' && a.target === 'settings_password_confirm_modal');
      const reset = opener.find((a) => a.handler === 'setState' && Object.prototype.hasOwnProperty.call(a.params ?? {}, 'appKeyReauthToken'));
      expect(open).toBeDefined();
      expect(reset?.params?.appKeyReauthToken).toBeNull();
      expect(opener.indexOf(reset as Json)).toBeLessThan(opener.indexOf(open as Json));
    });

    /**
     * 설정 화면 init 이 창을 연 직후 데이터가 도착하면 창 본문이 다시 마운트된다. 엔진은 onUnmount 를 마운트 때 문맥으로
     * 돌려 닫힘과 재마운트를 가릴 수 없다 — 그래서 unmount 정리가 토큰을 지우면 돌아온 토큰을 잃는다
     * (브라우저 실측 2026-10-24: 「본인 확인을 마쳤습니다」가 안 보이고 [키 재생성]이 잠긴 채 남았다).
     *
     * @scenario surface=app_key_modal, close=unmount
     * @effects app_key_token_survives_remount_while_open
     */
    it('[case:state-advanced-modal-onunmount-context] 창 본문 unmount 정리는 비밀번호·인증번호만 지우고 받아 온 토큰은 건드리지 않는다', () => {
      const onUnmount = flattenActions(modalNodes.flatMap((n) => asArray<Json>(n.lifecycle?.onUnmount)));
      const clear = onUnmount.find((a) => a.handler === 'setState' && a.params?.appKeyPassword === '');
      expect(clear, 'unmount 정리가 없다').toBeDefined();
      expect(clear?.if).toBeUndefined();
      expect(clear?.params).toMatchObject({ appKeyPassword: '', appKeyReauthCode: '', appKeyReauthChallengeId: null });
      expect(Object.prototype.hasOwnProperty.call(clear?.params ?? {}, 'appKeyReauthToken')).toBe(false);
    });
  });
});
