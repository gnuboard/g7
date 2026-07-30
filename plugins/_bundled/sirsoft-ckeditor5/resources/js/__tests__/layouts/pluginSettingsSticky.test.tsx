/**
 * @file pluginSettingsSticky.test.tsx
 * @description sirsoft-ckeditor5 플러그인 환경설정 화면 하단 저장 버튼 sticky 고정 테스트
 *
 * 플러그인 환경설정(plugin_settings.json)의 하단 저장/취소 버튼 영역이
 * 긴 콘텐츠를 스크롤하는 동안에도 화면 하단에 고정되도록 sticky 클래스가
 * 적용되어 있는지 검증한다.
 */

import { describe, it, expect } from 'vitest';
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

describe('plugin_settings 하단 버튼 sticky 고정', () => {
  it('footer_buttons 에 sticky bottom 고정 클래스가 존재해야 한다', () => {
    const footer = findById(pluginSettingsLayout, 'footer_buttons');
    expect(footer).toBeDefined();

    const className = classNameOf(footer);

    // 명제는 "푸터가 하단에 sticky 로 붙고 위로 겹친다" 다. 그 명제를 유틸리티 클래스 나열로 단언하면
    // 축약 클래스(`.sticky-footer-buttons` = `@apply sticky bottom-0 z-10 …`)를 쓰는 저작본에서 red 가
    // 된다 — 축약 클래스가 이 저장소의 표준 표기이므로 둘 중 어느 표기든 통과해야 한다.
    const isShorthand = /sticky-footer-buttons(-between)?/.test(className);
    if (!isShorthand) {
      expect(className).toContain('sticky');
      expect(className).toContain('bottom-0');
      expect(className).toContain('z-10');
    }
    expect(isShorthand || className.includes('sticky')).toBe(true);
  });
});
