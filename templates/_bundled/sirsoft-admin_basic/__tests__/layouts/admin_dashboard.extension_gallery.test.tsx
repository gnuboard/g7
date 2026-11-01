/**
 * @file admin_dashboard.extension_gallery.test.tsx
 * @description 대시보드 확장 상태 갤러리 — 실제 admin_dashboard.json 으로 칸당 6장 제한을 검증한다
 *
 * 테스트 대상:
 * - 모듈·플러그인: 설치 목록 API 가 전량을 주므로 반복식이 받은 순서 그대로 앞 6장만 깐다
 * - 넘친 개수는 「외 N개 더 있음」으로 적고, 6장 이하면 그 문구를 띄우지 않는다
 * - 템플릿: 서버가 쪽 단위로 6건만 주므로 넘친 개수는 pagination.total 로 센다
 * - 아이콘: icon_url 이 있으면 이미지를, 없으면 기본 글리프만 그린다
 *
 * @scenario-manifest templates/_bundled/sirsoft-admin_basic/tests/scenarios/admin-dashboard-extension-gallery.yaml
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import path from 'path';
import fs from 'fs';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const layoutPath = path.resolve(__dirname, '../../layouts/admin_dashboard.json');
const dashboardLayout: any = JSON.parse(fs.readFileSync(layoutPath, 'utf-8'));

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text, title }) => (
  <p className={className} title={title}>{children || text}</p>
);
const TestH1: React.FC<any> = ({ className, children, text }) => <h1 className={className}>{children || text}</h1>;
const TestH2: React.FC<any> = ({ className, children, text }) => <h2 className={className}>{children || text}</h2>;
const TestIcon: React.FC<any> = ({ name }) => <i data-testid={`icon-${name}`} />;
const TestImg: React.FC<any> = ({ src, alt }) => <img src={src} alt={alt} />;
const TestA: React.FC<any> = ({ href, className, children }) => <a href={href} className={className}>{children}</a>;
const TestButton: React.FC<any> = ({ className, children }) => <button className={className}>{children}</button>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  admin: {
    dashboard: {
      title: '대시보드',
      module_status: { title: '모듈 상태', subtitle: '모듈', active: '활성화', inactive: '비활성화' },
      plugin_status: { title: '플러그인 상태', subtitle: '플러그인', active: '활성화', inactive: '비활성화' },
      template_status: { title: '템플릿 상태', subtitle: '템플릿', active: '활성화', inactive: '비활성화' },
      extension_status: { more: '더보기', more_count: '외 {{count}}개 더 있음' },
    },
  },
};

/** 이름이 `{prefix} n` 인 확장 n 개 — 앞에서 i 번째만 비활성으로 둘 수 있다 */
function makeExtensions(prefix: string, count: number, inactiveIndex = -1) {
  return Array.from({ length: count }, (_, i) => ({
    identifier: `${prefix.toLowerCase()}-${i + 1}`,
    name: `${prefix} ${i + 1}`,
    version: '1.0.0',
    status: i === inactiveIndex ? 'inactive' : 'active',
    icon_url: i === 0 ? `/icons/${prefix.toLowerCase()}-1.svg` : null,
  }));
}

describe('어드민 대시보드 - 확장 상태 갤러리', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  async function renderDashboard(opts: { modules: any[]; plugins: any[]; templates: any[]; templateTotal: number }) {
    testUtils = createLayoutTest(dashboardLayout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
      auth: {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', permissions: ['core.modules.read', 'core.plugins.read', 'core.templates.read'] },
        authType: 'admin',
      },
    });
    testUtils.mockApi('dashboard_stats', { response: {} });
    testUtils.mockApi('dashboard_activities', { response: { data: [] } });
    testUtils.mockApi('dashboard_alerts', { response: { data: [] } });
    testUtils.mockApi('dashboard_modules', { response: { data: { data: opts.modules } } });
    testUtils.mockApi('dashboard_plugins', { response: { data: { data: opts.plugins } } });
    testUtils.mockApi('dashboard_templates', {
      response: {
        data: { data: opts.templates, pagination: { current_page: 1, last_page: 1, per_page: 6, total: opts.templateTotal } },
      },
    });
    await testUtils.render();
  }

  // @scenario kind=plugin, overflow=over_six
  // @effects gallery_shows_at_most_six_in_server_order, overflow_note_only_when_over_six
  it('플러그인이 15개면 받은 순서대로 앞 6장만 그리고 외 9개를 적는다', async () => {
    // 7번째(인덱스 6)를 비활성으로 둔다 — 활성 먼저 줄 세우지 않으므로 잘려서 보이지 않아야 한다
    await renderDashboard({
      modules: makeExtensions('Module', 3),
      plugins: makeExtensions('Plugin', 15, 6),
      templates: makeExtensions('Template', 2),
      templateTotal: 2,
    });

    for (let i = 1; i <= 6; i++) {
      expect(screen.getByText(`Plugin ${i}`)).toBeTruthy();
    }
    expect(screen.queryByText('Plugin 7')).toBeNull();
    expect(screen.queryByText('Plugin 15')).toBeNull();
    expect(screen.getByText('외 9개 더 있음')).toBeTruthy();
  });

  // @scenario kind=module, overflow=at_most_six
  // @effects overflow_note_only_when_over_six
  it('6장 이하인 칸에는 외 N개 문구를 띄우지 않는다', async () => {
    await renderDashboard({
      modules: makeExtensions('Module', 6),
      plugins: makeExtensions('Plugin', 2),
      templates: makeExtensions('Template', 2),
      templateTotal: 2,
    });

    expect(screen.getByText('Module 6')).toBeTruthy();
    expect(screen.queryByText(/더 있음/)).toBeNull();
  });

  // @scenario kind=module, overflow=over_six
  // @effects gallery_shows_at_most_six_in_server_order, overflow_note_only_when_over_six
  it('모듈이 8개면 앞 6장만 그리고 외 2개를 적는다', async () => {
    await renderDashboard({
      modules: makeExtensions('Module', 8),
      plugins: makeExtensions('Plugin', 1),
      templates: makeExtensions('Template', 1),
      templateTotal: 1,
    });

    expect(screen.getByText('Module 6')).toBeTruthy();
    expect(screen.queryByText('Module 7')).toBeNull();
    expect(screen.getByText('외 2개 더 있음')).toBeTruthy();
  });

  // @scenario kind=plugin, overflow=at_most_six
  // @effects gallery_shows_at_most_six_in_server_order, overflow_note_only_when_over_six
  it('플러그인이 딱 6개면 6장을 모두 그리고 외 N개 문구는 없다', async () => {
    await renderDashboard({
      modules: makeExtensions('Module', 1),
      plugins: makeExtensions('Plugin', 6, 5),
      templates: makeExtensions('Template', 1),
      templateTotal: 1,
    });

    expect(screen.getByText('Plugin 1')).toBeTruthy();
    expect(screen.getByText('Plugin 6')).toBeTruthy();
    expect(screen.queryByText(/더 있음/)).toBeNull();
  });

  // @scenario kind=template, overflow=over_six
  // @effects template_overflow_counted_from_pagination_total, gallery_shows_at_most_six_in_server_order
  it('템플릿은 받은 건수가 아니라 pagination.total 로 넘친 개수를 센다', async () => {
    await renderDashboard({
      modules: makeExtensions('Module', 1),
      plugins: makeExtensions('Plugin', 1),
      templates: makeExtensions('Template', 6),
      templateTotal: 8,
    });

    expect(screen.getByText('Template 6')).toBeTruthy();
    expect(screen.getByText('외 2개 더 있음')).toBeTruthy();
  });

  // @scenario kind=template, overflow=at_most_six
  // @effects icon_image_or_fallback_glyph
  it('icon_url 이 있는 확장만 아이콘 이미지를 그리고 나머지는 기본 글리프만 남는다', async () => {
    await renderDashboard({
      modules: makeExtensions('Module', 2),
      plugins: makeExtensions('Plugin', 2),
      templates: makeExtensions('Template', 2),
      templateTotal: 2,
    });

    const srcs = Array.from(document.querySelectorAll('img')).map((img) => img.getAttribute('src'));
    expect(srcs).toEqual(expect.arrayContaining(['/icons/module-1.svg', '/icons/plugin-1.svg', '/icons/template-1.svg']));
    expect(srcs).toHaveLength(3);
    // 글리프는 아이콘 유무와 상관없이 카드마다 깔린다(같은 글리프를 빠른 메뉴 등 다른 칸도 쓰므로 하한만 본다)
    expect(screen.getAllByTestId('icon-cube').length).toBeGreaterThanOrEqual(2);
    expect(screen.getAllByTestId('icon-puzzle-piece').length).toBeGreaterThanOrEqual(2);
    expect(screen.getAllByTestId('icon-palette').length).toBeGreaterThanOrEqual(2);
  });
});
