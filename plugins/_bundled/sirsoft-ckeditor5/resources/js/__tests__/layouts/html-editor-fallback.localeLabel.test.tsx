/**
 * CKEditor5 평문 폴백 — 다국어 탭 글자 (#148)
 *
 * 편집기를 못 세웠을 때 그리는 평문 폴백의 다국어 탭도 언어 이름으로 보인다 — 종전 대문자 코드(KO·EN·JA·FIL-PH).
 * 언어팩으로 켠 언어는 서버가 주는 언어팩의 언어 이름(`appConfig.localeNames`)을 쓴다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { renderTextareaFallback } from '../../handlers/textareaFallback';

describe('CKEditor5 평문 폴백 다국어 탭 글자 (#148)', () => {
    let container: HTMLElement;

    beforeEach(() => {
        container = document.createElement('div');
        container.id = 'ckeditor5-content';
        document.body.appendChild(container);
        (window as any).G7Core = { state: { setLocal: vi.fn() } };
    });

    afterEach(() => {
        container.remove();
        delete (window as any).G7Core;
        delete (window as any).G7Config;
        vi.restoreAllMocks();
    });

    // @scenario shape=two_letter
    // @effects admin_locale_label_uses_language_pack_name
    it('내장 언어 탭은 언어 이름이다 (한국어·English·日本語)', () => {
        renderTextareaFallback({
            container,
            name: 'content',
            height: 400,
            readOnly: false,
            multilingual: true,
            locales: ['ko', 'en', 'ja'],
            activeLocale: 'ko',
            contentMap: { ko: '한국어 본문' },
        });

        const tabs = container.querySelectorAll('.ckeditor5-locale-tabs button');

        expect(tabs).toHaveLength(3);
        expect(tabs[0].textContent).toContain('한국어');
        expect(tabs[1].textContent).toContain('English');
        expect(tabs[2].textContent).toContain('日本語');
    });

    // @scenario shape=region_tagged
    // @effects admin_locale_label_uses_language_pack_name
    it('언어팩으로 켠 언어 탭은 언어팩 이름이다 (종전 FIL-PH)', () => {
        (window as any).G7Config = { appConfig: { localeNames: { 'fil-PH': 'Filipino (Pilipinas)' } } };

        renderTextareaFallback({
            container,
            name: 'content',
            height: 400,
            readOnly: false,
            multilingual: true,
            locales: ['ko', 'fil-PH'],
            activeLocale: 'ko',
            contentMap: { ko: '한국어 본문' },
        });

        const tabs = container.querySelectorAll('.ckeditor5-locale-tabs button');

        expect(tabs[1].textContent).toContain('Filipino (Pilipinas)');
        expect(tabs[1].textContent).not.toContain('FIL-PH');
    });
});
