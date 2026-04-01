/**
 * @file admin_ecommerce_common_info.test.tsx
 * @description 공통정보 관리 레이아웃 테스트
 *
 * 테스트 대상:
 * - admin_ecommerce_common_info_view.json (보기 패널)
 * - TabNavigation을 활용한 다국어 탭 전환
 * - 조건부 렌더링 (is_default, is_active 뱃지)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import viewLayoutFixture from '../fixtures/admin_ecommerce_common_info_view.json';

// TabNavigation 목 컴포넌트
const TestTabNavigation: React.FC<{
  tabs?: Array<{ id: string; label: string }>;
  activeTabId?: string;
  variant?: string;
  className?: string;
  onTabChange?: (tabId: string) => void;
}> = ({ tabs, activeTabId, className, onTabChange }) => (
  <div className={className} data-testid="tab-navigation">
    {tabs?.map((tab) => (
      <button
        key={tab.id}
        data-testid={`tab-${tab.id}`}
        className={activeTabId === tab.id ? 'active' : ''}
        onClick={() => onTabChange?.(tab.id)}
      >
        {tab.label}
      </button>
    ))}
  </div>
);

// 테스트용 레지스트리
let registry: MockComponentRegistry;

beforeEach(() => {
  // 기본 컴포넌트가 포함된 레지스트리 생성
  registry = createMockComponentRegistryWithBasics();

  // Composite 컴포넌트 추가 등록
  registry.register('composite', 'TabNavigation', TestTabNavigation);
});

afterEach(() => {
  vi.clearAllMocks();
});

describe('공통정보 보기 패널', () => {
  describe('기본 렌더링', () => {
    it('selectedId가 있고 panelMode가 view일 때 렌더링됨', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 탭 네비게이션이 렌더링되는지 확인
      expect(screen.getByTestId('tab-navigation')).toBeInTheDocument();
      expect(screen.getByTestId('tab-ko')).toBeInTheDocument();
      expect(screen.getByTestId('tab-en')).toBeInTheDocument();

      cleanup();
    });

    it('공통정보명이 표시됨', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 공통정보명 표시 확인
      expect(screen.getByText('일반 상품 안내')).toBeInTheDocument();

      cleanup();
    });

    it('selectedId가 없으면 렌더링되지 않음', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialState: {
          _global: {
            selectedId: null,
            panelMode: 'view',
            selectedItem: null,
          },
        },
      });

      await render();

      // 탭 네비게이션이 렌더링되지 않음
      expect(screen.queryByTestId('tab-navigation')).not.toBeInTheDocument();

      cleanup();
    });
  });

  describe('상태 뱃지 조건부 렌더링', () => {
    it('is_default=true일 때 기본 뱃지가 표시됨', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 기본 뱃지 표시 확인
      expect(screen.getByTestId('badge-default')).toBeInTheDocument();
      expect(screen.getByText('기본')).toBeInTheDocument();

      cleanup();
    });

    it('is_active=true일 때 사용 뱃지가 표시됨', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 사용 뱃지 표시 확인
      expect(screen.getByTestId('badge-active')).toBeInTheDocument();
      expect(screen.getByText('사용')).toBeInTheDocument();

      cleanup();
    });

    it('is_active=false일 때 미사용 뱃지가 표시됨', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialState: {
          _global: {
            selectedId: 1,
            panelMode: 'view',
            selectedItem: {
              id: 1,
              name: { ko: '비활성 안내', en: 'Inactive Info' },
              content: { ko: '내용', en: 'Content' },
              content_mode: 'text',
              is_default: false,
              is_active: false,
            },
          },
        },
      });

      await render();

      // 미사용 뱃지 표시 확인
      expect(screen.getByTestId('badge-inactive')).toBeInTheDocument();
      expect(screen.getByText('미사용')).toBeInTheDocument();

      // 기본 뱃지는 표시되지 않음
      expect(screen.queryByTestId('badge-default')).not.toBeInTheDocument();

      cleanup();
    });
  });

  describe('다국어 탭 전환', () => {
    it('기본 로케일(ko)에서 한국어 탭이 활성화됨', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup, getState } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 초기 상태 확인
      const state = getState();
      expect(state._local.viewLocale).toBe('ko');

      // 한국어 탭이 활성화됨
      const koTab = screen.getByTestId('tab-ko');
      expect(koTab).toHaveClass('active');

      cleanup();
    });

    it('한국어 탭에서 한국어 데이터가 표시됨', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 한국어 콘텐츠 영역이 표시됨
      expect(screen.getByTestId('content-ko')).toBeInTheDocument();

      // 한국어 데이터가 표시되는지 확인
      expect(screen.getByText('이 상품은 품질 보증이 됩니다.')).toBeInTheDocument();

      cleanup();
    });

    it('영어 탭으로 전환 시 로컬 상태가 변경됨', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 영어 탭 선택 (상태 직접 변경으로 시뮬레이션)
      setState('viewLocale', 'en', 'local');
      await rerender();

      const state = getState();
      expect(state._local.viewLocale).toBe('en');

      cleanup();
    });

    it('영어 탭에서 영어 데이터가 표시됨', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialState: {
          _local: {
            viewLocale: 'en',
          },
        },
      });

      await render();

      // 영어 콘텐츠 영역이 표시됨
      expect(screen.getByTestId('content-en')).toBeInTheDocument();

      // 영어 데이터가 표시되는지 확인
      expect(screen.getByText('This product is quality guaranteed.')).toBeInTheDocument();

      cleanup();
    });

    it('탭 전환 시 데이터가 올바르게 전환됨 (setState 시뮬레이션)', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 초기 상태: 한국어 탭 활성화, 한국어 데이터 표시
      expect(screen.getByTestId('tab-ko')).toHaveClass('active');
      expect(screen.getByText('이 상품은 품질 보증이 됩니다.')).toBeInTheDocument();

      // 영어 탭으로 전환 (setState로 시뮬레이션)
      setState('viewLocale', 'en', 'local');
      await rerender();

      // 상태 확인: viewLocale이 'en'으로 변경됨
      expect(getState()._local.viewLocale).toBe('en');

      // 영어 탭이 활성화됨
      expect(screen.getByTestId('tab-en')).toHaveClass('active');
      expect(screen.getByTestId('tab-ko')).not.toHaveClass('active');

      // 영어 데이터가 표시됨
      expect(screen.getByText('This product is quality guaranteed.')).toBeInTheDocument();

      // 한국어 콘텐츠 영역은 더 이상 표시되지 않음
      expect(screen.queryByTestId('content-ko')).not.toBeInTheDocument();

      cleanup();
    });

    it('탭을 여러 번 전환해도 올바르게 동작함', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 1. 영어 탭으로 전환
      setState('viewLocale', 'en', 'local');
      await rerender();
      expect(getState()._local.viewLocale).toBe('en');
      expect(screen.getByText('This product is quality guaranteed.')).toBeInTheDocument();
      expect(screen.queryByTestId('content-ko')).not.toBeInTheDocument();

      // 2. 다시 한국어 탭으로 전환
      setState('viewLocale', 'ko', 'local');
      await rerender();
      expect(getState()._local.viewLocale).toBe('ko');
      expect(screen.getByText('이 상품은 품질 보증이 됩니다.')).toBeInTheDocument();
      expect(screen.queryByTestId('content-en')).not.toBeInTheDocument();

      // 3. 다시 영어 탭으로 전환
      setState('viewLocale', 'en', 'local');
      await rerender();
      expect(getState()._local.viewLocale).toBe('en');
      expect(screen.getByText('This product is quality guaranteed.')).toBeInTheDocument();

      cleanup();
    });
  });

  describe('panelMode 전환', () => {
    it('panelMode가 edit으로 변경되면 view 패널이 숨겨짐', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup, setState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 초기: view 패널 표시
      expect(screen.getByTestId('tab-navigation')).toBeInTheDocument();

      // panelMode를 edit으로 변경
      setState('panelMode', 'edit', 'global');
      await rerender();

      // view 패널이 숨겨짐
      expect(screen.queryByTestId('tab-navigation')).not.toBeInTheDocument();

      cleanup();
    });
  });

  describe('레이아웃 검증', () => {
    it('레이아웃에 검증 오류가 없어야 함', async () => {
      const layoutJson = viewLayoutFixture;
      const { render, cleanup, assertNoValidationErrors } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 검증 오류가 없어야 함
      expect(() => assertNoValidationErrors()).not.toThrow();

      cleanup();
    });
  });
});
