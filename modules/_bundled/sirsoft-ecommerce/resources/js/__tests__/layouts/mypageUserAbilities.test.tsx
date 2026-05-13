/**
 * @file mypageUserAbilities.test.tsx
 * @description 마이페이지 사용자 abilities 기반 권한 제어 렌더링 테스트
 *
 * 테스트 대상:
 * - 배송지 목록: address.abilities.can_delete 기반 삭제 버튼 조건부 렌더링
 * - 주문 상세: order.data.abilities.can_cancel 기반 취소 버튼 조건부 렌더링
 * - 실제 레이아웃 JSON 구조 검증 (abilities 패턴 사용 확인)
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 컴포넌트 정의
const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  text?: string;
  type?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} disabled={disabled} onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={className} data-icon={name} />
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ──────────────────────────────────────────────
// 1. 배송지 삭제 버튼 - per-item abilities 기반 if 조건부 렌더링
//    (commonInfoAbilities.test.tsx의 _local 패턴과 동일)
// ──────────────────────────────────────────────

function createAddressDeleteButtonLayout(canDelete: boolean) {
  return {
    version: '1.0.0',
    layout_name: `test_address_delete_${canDelete}`,
    initGlobal: {
      currentAddress: {
        id: 1,
        label: canDelete ? '회사' : '기본 배송지',
        abilities: { can_delete: canDelete, can_update: true },
      },
    },
    components: [
      {
        id: 'main-content',
        type: 'basic',
        name: 'Div',
        children: [
          {
            type: 'basic',
            name: 'Span',
            text: '{{_global.currentAddress.label}}',
          },
          {
            type: 'basic',
            name: 'Button',
            if: '{{!!_global.currentAddress?.abilities?.can_delete}}',
            props: { 'data-testid': 'delete-button' },
            text: '삭제',
          },
        ],
      },
    ],
  };
}

describe('마이페이지 배송지 - 삭제 버튼 abilities 기반 권한 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('can_delete=true → 삭제 버튼 표시', async () => {
    const testUtils = createLayoutTest(createAddressDeleteButtonLayout(true), { componentRegistry: registry });
    await testUtils.render();

    expect(screen.queryByTestId('delete-button')).not.toBeNull();

    testUtils.cleanup();
  });

  it('can_delete=false (기본 배송지) → 삭제 버튼 숨김', async () => {
    const testUtils = createLayoutTest(createAddressDeleteButtonLayout(false), { componentRegistry: registry });
    await testUtils.render();

    expect(screen.queryByTestId('delete-button')).toBeNull();

    testUtils.cleanup();
  });
});

// ──────────────────────────────────────────────
// 2. 주문 상세 취소 버튼 - abilities 기반 조건부 렌더링
// ──────────────────────────────────────────────

function createOrderDetailLayout(canCancel: boolean) {
  return {
    version: '1.0.0',
    layout_name: `test_order_cancel_button_${canCancel}`,
    data_sources: [],
    state: {},
    initGlobal: {
      orderData: {
        data: {
          id: 100,
          order_number: 'ORD-2026-001',
          status: canCancel ? 'pending' : 'shipped',
          abilities: { can_cancel: canCancel },
        },
      },
    },
    components: [
      {
        id: 'order_actions',
        type: 'basic',
        name: 'Div',
        props: { className: 'flex gap-3', 'data-testid': 'order-actions' },
        children: [
          {
            comment: '주문 취소 버튼 - 취소 가능한 경우만 표시',
            id: 'cancel_button',
            type: 'basic',
            name: 'Button',
            if: '{{!!_global.orderData?.data?.abilities?.can_cancel}}',
            props: {
              className: 'flex-1 px-6 py-3 border border-gray-300 text-gray-700 rounded-lg font-medium',
              'data-testid': 'cancel-button',
            },
            text: '주문 취소',
          },
        ],
      },
    ],
  };
}

describe('마이페이지 주문 상세 - 취소 버튼 abilities 기반 권한 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('can_cancel=true → 취소 버튼 표시', async () => {
    const testUtils = createLayoutTest(createOrderDetailLayout(true), { componentRegistry: registry });
    await testUtils.render();

    expect(screen.queryByTestId('cancel-button')).not.toBeNull();

    testUtils.cleanup();
  });

  it('can_cancel=false → 취소 버튼 숨김', async () => {
    const testUtils = createLayoutTest(createOrderDetailLayout(false), { componentRegistry: registry });
    await testUtils.render();

    expect(screen.queryByTestId('cancel-button')).toBeNull();

    testUtils.cleanup();
  });
});

// ──────────────────────────────────────────────
// 3. 실제 레이아웃 JSON 구조 검증
// ──────────────────────────────────────────────

import addressListPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/addresses/_list.json';
import orderShowLayout from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/mypage/orders/show.json';
import orderItemsPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_items.json';

/**
 * 재귀적으로 노드 트리에서 if 조건에 특정 패턴이 포함된 노드를 찾는다.
 * children, components, slots 모두 탐색한다.
 */
function findNodeByIfPattern(node: any, pattern: string): any {
  if (node.if && typeof node.if === 'string' && node.if.includes(pattern)) {
    return node;
  }
  // children 탐색
  if (node.children) {
    for (const child of node.children) {
      const found = findNodeByIfPattern(child, pattern);
      if (found) return found;
    }
  }
  // components 탐색
  if (node.components) {
    for (const comp of node.components) {
      const found = findNodeByIfPattern(comp, pattern);
      if (found) return found;
    }
  }
  // slots 탐색 (show.json 등 extends 레이아웃에서 사용)
  if (node.slots) {
    for (const slotKey of Object.keys(node.slots)) {
      const slotComponents = node.slots[slotKey];
      if (Array.isArray(slotComponents)) {
        for (const comp of slotComponents) {
          const found = findNodeByIfPattern(comp, pattern);
          if (found) return found;
        }
      }
    }
  }
  return null;
}

describe('실제 레이아웃 JSON - abilities 패턴 사용 검증', () => {
  it('_list.json 배송지 삭제 버튼이 abilities?.can_delete 조건을 사용한다', () => {
    // iteration 노드는 partial 트리 깊은 곳에 있을 수 있음 → 전체 트리 검색으로 전환
    const deleteButton = findNodeByIfPattern(
      addressListPartial,
      'address.abilities?.can_delete',
    );
    expect(deleteButton).not.toBeNull();
    expect(deleteButton.if).toBe('{{address.abilities?.can_delete === true}}');
    expect(deleteButton.if).not.toContain('is_default');
  });

  it('주문 취소 버튼이 abilities?.can_cancel 조건을 사용한다 (_items partial 로 이전)', () => {
    // show.json 의 인라인 취소 버튼 → _items.json partial 로 이전됨
    const cancelButton = findNodeByIfPattern(orderItemsPartial, 'abilities?.can_cancel');
    expect(cancelButton).not.toBeNull();
    expect(cancelButton.if).toContain('order.data.abilities?.can_cancel === true');
    // show.json 자체에는 단순 모달 partial 만 등록되어 있음
    void orderShowLayout;
  });
});
