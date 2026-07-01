/**
 * @file adminEcommerceSettingsSticky.test.tsx
 * @description 전자상거래 환경설정 화면 상단 탭 / 하단 버튼 sticky 고정 테스트
 *
 * 전자상거래 환경설정(admin_ecommerce_settings.json)의 상단 탭과 하단 저장 버튼이
 * 스크롤 중에도 화면에 고정되도록 sticky 클래스가 적용되어 있는지 검증한다.
 */

import { describe, it, expect } from 'vitest';
import adminEcommerceSettingsLayout from '../../../layouts/admin/admin_ecommerce_settings.json';

/** 레이아웃 트리에서 주어진 id 의 노드를 찾는다. */
function findById(node: unknown, id: string): Record<string, unknown> | undefined {
  if (!node || typeof node !== 'object') {
    return undefined;
  }
  const value = node as Record<string, unknown>;
  if (value.id === id) {
    return value;
  }
  for (const child of Object.values(value)) {
    const found = findById(child, id);
    if (found) {
      return found;
    }
  }
  return undefined;
}

function classNameOf(node: Record<string, unknown> | undefined): string {
  const props = (node?.props ?? {}) as Record<string, unknown>;
  return typeof props.className === 'string' ? props.className : '';
}

describe('admin_ecommerce_settings 환경설정 sticky 고정', () => {
  it('상단 탭(tab_navigation)에 sticky-tab-nav 시맨틱 자산이 존재해야 한다 (#399)', () => {
    const tabNav = findById(adminEcommerceSettingsLayout, 'tab_navigation');
    expect(tabNav).toBeDefined();

    const className = classNameOf(tabNav);
    expect(className).toContain('sticky-tab-nav');
  });

  it('하단 버튼(footer_buttons)에 sticky-footer-buttons 시맨틱 자산이 존재해야 한다 (#399)', () => {
    const footer = findById(adminEcommerceSettingsLayout, 'footer_buttons');
    expect(footer).toBeDefined();

    const className = classNameOf(footer);
    expect(className).toContain('sticky-footer-buttons');
  });
});
