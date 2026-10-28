/**
 * @file ExtensionCardGrid.dependency-unmet.test.tsx
 * @description 확장 목록 카드가 미충족 의존을 빨간 글자·경고 아이콘·툴팁으로 구분한다 (코어 7.1.4, #152)
 *
 * 카드의 의존 배지가 충족·미충족 구분 없이 같은 모양이라, 켜면 막히거나(활성화 409) 화면이 503 이 되는 원인을
 * 목록에서 알 수 없었다. 미충족(`unmet`) 항목만 빨간 글자·경고 아이콘에 사유를 `title`·`aria-label` 로 싣고,
 * 충족 항목은 종전 마크업 그대로 둔다(회귀).
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { ExtensionCardGrid } from '../../src/components/composite/ExtensionCardGrid';

beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => key,
    locale: { current: () => 'ko' },
    state: { subscribe: () => () => {} },
  };
});

afterEach(() => {
  delete (window as any).G7Core;
});

/** 의존 둘(충족 1·미충족 1)을 가진 카드 하나를 그린다. */
function renderCard(viewMode: 'gallery' | 'list') {
  return render(
    <ExtensionCardGrid
      viewMode={viewMode}
      items={[
        {
          id: 'heujung-apt_rebuilding',
          name: '재건축 단지 소통',
          dependencies: [
            { type: 'module', name: '게시판' },
            { type: 'module', name: '재건축 단지 소통', unmet: true, reason: '버전 미달 0.1.0 → >=9.9.9' },
          ],
        },
      ]}
    />
  );
}

describe('카드 의존 배지 — 미충족 표시', () => {
  // @scenario surface=card_component_unmet
  // @effects card_marks_unmet_dependency
  it.each(['gallery', 'list'] as const)('%s 보기: 미충족 항목은 빨간 글자·경고 아이콘·title·aria-label 을 싣는다', (viewMode) => {
    const { container } = renderCard(viewMode);
    const unmet = container.querySelectorAll('[data-unmet="true"]');

    expect(unmet).toHaveLength(1);
    const node = unmet[0] as HTMLElement;
    expect(node.className).toContain('text-red-600');
    expect(node.className).toContain('dark:text-red-400');
    expect(node.getAttribute('title')).toBe('버전 미달 0.1.0 → >=9.9.9');
    expect(node.getAttribute('aria-label')).toBe('재건축 단지 소통 — 버전 미달 0.1.0 → >=9.9.9');
    expect(node.querySelector('.fa-exclamation-triangle')).not.toBeNull();
  });

  // @scenario surface=card_component_met
  // @effects card_met_dependency_unchanged
  it('충족 항목은 종전처럼 회색 아이콘에 title 이 없다', () => {
    const { container } = renderCard('gallery');
    const deps = container.querySelector('[data-ref="ext_deps"]') as HTMLElement;
    const met = Array.from(deps.querySelectorAll('span.inline-flex')).filter((el) => !el.hasAttribute('data-unmet'));

    expect(met).toHaveLength(1);
    expect(met[0].getAttribute('title')).toBeNull();
    expect(met[0].className).not.toContain('text-red-600');
    expect(met[0].querySelector('.fa-cube')).not.toBeNull();
  });
});
