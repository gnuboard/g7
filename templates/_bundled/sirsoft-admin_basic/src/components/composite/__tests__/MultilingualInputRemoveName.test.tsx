// e2e:allow 이커머스 전수 실측 S5 — Playwright MCP 브라우저로 화면 재현·재실측(이력 「Chrome MCP 정밀 점검 매트릭스」·결함 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file MultilingualInputRemoveName.test.tsx
 * @description 다국어 입력의 언어 제거(×) 버튼이 어느 언어를 지우는지 읽을 수 있는 이름을 갖는지
 *
 * 상품 등록 화면의 상품명처럼 다국어 입력에 언어를 더하면 언어 탭마다 × 버튼이 붙는데, 아이콘만 그려
 * 화면 낭독기가 아이콘 이름 「xmark」 로 읽었다(이커머스 전수 실측 S5-D34, 2026-10-07 — 상품 등록 화면
 * English·日本語 탭). 관리자 화면의 모든 다국어 입력이 같은 부품을 쓴다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MultilingualInput, LocaleOption } from '../MultilingualInput';

const LOCALES: LocaleOption[] = [
    { code: 'ko', name: '한국어', nativeName: '한국어' },
    { code: 'en', name: 'English', nativeName: 'English' },
];

describe('다국어 입력 언어 제거 버튼 이름', () => {
    beforeEach(() => {
        (window as any).G7Core = {
            t: (key: string, params?: Record<string, string>) => {
                if (key === 'common.remove_language') return `${params?.language} 입력 제거`;
                return ({ 'common.language_ko': '한국어', 'common.language_en': 'English' } as Record<string, string>)[key] ?? key;
            },
            locale: { supported: () => ['ko', 'en'], current: () => 'ko' },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario case=c01
     * @effects multilingual_remove_named
     */
    it('탭 배치에서 × 버튼이 「English 입력 제거」 로 읽힌다(아이콘 이름이 아니다)', () => {
        render(<MultilingualInput name="name" layout="tabs" value={{ ko: '상품', en: 'Item' }} availableLocales={LOCALES} defaultLocale="ko" />);

        expect(screen.getByRole('button', { name: 'English 입력 제거' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'xmark' })).toBeNull();
    });

    /**
     * @scenario case=c02
     * @effects multilingual_remove_named
     */
    it('줄 배치(inline)의 언어별 입력칸에서도 같은 이름을 단다', () => {
        render(<MultilingualInput name="name" layout="inline" value={{ ko: '상품', en: 'Item' }} availableLocales={LOCALES} defaultLocale="ko" />);

        expect(screen.getByRole('button', { name: 'English 입력 제거' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'xmark' })).toBeNull();
    });
});
