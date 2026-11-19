// @vitest-environment jsdom
/**
 * @file reauth-slots-render.test.tsx
 * @description 본인 재확인 자리 2종(정보 수정 `mypage_reauth_methods`, 비밀번호 첫 설정 `mypage_change_password_reauth`)의 실제 렌더 회귀.
 *
 * 두 조각은 템플릿 확장점의 기본 안내(관리자 문의)를 통째로 바꾼다(`mode: replace`). 그래서 소셜 계정이 연결되지 않은 회원
 * (회사 계정으로만 가입해 메일을 받을 수 없는 회원 등)에게도 이 조각이 그려진다. 종전에는 그 회원에게도 「연결한 소셜 계정으로 다시
 * 로그인…」 「연결된 소셜 계정이 없어…」 가 보였다. 소셜 재확인 대상(`reauth_via_social`)일 때만 소셜 안내·제공자 버튼을 보이고,
 * 아니면 템플릿 기본 안내와 같은 문구를 보인다.
 *
 * 실제 렌더 도구(createLayoutTest)에 실물 조각 JSON 과 실물 데이터소스(내 연결 목록)를 올려 그린다.
 */
import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
// 엔진(코어)은 저장소 루트의 react 를, 이 확장의 부품은 확장 node_modules 의 react 를 읽어 사본이 둘이 된다 — 훅을 쓰는
// 실제 부품을 엔진 아래에서 그리면 「Invalid hook call」 이 난다. 설정에서 전역으로 합치면 다른 레이아웃 시험이 깨지므로
// 이 파일에서만 확장 쪽 react 를 루트 사본으로 바꿔 낀다(templates/_bundled/sirsoft-admin_basic/src/__tests__/layouts/shippingPolicyFormLabelNames.test.tsx 와 같은 방식)
vi.mock('react', async () => {
    const p = await import('path');
    const u = await import('url');
    const dir = p.dirname(u.fileURLToPath(import.meta.url));

    return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../../../node_modules/react/index.js')).href);
});

import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import reauthMethods from '../../../extensions/mypage_reauth_methods.json';
import changePasswordReauth from '../../../extensions/mypage_change_password_reauth.json';
import ko from '../../../lang/ko.json';
import koMypage from '../../../../../../../templates/_bundled/sirsoft-basic/lang/partial/ko/mypage.json';
// 재확인 안내는 템플릿 키가 아니라 코어 키(core.compat.*)를 쓴다 — 7.1.x 옛 템플릿에서도 문구가 보이게
import koCompat from '../../../../../../../lang/partial/ko/compat.json';

/** 태그 하나를 그리는 시험용 컴포넌트 */
const Passthrough = (tag: keyof React.JSX.IntrinsicElements) => {
  const Comp: React.FC<any> = ({ id, className, children, text, role }) => React.createElement(tag, { id, className, role }, children ?? text);

  return Comp;
};

/** 제공자 버튼 — 받은 제공자 수만큼 버튼을 그린다(실물처럼 비면 아무것도 그리지 않는다) */
const ProviderButtons: React.FC<any> = ({ providers }) => {
  const items = Array.isArray(providers) ? providers : [];
  if (items.length === 0) return null;

  return (
    <div data-testid="provider-buttons">
      {items.map((p: any) => (
        <button key={p.provider_key} type="button">{p.label}</button>
      ))}
    </div>
  );
};

/** 덮어쓰기 전 레지스트리 원본 */
let savedRegistry: unknown = null;

/**
 * 조각이 쓰는 컴포넌트만 등록한 레지스트리를 만든다.
 *
 * @returns 시험 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  savedRegistry ??= (registry as any).registry;
  (registry as any).registry = {
    Div: { component: Passthrough('div'), metadata: { name: 'Div', type: 'basic' } },
    P: { component: Passthrough('p'), metadata: { name: 'P', type: 'basic' } },
    'sirsoft-social_login.ProviderButtons': { component: ProviderButtons, metadata: { name: 'sirsoft-social_login.ProviderButtons', type: 'composite' } },
    Fragment: { component: Passthrough('div'), metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * 조각을 한 화면으로 감싼다(실물 데이터소스 + 실물 컴포넌트).
 *
 * @param fragment 구운 조각 JSON
 * @param name 시험 레이아웃 이름
 * @returns 감싼 레이아웃
 */
function wrap(fragment: any, name: string): any {
  return {
    version: '1.0.0',
    layout_name: name,
    data_sources: fragment.data_sources,
    components: [{ id: 'slot', type: 'basic', name: 'Div', children: fragment.components }],
  };
}

const KAKAO = { provider_key: 'kakao', label: '카카오', brand: 'kakao', email: null, linked_at: null, last_login_at: null };

/**
 * 조각을 그린다.
 *
 * @param fragment 구운 조각 JSON
 * @param accounts 내 연결 목록 응답의 `data`
 * @param user 바깥 화면 내 정보(`/api/me`)의 `data` (없으면 싣지 않음)
 * @returns 그려진 화면
 */
async function renderFragment(fragment: any, accounts: Record<string, unknown>, user: Record<string, unknown> | null = null): Promise<HTMLElement> {
  utils = createLayoutTest(wrap(fragment, `test_${fragment.extension_point}`), {
    auth: { isAuthenticated: true, user: { id: 7, name: '회원' }, authType: 'user' },
    locale: 'ko',
    translations: { 'sirsoft-social_login': ko, mypage: koMypage, core: { compat: koCompat } },
    componentRegistry: setupRegistry(),
    // 바깥 화면(비밀번호 변경)의 내 정보 데이터소스 `user` — 코어가 고를 재확인 수단(`reauth_provider`)을 싣는다
    ...(user ? { initialData: { user: { data: user } } } : {}),
  });
  utils.mockApi('socialReauthAccounts', { response: { success: true, data: accounts } });
  const { container } = await utils.render();

  return container;
}

let utils: ReturnType<typeof createLayoutTest> | null = null;

afterEach(() => {
  utils?.cleanup();
  utils = null;
  if (savedRegistry) {
    (ComponentRegistry.getInstance() as any).registry = savedRegistry;
  }
});

describe('정보 수정 본인 확인 자리 (mypage_reauth_methods)', () => {
  /**
   * @scenario member=company_account_only, accounts=none
   * @effects non_social_member_sees_template_default_notice, no_social_wording_for_non_social_member
   */
  it('소셜 재확인 대상이 아닌 회원(회사 계정 회원 등)에게는 소셜 안내 대신 템플릿 기본 안내를 보인다', async () => {
    const container = await renderFragment(reauthMethods, { accounts: [], available: [], reauth_via_social: false });

    expect(container.textContent).toContain(koMypage.profile.reauth_unavailable);
    expect(container.textContent).not.toContain(ko.reauth.notice);
    expect(container.textContent).not.toContain(ko.reauth.unavailable);
    expect(container.textContent).not.toContain('소셜');
    expect(container.querySelector('[data-testid="provider-buttons"]')).toBeNull();
  });

  /**
   * @scenario member=social_placeholder, accounts=one
   * @effects social_member_sees_social_notice_and_buttons
   */
  it('소셜 재확인 대상 회원에게는 소셜 안내와 연결한 제공자 버튼을 보이고 관리자 문의 안내는 보이지 않는다', async () => {
    const container = await renderFragment(reauthMethods, { accounts: [KAKAO], available: [], reauth_via_social: true });

    expect(container.textContent).toContain(ko.reauth.notice);
    expect(container.querySelectorAll('[data-testid="provider-buttons"] button')).toHaveLength(1);
    expect(container.textContent).not.toContain(koMypage.profile.reauth_unavailable);
  });
});

describe('비밀번호 첫 설정 본인 확인 자리 (mypage_change_password_reauth)', () => {
  /**
   * @scenario member=company_account_only, accounts=none
   * @effects non_social_member_sees_template_default_notice, no_social_wording_for_non_social_member
   */
  it('소셜 재확인 대상이 아닌 회원에게는 소셜 안내 대신 템플릿 기본 안내를 보인다', async () => {
    const container = await renderFragment(changePasswordReauth, { accounts: [], available: [], reauth_via_social: false });

    expect(container.textContent).toContain(koMypage.profile.change_password_reauth_unavailable);
    expect(container.textContent).not.toContain(ko.reauth.password_notice);
    expect(container.textContent).not.toContain('소셜');
    expect(container.querySelector('[data-testid="provider-buttons"]')).toBeNull();
  });

  /**
   * @scenario member=social_placeholder, accounts=one
   * @effects social_member_sees_social_notice_and_buttons
   */
  it('소셜 재확인 대상 회원에게는 소셜 안내와 제공자 버튼을 보인다', async () => {
    const container = await renderFragment(changePasswordReauth, { accounts: [KAKAO], available: [], reauth_via_social: true });

    expect(container.textContent).toContain(ko.reauth.password_notice);
    expect(container.querySelectorAll('[data-testid="provider-buttons"] button')).toHaveLength(1);
    expect(container.textContent).not.toContain(koMypage.profile.change_password_reauth_unavailable);
  });
});

describe('본인 확인 자리 — 코어가 다른 수단을 고르는 회원 (회사 계정과 소셜 둘 다 연결)', () => {
  /**
   * @scenario member=company_and_social, accounts=one, reauth_via_social=false, reauth_provider=external
   * @effects both_linked_member_sees_actual_method_notice
   */
  it('정보 수정 자리: 소셜을 연결했어도 코어가 회사 계정을 고르면 소셜 안내·카카오 버튼 대신 템플릿 기본 안내를 보인다', async () => {
    const container = await renderFragment(reauthMethods, { accounts: [KAKAO], available: [], reauth_via_social: false }, { reauth_provider: 'sirsoft-sso' });

    expect(container.textContent).toContain(koMypage.profile.reauth_unavailable);
    expect(container.textContent).not.toContain(ko.reauth.notice);
    expect(container.querySelector('[data-testid="provider-buttons"]')).toBeNull();
  });

  /**
   * @scenario member=company_and_social, accounts=one, reauth_via_social=false, reauth_provider=external
   * @effects both_linked_member_sees_actual_method_notice
   */
  it('비밀번호 첫 설정 자리: 코어가 회사 계정을 고르면 관리자 문의 대신 「저장하면 그 방법으로 확인」 안내를 보인다', async () => {
    const container = await renderFragment(changePasswordReauth, { accounts: [KAKAO], available: [], reauth_via_social: false }, { reauth_provider: 'sirsoft-sso' });

    expect(container.querySelector('#social_change_password_reauth_other')).not.toBeNull();
    expect(container.textContent).not.toContain(ko.reauth.password_notice);
    expect(container.textContent).not.toContain(koMypage.profile.change_password_reauth_unavailable);
    expect(container.querySelector('[data-testid="provider-buttons"]')).toBeNull();
  });

  /**
   * @scenario member=undeliverable_no_method, accounts=none, reauth_via_social=false, reauth_provider=none
   * @effects both_linked_member_sees_actual_method_notice
   */
  it('비밀번호 첫 설정 자리: 쓸 수 있는 수단이 없으면 지금처럼 관리자 문의 안내를 보인다', async () => {
    const container = await renderFragment(changePasswordReauth, { accounts: [], available: [], reauth_via_social: false }, { reauth_provider: null });

    expect(container.textContent).toContain(koMypage.profile.change_password_reauth_unavailable);
    expect(container.querySelector('#social_change_password_reauth_other')).toBeNull();
  });
});
