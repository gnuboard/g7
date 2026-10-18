/**
 * @file admin-template-detail-externals.test.tsx
 * @description 템플릿 정보 모달 - 외부 리소스(externals) 섹션 렌더링 테스트
 *
 * 테스트 대상:
 * - partials/admin_template_list/_modal_detail.json (external_resources_section)
 *
 * 검증:
 * - externals 가 비어 있을 때 "외부 리소스가 없습니다" 메시지
 * - externals 항목 행 렌더링 — id, type, url
 * - id 가 없는 항목은 id 배지를 생략하고 type/url 만 표시
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<any> = ({ className, children }) => (
  <div className={className}>{children}</div>
);
const TestSpan: React.FC<any> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);
const TestP: React.FC<any> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);
const TestIcon: React.FC<any> = ({ name, className }) => (
  <i className={className} data-icon={name} />
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

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
    templates: {
      modals: {
        external_resources: '외부 리소스',
        external_resources_description: '이 템플릿이 사용하는 외부 스타일·웹폰트·스크립트입니다.',
        no_external_resources: '외부 리소스가 없습니다.',
      },
    },
  },
};

function loadSection(): any {
  const fs = require('fs');
  const path = require('path');
  const full = path.resolve(
    __dirname,
    '..',
    '..',
    'layouts',
    'partials/admin_template_list/_modal_detail.json',
  );
  const json = JSON.parse(fs.readFileSync(full, 'utf-8'));
  return findNodeById(json.children ?? [json], 'external_resources_section');
}

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

function buildLayout(section: any, externals: any[]) {
  return {
    version: '1.0.0',
    layout_name: 'external_resources_section_test',
    initGlobal: { selectedTemplate: { externals } },
    components: [section],
  };
}

describe('템플릿 정보 모달 - 외부 리소스 섹션', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;
  let section: any;

  beforeEach(() => {
    registry = setupRegistry();
    section = loadSection();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('external_resources_section 이 template partial 에 존재한다', () => {
    expect(section).toBeDefined();
    expect(section.id).toBe('external_resources_section');
  });

  it('externals 가 비어 있으면 "외부 리소스가 없습니다" 메시지가 표시된다', async () => {
    testUtils = createLayoutTest(buildLayout(section, []), {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.getByText('외부 리소스가 없습니다.')).toBeTruthy();
  });

  it('externals 항목의 id, type, url 이 모두 렌더링된다', async () => {
    testUtils = createLayoutTest(
      buildLayout(section, [
        {
          id: 'fontawesome',
          type: 'style',
          url: 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
        },
        {
          id: 'pretendard',
          type: 'webfont',
          url: 'https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.min.css',
        },
      ]),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('fontawesome')).toBeTruthy();
    expect(screen.getByText('pretendard')).toBeTruthy();
    expect(screen.getByText('style')).toBeTruthy();
    expect(screen.getByText('webfont')).toBeTruthy();
    expect(
      screen.getByText('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'),
    ).toBeTruthy();
  });

  it('id 가 없는 항목은 type 과 url 만 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayout(section, [
        {
          type: 'preconnect',
          url: 'https://cdn.example.com',
        },
      ]),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('preconnect')).toBeTruthy();
    expect(screen.getByText('https://cdn.example.com')).toBeTruthy();
  });
});
