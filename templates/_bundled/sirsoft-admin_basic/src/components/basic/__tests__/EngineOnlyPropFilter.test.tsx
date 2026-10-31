// e2e:allow 이커머스 전수 실측 후속 B7 — 배송정책 등록 화면 렌더 시험의 React 경고에서 발견. 동작 무변(DOM 속성 하나를 거름)이라 단위 회귀로 충분
/**
 * @file EngineOnlyPropFilter.test.tsx
 * @description 엔진만 읽는 속성(autoBinding)을 입력칸·글상자가 DOM 에 흘리지 않는지
 *
 * 레이아웃의 `:autoBinding="false"`(자동 바인딩을 끄는 표시)는 엔진이 읽은 뒤 props 로 그대로 넘긴다.
 * Input·Textarea 가 이를 DOM 에 펼쳐 React 가 「React does not recognize the `autoBinding` prop」 을 냈다
 * (배송정책 등록 산정 방식 라디오 — 이커머스 전수 실측 후속 B7, 2026-10-08).
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';
import { Input } from '../Input';
import { Textarea } from '../Textarea';

/**
 * 렌더 중 React 가 autoBinding 경고를 냈는지 본다.
 *
 * @param ui 그릴 요소
 * @returns 그려진 컨테이너와 경고 여부
 */
function renderWatchingWarnings(ui: React.ReactElement): { container: HTMLElement; warned: boolean } {
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    const { container } = render(ui);
    const warned = errors.mock.calls.some((call) => call.some((arg) => String(arg).includes('autoBinding')));
    errors.mockRestore();

    return { container, warned };
}

describe('엔진 전용 속성 거르기', () => {
    /**
     * @scenario component=input
     * @effects engine_only_prop_not_in_dom
     */
    it('Input(라디오) 은 autoBinding 을 DOM 속성으로 흘리지 않는다', () => {
        const extra = { autoBinding: false } as Record<string, unknown>;
        const { container, warned } = renderWatchingWarnings(
            <Input type="radio" checked={false} onChange={() => {}} {...extra} />,
        );

        expect((container.querySelector('input') as HTMLInputElement).hasAttribute('autobinding')).toBe(false);
        expect(warned).toBe(false);
    });

    /**
     * @scenario component=textarea
     * @effects engine_only_prop_not_in_dom
     */
    it('Textarea 는 autoBinding 을 DOM 속성으로 흘리지 않는다', () => {
        const extra = { autoBinding: false } as Record<string, unknown>;
        const { container, warned } = renderWatchingWarnings(<Textarea value="" onChange={() => {}} {...extra} />);

        expect((container.querySelector('textarea') as HTMLTextAreaElement).hasAttribute('autobinding')).toBe(false);
        expect(warned).toBe(false);
    });
});
