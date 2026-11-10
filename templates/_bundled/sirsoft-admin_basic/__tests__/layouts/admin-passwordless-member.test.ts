/**
 * @file admin-passwordless-member.test.ts
 * @description 비밀번호 없는 회원(외부 인증 전용)의 관리자 화면 회귀 테스트 (sirsoft-admin_basic, #40)
 *
 * - 회원 상세 기본 정보에 「비밀번호」 배지(설정됨·미설정)가 있고, 비밀번호가 없으면 비밀번호 정보 영역은 만료 정책 대상이
 *   아니라는 안내 한 줄로 바뀐다(A1).
 * - 비밀번호 없는 관리자는 앱 키 재생성 창과 자기 정보 수정(비밀번호 처음 설정)에서 비밀번호 대신 이메일 인증번호로 확인하고,
 *   확인해 받은 토큰(`verification_token`)을 요청에 싣는다(K9).
 *
 * 조건식은 실제 값으로 평가해 두 상태를 모두 밟는다.
 *
 * @since 1.2.0
 */

import { describe, it, expect } from 'vitest';
import userDetailLayout from '../../layouts/admin_user_detail.json';
import userFormLayout from '../../layouts/admin_user_form.json';
import passwordConfirmModal from '../../layouts/partials/admin_settings/_modal_password_confirm.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

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
  // Modal 고정 하단(props.footer)의 단추도 걷는다(#869 P1 — 바닥 단추를 footer 로 옮김)
  const footer = Array.isArray(node.props?.footer) ? node.props.footer.flatMap(flatten) : [];
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  const modals = Array.isArray(node.modals) ? node.modals.flatMap(flatten) : [];
  return [node, ...children, ...footer, ...slots, ...modals];
}

/**
 * 액션을 평탄화합니다.
 *
 * @param actions 액션(들)
 * @returns 액션 목록
 */
function flattenActions(actions: any): Json[] {
  if (!actions) return [];
  const list = Array.isArray(actions) ? actions : [actions];
  return list.flatMap((a) => [a, ...flattenActions(a.actions), ...flattenActions(a.onSuccess), ...flattenActions(a.onError)]);
}

/**
 * `{{식}}` 조건을 주어진 값으로 평가합니다.
 *
 * @param expr 조건 문자열
 * @param scope 평가 값
 * @returns 평가 결과
 */
function evaluate(expr: string, scope: Json): boolean {
  const body = String(expr).replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return Boolean(new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope)));
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

describe('sirsoft-admin_basic 비밀번호 없는 회원 (#40)', () => {
  const detailNodes = flatten(userDetailLayout);

  /**
   * @scenario has_password=false|true, surface=user_detail
   * @effects password_state_badge_two_states
   */
  it.each([
    [false, 'admin.users.detail.password.not_set'],
    [true, 'admin.users.detail.password.set'],
  ])('has_password=%s 면 비밀번호 배지가 %s 로 보인다', (hasPassword, key) => {
    const field = detailNodes.find((n) => n.id === 'field_has_password');
    expect(field).toBeDefined();

    const badges = (field?.children ?? []).filter((c: Json) => c.if);
    const visible = badges.filter((c: Json) => evaluate(c.if, { user: { data: { has_password: hasPassword } } }));
    expect(visible).toHaveLength(1);
    expect(String(visible[0].text)).toBe('$t:' + key);
  });

  /**
   * @scenario has_password=false, surface=user_detail
   * @effects password_section_not_applicable_for_passwordless
   */
  it('비밀번호가 없으면 비밀번호 정보 영역은 만료 항목 대신 「해당 없음」 안내만 보인다', () => {
    const notice = detailNodes.find((n) => n.id === 'password_not_set_notice');
    const grid = detailNodes.find((n) => (n.children ?? []).some((c: Json) => c.id === 'field_password_changed_at'));
    const passwordless = { user: { data: { has_password: false } } };
    const holder = { user: { data: { has_password: true } } };

    expect(evaluate(notice?.if, passwordless)).toBe(true);
    expect(evaluate(grid?.if, passwordless)).toBe(false);
    expect(evaluate(notice?.if, holder)).toBe(false);
    expect(evaluate(grid?.if, holder)).toBe(true);
  });

  /**
   * @scenario has_password=false|true, surface=app_key_modal
   * @effects app_key_modal_uses_email_code_for_passwordless
   */
  it('앱 키 재생성 창 — 비밀번호 없는 관리자는 인증번호를 받아 확인한 토큰으로 재생성한다', () => {
    const nodes = flatten(passwordConfirmModal);
    const section = nodes.find((n) => n.id === 'app_key_reauth_section');
    // 관리자 템플릿에는 `_global.currentUser` 가 없다 — 관리자 기반 레이아웃의 데이터소스 `current_user`(`/api/admin/auth/user`)를 읽어야 한다.
    const passwordless = { current_user: { data: { has_password: false } } };
    const holder = { current_user: { data: { has_password: true } } };

    expect(evaluate(section?.if, passwordless)).toBe(true);
    expect(evaluate(section?.if, holder)).toBe(false);
    for (const node of nodes.filter((n) => typeof n.if === 'string' && n.if.includes('has_password'))) {
      expect(node.if).not.toContain('_global.currentUser');
    }

    const request = flattenActions(flatten(section).flatMap((n) => n.actions ?? [])).find((a) => a.target === '/api/identity/challenges');
    // 7.2.0(A7t, K17) — 수단을 메일로 고정하지 않는다. 서버가 이 관리자에게 쓸 수 있는 수단을 고르고, 메일이면 종전 인증번호 흐름이다
    // (외부 수단 갈래와 돌아올 화면 return_path 는 admin-reauth-external.test.ts 가 잠근다).
    // 관리자 화면에서 시작한 인증임을 알린다(scope=admin) — 메일의 사이트 링크가 사이트 주소 기준이다
    expect(request?.params?.body).toEqual({ purpose: 'sensitive_action', return_path: '/admin/settings?tab=security&reauth=app_key', scope: 'admin' });

    const regenerate = nodes.find((n) => n.id === 'app_key_reauth_regenerate_button');
    const verify = flattenActions(regenerate?.actions).find((a) => String(a.target).includes('/verify'));
    const call = flattenActions(verify?.onSuccess).find((a) => a.target === '/api/admin/settings/regenerate-app-key');
    expect(call?.params?.body?.verification_token).toBe('{{response.data?.verification_token}}');
  });

  /**
   * @scenario has_password=false|true, surface=app_key_modal
   * @effects app_key_modal_title_follows_password_presence
   */
  it('앱 키 재생성 창 제목은 비밀번호 없는 관리자에게 「본인 확인」, 비밀번호 관리자에게 종전 「비밀번호 확인」이다', () => {
    const modal = flatten(passwordConfirmModal).find((n) => n.id === 'settings_password_confirm_modal');
    const title = String(modal?.props?.title ?? '');
    // 식 값을 그대로 계산한다(`evaluate` 는 조건식용 불린)
    const value = (current_user: Json): unknown =>
      // eslint-disable-next-line no-new-func
      new Function('current_user', `return (${title.replace(/^\{\{/, '').replace(/\}\}$/, '')});`)(current_user);

    expect(value({ data: { has_password: false } })).toBe('$t:admin.settings.modals.identity_confirm_title');
    expect(value({ data: { has_password: true } })).toBe('$t:admin.settings.modals.password_confirm_title');
    expect(typeof langAt(adminKo as Json, 'settings.modals.identity_confirm_title')).toBe('string');
    expect(typeof langAt(adminEn as Json, 'settings.modals.identity_confirm_title')).toBe('string');
  });

  /**
   * @scenario has_password=false|true, surface=admin_self_update
   * @effects self_update_uses_email_code_for_passwordless
   */
  it('자기 정보 수정 — 비밀번호 없는 관리자는 현재 비밀번호 대신 인증번호로 확인해 토큰을 폼 값에 싣는다', () => {
    const nodes = flatten(userFormLayout);
    const current = nodes.find((n) => n.id === 'field_current_password');
    const reauth = nodes.find((n) => n.id === 'password_reauth_verification');
    const scope = (hasPassword: boolean) => ({
      route: { id: 'me' },
      current_user: { data: { uuid: 'me' } },
      _local: { form: { password: 'NewPassw0rd!' } },
      user: { data: { has_password: hasPassword } },
    });

    expect(evaluate(current?.if, scope(false))).toBe(false);
    expect(evaluate(reauth?.if, scope(false))).toBe(true);
    expect(evaluate(current?.if, scope(true))).toBe(true);
    expect(evaluate(reauth?.if, scope(true))).toBe(false);

    const confirm = flattenActions(flatten(reauth).flatMap((n) => n.actions ?? [])).find((a) => String(a.target).includes('/verify'));
    const store = flattenActions(confirm?.onSuccess).find((a) => a.handler === 'setState');
    expect(store?.params?.['form.verification_token']).toBe('{{response.data?.verification_token}}');
  });

  /**
   * @scenario locale=ko|en
   * @effects passwordless_admin_lang_keys_present
   */
  it.each([
    ['ko', adminKo],
    ['en', adminEn],
  ])('%s lang 에 새 문구 키가 있다', (_locale, lang) => {
    for (const key of [
      'users.detail.password.state',
      'users.detail.password.set',
      'users.detail.password.not_set',
      'users.detail.password.not_set_hint',
      'users.form.password_policy.reauth_notice',
      'users.form.password_policy.reauth_verified',
      'settings.modals.reauth_notice',
      'settings.modals.reauth_request_code',
    ]) {
      expect(typeof langAt(lang as Json, key), key).toBe('string');
    }
  });
});
