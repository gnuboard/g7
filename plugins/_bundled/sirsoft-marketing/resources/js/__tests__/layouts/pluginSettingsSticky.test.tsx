/**
 * @file pluginSettingsSticky.test.tsx
 * @description sirsoft-marketing 플러그인 환경설정 화면 하단 저장 버튼 sticky 고정 테스트
 *
 * 플러그인 환경설정(plugin_settings.json)의 하단 저장/취소 버튼 영역이
 * 긴 콘텐츠를 스크롤하는 동안에도 화면 하단에 고정되도록 sticky 클래스가
 * 적용되어 있는지 검증한다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

/** 관리자 템플릿의 컴파일된 컴포넌트 CSS 경로 (합성 유틸리티 클래스의 실제 선언 위치). */
const ADMIN_COMPONENTS_CSS = 'templates/_bundled/sirsoft-admin_basic/dist/css/components.css';

/**
 * 저장소 루트(artisan 기준)를 위로 훑어 찾는다.
 *
 * @returns 저장소 루트 절대경로
 */
function repoRoot(): string {
  let current = path.dirname(fileURLToPath(import.meta.url));

  for (let depth = 0; depth < 10; depth++) {
    if (fs.existsSync(path.join(current, 'artisan'))) {
      return current;
    }
    current = path.dirname(current);
  }

  throw new Error('artisan 을 가진 저장소 루트를 찾지 못했습니다.');
}

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
  it('footer_buttons 가 sticky 고정 유틸리티 클래스를 사용해야 한다', () => {
    const footer = findById(pluginSettingsLayout, 'footer_buttons');
    expect(footer).toBeDefined();

    // 레이아웃은 raw Tailwind 원자(sticky bottom-0 z-10) 대신 합성 유틸리티 한 개를 쓴다.
    // 원자 이름을 직접 단언하면 합성 클래스로 옮긴 뒤 고정이 멀쩡한데도 실패한다.
    expect(classNameOf(footer)).toContain('sticky-footer-buttons');
  });

  it('그 유틸리티 클래스가 실제로 하단 고정 속성을 선언해야 한다', () => {
    // 클래스 이름만 대조하면 동어반복이라, 컴파일된 CSS 에서 실제 선언을 확인한다 —
    // 클래스명이 남아 있어도 선언이 사라지면 화면에서 고정이 풀리기 때문이다.
    const css = fs.readFileSync(path.join(repoRoot(), ADMIN_COMPONENTS_CSS), 'utf-8');
    const rule = /\.sticky-footer-buttons\{([^}]*)\}/.exec(css);

    expect(rule).not.toBeNull();
    expect(rule![1]).toContain('position:sticky');
    expect(rule![1]).toContain('bottom:');
    expect(rule![1]).toContain('z-index:10');
  });
});
