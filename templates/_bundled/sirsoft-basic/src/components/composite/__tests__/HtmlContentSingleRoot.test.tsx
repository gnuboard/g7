/**
 * HtmlContent 는 Fragment(본문 Div + ImageGallery)를 돌려주지만 부모 DOM 에는 본문 Div 하나만 놓인다
 *
 * 확대 보기(ImageGallery)는 서드파티 Lightbox 라 닫혀 있으면 DOM 을 그리지 않고, 열리면 document.body 로 포털한다.
 * 그래서 HtmlContent 를 품은 부모의 첫 자식, 형제 선택자(space-y, divide-y, first:, last:)와 줄 자르기(line-clamp)는
 * 본문 Div 하나만 본다. Lightbox 판올림으로 닫힌 상태의 자리 표시나 인라인 렌더가 생기면 이 시험이 깨진다.
 *
 * @scenario-manifest tests/scenarios/html-content-single-root-alpha4.yaml
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { HtmlContent } from '../HtmlContent';

const CONTENT = '<p>본문</p><img src="/api/attachment/abc" alt="그림">';

describe('HtmlContent 부모 DOM 구조', () => {
    // @scenario gallery=closed
    // @effects html_content_parent_sees_single_element
    it('닫힌 확대 보기는 부모 안에 아무것도 그리지 않아 자식은 본문 Div 하나다', () => {
        render(
            <div data-testid="parent">
                <HtmlContent content={CONTENT} isHtml className="body-box" />
            </div>,
        );
        const parent = screen.getByTestId('parent');

        expect(parent.childElementCount).toBe(1);
        expect(parent.firstElementChild?.classList.contains('body-box')).toBe(true);
        expect(document.querySelector('[role="dialog"]')).toBeNull();
    });

    // @scenario gallery=open
    // @effects html_content_gallery_portals_outside_parent
    it('이미지를 눌러 확대 보기가 열려도 대화상자는 body 쪽에 붙고 부모 자식 수는 그대로다', async () => {
        render(
            <div data-testid="parent">
                <HtmlContent content={CONTENT} isHtml className="body-box" />
            </div>,
        );
        const parent = screen.getByTestId('parent');

        fireEvent.click(parent.querySelector('img') as HTMLImageElement);

        const dialog = await screen.findByRole('dialog');
        expect(parent.contains(dialog)).toBe(false);
        expect(parent.childElementCount).toBe(1);
        expect(parent.firstElementChild?.classList.contains('body-box')).toBe(true);
    });
});
