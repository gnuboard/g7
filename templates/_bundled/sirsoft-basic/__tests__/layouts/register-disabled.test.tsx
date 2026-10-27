/**
 * @file register-disabled.test.tsx
 * @description 「일반 회원가입 받지 않음」 화면 회귀 테스트 (sirsoft-basic, 7.2.0 B1⑦·B2)
 *
 * 환경설정 `security.registration_enabled` 가 거짓이면(`_global.settings.security.registration_enabled === false`)
 * - `/register` 는 가입 폼·가입 확장 칸 대신 안내 카드(data-ref `registration_closed_card`)와 로그인 버튼을 보인다.
 * - 가입 링크를 숨긴다 — `_user_base` 모바일 드로어, `partials/home/_welcome_card`, 로그인 화면 하단.
 * - 데스크톱 헤더(Header 컴포넌트)에는 레이아웃이 `registrationEnabled` prop 을 넘긴다(컴포넌트가 가입 링크를 숨긴다).
 *
 * 값이 없거나 참이면 지금처럼 가입을 받는다(`!== false` — 설정이 아직 안 내려온 화면이 가입을 막지 않게).
 *
 * @vitest-environment jsdom
 * @since 1.3.0
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { fireEvent, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import registerPage from '../../layouts/auth/register.json';
import loginPage from '../../layouts/auth/login.json';
import userBase from '../../layouts/_user_base.json';
import welcomeCard from '../../layouts/partials/home/_welcome_card.json';
import koAuth from '../../lang/partial/ko/auth.json';

/** DOM 에 넘길 속성 */
const PASS_PROPS = ['id', 'className', 'role', 'type', 'href', 'onClick'];

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
    Flex: basic('div', 'Flex'),
    Span: basic('span', 'Span'),
    P: basic('p', 'P'),
    H2: basic('h2', 'H2'),
    A: basic('a', 'A'),
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

/**
 * 조각 참조·확장점을 표지(marker) 칸으로 바꿉니다 — 이 시험은 가입 폼 자체가 아니라 그 자리의 노출만 잰다.
 *
 * @param node 노드
 * @returns 바꾼 노드
 */
function stubPartials(node: any): any {
  if (Array.isArray(node)) return node.map(stubPartials);
  if (!node || typeof node !== 'object') return node;
  if (node.partial) return { type: 'basic', name: 'Div', props: { id: `partial:${node.partial}` } };
  if (node.type === 'extension_point') return { type: 'basic', name: 'Div', props: { id: `extension:${node.name}` } };
  return { ...node, ...(Array.isArray(node.children) ? { children: node.children.map(stubPartials) } : {}) };
}

/** 가입 화면 카드 — 제목·닫힘 안내·폼 자리·「이미 회원이신가요」를 담은 상자 */
const registerCard = flatten(registerPage as any).find(
  (n) => n.name === 'Div' && Array.isArray(n.children) && n.children.some((c: any) => c.id === 'registration_closed_card'),
);

/** 로그인 화면 하단 링크 영역 (비밀번호 찾기 · 회원가입) */
const loginFooterLinks = flatten(loginPage as any).find(
  (n) => n.name === 'Div' && Array.isArray(n.children) && n.children.some((c: any) => c.props?.href === '/register'),
);

/** 모바일 드로어의 비로그인 버튼 줄 (로그인 · 회원가입) */
const drawerGuestButtons = flatten((userBase as any).components).find(
  (n) => n.name === 'Div' && Array.isArray(n.children) && n.children.some((c: any) => c.props?.href === '/register'),
);

/**
 * 노드 하나를 단독 레이아웃으로 그립니다.
 *
 * @param node 그릴 노드
 * @param registrationEnabled 원 설정값 (undefined 면 설정 없음)
 * @param registrationOpen 서버가 계산한 실효값 `appConfig.auth.registration_open` (undefined 면 싣지 않음)
 * @returns 레이아웃 시험 하네스
 */
async function renderNode(node: any, registrationEnabled: boolean | undefined, registrationOpen?: boolean) {
  const settings = registrationEnabled === undefined ? {} : { security: { registration_enabled: registrationEnabled } };
  const appConfig = registrationOpen === undefined ? {} : { auth: { registration_open: registrationOpen } };
  const t = createLayoutTest(
    { version: '1.0.0', layout_name: 'register_disabled_test', components: [stubPartials(node)] } as any,
    {
      componentRegistry: setupRegistry(),
      translations: { auth: koAuth },
      initialState: { _global: { settings, appConfig, currentUser: null } },
      initialData: { $locale: 'ko', $templateId: 'test-template' },
    },
  );
  await t.render();
  return t;
}

/**
 * 가입 링크(`a[href="/register"]`) 수를 셉니다.
 *
 * @returns 개수
 */
function registerLinkCount(): number {
  return document.querySelectorAll('a[href="/register"]').length;
}

describe('sirsoft-basic 일반 회원가입 받지 않음 (7.2.0 B2)', () => {
  beforeEach(() => {
    setupRegistry();
  });

  /**
   * @scenario registration=closed, surface=register
   * @effects register_closed_shows_notice_card
   */
  it('가입을 받지 않으면 /register 는 폼·확장 칸 대신 안내 카드와 로그인 버튼을 보인다', async () => {
    expect(registerCard, 'register.g7.html 에 registration_closed_card 를 담은 카드가 없다').toBeDefined();
    const t = await renderNode(registerCard, false);

    const card = document.getElementById('registration_closed_card');
    expect(card, '안내 카드(registration_closed_card)가 그려지지 않았다').not.toBeNull();
    expect(card!.textContent).toContain(koAuth.register.closed_notice);
    expect(card!.querySelector('a[href="/login"]')?.textContent).toBe(koAuth.login);

    expect(document.getElementById('partial:partials/auth/_register_form.json')).toBeNull();
    expect(document.getElementById('extension:auth_register_extensions')).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario registration=closed, surface=register
   * @effects register_closed_login_button_navigates
   */
  it('안내 카드의 로그인 버튼은 /login 으로 보낸다', async () => {
    const t = await renderNode(registerCard, false);

    fireEvent.click(document.getElementById('registration_closed_card')!.querySelector('a[href="/login"]')!);
    await waitFor(() => expect(t.getNavigationHistory()).toEqual(['/login']));

    t.cleanup();
  });

  /**
   * @scenario registration=unset, surface=register
   * @effects register_open_keeps_form
   */
  /**
   * @scenario registration=open, surface=register
   * @effects register_open_keeps_form
   */
  it.each([[true], [undefined]])('registration_enabled=%s 면 지금처럼 가입 폼과 확장 칸을 그리고 안내 카드는 없다', async (value) => {
    const t = await renderNode(registerCard, value);

    expect(document.getElementById('registration_closed_card')).toBeNull();
    expect(document.getElementById('partial:partials/auth/_register_form.json')).not.toBeNull();
    expect(document.getElementById('extension:auth_register_extensions')).not.toBeNull();

    t.cleanup();
  });

  /**
   * @scenario registration=open, surface=login_footer
   * @effects register_links_hidden_when_closed
   */
  /**
   * @scenario registration=unset, surface=login_footer
   * @effects register_links_hidden_when_closed
   */
  /**
   * @scenario registration=closed, surface=mobile_drawer
   * @effects register_links_hidden_when_closed
   */
  /**
   * @scenario registration=open, surface=mobile_drawer
   * @effects register_links_hidden_when_closed
   */
  /**
   * @scenario registration=unset, surface=mobile_drawer
   * @effects register_links_hidden_when_closed
   */
  /**
   * @scenario registration=closed, surface=welcome_card
   * @effects register_links_hidden_when_closed
   */
  /**
   * @scenario registration=open, surface=welcome_card
   * @effects register_links_hidden_when_closed
   */
  /**
   * @scenario registration=unset, surface=welcome_card
   * @effects register_links_hidden_when_closed
   */
  /**
   * @scenario registration=closed, surface=login_footer
   * @effects register_links_hidden_when_closed
   */
  it.each([
    ['로그인 화면 하단', () => loginFooterLinks],
    ['모바일 드로어', () => drawerGuestButtons],
    ['홈 환영 카드', () => welcomeCard],
  ])('%s 의 가입 링크는 가입을 받지 않으면 숨고, 받거나 설정이 없으면 보인다', async (label, pick) => {
    const node = pick();
    expect(node, `${label} 노드를 찾지 못했다`).toBeDefined();

    const closed = await renderNode(node, false);
    expect(registerLinkCount(), `${label} — 닫힘`).toBe(0);
    closed.cleanup();

    const open = await renderNode(node, true);
    expect(registerLinkCount(), `${label} — 열림`).toBe(1);
    open.cleanup();

    const unset = await renderNode(node, undefined);
    expect(registerLinkCount(), `${label} — 설정 없음`).toBe(1);
    unset.cleanup();
  });

  /**
   * 확장이 가입을 닫으면(예: 사이트 전체 회사 계정 강제 — 필터 `core.auth.self_registration_open`) 원 설정값은 켜져 있어도
   * 서버가 계산한 실효값 `appConfig.auth.registration_open` 이 false 다. 화면은 실효값을 따른다(W37 — 서버는 403 인데 폼이 보이던 결함).
   *
   * @scenario registration=closed_by_extension, surface=register
   * @effects effective_flag_closes_register_screens
   */
  /**
   * @scenario registration=closed_by_extension, surface=login_footer
   * @effects effective_flag_closes_register_screens
   */
  /**
   * @scenario registration=closed_by_extension, surface=mobile_drawer
   * @effects effective_flag_closes_register_screens
   */
  /**
   * @scenario registration=closed_by_extension, surface=welcome_card
   * @effects effective_flag_closes_register_screens
   */
  it("원 설정값이 켜져 있어도 실효값이 닫힘이면 /register 는 안내 카드이고 가입 링크 세 곳이 숨는다", async () => {
    const t = await renderNode(registerCard, true, false);
    expect(document.getElementById("registration_closed_card"), "실효값 닫힘인데 안내 카드가 없다").not.toBeNull();
    expect(document.getElementById("partial:partials/auth/_register_form.json")).toBeNull();
    t.cleanup();

    for (const [label, node] of [["로그인 화면 하단", loginFooterLinks], ["모바일 드로어", drawerGuestButtons], ["홈 환영 카드", welcomeCard]] as const) {
      const closed = await renderNode(node, true, false);
      expect(registerLinkCount(), `${label} — 실효값 닫힘`).toBe(0);
      closed.cleanup();
      const open = await renderNode(node, false, true);
      expect(registerLinkCount(), `${label} — 실효값 열림이 원 설정값보다 앞선다`).toBe(1);
      open.cleanup();
    }
  });

  /**
   * @effects header_receives_registration_flag
   */
  it('데스크톱 헤더(Header)에 registrationEnabled prop 을 같은 조건으로 넘긴다 — 모바일 메뉴는 MobileNav 컴포넌트가 아니라 레이아웃 드로어다', () => {
    const nodes = flatten((userBase as any).components);
    const header = nodes.find((n) => n.id === 'desktop_header');

    expect(header?.name).toBe('Header');
    expect(header?.props?.registrationEnabled).toBe('{{(_global.appConfig?.auth?.registration_open ?? _global.settings?.security?.registration_enabled) !== false}}');
    // MobileNav 컴포넌트는 레이아웃에 마운트되지 않는다 — 모바일 가입 링크는 위 드로어(data-ref mobile_nav_drawer)가 숨긴다.
    expect(nodes.some((n) => n.name === 'MobileNav')).toBe(false);
    expect(nodes.some((n) => n.id === 'mobile_nav_drawer')).toBe(true);
  });
});
