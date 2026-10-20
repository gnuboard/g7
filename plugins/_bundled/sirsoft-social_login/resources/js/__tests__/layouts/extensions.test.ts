/**
 * @file extensions.test.ts
 * @description 사용자 화면 확장 조각(로그인 버튼·로그인 안내·가입 버튼·가입 모달·마이페이지 카드·본인 재확인 3종)의 구운 JSON 계약.
 *
 * 렌더 대신 구운 레이아웃 JSON 을 읽어 주입 자리·조건·요청 주소·응답 분기를 단언하고, 모든 `$t:` 키가 ko/en 에 있는지 본다.
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
  const tokens = [...text.matchAll(/\$t:(?:defer:)?sirsoft-social_login\.([a-z0-9_.]+)/g)].map((m) => m[1]);
  const calls = [...text.matchAll(/\$t\('sirsoft-social_login\.([a-z0-9_.]+[a-z0-9_])'\)/g)].map((m) => m[1]);

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

const ALL = [
  'auth_login_buttons',
  'auth_login_notices',
  'auth_register_buttons',
  'auth_register_social_modals',
  'mypage_social_accounts_card',
  'mypage_reauth_methods',
  'mypage_profile_edit_reauth',
  'mypage_change_password_reauth',
];

describe('사용자 화면 확장 조각', () => {
  /**
   * @scenario surface=all, locale=ko|en
   * @effects extension_i18n_keys_present
   */
  it.each(ALL)('%s 의 문구 키가 ko·en 에 모두 있다', (name) => {
    const keys = pluginKeys(load(name));
    const ko = lang('ko');
    const en = lang('en');

    const missing = keys.filter((key) => typeof dig(ko, key) !== 'string' || typeof dig(en, key) !== 'string');
    expect(missing).toEqual([]);
  });

  /**
   * @scenario surface=all, iteration=data_each
   * @effects no_deferred_translation_in_data_each
   */
  it.each(ALL)('%s 는 `$t:defer:` 를 쓰지 않는다 — data-each 반복에서는 풀리지 않고 토큰이 그대로 보인다', (name) => {
    expect(JSON.stringify(load(name))).not.toContain('$t:defer:');
  });

  /**
   * @scenario surface=register_modal|mypage_card, modal=reopen
   * @effects modal_state_lives_in_global
   */
  it.each(['auth_register_social_modals', 'mypage_social_accounts_card'])('%s 의 모달은 _local 이 아니라 _global 상태를 쓴다 — 모달은 페이지 _local 의 낡은 값을 본다', (name) => {
    const json = load(name);
    const modalText = JSON.stringify(json.modals);
    const localWrites = flatten(json).filter((n) => n.handler === 'setState' && n.params?.target === 'local');

    expect(modalText).not.toMatch(/_local\./);
    expect(localWrites).toEqual([]);
  });

  /**
   * @scenario surface=login
   * @effects login_buttons_slot_and_guard
   */
  it('로그인 버튼은 로그인 확장점에 붙고, 켜진 제공자가 없으면 그리지 않으며, 복귀 주소를 넘긴다', () => {
    const json = load('auth_login_buttons');
    const section = json.components[0];
    const buttons = flatten(section).find((n) => n.name === 'sirsoft-social_login.ProviderButtons');

    expect(json.extension_point).toBe('auth_login_extensions');
    expect(section.if).toContain('socialProviders?.data');
    expect(buttons.props).toMatchObject({ intent: 'login', redirect: "{{query.redirect ?? '/'}}" });
    expect(json.data_sources[0]).toMatchObject({ id: 'socialProviders', endpoint: '/api/plugins/sirsoft-social_login/providers', auth_mode: 'optional' });
  });

  /**
   * @scenario surface=login, query=social_error|social_notice
   * @effects login_notices_toast_with_fallback
   */
  it('로그인 안내는 실패 사유를 문구로 바꾸고 모르는 코드는 일반 실패 문구로 보인다', () => {
    const json = load('auth_login_notices');
    const [error, notice] = json.init_actions;

    expect(json.target_layout).toBe('auth/login');
    expect(error.if).toBe('{{!!query.social_error}}');
    expect(error.params.message).toContain("$t('sirsoft-social_login.errors.provider_error')");
    expect(notice.if).toContain("'pending_verification'");
  });

  /**
   * @scenario surface=register, query=social_pending
   * @effects register_buttons_hidden_during_social_signup
   */
  it('가입 버튼은 소셜 가입을 이어 가는 중에는 숨긴다', () => {
    const section = load('auth_register_buttons').components[0];

    expect(section.if).toContain('!query.social_pending');
    expect(flatten(section).find((n) => n.name === 'sirsoft-social_login.ProviderButtons').props.intent).toBe('register');
  });

  /**
   * @scenario surface=register_modal, step=complete|existing|expired
   * @effects signup_modal_opens_by_step, signup_submit_requires_consent_and_email, signup_success_navigates_handoff
   */
  it('가입 모달은 단계에 따라 열리고, 동의·이메일 확인 전에는 제출할 수 없으며, 성공하면 인계 주소로 간다', () => {
    const json = load('auth_register_social_modals');
    const fetch = json.init_actions.find((a: any) => a.handler === 'apiCall');
    const opens = fetch.onSuccess.filter((a: any) => a.handler === 'openModal');

    expect(fetch.if).toBe('{{!!query.social_pending}}');
    expect(opens.map((a: any) => a.target)).toEqual(['social_existing_account', 'social_signup_complete']);
    expect(fetch.onError.find((a: any) => a.handler === 'openModal').target).toBe('social_signup_complete');

    const submit = flatten(json.modals).find((n) => n.id === 'social_signup_submit');
    expect(submit.props.disabled).toContain('agree_terms');
    expect(submit.props.disabled).toContain('agree_privacy');
    expect(submit.props.disabled).toContain('socialLoginEmailVerified');

    const complete = flatten(submit.actions).find((a) => a.handler === 'apiCall');
    expect(complete.target).toBe('/api/plugins/sirsoft-social_login/signup/complete');
    expect(Object.keys(complete.params.body)).toEqual(['pending_token', 'name', 'nickname', 'email', 'agree_terms', 'agree_privacy']);
    expect(complete.params.body).not.toHaveProperty('password');
    const nav = flatten(complete.onSuccess).find((a) => a.handler === 'navigate');
    expect(nav.params.path).toContain('handoff_url');
  });

  /**
   * @scenario surface=register_modal, code=sent
   * @effects signup_code_countdown
   */
  it('가입 모달은 인증번호를 보낸 뒤 유효 시각까지 남은 시간을 1초마다 보이고, 지나면 재발송 안내로 바꾼다', () => {
    const json = load('auth_register_social_modals');
    const tick = json.init_actions.find((a: any) => a.handler === 'startInterval');
    const timer = flatten(json.modals).find((n) => n.id === 'social_signup_code_timer');
    const request = flatten(json.modals).find((n) => n.handler === 'apiCall' && n.target === '/api/plugins/sirsoft-social_login/signup/email/request');
    const stored = flatten(request.onSuccess).find((a) => a.handler === 'setState');

    expect(tick.if).toBe('{{!!query.social_pending}}');
    expect(tick.params.intervalMs).toBe(1000);
    expect(tick.params.actions[0].params.socialLoginEmailRemaining).toContain('_global.socialLoginEmailExpiresAt');
    expect(stored.params.socialLoginEmailExpiresAt).toBe('{{response.data?.expires_at}}');
    expect(JSON.stringify(timer)).toContain('sirsoft-social_login.signup.code_remaining');
    expect(JSON.stringify(timer)).toContain('sirsoft-social_login.signup.code_expired');
  });

  /**
   * @scenario surface=existing_modal, has_password=false
   * @effects existing_modal_hides_password_for_passwordless, existing_discard_switches_modal
   */
  it('이미 가입된 이메일 모달은 비밀번호 없는 계정에 비밀번호 방법을 숨기고, [새 계정으로 가입]은 가입 모달로 바꾼다', () => {
    const nodes = flatten(load('auth_register_social_modals').modals);
    const password = nodes.find((n) => n.id === 'social_existing_password');
    const discard = nodes.find((n) => n.id === 'social_existing_new_account');
    const call = flatten(discard.actions).find((a) => a.handler === 'apiCall');

    expect(password.if).toContain('has_password !== false');
    expect(call.target).toBe('/api/plugins/sirsoft-social_login/signup/discard-existing');
    expect(flatten(call.onSuccess).find((a) => a.handler === 'openModal').target).toBe('social_signup_complete');
  });

  /**
   * @scenario surface=mypage_card, response=409
   * @effects mypage_card_after_other_cards, unlink_409_shows_last_method
   */
  it('마이페이지 카드는 다른 카드 뒤에 붙고, 해제가 마지막 수단으로 거절되면 비밀번호 설정 안내를 띄운다', () => {
    const json = load('mypage_social_accounts_card');
    const confirm = flatten(json.modals).find((n) => n.id === 'social_unlink_confirm_submit');
    const call = flatten(confirm.actions).find((a) => a.handler === 'apiCall');

    expect(json.target_layout).toBe('mypage/profile');
    expect(json.priority).toBeLessThan(90);
    expect(json.injections[0]).toMatchObject({ target_id: 'profile_view_bio_card', position: 'append' });
    expect(call.params.method).toBe('DELETE');
    expect(JSON.stringify(call.onError)).toContain("error.errors?.code === 'last_method'");
  });

  /**
   * @scenario surface=mypage_card, brand=all
   * @effects mypage_row_shows_provider_symbol
   */
  it('마이페이지 카드의 연결 줄은 제공자 심볼 컴포넌트를 쓰고 계열·이름을 넘긴다', () => {
    const json = load('mypage_social_accounts_card');
    const list = flatten(json.injections).find((n) => n.id === 'social_accounts_list');
    const mark = flatten(list).find((n) => n.name === 'sirsoft-social_login.ProviderMark');
    const declared = JSON.parse(fs.readFileSync(path.join(ROOT, 'components.json'), 'utf8')).components.basic.map((c: any) => c.name);

    expect(mark?.props).toEqual({ brand: '{{account.brand}}', label: '{{account.label}}' });
    expect(declared).toContain('ProviderMark');
    expect(JSON.stringify(json)).not.toContain('sl-badge');
  });

  /**
   * @scenario surface=mypage_card, accounts=none
   * @effects mypage_card_empty_state
   */
  it('마이페이지 카드는 연결이 0건이면 목록 대신 빈 안내를 보이고, 연결할 제공자가 없으면 연결 영역을 숨긴다', () => {
    const nodes = flatten(load('mypage_social_accounts_card').injections);
    const empty = nodes.find((n) => JSON.stringify(n.text ?? '').includes('sirsoft-social_login.mypage.empty'));
    const list = nodes.find((n) => n.id === 'social_accounts_list');
    const available = nodes.find((n) => n.id === 'social_accounts_available');

    expect(empty.if).toContain('accounts ?? []).length === 0');
    expect(list.if).toContain('accounts ?? []).length > 0');
    expect(available.if).toContain('available ?? []).length > 0');
    expect(typeof dig(lang('ko'), 'mypage.empty')).toBe('string');
    expect(typeof dig(lang('en'), 'mypage.empty')).toBe('string');
  });

  /**
   * @scenario surface=profile_edit_gate|change_password, member=placeholder
   * @effects reauth_slots_replace_default, reauth_return_claims_and_opens_form
   */
  it('본인 재확인 조각은 템플릿 확장점을 바꾸고, 돌아오면 수령 코드로 토큰을 받아 정보 수정 관문을 통과시킨다', () => {
    expect(load('mypage_reauth_methods')).toMatchObject({ extension_point: 'mypage_reauth_methods', mode: 'replace' });
    expect(load('mypage_change_password_reauth')).toMatchObject({ extension_point: 'mypage_change_password_reauth', mode: 'replace' });

    const gate = load('mypage_profile_edit_reauth');
    const claim = gate.init_actions.find((a: any) => a.handler === 'apiCall');
    const verify = [].concat(claim.onSuccess).find((a: any) => a.handler === 'apiCall') as any;

    expect(gate.target_layout).toBe('mypage/profile-edit');
    expect(claim.target).toBe('/api/plugins/sirsoft-social_login/me/reauth/claim');
    expect(claim.params.body).toEqual({ code: '{{query.social_reauth}}' });
    expect(verify.target).toBe('/api/me/verify-password');
    expect(verify.params.body.verification_token).toBe('{{response.data?.verification_token}}');
    expect(([].concat(verify.onSuccess).find((a: any) => a.handler === 'setState') as any).params.isPasswordVerified).toBe(true);
  });
});
