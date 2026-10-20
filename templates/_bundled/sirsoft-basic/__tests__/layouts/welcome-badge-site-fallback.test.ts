/**
 * @file welcome-badge-site-fallback.test.ts
 * @description 홈 환영 배지와 사이트 이름 폴백이 화면 언어로 나오는지 렌더 검증 (#149)
 *
 * 결함(제보): 홈 환영 카드의 배지 「Welcome」 이 ko·en·ja 모든 화면에서 영어로 나왔다. 같은 결함군으로
 *   사이트 이름을 비워 둔 사이트의 헤더 로고 자리·푸터·환영 카드 제목이 언어와 무관하게 「Site」(저작권 문구는
 *   「그누보드7」)로 나왔다.
 *
 * 처치: 배지는 새 키 `home.welcome_badge`, 사이트 이름 폴백은 기존 키 `user.base_layout_title`(「그누보드7」/
 *   「Gnuboard7」/「グヌボード7」)로 바꿨다.
 *
 * 검증 방식: 실제 `_welcome_card.json` 을 ko 언어 파일로 렌더하고, `_welcome_card`·`_user_base` 굽기 산출에
 *   하드코딩 리터럴이 남지 않았는지 정적 대조한다.
 *
 * @scenario-manifest tests/scenarios/board-extension-slots.yaml
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

type Node = Record<string, any>;

const ROOT = path.resolve(__dirname, '../..');
const readJson = (rel: string): Node => JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf8'));

const welcomeCard = readJson('layouts/partials/home/_welcome_card.json');

/** 텍스트를 그대로 그리는 기본 컴포넌트 */
const Passthrough =
  (Tag: string): React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =>
  ({ className, children, text }) =>
    React.createElement(Tag, { className }, children ?? text);

/**
 * 환영 카드가 쓰는 기본 컴포넌트만 등록한다.
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => React.createElement(React.Fragment, null, children);
  const entry = (name: string, tag: string) => ({ component: Passthrough(tag), metadata: { name, type: 'basic' } });
  (registry as any).registry = {
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: entry('Div', 'div'),
    Span: entry('Span', 'span'),
    H2: entry('H2', 'h2'),
    P: entry('P', 'p'),
    A: entry('A', 'a'),
    Button: entry('Button', 'button'),
    Icon: { component: () => React.createElement('i'), metadata: { name: 'Icon', type: 'basic' } },
  };
  return registry;
}

/** ko 언어 묶음 — 환영 카드가 읽는 home·user 묶음만 실제 파일에서 */
const KO = {
  home: readJson('lang/partial/ko/home.json'),
  user: readJson('lang/partial/ko/user.json'),
};

let current: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 환영 카드만으로 화면을 만들어 렌더한다.
 *
 * @param general 사이트 일반 설정(`_global.settings.general`)
 */
async function renderWelcome(general: Record<string, unknown>) {
  // 표현식 안 `$t('…')` 는 렌더 문맥의 `$templateId` 로 사전을 고르고, 없으면 `window.__templateApp` 설정에서 회수한다
  // (DataBindingEngine). 실제 앱은 문맥에 넣지만 하네스는 넣지 않으므로 하네스 사전(`test-template`)을 가리키게 한다.
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
  current = createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'welcome_badge_site_fallback_test',
      components: [{ id: 'root', type: 'basic', name: 'Div', children: [welcomeCard] }],
    } as any,
    {
      translations: KO,
      locale: 'ko',
      initialState: { _global: { settings: { general }, currentUser: null } },
      componentRegistry: setupTestRegistry(),
    } as any,
  );
  await current.render();
  return current;
}

describe('홈 환영 카드 — 배지·사이트 이름 폴백 번역 (#149)', () => {
  afterEach(() => {
    current?.cleanup();
    current = null;
    delete (window as any).__templateApp;
  });

  /**
   * @scenario surface=home_welcome, locale=ko, site_name=empty
   * @effects welcome_badge_follows_screen_locale
   */
  it('ko 로 렌더하면 배지가 「환영합니다」 이고 「Welcome」 이 없다', async () => {
    await renderWelcome({});
    const spans = Array.from(document.querySelectorAll('span')).map((s) => (s.textContent ?? '').trim());
    expect(spans).toContain('환영합니다');
    expect(spans).not.toContain('Welcome');
  });

  /**
   * @scenario surface=home_welcome, locale=ko, site_name=empty
   * @effects empty_site_name_falls_back_to_localized_site_title
   */
  it('사이트 이름이 없으면 제목이 「그누보드7에 오신 것을 환영합니다」 다(「Site」 아님)', async () => {
    await renderWelcome({});
    expect((document.querySelector('h2')?.textContent ?? '').trim()).toBe('그누보드7에 오신 것을 환영합니다');
  });

  /**
   * @scenario surface=home_welcome, locale=ko, site_name=set
   * @effects empty_site_name_falls_back_to_localized_site_title
   */
  it('사이트 이름이 있으면 그 이름을 쓴다(폴백 미적용)', async () => {
    await renderWelcome({ site_name: '언어별 커뮤니티' });
    expect((document.querySelector('h2')?.textContent ?? '').trim()).toBe('언어별 커뮤니티에 오신 것을 환영합니다');
  });
});

describe('사이트 이름 폴백 하드코딩 리터럴 0 (굽기 산출 정적 대조)', () => {
  /**
   * @scenario surface=user_base_header_footer, locale=ko, site_name=empty
   * @effects empty_site_name_falls_back_to_localized_site_title
   */
  it.each(['layouts/partials/home/_welcome_card.json', 'layouts/_user_base.json'])('%s 에 \'Site\'·Welcome·\'그누보드7\' 폴백이 없다', (rel) => {
    const raw = fs.readFileSync(path.join(ROOT, rel), 'utf8');
    expect(raw).not.toContain("?? 'Site'");
    expect(raw).not.toContain("?? '그누보드7'");
    expect(raw).not.toMatch(/"text":\s*"Welcome"/);
  });

  /**
   * @scenario surface=user_base_header_footer, locale=ko, site_name=empty
   * @effects empty_site_name_falls_back_to_localized_site_title
   */
  it('_user_base 의 사이트 이름 폴백 5곳이 모두 user.base_layout_title 키를 쓴다', () => {
    const raw = fs.readFileSync(path.join(ROOT, 'layouts/_user_base.json'), 'utf8');
    expect(raw.split("site_name ?? $t('user.base_layout_title')").length - 1).toBe(5);
  });
});
