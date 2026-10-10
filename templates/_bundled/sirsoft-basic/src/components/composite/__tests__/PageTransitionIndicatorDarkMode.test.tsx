/**
 * @file PageTransitionIndicatorDarkMode.test.tsx
 * @description 페이지 전환 로딩 막대가 다크 모드에서도 배경과 구별되는 색을 갖는지
 *
 * 전환 중 상단에 뜨는 로딩 막대의 움직이는 띠가 `bg-blue-700` 하나뿐이라, 다크 모드의 어두운 배경 위에서
 * 대비가 낮아 거의 보이지 않았다(이커머스 전수 실측 S5 검증 — darkmode-class-pair 지적, 2026-10-07).
 * 띠에 다크 모드 색(`dark:bg-blue-400`)을 짝으로 둔다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { PageTransitionIndicator } from '../PageTransitionIndicator';

describe('페이지 전환 로딩 막대 — 다크 모드 색', () => {
    beforeEach(() => {
        (window as any).G7Core = {
            t: (key: string) => key,
            TransitionManager: { getIsPending: () => true, subscribe: () => () => {} },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario case=bar_dark_pair
     * @effects loading_bar_has_dark_color
     */
    it('움직이는 띠가 라이트 색과 다크 모드 색을 함께 갖는다', () => {
        const { container } = render(<PageTransitionIndicator />);
        const bar = [...container.querySelectorAll('div')].find((d) => d.className.includes('bg-blue-700'));

        expect(bar).toBeDefined();
        expect(bar?.className).toContain('dark:bg-blue-400');
    });
});
