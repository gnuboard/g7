/**
 * @file shopAdditionalOptions.test.tsx
 * @description 상품 추가옵션(유료 옵션) 유저 흐름 레이아웃 검증
 *
 * 1. 렌더링: 추가옵션 그룹별 선택지 표시(추가금 라벨) — 최소 레이아웃 실제 렌더
 * 2. 구조: 실제 partial(_purchase_card / _cart_item / _modal_cart_option_change /
 *    표시 4화면)이 추가옵션 바인딩·핸들러·필수 가드를 포함하는지 검증
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import purchaseCard from '../../../layouts/partials/shop/detail/_purchase_card.json';
import cartItem from '../../../layouts/partials/shop/_cart_item.json';
import modalOptionChange from '../../../layouts/partials/shop/_modal_cart_option_change.json';
import checkoutItems from '../../../layouts/partials/shop/_checkout_items.json';
import orderComplete from '../../../layouts/shop/order_complete.json';
import mypageItems from '../../../layouts/partials/mypage/orders/_items.json';

// ============================================================
// 테스트용 컴포넌트
// ============================================================

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>
    {children}
  </div>
);
const TestSpan: React.FC<any> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>
    {children ?? text}
  </span>
);
const TestP: React.FC<any> = ({ className, children, text, 'data-testid': testId }) => (
  <p className={className} data-testid={testId}>
    {children ?? text}
  </p>
);

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;
  (registry as any).registry = {
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
  };
  return registry;
}

// ============================================================
// 1. 렌더링 — 추가옵션 라인 표시 (장바구니 항목 스타일)
// ============================================================

describe('추가옵션 표시 렌더링', () => {
  beforeEach(() => setupRegistry());

  const layout = {
    version: '1.0.0',
    layout_name: 'shop_additional_options_test',
    data_sources: [
      { id: 'cart', type: 'api', endpoint: '/api/cart', method: 'GET', auto_fetch: true },
    ],
    components: [
      {
        type: 'basic',
        name: 'Div',
        iteration: { source: '{{cart.data.items ?? []}}', item_var: 'item' },
        children: [
          {
            type: 'basic',
            name: 'Div',
            if: '{{item.additional_options?.length > 0}}',
            iteration: { source: '{{item.additional_options ?? []}}', item_var: 'addOpt' },
            children: [
              {
                type: 'basic',
                name: 'P',
                text: "{{addOpt.group_name}}: {{addOpt.name}}{{(addOpt.price_adjustment ?? 0) > 0 ? ' (+' + addOpt.price_adjustment.toLocaleString() + '원)' : ''}}",
              },
            ],
          },
        ],
      },
    ],
  };

  it('선택된 추가옵션 그룹명·선택지명·추가금을 표시한다', async () => {
    const testUtils = createLayoutTest(layout as any);
    testUtils.mockApi('cart', {
      response: {
        data: {
          items: [
            {
              id: 1,
              additional_options: [
                { additional_option_id: 1, value_id: 101, group_name: '각인', name: '각인 추가', price_adjustment: 5000 },
              ],
            },
          ],
        },
      },
    });
    await testUtils.render();

    expect(
      screen.getByText((c) => c.includes('각인 추가') && c.includes('5,000'))
    ).toBeInTheDocument();
    testUtils.cleanup();
  });

  it('추가금 0인 선택지는 추가금 표기를 생략한다', async () => {
    const testUtils = createLayoutTest(layout as any);
    testUtils.mockApi('cart', {
      response: {
        data: {
          items: [
            {
              id: 1,
              additional_options: [
                { additional_option_id: 1, value_id: 100, group_name: '각인', name: '없음', price_adjustment: 0 },
              ],
            },
          ],
        },
      },
    });
    const { container } = await testUtils.render();

    expect(
      screen.getByText((c) => c.includes('각인') && c.includes('없음'))
    ).toBeInTheDocument();
    // 추가금 0 → '+' 표기 없음
    expect(container.textContent).not.toContain('+0');
    testUtils.cleanup();
  });
});

// ============================================================
// 2. 구조 검증 — 실제 partial
// ============================================================

describe('_purchase_card.json 구조 — 블럭별 추가옵션', () => {
  const str = JSON.stringify(purchaseCard);

  it('블럭 내부 추가옵션 그룹 iteration이 product.data.additional_options를 사용한다', () => {
    expect(str).toContain('product.data?.additional_options');
    expect(str).toContain('"item_var":"addGroup"');
  });

  it('블럭별 추가옵션 변경 핸들러 setBlockAdditionalOption을 호출한다', () => {
    expect(str).toContain('sirsoft-basic.setBlockAdditionalOption');
  });

  it('addSelectedItemIfComplete에 additionalOptionGroups를 전달한다', () => {
    expect(str).toContain('"additionalOptionGroups"');
  });

  it('담기/구매 body에 additional_option_selections를 포함한다', () => {
    expect(str).toContain('additional_option_selections');
    expect(str).toContain('additional_option_id');
    expect(str).toContain('value_id');
  });

  it('필수 추가옵션 미선택 차단 가드(additional_option_required)가 있다', () => {
    expect(str).toContain('shop.additional_option_required');
    expect(str).toContain('is_required');
  });

  it('레거시 카드레벨 자유텍스트 추가옵션 입력을 제거했다', () => {
    // 구 스텁: additional_option_{{addOption.id}} 텍스트 input
    expect(str).not.toContain('additional_option_{{addOption.id}}');
    expect(str).not.toContain('_local.additionalOptions?.[addOption.id]');
  });

  it('선택된 옵션 블록은 space-y-4 부모 + iteration 자식으로 분리한다 (붙어 보이는 회귀 방지)', () => {
    // G7 엔진은 iteration 노드를 N번 복제하므로, space-y-* 가 iteration 과 같은 노드에 있으면
    // 각 아이템이 자기 wrapper 를 소유해 형제 간 간격이 0 이 된다. 따라서:
    //   - selectedOptionItems iteration 노드 자체에는 space-y-* 가 없어야 한다
    //   - 그 iteration 노드의 부모가 space-y-4 를 가져야 한다
    const findParentOfSelectedIteration = (
      node: any,
      parent: any
    ): { iterNode: any; parent: any } | null => {
      if (!node || typeof node !== 'object') return null;
      const src = node.iteration?.source;
      if (typeof src === 'string' && src.includes('_local.selectedOptionItems')) {
        return { iterNode: node, parent };
      }
      for (const child of node.children ?? []) {
        const found = findParentOfSelectedIteration(child, node);
        if (found) return found;
      }
      return null;
    };

    let result: { iterNode: any; parent: any } | null = null;
    for (const child of (purchaseCard as any).children ?? []) {
      result = findParentOfSelectedIteration(child, purchaseCard);
      if (result) break;
    }

    expect(result).not.toBeNull();
    const iterClass = result!.iterNode.props?.className ?? '';
    const parentClass = result!.parent?.props?.className ?? '';
    // iteration 노드에는 space-y-* 금지 (동거 시 간격 미적용 회귀)
    expect(iterClass).not.toMatch(/\bspace-y-/);
    // 부모가 형제 간격(space-y-4)을 담당
    expect(parentClass).toContain('space-y-4');
  });
});

describe('_cart_item.json 구조 — 추가옵션 표시 + 모달 시드', () => {
  const str = JSON.stringify(cartItem);

  it('item.additional_options를 옵션 라인 아래 표시한다', () => {
    expect(str).toContain('item.additional_options');
    expect(str).toContain('addOpt.group_name');
  });

  it('옵션변경 모달 열기 시 additionalSelections를 현재 선택으로 시드한다', () => {
    expect(str).toContain('additionalSelections');
    expect(str).toContain('a.additional_option_id');
    expect(str).toContain('a.value_id');
  });
});

describe('_modal_cart_option_change.json 구조 — 추가옵션 재선택', () => {
  const str = JSON.stringify(modalOptionChange);

  it('optionProduct.additional_options 기반 그룹 재선택 UI가 있다', () => {
    expect(str).toContain('optionProduct?.additional_options');
    expect(str).toContain('additionalSelections');
  });

  it('PATCH body에 additional_option_selections를 포함한다', () => {
    expect(str).toContain('additional_option_selections');
  });
});

describe('표시 전용 화면 — 스냅샷 기반 추가옵션 별행', () => {
  it('체크아웃 항목에 additional_options 표시가 있다', () => {
    expect(JSON.stringify(checkoutItems)).toContain('item.additional_options');
  });
  it('주문완료 항목에 additional_options 표시가 있다', () => {
    expect(JSON.stringify(orderComplete)).toContain('item.additional_options');
  });
  it('마이페이지 주문상세 항목에 additional_options 표시가 있다', () => {
    expect(JSON.stringify(mypageItems)).toContain('item.additional_options');
  });
});
