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
  /**
   * @scenario area=top_tabs
   * @effects settings_top_tabs_stay_fixed_at_every_width
   */
  it('상단 탭(tab_navigation)이 모든 폭에서 상단에 고정된다 (#399)', () => {
    const tabNav = findById(adminEcommerceSettingsLayout, 'tab_navigation');
    expect(tabNav).toBeDefined();

    // 폭 조건을 품은 의미 클래스 대신 유틸리티로 펼쳤다 — 기본과 모든 구간 값에 고정 배치가 있어야 한다
    const className = classNameOf(tabNav);
    expect(className).toContain('ecommerce:sticky');
    expect(className).toContain('ecommerce:top-0');
    const responsive = (tabNav?.responsive ?? {}) as Record<string, { props?: { className?: string } }>;
    expect(Object.keys(responsive).length).toBeGreaterThan(0);
    for (const range of Object.values(responsive)) {
      expect(range.props?.className).toContain('ecommerce:sticky');
      expect(range.props?.className).toContain('ecommerce:top-0');
    }
  });

  /**
   * @scenario area=footer_buttons
   * @effects settings_footer_buttons_stay_fixed
   */
  it('하단 버튼(footer_buttons)에 sticky-footer-buttons 시맨틱 자산이 존재해야 한다 (#399)', () => {
    const footer = findById(adminEcommerceSettingsLayout, 'footer_buttons');
    expect(footer).toBeDefined();

    const className = classNameOf(footer);
    expect(className).toContain('sticky-footer-buttons');
  });
});
