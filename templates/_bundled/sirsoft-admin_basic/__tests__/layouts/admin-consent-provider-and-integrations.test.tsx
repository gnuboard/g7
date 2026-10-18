/**
 * @file admin-consent-provider-and-integrations.test.tsx
 * @description #639 관리자 화면 — 개인정보 탭 「동의 관리 제공자」 카드, 방문자 통계 동의 출처 배지, 확장 상세 「연동 확장」 행
 *
 * 테스트 대상:
 * - partials/admin_settings/_tab_privacy.json (card_consent_provider)
 * - admin_analytics_settings.json (consent_source_row)
 * - partials/admin_{module,plugin,template}_list/_modal_detail.json (integrations_section)
 *
 * 검증:
 * - 선택 상태(ambiguous·missing·none 두 사유·auto)마다 안내가 하나만, 맞는 문구로 뜬다
 * - 「자동」 선택지는 auto 일 때 지금 판단하는 제공자 이름을 함께 보인다
 * - 배지는 선택 상태로 색을 고르고, 선택이 필요하면 개인정보 탭으로 가는 링크가 있다
 * - 연동 확장 행은 이름·미설치·비활성 배지를 보이고, 선언이 없으면 절을 그리지 않는다
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<any> = ({ className, children, role }) => (
  <div className={className} role={role}>{children}</div>
);
const TestSpan: React.FC<any> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);
const TestP: React.FC<any> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);
const TestH3: React.FC<any> = ({ className, children, text }) => (
  <h3 className={className}>{children || text}</h3>
);
const TestIcon: React.FC<any> = ({ name, className }) => <i className={className} data-icon={name} />;
const TestSelect: React.FC<any> = ({ options, disabled }) => (
  <select data-testid="consent-provider-select" disabled={disabled}>
    {(options ?? []).map((o: any) => (
      <option key={o.value} value={o.value}>{o.label}</option>
    ))}
  </select>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  admin: {
    settings: {
      privacy: {
        consent_provider: {
          title: '동의 관리 제공자',
          desc: '설명',
          label: '제공자',
          auto: '자동',
          auto_current: '자동 (현재: {{provider}})',
          ambiguous: '제공자가 2개 이상',
          missing: '선택한 제공자 비활성',
          none_no_provider: '동의 관리 확장 없음',
          none_unavailable: '동의 수집 꺼짐',
        },
      },
    },
    extension_integrations: {
      title: '함께 쓰면 연동되는 확장',
      hint: '없어도 동작합니다.',
      not_installed: '미설치',
      inactive: '비활성',
    },
  },
};

/** 레이아웃 JSON 을 읽는다 */
function loadJson(relativePath: string): any {
  const fs = require('fs');
  const path = require('path');
  return JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', '..', 'layouts', relativePath), 'utf-8'));
}

/** id 로 노드를 찾는다 (children·components·slots 등 하위 값을 모두 훑는다) */
function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return undefined;
  if (Array.isArray(node)) {
    for (const item of node) {
      const found = findNodeById(item, id);
      if (found) return found;
    }
    return undefined;
  }
  if (node.id === id) return node;
  for (const value of Object.values(node)) {
    if (value && typeof value === 'object') {
      const found = findNodeById(value, id);
      if (found) return found;
    }
  }
  return undefined;
}

/**
 * 표현식 안 `$t()` 는 앱 설정의 템플릿 ID 로 사전을 찾는다 — 테스트 유틸이 사전을 넣는 템플릿 ID 를 알려 준다.
 */
function stubTemplateApp(): void {
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
}

const PROVIDERS = [{ id: 'sirsoft-gdpr', label: '쿠키 동의 배너' }, { id: 'acme-consent', label: 'Acme' }];
const ALERT_TEXTS = ['제공자가 2개 이상', '선택한 제공자 비활성', '동의 관리 확장 없음', '동의 수집 꺼짐'];

describe('개인정보 탭 — 동의 관리 제공자 카드 (#639 §4.1)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;
  let card: any;

  beforeEach(() => {
    registry = setupRegistry();
    card = findNodeById(loadJson('partials/admin_settings/_tab_privacy.json'), 'card_consent_provider');
    stubTemplateApp();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
    delete (window as any).__templateApp;
  });

  /** 선택 상태로 카드를 그린다 */
  async function renderWith(selection: Record<string, any>, providers = PROVIDERS, saved = '') {
    testUtils = createLayoutTest(
      {
        version: '1.0.0',
        layout_name: 'consent_provider_card_test',
        initLocal: { form: { privacy: { consent_provider: saved }, _meta: { consent_selection: selection, consent_providers: providers } } },
        components: [card],
      },
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();
  }

  /** 지금 보이는 안내 문구 */
  function visibleAlerts(): string[] {
    return ALERT_TEXTS.filter((t) => screen.queryByText(t) !== null);
  }

  // @scenario case=admin_card
  // @effects admin_privacy_tab_shows_consent_provider_state
  it('카드가 개인정보 탭 맨 위에 있다', () => {
    const tab = findNodeById(loadJson('partials/admin_settings/_tab_privacy.json'), 'tab_content_privacy');
    expect(tab.children[0].id).toBe('card_consent_provider');
  });

  // @scenario case=admin_card
  // @effects admin_privacy_tab_shows_consent_provider_state
  it('auto 면 안내 없이 「자동 (현재: 제공자)」 선택지를 보인다', async () => {
    await renderWith({ state: 'auto', reason: null, provider_id: 'sirsoft-gdpr', provider_label: '쿠키 동의 배너' }, [PROVIDERS[0]]);

    expect(visibleAlerts()).toEqual([]);
    const options = Array.from(screen.getByTestId('consent-provider-select').querySelectorAll('option')).map((o) => o.textContent);
    expect(options).toEqual(['자동 (현재: 쿠키 동의 배너)', '쿠키 동의 배너']);
  });

  // @scenario case=admin_card
  // @effects admin_privacy_tab_shows_consent_provider_state
  it('ambiguous 면 「확인 불가」 경고 하나만 뜨고 선택지에 제공자가 모두 있다', async () => {
    await renderWith({ state: 'ambiguous', reason: null, provider_id: null, provider_label: null });

    expect(visibleAlerts()).toEqual(['제공자가 2개 이상']);
    const options = Array.from(screen.getByTestId('consent-provider-select').querySelectorAll('option')).map((o) => o.textContent);
    expect(options).toEqual(['자동', '쿠키 동의 배너', 'Acme']);
  });

  // @scenario case=admin_card
  // @effects admin_privacy_tab_shows_consent_provider_state
  it('missing 이면 「선택한 제공자 비활성」 경고만 뜬다', async () => {
    await renderWith({ state: 'missing', reason: null, provider_id: null, provider_label: null });
    expect(visibleAlerts()).toEqual(['선택한 제공자 비활성']);
  });

  // @scenario case=admin_card
  // @effects admin_privacy_tab_shows_consent_provider_state
  it('missing 이면 사라진 제공자의 저장 id 를 선택지로 보여 선택 상자가 빈 글자가 되지 않는다', async () => {
    await renderWith({ state: 'missing', reason: null, provider_id: null, provider_label: null }, [PROVIDERS[1]], 'sirsoft-gdpr');

    const options = Array.from(screen.getByTestId('consent-provider-select').querySelectorAll('option')).map((o) => o.textContent);
    expect(options).toEqual(['자동', 'Acme', 'sirsoft-gdpr']);
  });

  // @scenario case=admin_card
  // @effects admin_privacy_tab_shows_consent_provider_state
  it('selected 면 경고 없이 고른 제공자가 선택지에 있다', async () => {
    await renderWith({ state: 'selected', reason: null, provider_id: 'acme-consent', provider_label: 'Acme' }, PROVIDERS, 'acme-consent');

    expect(visibleAlerts()).toEqual([]);
    const options = Array.from(screen.getByTestId('consent-provider-select').querySelectorAll('option')).map((o) => o.textContent);
    expect(options).toEqual(['자동', '쿠키 동의 배너', 'Acme']);
  });

  // @scenario case=admin_card
  // @effects admin_privacy_tab_shows_consent_provider_state
  it('none 은 사유에 따라 문구가 갈린다', async () => {
    await renderWith({ state: 'none', reason: 'no_provider', provider_id: null, provider_label: null }, []);
    expect(visibleAlerts()).toEqual(['동의 관리 확장 없음']);
    testUtils.cleanup();

    await renderWith({ state: 'none', reason: 'provider_unavailable', provider_id: null, provider_label: null });
    expect(visibleAlerts()).toEqual(['동의 수집 꺼짐']);
  });

  // @scenario case=admin_card
  // @effects admin_privacy_tab_shows_consent_provider_state
  it('읽기 전용이면 선택 상자가 잠긴다', () => {
    const select = findNodeById(card, 'consent_provider_field');
    const json = JSON.stringify(select);
    expect(json).toContain('"name":"privacy.consent_provider"');
    expect(json).toContain('"disabled":"{{_computed.isReadOnly}}"');
  });
});

describe('방문자 통계 설정 — 동의 출처 배지 (#639 §4.2)', () => {
  const layout = loadJson('admin_analytics_settings.json');
  const row = findNodeById(layout, 'consent_source_row');

  // @scenario case=admin_badge
  // @effects analytics_badge_follows_consent_selection
  it('배지 색과 문구가 선택 상태(auto·selected 초록, ambiguous·missing 주황, 그 외 회색)로 정해진다', () => {
    const badge = row.children.find((c: any) => c.name === 'Badge');
    expect(badge.props.color).toContain("['auto', 'selected'].includes(analytics_status?.data?.collection?.consent_selection ?? '') ? 'green'");
    expect(badge.props.color).toContain("['ambiguous', 'missing'].includes(analytics_status?.data?.collection?.consent_selection ?? '') ? 'orange' : 'gray'");
    expect(badge.props.text).toContain('consent_provider_label');
    expect(badge.props.text).toContain('consent_source_needs_selection');
    expect(JSON.stringify(row)).not.toContain('consent_sources.');
  });

  // @scenario case=admin_badge
  // @effects analytics_badge_follows_consent_selection
  it('선택이 필요하면 개인정보 탭으로 가는 링크가 있다', () => {
    const link = findNodeById(row, 'consent_source_choose_link');
    const wrapper = (row.children ?? []).find((child: any) => (child.children ?? []).includes(link));
    // 선택이 필요한 상태는 감싸는 요소가, 목적지를 열 권한(core.settings.read)은 링크 자신이 가린다.
    expect(wrapper?.if).toContain("['ambiguous', 'missing']");
    expect(link.if).toBe('{{_computed.canReadDefinitions}}');
    expect(JSON.stringify(link.actions)).toContain('/admin/settings?tab=privacy');
  });
});

describe('확장 상세 — 함께 쓰면 연동되는 확장 (#639 §4.3)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  const cases: Array<[string, string]> = [
    ['partials/admin_module_list/_modal_detail.json', 'selectedModule'],
    ['partials/admin_plugin_list/_modal_detail.json', 'selectedPlugin'],
    ['partials/admin_template_list/_modal_detail.json', 'selectedTemplate'],
  ];

  for (const [file, globalKey] of cases) {
    // @scenario case=admin_detail
    // @effects extension_detail_shows_integrations
    it(`${file} — 이름·미설치·비활성 배지를 보이고, 선언이 없으면 그리지 않는다`, async () => {
      const section = findNodeById(loadJson(file), 'integrations_section');
      expect(section).toBeDefined();

      testUtils = createLayoutTest(
        {
          version: '1.0.0',
          layout_name: 'integrations_test',
          initGlobal: {
            [globalKey]: {
              integrations: [
                { identifier: 'sirsoft-board', name: '게시판', type: 'module', is_installed: true, is_active: true },
                { identifier: 'sirsoft-ecommerce', name: 'sirsoft-ecommerce', type: 'module', is_installed: false, is_active: false },
                { identifier: 'sirsoft-marketing', name: '마케팅', type: 'plugin', is_installed: true, is_active: false },
              ],
            },
          },
          components: [section],
        },
        { translations, locale: 'ko', componentRegistry: registry },
      );
      await testUtils.render();

      expect(screen.getByText('함께 쓰면 연동되는 확장')).toBeTruthy();
      expect(screen.getByText('게시판 (sirsoft-board)')).toBeTruthy();
      expect(screen.getAllByText('미설치')).toHaveLength(1);
      expect(screen.getAllByText('비활성')).toHaveLength(1);
      testUtils.cleanup();

      testUtils = createLayoutTest(
        { version: '1.0.0', layout_name: 'integrations_empty', initGlobal: { [globalKey]: { integrations: [] } }, components: [section] },
        { translations, locale: 'ko', componentRegistry: registry },
      );
      await testUtils.render();
      expect(screen.queryByText('함께 쓰면 연동되는 확장')).toBeNull();
    });
  }
});
