// e2e:allow 시험 기대값만 의미 클래스(화면 모양 같음)에 맞춘 단위 시험 수정 — 화면 변경 없음
/**
 * @file pluginSettingsSticky.test.tsx
 * @description sirsoft-pay_nicepayments 플러그인 환경설정 화면 하단 저장 버튼 sticky 고정 테스트
 *
 * 플러그인 환경설정(plugin_settings.json)의 하단 저장/취소 버튼 영역이
 * 긴 콘텐츠를 스크롤하는 동안에도 화면 하단에 고정되도록 sticky 클래스가
 * 적용되어 있는지 검증한다.
 *
 * @scenario-manifest tests/scenarios/plugin-settings-sticky.yaml
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

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

function collectClassNames(node: unknown, classNames: string[] = []): string[] {
  if (!node || typeof node !== 'object') {
    return classNames;
  }
  const value = node as Record<string, unknown>;
  const props = (value.props ?? {}) as Record<string, unknown>;
  if (typeof props.className === 'string') {
    classNames.push(props.className);
  }
  for (const child of Object.values(value)) {
    collectClassNames(child, classNames);
  }

  return classNames;
}

describe('plugin_settings 하단 버튼 sticky 고정', () => {
  /**
   * @scenario plugin=sirsoft-pay_nicepayments, element=footer
   *
   * @effects footer_buttons_stick_to_bottom
   */
  it('footer_buttons 에 sticky bottom 고정 클래스가 존재해야 한다', () => {
    const footer = findById(pluginSettingsLayout, 'footer_buttons');
    expect(footer).toBeDefined();

    const className = classNameOf(footer);

    // 명제는 "푸터가 하단에 sticky 로 붙고 위로 겹친다" 다. 그 명제를 유틸리티 클래스 나열로 단언하면
    // 축약 클래스(`.sticky-footer-buttons` = `@apply sticky bottom-0 z-10 …`)를 쓰는 소스에서 red 가
    // 된다 — 축약 클래스가 이 저장소의 표준 표기이므로 둘 중 어느 표기든 통과해야 한다.
    const isShorthand = /sticky-footer-buttons(-between)?/.test(className);
    if (!isShorthand) {
      expect(className).toContain('sticky');
      expect(className).toContain('bottom-0');
      expect(className).toContain('z-10');
    }
    expect(isShorthand || className.includes('sticky')).toBe(true);
  });

  /**
   * @scenario plugin=sirsoft-pay_nicepayments, element=root
   *
   * @effects settings_root_layout_by_width
   */
  it('활성 테마의 신규 시맨틱 CSS 없이도 설정 화면 기본 레이아웃이 적용되어야 한다', () => {
    const root = findById(pluginSettingsLayout, 'plugin_settings_content');
    expect(root).toBeDefined();

    const rootClassName = classNameOf(root);
    expect(rootClassName).toContain('p-4');
    // 화면 폭 분기는 엔진 responsive 로 쓴다 — 640~1023px 은 p-6, 1024px 이상은 p-8
    const responsive = (root?.responsive ?? {}) as Record<string, Record<string, unknown>>;
    // 1.2.1부터 640~1023 구간은 같은 모양의 의미 클래스(p-6·min-h-screen·bg-gray-50·다크 gray-900)를 쓴다
    const tabletClasses = classNameOf(responsive['640-1023']).split(' ');
    expect(tabletClasses.includes('paynicepayments:p-6') || tabletClasses.includes('pay-nicepayments-admin-page-content')).toBe(true);
    if (tabletClasses.includes('pay-nicepayments-admin-page-content')) {
      const css = readFileSync(resolve(__dirname, '../../../css/g7-extension.generated.css'), 'utf-8');
      const rule = css.match(/\.pay-nicepayments-admin-page-content\s*\{([^}]*)\}/)?.[1] ?? '';
      expect(rule).toMatch(/padding:\s*calc\(\.25rem \* 6\)/);
      expect(rule).toMatch(/min-height:\s*100vh/);
    }
    // 커스텀 구간(640-1023)과 프리셋을 한 노드에 섞지 않는다 — 1024px 이상도 커스텀 구간 1024- 로 쓴다
    expect(classNameOf(responsive['1024-']).split(' ')).toContain('paynicepayments:p-8');
    expect(responsive.desktop).toBeUndefined();
    expect(rootClassName).toContain('min-h-screen');
    expect(rootClassName).toContain('bg-gray-50');
    expect(rootClassName).toContain('dark:bg-gray-900');

    const classNames = collectClassNames(pluginSettingsLayout);
    for (const className of classNames) {
      expect(className).not.toContain('admin-page-content-responsive');
      expect(className).not.toContain('flex-between');
      expect(className).not.toContain('sticky-footer-buttons');
      expect(className).not.toContain('row-stack');
    }
  });
});
