// e2e:allow 이커머스 전수 실측 S5 — Playwright MCP 브라우저로 화면 재현·재실측(이력 「Chrome MCP 정밀 점검 매트릭스」·결함 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file MultilingualTagInputPasteAndNames.test.tsx
 * @description 다국어 태그 입력 — 쉼표로 여러 값을 넣을 때 나머지 값 보존, 태그 제거 버튼·입력칸 읽기 이름
 *
 * 상품 옵션값 칸(「쉼표로 구분해 입력」)에 「Red,Blue」 를 붙여 넣으면 첫 값만 다국어 창으로 넘어가고 나머지는
 * 조용히 사라졌다(S5-D36). 태그마다 붙은 × 버튼은 아이콘 이름 「times」 로 읽혔고, 태그가 하나라도 생기면
 * 입력칸은 안내 글자가 비어 이름 없는 칸이 됐다(S5-D37). 이커머스 전수 실측 2026-10-08 — 상품 등록 변종.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MultilingualTagInput } from '../MultilingualTagInput';

describe('다국어 태그 입력 — 붙여넣기와 읽기 이름', () => {
    beforeEach(() => {
        (window as any).G7Core = {
            t: (key: string, params?: Record<string, string>) => (key === 'common.remove_tag' ? `${params?.tag} 제거` : key),
            locale: { supported: () => ['ko', 'en'], current: () => 'en' },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario case=c01
     * @effects tag_paste_keeps_remainder
     */
    it('쉼표로 여러 값을 넣으면 첫 값은 창으로, 나머지는 입력칸에 남는다', () => {
        render(<MultilingualTagInput name="values" value={[]} creatable placeholder="Enter option values separated by comma" />);

        const input = screen.getByRole('textbox', { name: 'Enter option values separated by comma' });
        fireEvent.change(input, { target: { value: 'Red,Blue, Green' } });

        expect((screen.getAllByDisplayValue('Red')).length).toBeGreaterThan(0);
        expect((input as HTMLInputElement).value).toBe('Blue, Green');
    });

    /**
     * @scenario case=c02
     * @effects tag_paste_keeps_remainder
     */
    it('남은 값에서 Enter 를 누르면 다음 한 값만 창으로 넘기고 그 뒤는 또 남긴다', () => {
        render(<MultilingualTagInput name="values" value={[]} creatable placeholder="values" />);

        const input = screen.getByRole('textbox', { name: 'values' }) as HTMLInputElement;
        fireEvent.change(input, { target: { value: 'Red,Blue,Green' } });
        expect(input.value).toBe('Blue, Green');

        fireEvent.keyDown(input, { key: 'Enter' });

        expect(screen.getAllByDisplayValue('Blue').length).toBeGreaterThan(0);
        expect(input.value).toBe('Green');
    });

    /**
     * @scenario case=c03
     * @effects tag_controls_named
     */
    it('태그 제거 버튼은 그 태그 값으로 읽히고(아이콘 이름 아님), 태그가 있어도 입력칸 이름이 남는다', () => {
        render(<MultilingualTagInput name="values" value={[{ ko: '빨강', en: 'Red' }]} creatable placeholder="Enter option values separated by comma" />);

        expect(screen.getByRole('button', { name: 'Red 제거' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'times' })).toBeNull();
        expect(screen.getByRole('textbox', { name: 'Enter option values separated by comma' })).toBeInTheDocument();
    });
});
