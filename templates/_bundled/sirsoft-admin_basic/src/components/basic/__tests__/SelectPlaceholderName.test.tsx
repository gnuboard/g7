// e2e:allow 이커머스 전수 실측 S5 — Playwright MCP 브라우저로 화면 재현·재실측(이력 「Chrome MCP 정밀 점검 매트릭스」·결함 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file SelectPlaceholderName.test.tsx
 * @description 값이 비었을 때 선택 상자가 안내 글자(placeholder)를 보이고 그 글자로 읽히는지
 *
 * 선택 상자(Select)는 `placeholder` 를 화면에 그리지 않고 바깥 상자의 속성으로만 흘려, 값이 비면 빈칸만
 * 보였고 화면 낭독기는 이름 없는 「버튼」 으로 읽었다(이커머스 전수 실측 S5-D35, 2026-10-07 — 상품 등록
 * 화면 브랜드 선택). 레이아웃 29곳이 `<select placeholder="…">` 로 안내 글자를 넘긴다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Select } from '../Select';

const options = [
    { value: 1, label: '브랜드 A' },
    { value: 2, label: '브랜드 B' },
];

describe('선택 상자 안내 글자', () => {
    /**
     * @scenario case=c01
     * @effects select_placeholder_visible
     */
    it('값이 비면 안내 글자를 보이고 그 글자가 버튼 이름이 된다', () => {
        render(<Select options={options} value="" placeholder="브랜드를 선택하세요" />);

        const button = screen.getByRole('button', { name: '브랜드를 선택하세요' });
        expect(button).toBeInTheDocument();
        expect(screen.getByText('브랜드를 선택하세요').className).toContain('dark:text-gray-500');
    });

    /**
     * @scenario case=c02
     * @effects select_placeholder_visible
     */
    it('값을 고르면 고른 글자를 보인다', () => {
        render(<Select options={options} value={2} placeholder="브랜드를 선택하세요" />);

        expect(screen.getByRole('button', { name: '브랜드 B' })).toBeInTheDocument();
        expect(screen.queryByText('브랜드를 선택하세요')).toBeNull();
    });

    /**
     * @scenario case=c03
     * @effects select_trigger_named
     */
    it('aria-label 을 넘기면 바깥 상자가 아니라 버튼에 단다', () => {
        const { container } = render(<Select options={options} value={1} aria-label="브랜드" />);

        expect(screen.getByRole('button', { name: '브랜드' })).toBeInTheDocument();
        expect((container.firstChild as HTMLElement).getAttribute('aria-label')).toBeNull();
    });
});
