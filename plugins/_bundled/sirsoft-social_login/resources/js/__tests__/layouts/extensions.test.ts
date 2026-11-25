/**
 * @file extensions.test.ts
 * @description 사용자 화면 확장 조각(로그인 버튼·로그인 안내·가입 버튼·가입 모달·마이페이지 카드·본인 재확인 3종)의 구운 JSON 계약.
 *
 * 렌더 대신 구운 레이아웃 JSON 을 읽어 주입 자리·조건·요청 주소·응답 분기를 단언하고, 모든 `$t:` 키가 ko/en 에 있는지 본다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { applyResponsiveClassMerge } from '@core/template-engine/helpers/StyleHelpers';

const ROOT = path.resolve(__dirname, '../../../..');
const EXT = path.join(ROOT, 'resources/extensions');
const load = (name: string): any => JSON.parse(fs.readFileSync(path.join(EXT, `${name}.json`), 'utf8'));
const lang = (locale: string): any => JSON.parse(fs.readFileSync(path.join(ROOT, `resources/lang/${locale}.json`), 'utf8'));

/**
 * 폭 구간의 실효 className — 구간 class(있으면 통째 교체) 위에 구간 class-merge 를 엔진 규칙으로 덧붙인 값 (engine-v1.98.0).
 * 접두 토큰(`sl:py-3`)끼리 충돌 교체가 되도록 서버가 내려 주는 접두 목록을 잠시 세운다.
 *
 * @param node 레이아웃 노드
 * @param key 구간 키
 * @returns 구간 실효 className (구간이 없으면 빈 문자열)
 */
function rangeClassName(node: any, key: string): string {
  const range = node?.responsive?.[key];
  if (!range) return '';
  const w = window as any;
  const prev = w.G7Config;
  w.G7Config = { ...(prev ?? {}), extensionClassPrefixes: ['sl'] };
  try {
    return applyResponsiveClassMerge(range.props?.className ?? node?.props?.className, range.classMerge);
  } finally {
    w.G7Config = prev;
  }
}

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
    const section = json.components.find((c: any) => c.id === 'social_login_buttons_section');
    const buttons = flatten(section).find((n) => n.name === 'sirsoft-social_login.ProviderButtons');

    expect(json.extension_point).toBe('auth_login_extensions');
    expect(section.if).toContain('socialProviders?.data');
    expect(buttons.props).toMatchObject({ intent: 'login', redirect: "{{query.redirect ?? '/'}}" });
    expect(json.data_sources[0]).toMatchObject({ id: 'socialProviders', endpoint: '/api/plugins/sirsoft-social_login/providers', auth_mode: 'optional' });
  });

  /**
   * @scenario surface=login, social_error=email_required|other, provider=enabled|unknown, redirect=safe|unsafe|absent
   * @effects login_reconsent_button_for_email_refusal
   */
  it('이메일 제공을 거절해 돌아온 회원에게만 그 제공자의 재동의 단추를 보이고, 시작 주소에 재동의 요청과 같은 출처 복귀 주소만 싣는다', () => {
    const block = load('auth_login_buttons').components.find((c: any) => c.id === 'social_login_reconsent');
    const link = flatten(block).find((n) => n.id === 'social_login_reconsent_link');
    const run = (expr: string, query: Record<string, unknown>): unknown => {
      const socialProviders = { data: [{ key: 'naver', label: '네이버', start_url: '/plugins/sirsoft-social_login/oauth/naver/start' }] };
      // eslint-disable-next-line no-new-func
      return new Function('query', 'socialProviders', `return (${expr.replace(/^\{\{|\}\}$/g, '')});`)(query, socialProviders);
    };

    expect(run(block.if, { social_error: 'email_required', provider: 'naver' })).toBe(true);
    expect(run(block.if, { social_error: 'access_denied', provider: 'naver' })).toBe(false);
    expect(run(block.if, { social_error: 'email_required', provider: 'kakao' })).toBe(false);

    const start = '/plugins/sirsoft-social_login/oauth/naver/start?reconsent=1';
    expect(run(link.props.href, { provider: 'naver', redirect: '/mypage' })).toBe(`${start}&redirect=%2Fmypage`);
    expect(run(link.props.href, { provider: 'naver', redirect: '//evil.test' })).toBe(start);
    expect(run(link.props.href, { provider: 'naver' })).toBe(start);
    expect(link.name).toBe('A');
    expect(link.text).toContain('$t:sirsoft-social_login.buttons.reconsent_with|provider=');
  });

  /**
   * @scenario surface=register, brand=all, locale=ko|en
   * @effects kakao_register_label_follows_bi
   */
  it('카카오 단추는 가입 화면에서도 카카오 디자인 가이드가 허용한 로그인 문구를 쓴다', () => {
    for (const locale of ['ko', 'en']) {
      const buttons = lang(locale).buttons;
      expect(buttons.register_with.kakao).toBe(buttons.login_with.kakao);
    }
    expect(lang('ko').buttons.register_with.kakao).toBe('카카오 로그인');
    expect(lang('en').buttons.register_with.kakao).toBe('Login with Kakao');
  });

  /**
   * @scenario surface=login|register, network=failed
   * @effects provider_list_failure_is_silent
   */
  it.each(['auth_login_buttons', 'auth_register_buttons'])('%s 는 제공자 목록을 못 받아도 오류 토스트를 띄우지 않고 버튼만 그리지 않는다', (name) => {
    const source = load(name).data_sources.find((s: any) => s.id === 'socialProviders');

    // 다른 확장의 로그인·가입 화면에 붙는 덧붙임이라, 목록 실패가 템플릿 기본 오류 처리(원문 토스트)로 번지면 안 된다
    expect(source.errorHandling?.default?.handler).toBe('suppress');
    expect(source.fallback).toEqual({ data: [] });
  });

  /**
   * @scenario surface=mypage_card, has_password=false, accounts=one, policy=allow_unlink_last_method
   * @effects unlink_last_method_warns_before_confirm
   */
  it('비밀번호 없는 회원의 마지막 연결을 끊을 수 있는 사이트면 확인 창이 「마지막 로그인 수단」을 경고한다', () => {
    const json = load('mypage_social_accounts_card');
    const nodes = flatten(json);
    const open = nodes.find((n) => n.handler === 'setState' && 'socialLoginUnlinkTarget' in (n.params ?? {}) && n.params.socialLoginUnlinkTarget !== null);
    const flag = String(open?.params?.socialLoginUnlinkIsLast ?? '');

    // 열 때 계산해 둔다 — 모달은 연 화면의 데이터소스를 열 때 값으로만 본다
    expect(flag).toContain('has_password === false');
    expect(flag).toContain('.length === 1');
    expect(flag).toContain('can_unlink');
    expect(flag).toContain('allowed === true');

    const warning = flatten(json.modals).find((n) => String(n.text ?? '').includes('sirsoft-social_login.mypage.unlink_last_warning'));
    expect(warning?.if).toBe('{{_global.socialLoginUnlinkIsLast === true}}');
    expect(typeof dig(lang('ko'), 'mypage.unlink_last_warning')).toBe('string');
    expect(typeof dig(lang('en'), 'mypage.unlink_last_warning')).toBe('string');
  });

  /**
   * @scenario surface=register_modal, policy=signup_before_submit
   * @effects signup_identity_target_is_signup_email
   */
  it('가입 완료 요청은 본인인증 대상(identity_target)으로 가입 이메일을 넘긴다 — 428 뒤 코어 본인인증 창이 대상 없이 422 로 멈추지 않게', () => {
    const call = flatten(load('auth_register_social_modals')).find((n) => n.handler === 'apiCall' && String(n.target).includes('/signup/complete'));

    // 코어 화면(비밀번호 찾기 등)과 같이 액션 수준 `identity_target` — 인터셉터가 여기서 대상을 읽는다
    expect(call?.identity_target?.email).toBe(call?.params?.body?.email);
    expect(call?.identity_target?.email).toContain('socialLoginForm?.email');
  });

  /**
   * @scenario surface=register_modal, step=expired
   * @effects signup_title_has_generic_provider
   */
  it('가입 모달 제목은 대기가 만료돼 제공자를 모를 때도 자리표({{provider}})를 남기지 않는다', () => {
    const modal = flatten(load('auth_register_social_modals')).find((n) => n.id === 'social_signup_complete');
    const title = String(modal?.props?.title ?? '');

    // 빈 값을 넘기면 번역기가 자리표를 그대로 둔다 — 일반 이름을 기본값으로 넘긴다
    expect(title).toContain("?? $t('sirsoft-social_login.signup.provider_generic')");
    expect(typeof dig(lang('ko'), 'signup.provider_generic')).toBe('string');
    expect(typeof dig(lang('en'), 'signup.provider_generic')).toBe('string');
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
   * @scenario surface=signup_modal, viewport=390
   * @effects modal_action_buttons_reach_touch_size_on_phone
   */
  it('가입·기존 계정 모달의 작은 동작 단추는 휴대폰 폭에서 손가락 크기(44px 이상)로 커지고 넓은 화면에서는 그대로다', () => {
    const buttons = flatten(load('auth_register_social_modals').modals)
      .filter((n) => n.name === 'Button' && /(^|\s)sl:px-3(\s|$)/.test(String(n.props?.className ?? '')));

    // 모집단: 인증번호 받기·확인, 비밀번호 확인, 기존 계정 인증번호 받기·확인, 새 계정으로 가입, 취소
    expect(buttons.length).toBeGreaterThanOrEqual(7);
    for (const b of buttons) {
      // 기본(640px 미만)은 손가락 크기 py-3, 640px 이상 구간(responsive 640-)에서만 py-2 로 줄어든다
      const cls = String(b.props.className);
      const wide = rangeClassName(b, '640-');
      const label = b.props?.['data-ref'] ?? cls;
      expect(cls, label).toMatch(/(^|\s)sl:py-3(\s|$)/);
      expect(cls, label).not.toMatch(/(^|\s)sl:py-2(\s|$)/);
      expect(cls, label).not.toMatch(/(^|\s)sl:(sm|md|lg|xl|2xl):/);
      expect(wide, label).toMatch(/(^|\s)sl:py-2(\s|$)/);
      expect(wide, label).not.toMatch(/(^|\s)sl:py-3(\s|$)/);
    }
  });

  /**
   * @scenario surface=signup_modal, input=name|nickname|email|existing_password
   * @effects signup_modal_inputs_have_labels
   */
  it('가입·기존 계정 모달의 입력칸은 라벨과 이어져 화면 낭독기가 이름을 읽는다', () => {
    const nodes = flatten(load('auth_register_social_modals').modals);

    for (const field of ['social_name', 'social_nickname', 'social_email', 'social_existing_password']) {
      const input = nodes.find((n) => n.props?.name === field);
      expect(input?.props?.id, field).toBeTruthy();
      const label = nodes.find((n) => n.name === 'Label' && n.props?.htmlFor === input.props.id);
      expect(label, field).toBeTruthy();
    }
  });

  /**
   * @scenario surface=mypage_card, intent=link
   * @effects mypage_link_buttons_touch_size
   */
  it('마이페이지 「다른 계정 연결하기」 단추는 로그인 화면과 같은 기본 크기(44px)다', () => {
    const buttons = flatten(load('mypage_social_accounts_card')).filter((n) => n.name === 'sirsoft-social_login.ProviderButtons' || String(n.name ?? '').endsWith('ProviderButtons'));

    expect(buttons.length).toBeGreaterThan(0);
    for (const b of buttons) {
      expect(b.props?.size ?? 'md').toBe('md');
    }
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
