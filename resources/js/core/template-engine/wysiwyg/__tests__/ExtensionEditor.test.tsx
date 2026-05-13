/**
 * ExtensionEditor.test.tsx
 *
 * ExtensionEditor 컴포넌트 테스트
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import {
  ExtensionEditor,
  InjectedComponent,
  ExtensionPointInfo,
  ExtensionSource,
} from '../components/PropertyPanel/ExtensionEditor';

// ============================================================================
// 테스트 헬퍼
// ============================================================================

function createTestModules(): ExtensionSource[] {
  return [
    {
      type: 'module',
      identifier: 'sirsoft-ecommerce',
      name: 'E-Commerce',
      version: '1.0.0',
      isActive: true,
    },
    {
      type: 'module',
      identifier: 'sirsoft-board',
      name: 'Board',
      version: '2.1.0',
      isActive: true,
    },
  ];
}

function createTestPlugins(): ExtensionSource[] {
  return [
    {
      type: 'plugin',
      identifier: 'sirsoft-payment',
      name: 'Payment Gateway',
      version: '1.5.0',
      isActive: true,
    },
  ];
}

function createTestInjectedComponents(): InjectedComponent[] {
  return [
    {
      component: {
        id: 'ecommerce_widget',
        type: 'composite',
        name: 'ProductWidget',
      },
      source: {
        type: 'module',
        identifier: 'sirsoft-ecommerce',
        name: 'E-Commerce',
        isActive: true,
      },
      targetId: 'dashboard_sidebar',
      position: 'append_child',
      priority: 10,
      isEditable: true,
      hasOverride: false,
    },
    {
      component: {
        id: 'board_notification',
        type: 'composite',
        name: 'BoardNotification',
      },
      source: {
        type: 'module',
        identifier: 'sirsoft-board',
        name: 'Board',
        isActive: true,
      },
      targetId: 'header_area',
      position: 'prepend',
      priority: 5,
      isEditable: true,
      hasOverride: true,
    },
  ];
}

function createTestExtensionPoints(): ExtensionPointInfo[] {
  return [
    {
      id: 'ep_dashboard_widgets',
      name: 'dashboard_widgets',
      description: '대시보드 위젯 영역',
      registeredExtensions: [
        {
          source: {
            type: 'module',
            identifier: 'sirsoft-ecommerce',
            name: 'E-Commerce',
            isActive: true,
          },
          components: [
            { id: 'sales_chart', type: 'composite', name: 'SalesChart' },
          ],
          priority: 10,
        },
      ],
      defaultComponents: [],
    },
    {
      id: 'ep_footer_links',
      name: 'footer_links',
      description: '푸터 링크 영역',
      registeredExtensions: [],
      defaultComponents: [
        { id: 'default_footer', type: 'basic', name: 'Div' },
      ],
    },
  ];
}

// ============================================================================
// 테스트
// ============================================================================

describe('ExtensionEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('기본 렌더링', () => {
    it('헤더가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={[]}
          activePlugins={[]}
        />
      );

      expect(screen.getByText('확장 컴포넌트')).toBeInTheDocument();
    });

    it('탭 버튼들이 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={createTestExtensionPoints()}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      expect(screen.getByText(/주입된 컴포넌트/)).toBeInTheDocument();
      expect(screen.getByText(/Extension Points/)).toBeInTheDocument();
      expect(screen.getByText(/활성 소스/)).toBeInTheDocument();
    });

    it('도움말이 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={[]}
          activePlugins={[]}
        />
      );

      expect(screen.getByText('확장 컴포넌트 규칙:')).toBeInTheDocument();
    });
  });

  describe('주입된 컴포넌트 탭', () => {
    it('주입된 컴포넌트 목록이 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      expect(screen.getByText('ProductWidget')).toBeInTheDocument();
      expect(screen.getByText('BoardNotification')).toBeInTheDocument();
    });

    it('빈 상태일 때 안내 메시지가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={[]}
          activePlugins={[]}
        />
      );

      expect(screen.getByText(/주입된 확장 컴포넌트가 없습니다/)).toBeInTheDocument();
    });

    it('출처 배지가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      // 모듈 출처 배지
      expect(screen.getByText(/모듈: E-Commerce/)).toBeInTheDocument();
    });

    it('오버라이드된 컴포넌트에 배지가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      expect(screen.getByText('오버라이드됨')).toBeInTheDocument();
    });

    it('오버라이드 개수 안내가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      expect(screen.getByText(/1개의 컴포넌트가 오버라이드되었습니다/)).toBeInTheDocument();
    });

    it('컴포넌트 클릭 시 상세 정보가 펼쳐져야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      // 첫 번째 컴포넌트 클릭
      fireEvent.click(screen.getByText('ProductWidget'));

      // 상세 정보 표시
      expect(screen.getByText('출처:')).toBeInTheDocument();
      expect(screen.getByText('타겟:')).toBeInTheDocument();
    });

    it('position 레이블이 올바르게 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      expect(screen.getByText('마지막 자식')).toBeInTheDocument(); // append_child
      expect(screen.getByText('앞에 삽입')).toBeInTheDocument(); // prepend
    });
  });

  describe('Extension Points 탭', () => {
    it('탭 클릭 시 Extension Points가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={createTestExtensionPoints()}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      // Extension Points 탭 클릭
      fireEvent.click(screen.getByText(/Extension Points/));

      expect(screen.getByText('dashboard_widgets')).toBeInTheDocument();
      expect(screen.getByText('footer_links')).toBeInTheDocument();
    });

    it('등록된 확장 개수가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={createTestExtensionPoints()}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      fireEvent.click(screen.getByText(/Extension Points/));

      expect(screen.getByText('1개 확장 등록됨')).toBeInTheDocument();
    });

    it('기본값 사용 표시가 되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={createTestExtensionPoints()}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      fireEvent.click(screen.getByText(/Extension Points/));

      expect(screen.getByText('(기본값 사용)')).toBeInTheDocument();
    });

    it('Extension Point 클릭 시 상세 정보가 펼쳐져야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={createTestExtensionPoints()}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      fireEvent.click(screen.getByText(/Extension Points/));
      fireEvent.click(screen.getByText('dashboard_widgets'));

      expect(screen.getByText('대시보드 위젯 영역')).toBeInTheDocument();
    });

    it('빈 상태일 때 안내 메시지가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={[]}
          activePlugins={[]}
        />
      );

      fireEvent.click(screen.getByText(/Extension Points/));

      expect(screen.getByText(/정의된 Extension Point가 없습니다/)).toBeInTheDocument();
    });
  });

  describe('활성 소스 탭', () => {
    it('탭 클릭 시 활성 소스가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      // 활성 소스 탭 클릭
      fireEvent.click(screen.getByText(/활성 소스/));

      expect(screen.getByText('활성 모듈 (2)')).toBeInTheDocument();
      expect(screen.getByText('활성 플러그인 (1)')).toBeInTheDocument();
    });

    it('모듈 목록이 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      fireEvent.click(screen.getByText(/활성 소스/));

      expect(screen.getByText('E-Commerce')).toBeInTheDocument();
      expect(screen.getByText('Board')).toBeInTheDocument();
    });

    it('플러그인 목록이 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      fireEvent.click(screen.getByText(/활성 소스/));

      expect(screen.getByText('Payment Gateway')).toBeInTheDocument();
    });

    it('버전 정보가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      fireEvent.click(screen.getByText(/활성 소스/));

      expect(screen.getByText('v1.0.0')).toBeInTheDocument();
      expect(screen.getByText('v2.1.0')).toBeInTheDocument();
      expect(screen.getByText('v1.5.0')).toBeInTheDocument();
    });

    it('모듈이 없을 때 안내 메시지가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={[]}
          activePlugins={createTestPlugins()}
        />
      );

      fireEvent.click(screen.getByText(/활성 소스/));

      expect(screen.getByText('활성화된 모듈이 없습니다.')).toBeInTheDocument();
    });

    it('플러그인이 없을 때 안내 메시지가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={[]}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={[]}
        />
      );

      fireEvent.click(screen.getByText(/활성 소스/));

      expect(screen.getByText('활성화된 플러그인이 없습니다.')).toBeInTheDocument();
    });
  });

  describe('오버라이드 복원', () => {
    it('오버라이드된 컴포넌트에 복원 버튼이 표시되어야 함', () => {
      const onRemoveOverride = vi.fn();

      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
          onRemoveOverride={onRemoveOverride}
        />
      );

      // 오버라이드된 컴포넌트 클릭
      fireEvent.click(screen.getByText('BoardNotification'));

      expect(screen.getByText('원본으로 복원')).toBeInTheDocument();
    });

    it('복원 버튼 클릭 시 onRemoveOverride가 호출되어야 함', () => {
      const onRemoveOverride = vi.fn();

      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={[]}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
          onRemoveOverride={onRemoveOverride}
        />
      );

      // 오버라이드된 컴포넌트 클릭
      fireEvent.click(screen.getByText('BoardNotification'));

      // 복원 버튼 클릭
      fireEvent.click(screen.getByText('원본으로 복원'));

      expect(onRemoveOverride).toHaveBeenCalledWith('board_notification');
    });
  });

  describe('탭 카운트', () => {
    it('탭에 올바른 카운트가 표시되어야 함', () => {
      render(
        <ExtensionEditor
          injectedComponents={createTestInjectedComponents()}
          extensionPoints={createTestExtensionPoints()}
          activeModules={createTestModules()}
          activePlugins={createTestPlugins()}
        />
      );

      expect(screen.getByText(/주입된 컴포넌트 \(2\)/)).toBeInTheDocument();
      expect(screen.getByText(/Extension Points \(2\)/)).toBeInTheDocument();
      expect(screen.getByText(/활성 소스 \(3\)/)).toBeInTheDocument();
    });
  });
});
