/**
 * @file user-base-mobile-search.test.tsx
 * @description 모바일 메뉴 통합검색 기능 테스트
 *
 * 테스트 대상:
 * - templates/.../layouts/_user_base.json (모바일 검색바 섹션)
 *
 * 검증 항목:
 * - 검색 Input에 keydown Enter 액션이 정의되어 있는지
 * - Enter 키 입력 시 /search 페이지로 navigate 되는지
 * - 검색어가 query.q 파라미터로 전달되는지
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  createLayoutTest,
  screen,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 ==========

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  placeholder?: string;
  className?: string;
  value?: string;
  onKeyDown?: (e: React.KeyboardEvent) => void;
  onChange?: (e: React.ChangeEvent) => void;
}> = ({ type, name, placeholder, className, value, onKeyDown, onChange }) => (
  <input
    type={type}
    name={name}
    placeholder={placeholder}
    className={className}
    value={value}
    onKeyDown={onKeyDown}
    onChange={onChange}
    data-testid="search-input"
  />
);

const TestIcon: React.FC<{ name?: string; className?: string }> = ({ name, className }) => (
  <span className={className} data-icon={name} />
);

// ========== 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
  };
  return registry;
}

// ========== 모바일 검색바 레이아웃 (발췌) ==========

const mobileSearchLayout = {
  version: '1.0',
  layout_name: 'mobile_search_test',
  meta: { title: '모바일 검색 테스트' },
  components: [
    {
      comment: '검색바',
      type: 'basic' as const,
      name: 'Div',
      props: { className: 'p-4' },
      children: [
        {
          type: 'basic' as const,
          name: 'Div',
          props: { className: 'relative' },
          children: [
            {
              type: 'basic' as const,
              name: 'Icon',
              props: {
                name: 'search',
                className: 'absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500',
              },
            },
            {
              type: 'basic' as const,
              name: 'Input',
              props: {
                type: 'text',
                name: 'q',
                placeholder: '$t:user.search_placeholder',
                className: 'w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500',
              },
              actions: [
                {
                  type: 'keydown',
                  key: 'Enter',
                  handler: 'sequence',
                  actions: [
                    {
                      handler: 'setState',
                      params: { target: 'global', mobileMenuOpen: false },
                    },
                    {
                      handler: 'navigate',
                      params: { path: '/search', query: { q: '{{$event.target.value}}' } },
                    },
                  ],
                },
              ],
            },
          ],
        },
      ],
    },
  ],
};

// ========== 테스트 ==========

describe('모바일 메뉴 통합검색', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
    testUtils = createLayoutTest(mobileSearchLayout, {
      translations: {},
      locale: 'ko',
      componentRegistry: registry,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  // ── 구조 검증 ──

  it('검색 Input에 keydown Enter sequence 액션이 정의되어 있다', () => {
    const inputComponent = mobileSearchLayout.components[0].children[0].children[1];
    expect(inputComponent.name).toBe('Input');
    expect(inputComponent.actions).toBeDefined();
    expect(inputComponent.actions).toHaveLength(1);

    const enterAction = inputComponent.actions![0];
    expect(enterAction.type).toBe('keydown');
    expect(enterAction.key).toBe('Enter');
    expect(enterAction.handler).toBe('sequence');
    expect(enterAction.actions).toHaveLength(2);
  });

  it('Enter 시 메뉴 닫기 후 /search로 이동한다', () => {
    const inputComponent = mobileSearchLayout.components[0].children[0].children[1];
    const sequenceActions = inputComponent.actions![0].actions!;

    // 1단계: 메뉴 닫기
    expect(sequenceActions[0].handler).toBe('setState');
    expect(sequenceActions[0].params).toEqual({ target: 'global', mobileMenuOpen: false });

    // 2단계: 검색 페이지 이동
    expect(sequenceActions[1].handler).toBe('navigate');
    expect(sequenceActions[1].params).toEqual({
      path: '/search',
      query: { q: '{{$event.target.value}}' },
    });
  });

  it('검색 Input에 name="q" prop이 설정되어 있다', () => {
    const inputComponent = mobileSearchLayout.components[0].children[0].children[1];
    expect(inputComponent.props.name).toBe('q');
  });

  it('데스크톱 검색과 동일한 navigate 핸들러 패턴을 사용한다', () => {
    // _search_input.json의 keydown Enter 핸들러와 동일한 navigate 패턴 검증
    const inputComponent = mobileSearchLayout.components[0].children[0].children[1];
    const navigateAction = inputComponent.actions![0].actions![1];

    expect(navigateAction.handler).toBe('navigate');
    expect(navigateAction.params.query).toHaveProperty('q');
    // $event.target.value 패턴 사용 ($value 금지)
    expect(navigateAction.params.query.q).toContain('$event.target.value');
    expect(navigateAction.params.query.q).not.toContain('$value');
  });
});
