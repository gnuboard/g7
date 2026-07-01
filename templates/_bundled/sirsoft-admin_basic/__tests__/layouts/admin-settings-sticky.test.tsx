/**
 * @file admin-settings-sticky.test.tsx
 * @description 관리자 환경설정 화면 상단 탭 / 하단 버튼 sticky 고정 회귀 테스트
 *
 * 배경:
 * - 코어 환경설정(admin_settings.json)의 상단 탭과 하단 저장 버튼에는
 *   원래 sticky 고정 스타일이 적용되어 있었으나, 알림 설정 탭 재설계 과정에서
 *   tab_navigation 컨테이너 교체 + footer_buttons className 평탄화로 소실되었다.
 * - 본 테스트는 sticky className 이 다시 존재함을 보장하여 동일 회귀를 차단한다.
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import adminSettingsLayout from '../../layouts/admin_settings.json';
import { TabNavigation } from '../../src/components/composite/TabNavigation';

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

describe('admin_settings 환경설정 sticky 고정', () => {
  it('상단 탭 컨테이너(sticky_header)에 .sticky-tab-nav 시맨틱 클래스가 적용되어야 한다', () => {
    // #399 Phase 1.3: sticky 책임이 TabNavigation 노드 자체가 아니라 그를 감싸는
    // 래퍼 Div(sticky_header) 로 이동했다. 래퍼가 .sticky-tab-nav* 자산을 보유하면
    // 탭 영역 sticky 고정이 보존된다 (반응형 변형 sticky-tab-nav-responsive 포함).
    const stickyHeader = findById(adminSettingsLayout, 'sticky_header');
    expect(stickyHeader, 'sticky_header 래퍼 미존재').toBeDefined();

    const className = classNameOf(stickyHeader);
    expect(className).toContain('sticky-tab-nav');

    // 래퍼가 실제로 tab_navigation 을 감싸고 있어야 한다 (sticky 대상 보장).
    expect(findById(stickyHeader, 'tab_navigation'), 'sticky_header 가 tab_navigation 을 감싸지 않음').toBeDefined();
  });

  it('하단 버튼(footer_buttons)에 .sticky-footer-buttons 시맨틱 클래스가 적용되어야 한다', () => {
    const footer = findById(adminSettingsLayout, 'footer_buttons');
    expect(footer).toBeDefined();

    const className = classNameOf(footer);
    expect(className).toContain('sticky-footer-buttons');
  });

  it('상단/하단 sticky 자산 정의가 sticky/top|bottom/z 토큰을 포함 (계층 보장)', () => {
    // 시맨틱화 (#399 Phase 1.3 ~ 1.5) 이후 회귀 의도 보존:
    // .sticky-tab-nav 안에 sticky/top-0/z-40, .sticky-footer-buttons 안에
    // sticky/bottom-0/z-10 토큰이 살아있어야 상단 탭과 하단 저장 버튼이
    // 콘텐츠 스크롤 시 가려지지 않고, 헤더 (z-50) 보다 낮은 계층을 유지한다.
    const cssPath = path.resolve(__dirname, '../../src/styles/main.css');
    const css = fs.readFileSync(cssPath, 'utf-8');

    const topNavBlock = css.match(/\.sticky-tab-nav\s*\{[^}]*\}/)?.[0] ?? '';
    expect(topNavBlock).toContain('sticky');
    expect(topNavBlock).toContain('top-0');
    expect(topNavBlock).toContain('z-40');

    const footerBlock = css.match(/\.sticky-footer-buttons\s*\{[^}]*\}/)?.[0] ?? '';
    expect(footerBlock).toContain('sticky');
    expect(footerBlock).toContain('bottom-0');
    expect(footerBlock).toContain('z-10');

    expect(classNameOf(findById(adminSettingsLayout, 'footer_buttons'))).toContain('sticky-footer-buttons');
  });

  it('TabNavigation 컴포넌트가 className prop 을 렌더 루트에 전달한다', () => {
    // tab_navigation 은 composite TabNavigation 이므로 sticky 가 className prop 으로
    // 전달되어 실제 DOM 에 반영되는지 확인 (데스크톱 분기 = <nav>)
    const { container } = render(
      <TabNavigation
        tabs={[{ id: 'a', label: 'A' }]}
        activeTabId="a"
        className="sticky top-0 z-40"
      />
    );
    const nav = container.querySelector('nav');
    expect(nav).not.toBeNull();
    expect(nav?.className).toContain('sticky');
    expect(nav?.className).toContain('top-0');
  });
});
