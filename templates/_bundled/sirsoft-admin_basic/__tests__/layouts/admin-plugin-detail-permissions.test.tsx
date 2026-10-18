/**
 * @file admin-plugin-detail-permissions.test.tsx
 * @description 플러그인 정보 창의 권한 구획이 분류별 권한을 그린다 (회귀)
 *
 * 플러그인 상세 응답의 `permissions` 는 모듈과 같은 **분류 배열**이다(`[{identifier, name,
 * permissions: [{action, name}]}]`). 종전 창은 이 값을 개별 권한 목록으로 읽어 분류 이름만
 * 늘어놓을 자리였고, 그 전에는 서버가 `{name, description, categories}` 계층을 그대로 보내
 * `permissions.length` 가 없어 「권한 없음」도 목록도 그려지지 않았다. GDPR 플러그인 상세 창에서
 * 「권한」 제목 바로 아래가 비어 「역할」이 이어지는 것을 브라우저로 확인했다.
 *
 * 테스트 대상: partials/admin_plugin_list/_modal_detail.json (permissions_section)
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestIcon: React.FC<any> = ({ name, className }) => <i className={className} data-icon={name} />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/** 권한 구획이 쓰는 기본 컴포넌트와 레이아웃 뿌리(Fragment)만 등록한다. */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

const translations = {
  admin: {
    plugins: {
      modals: {
        permissions: '권한',
        no_permissions: '이 플러그인이 등록하는 권한이 없습니다.',
      },
    },
  },
};

/** 레이아웃 트리에서 id 로 노드를 찾는다. */
function findNodeById(nodes: any[] | any, id: string): any {
  const list = Array.isArray(nodes) ? nodes : [nodes];
  for (const node of list) {
    if (!node || typeof node !== 'object') continue;
    if (node.id === id) return node;
    const found = findNodeById(node.children ?? [], id);
    if (found) return found;
  }

  return undefined;
}

/** 구운 플러그인 정보 창에서 권한 구획을 꺼낸다. */
function loadPermissionsSection(): any {
  const full = path.resolve(__dirname, '..', '..', 'layouts', 'partials', 'admin_plugin_list', '_modal_detail.json');
  const json = JSON.parse(fs.readFileSync(full, 'utf-8'));

  return findNodeById(json.children ?? [json], 'permissions_section');
}

/** 권한 구획 하나만 담은 레이아웃 — 창 본문이 읽는 `_global.selectedPlugin` 을 채운다. */
function buildLayout(section: any, selectedPlugin: Record<string, unknown>) {
  return {
    version: '1.0.0',
    layout_name: 'plugin_detail_permissions_test',
    initGlobal: { selectedPlugin },
    components: [section],
  };
}

describe('플러그인 정보 창 — 권한 구획', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | undefined;
  let registry: ComponentRegistry;
  let section: any;

  beforeEach(() => {
    registry = setupRegistry();
    section = loadPermissionsSection();
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
  });

  // @scenario screen=plugins
  // @effects plugin_detail_permissions_render_by_category
  it('분류 이름 아래 개별 권한을 「이름 (분류.동작)」으로 그린다', async () => {
    expect(section?.id).toBe('permissions_section');
    testUtils = createLayoutTest(
      buildLayout(section, {
        identifier: 'sirsoft-gdpr',
        permissions: [
          {
            identifier: 'privacy',
            name: { ko: '개인정보 보호', en: 'Privacy' },
            permissions: [
              { action: 'view', name: { ko: '개인정보 조회', en: 'View Privacy' } },
              { action: 'update', name: { ko: '개인정보 설정 변경', en: 'Update Privacy' } },
            ],
          },
        ],
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('개인정보 보호')).toBeTruthy();
    expect(screen.getByText('개인정보 조회 (privacy.view)')).toBeTruthy();
    expect(screen.getByText('개인정보 설정 변경 (privacy.update)')).toBeTruthy();
    expect(screen.queryByText('이 플러그인이 등록하는 권한이 없습니다.')).toBeNull();
  });

  // @scenario screen=plugins
  // @effects plugin_detail_permissions_render_by_category
  it('권한이 없으면 「권한 없음」 안내를 그린다', async () => {
    testUtils = createLayoutTest(buildLayout(section, { identifier: 'sirsoft-tosspayments', permissions: [] }), {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.getByText('이 플러그인이 등록하는 권한이 없습니다.')).toBeTruthy();
  });
});
