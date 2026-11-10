/**
 * @file analyticsOverviewTopToggle.test.tsx
 * @description 방문자 통계 개요 상위 목록 카드의 「목록 펼치기」 단추는 768px 미만에서만 보인다
 *
 * 세 카드(인기 페이지·유입 경로·국가)의 목록은 좁은 화면에서만 접히고, 768px 이상에서는 responsive 가 목록 본문을
 * `block` 으로 펼친다. 그 폭에서 단추가 남으면 눌러도 아무 일이 없는 단추가 된다.
 *
 * 단추의 기본 클래스 `flex-between` 은 `templates/_bundled/sirsoft-admin_basic/src/styles/components/dashboard.css` 에 층 없이(@layer 밖) 선언돼 있어 utilities 층의
 * `hidden` 보다 강하다. 그래서 768px 이상 구간 값에 `flex-between` 을 함께 두면 `hidden` 이 져서 단추가 그대로 보인다
 * (예전 `md:hidden` 이 바로 이렇게 졌다). 이 시험은 구간 값에 `hidden` 이 있고 `display` 를 다시 켜는 클래스가 없음을
 * 실제 렌더로 잰다.
 *
 * 렌더 폭은 ResponsiveContext 를 바꿔 주입한다 — DynamicRenderer 는 useResponsive().width 로 responsive 키를 고른다.
 *
 * @scenario-manifest tests/scenarios/analytics-admin-screens.yaml
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { loadLayout, nodeById } from './analyticsLayoutTestUtils';

/** 현재 시험이 주입할 화면 폭 (아래 mock 이 읽는다) */
let mockWidth = 1280;

vi.mock('@core/template-engine/ResponsiveContext', () => ({
  ResponsiveContext: React.createContext(null),
  ResponsiveProvider: ({ children }: { children?: React.ReactNode }) => children,
  useResponsive: () => ({
    width: mockWidth,
    isMobile: mockWidth < 768,
    isTablet: mockWidth >= 768 && mockWidth < 1024,
    isDesktop: mockWidth >= 1024,
    matchedPreset: (mockWidth < 768 ? 'mobile' : mockWidth < 1024 ? 'tablet' : 'desktop') as
      | 'mobile'
      | 'tablet'
      | 'desktop',
  }),
}));

const TOGGLE_IDS = ['top_pages_card_toggle', 'top_referrers_card_toggle', 'top_countries_card_toggle'];

/** `display` 를 다시 켜는 클래스 — 하나라도 있으면 `hidden` 이 진다(`flex-between` 은 층 없는 규칙이라 늘 이긴다) */
const DISPLAY_ON_CLASSES = ['flex-between', 'flex', 'inline-flex', 'block', 'inline-block', 'grid'];

/** id·className 을 DOM 에 그대로 흘리는 스텁 */
const Stub: React.FC<Record<string, any>> = ({ children, className, id, text }) => (
  <div id={id} data-testid={id} className={className}>
    {children ?? text}
  </div>
);

function setupRegistry(): void {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Button: { component: Stub, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: Stub, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: Stub, metadata: { name: 'Icon', type: 'basic' } },
    Div: { component: Stub, metadata: { name: 'Div', type: 'basic' } },
    Fragment: {
      component: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
      metadata: { name: 'Fragment', type: 'layout' },
    },
  };
}

/**
 * 구운 개요 레이아웃에서 세 단추 노드만 꺼내 그 폭으로 렌더하고 단추별 클래스 낱말을 돌려준다.
 *
 * @param width 주입할 화면 폭(px)
 * @returns 단추 id → 클래스 낱말 목록
 */
async function toggleClassesAt(width: number): Promise<Record<string, string[]>> {
  mockWidth = width;
  setupRegistry();
  const layout = loadLayout('admin_analytics_overview.json');
  const nodes = TOGGLE_IDS.map((id) => {
    const node = nodeById(layout, id);
    expect(node, `${id} 노드가 레이아웃에 없다`).toBeDefined();
    // 렌더 확인 대상은 단추 자신의 클래스다 — 클릭 액션은 지운다(상태 핸들러 등록과 무관하게 렌더).
    const { actions, ...rest } = JSON.parse(JSON.stringify(node));
    return { ...rest, props: { ...rest.props, id } };
  });
  const test = createLayoutTest(
    { version: '1.0', layout_name: 'test_analytics_overview_toggle', data_sources: [], components: nodes } as any,
    { initialState: { _local: { openTop: {} } } },
  );
  await test.render();
  const out: Record<string, string[]> = {};
  for (const id of TOGGLE_IDS) {
    const el = document.getElementById(id);
    expect(el, `${width}px 에서 ${id} 가 렌더되지 않았다`).not.toBeNull();
    out[id] = (el?.className ?? '').split(/\s+/).filter(Boolean);
  }
  test.cleanup?.();
  return out;
}

afterEach(() => {
  cleanup();
});

describe('개요 상위 목록 — 펼치기 단추는 768px 미만에서만 보인다', () => {
  // @scenario screen=overview, state=normal, viewport=390, locale=ko
  // @effects overview_top_list_toggle_only_below_768
  it('767px 에서는 단추가 flex-between 으로 보이고 hidden 이 없다', async () => {
    const classes = await toggleClassesAt(767);
    for (const id of TOGGLE_IDS) {
      expect(classes[id], id).toContain('flex-between');
      expect(classes[id], id).not.toContain('hidden');
    }
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects overview_top_list_toggle_only_below_768
  it.each([768, 1280])('%ipx 에서는 단추가 hidden 이고 display 를 켜는 클래스가 없다', async (width) => {
    const classes = await toggleClassesAt(width);
    for (const id of TOGGLE_IDS) {
      expect(classes[id], `${width}px ${id}`).toContain('hidden');
      for (const on of DISPLAY_ON_CLASSES) {
        expect(classes[id], `${width}px ${id} 에 ${on} 이 남으면 hidden 이 진다`).not.toContain(on);
      }
    }
  });
});
