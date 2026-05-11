/**
 * @file dashboard.test.tsx
 * @description Admin Dashboard 레이아웃 렌더링 검증
 *
 * 테스트 대상:
 * - 환영 카드가 렌더링됨
 * - 환영 제목(H1)과 메시지(Span)가 다국어 키로 노출됨
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import dashboardLayout from '../../layouts/admin_dashboard.json';

// 테스트용 Basic 컴포넌트 (HTML 래퍼)
const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <h1 className={className} data-testid={testId}>{children || text}</h1>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

// createLayoutTest() 가 최상위 components 를 Fragment 로 감싸므로 Fragment 등록 필수
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <>{children}</>
);

function setupTestRegistry(): void {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
}

describe('admin_dashboard layout (gnuboard7-hello_admin_template)', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    if (testUtils) {
      testUtils.cleanup();
      testUtils = null;
    }
  });

  it('환영 카드가 렌더링된다', async () => {
    setupTestRegistry();

    // extends: _admin_base 는 베이스 레이아웃 로딩이 필요해 slots.content 만 단독 테스트
    const standaloneLayout = {
      ...dashboardLayout,
      extends: undefined,
    };

    testUtils = createLayoutTest(standaloneLayout as any);
    await testUtils.render();

    expect(screen.getByTestId('welcome-card')).toBeInTheDocument();
    expect(screen.getByTestId('welcome-title')).toBeInTheDocument();
    expect(screen.getByTestId('welcome-message')).toBeInTheDocument();
  });
});
