/**
 * @file admin-user-form-single-save.test.tsx
 * @description 회원 수정 폼 — 저장은 코어 회원 저장 요청 한 번뿐이다 (결정 2026-09-28)
 *
 * 모듈이 폼에 주입한 칸(예: 이커머스 결제 통화·배송국가)은 `_local.form` 에 실려 코어 회원 저장
 * 요청 본문으로 함께 가고, 그 모듈이 코어 훅으로 검증·저장한다. 저장 성공 뒤 모듈 전용 엔드포인트를
 * 따로 부르던 종전 배선이 되살아나면 요청이 두 갈래로 갈라져 한쪽만 실패하는 상태가 생긴다.
 *
 * 실제 admin_user_form.json 의 저장 버튼 노드를 그대로 렌더한다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import userForm from '../../layouts/admin_user_form.json';

type Json = Record<string, any>;

/** 레이아웃 트리에서 id 로 노드를 찾는다. */
function findById(node: unknown, id: string): Json | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findById(child, id);
      if (found) return found;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (obj.id === id) return obj;
    for (const value of Object.values(obj)) {
      const found = findById(value, id);
      if (found) return found;
    }
  }
  return null;
}

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSpan: React.FC<any> = ({ children, text }) => <span>{children ?? text}</span>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;
const TestButton: React.FC<any> = ({ children, onClick, disabled }) => (
  <button type="button" onClick={onClick} disabled={disabled}>
    {children}
  </button>
);
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;

/** 저장 버튼이 쓰는 기본 컴포넌트만 등록한다. */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
  };

  return registry;
}

/** 실제 레이아웃의 저장 버튼만 떼어낸 테스트 레이아웃 */
function buildLayout(): Json {
  const saveButton = findById(userForm.slots.content, 'footer_save_button');

  if (!saveButton) {
    throw new Error('저장 버튼 노드를 찾지 못했습니다.');
  }

  return {
    version: '1.0.0',
    layout_name: 'test_admin_user_form_single_save',
    named_actions: (userForm as Json).named_actions,
    components: [saveButton],
  };
}

describe('회원 수정 폼 — 코어 회원 저장 한 번에 주입 칸까지', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    setupRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
  });

  /** 테스트 하네스의 fetch spy 에 기록된 호출 [url, init] 목록 */
  function fetchCalls(): Array<[string, RequestInit | undefined]> {
    const spy = globalThis.fetch as unknown as { mock?: { calls: any[][] } };

    return (spy.mock?.calls ?? []).map((call) => [String(call[0]), call[1]]);
  }

  /**
   * @scenario entry_point=admin_update, extension_state=subscribed
   * @effects form_calls_only_core_save_api, form_field_rides_core_save_body
   */
  it('저장하면 코어 회원 저장 요청 하나만 나가고, 그 본문에 주입 칸 값이 실린다', async () => {
    testUtils = createLayoutTest(buildLayout() as any, {
      componentRegistry: ComponentRegistry.getInstance(),
      routeParams: { id: '7' },
      translations: {},
      initialState: {
        _local: {
          form: {
            status: 'active',
            email: 'seeded@example.com',
            name: '회원',
            ecommerce_preferred_currency: 'USD',
            ecommerce_preferred_shipping_country: 'US',
          },
        },
      },
      initialData: { user: { data: { id: 7, status: 'active' } } },
    });

    await testUtils.render();
    fireEvent.click(screen.getAllByRole('button')[0]);

    // 성공 갈래가 끝까지 돌았는지(목록으로 이동) 확인한 뒤에 "추가 호출 없음" 을 단언한다
    await waitFor(() => {
      expect(testUtils.getNavigationHistory().length).toBeGreaterThan(0);
    });

    const calls = fetchCalls();
    const saves = calls.filter(([url]) => url.includes('/api/admin/users/7'));

    expect(saves).toHaveLength(1);
    expect(calls.filter(([url]) => url.includes('/api/modules/'))).toEqual([]);

    const body = String(saves[0][1]?.body ?? '');
    expect(body).toContain('"ecommerce_preferred_currency":"USD"');
    expect(body).toContain('"ecommerce_preferred_shipping_country":"US"');
  });
});
