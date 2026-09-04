/**
 * CKEditor5 확보 실패 시의 평문 입력 폴백
 *
 * `html-editor.json` 이 `mode: "replace"` 라 코어 HtmlEditor 가 렌더되지 않는다.
 * 편집기를 못 세우면 사용자에게는 빈 div 만 남아 글을 쓸 방법이 없었다.
 *
 * @scenario custom_source=none
 * @effects failed_asset_falls_back_to_plain_input, failed_asset_shows_retry_notice, retry_recovers_editor
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    renderTextareaFallback,
    getTextareaFallbackValues,
    removeTextareaFallback,
    hasTextareaFallback,
} from '../../handlers/textareaFallback';

describe('CKEditor5 textarea 폴백', () => {
    let container: HTMLElement;
    let setLocal: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        container = document.createElement('div');
        container.id = 'ckeditor5-content';
        document.body.appendChild(container);

        setLocal = vi.fn();
        (window as any).G7Core = { state: { setLocal } };
    });

    afterEach(() => {
        container.remove();
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    describe('단일 모드', () => {
        it('textarea 를 세우고 초기 내용을 채운다', () => {
            renderTextareaFallback({
                container,
                name: 'content',
                height: 400,
                readOnly: false,
                multilingual: false,
                initialContent: '기존 본문',
            });

            const textarea = container.querySelector('textarea') as HTMLTextAreaElement;

            expect(textarea).not.toBeNull();
            expect(textarea.name).toBe('content');
            expect(textarea.value).toBe('기존 본문');
            expect(textarea.style.height).toBe('400px');
        });

        it('폴백 렌더 즉시 _mode 를 text 로 못 박는다 (아무것도 입력하지 않고 저장해도 평문 처리)', () => {
            renderTextareaFallback({
                container,
                name: 'content',
                height: 400,
                readOnly: false,
                multilingual: false,
                initialContent: '',
            });

            expect(setLocal).toHaveBeenCalledWith(
                expect.objectContaining({ 'form.content_mode': 'text' }),
                expect.anything()
            );
        });

        it('입력이 form.content 로 동기화된다', () => {
            renderTextareaFallback({
                container,
                name: 'content',
                height: 400,
                readOnly: false,
                multilingual: false,
                initialContent: '',
            });

            const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
            textarea.value = '사용자가 쓴 글';
            textarea.dispatchEvent(new Event('input'));

            const [updates] = setLocal.mock.calls[setLocal.mock.calls.length - 1];

            expect(updates['form.content']).toBe('사용자가 쓴 글');
            expect(updates['form.content_mode']).toBe('text');

            // `hasChanges` 는 이 배치에 없다 — 배치가 `render:false + selfManaged:true` 라
            // React 렌더를 일으키지 않아, 여기에 실으면 저장 버튼의 활성 조건이 재평가되지
            // 않는다(수정 화면에서 저장 자체가 불가능해진다). 별도 setLocal 로 나간다.
            expect(updates.hasChanges, '본문 배치에 섞으면 저장 버튼이 안 켜진다').toBeUndefined();
            expect(
                setLocal.mock.calls.some(([u]: [Record<string, any>]) => u.hasChanges === true),
                '플래그 자체는 올라가야 한다',
            ).toBe(true);
        });

        /**
         * 번역 실패는 폴백 대상이 아니다 — 영어로 뜰 뿐 편집기는 동작한다.
         *
         * @scenario asset_class=translation, outcome=failed
         * @effects failed_asset_falls_back_to_plain_input
         */
        it('readOnly 설정이면 textarea 도 읽기 전용', () => {
            renderTextareaFallback({
                container,
                name: 'content',
                height: 300,
                readOnly: true,
                multilingual: false,
                initialContent: '읽기 전용',
            });

            expect((container.querySelector('textarea') as HTMLTextAreaElement).readOnly).toBe(true);
        });

        it('이미 폴백이 서 있으면 다시 만들지 않는다 (입력 중이던 내용 보존)', () => {
            renderTextareaFallback({
                container, name: 'content', height: 400, readOnly: false,
                multilingual: false, initialContent: '',
            });

            const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
            textarea.value = '작성 중';

            renderTextareaFallback({
                container, name: 'content', height: 400, readOnly: false,
                multilingual: false, initialContent: '초기값',
            });

            expect(container.querySelectorAll('textarea')).toHaveLength(1);
            expect((container.querySelector('textarea') as HTMLTextAreaElement).value).toBe('작성 중');
        });
    });

    describe('다국어 모드', () => {
        const base = {
            name: 'content',
            height: 400,
            readOnly: false,
            multilingual: true as const,
            locales: ['ko', 'en', 'ja'],
            activeLocale: 'ko',
        };

        it('탭을 세우고 활성 로케일 값을 보여준다', () => {
            renderTextareaFallback({
                ...base,
                container,
                contentMap: { ko: '한국어 본문', en: 'English body' },
            });

            const tabs = container.querySelectorAll('.ckeditor5-locale-tabs button');
            const textarea = container.querySelector('textarea') as HTMLTextAreaElement;

            expect(tabs).toHaveLength(3);
            expect(textarea.value).toBe('한국어 본문');
        });

        it('탭 전환 시 현재 입력을 커밋하고 다음 로케일 값을 채운다', () => {
            renderTextareaFallback({
                ...base,
                container,
                contentMap: { ko: '한국어 본문', en: 'English body' },
            });

            const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
            textarea.value = '수정한 한국어';
            textarea.dispatchEvent(new Event('input'));

            const enTab = container.querySelector('button[data-locale="en"]') as HTMLButtonElement;
            enTab.click();

            expect(textarea.value).toBe('English body');

            const [updates] = setLocal.mock.calls[setLocal.mock.calls.length - 1];

            expect(updates['form.content.ko']).toBe('수정한 한국어');
            expect(updates['form.content.en']).toBe('English body');
            expect(updates['form.content_mode']).toBe('text');
        });

        it('입력이 있는 로케일 탭에만 체크 표시가 뜬다', () => {
            renderTextareaFallback({
                ...base,
                container,
                contentMap: { ko: '한국어 본문' },
            });

            const koCheck = container.querySelector('button[data-locale="ko"] [data-check]') as HTMLElement;
            const jaCheck = container.querySelector('button[data-locale="ja"] [data-check]') as HTMLElement;

            expect(koCheck.style.display).not.toBe('none');
            expect(jaCheck.style.display).toBe('none');
        });
    });

    describe('재시도 승계', () => {
        it('폴백에 입력한 내용을 편집기로 넘길 수 있다 (단일)', () => {
            renderTextareaFallback({
                container, name: 'content', height: 400, readOnly: false,
                multilingual: false, initialContent: '',
            });

            const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
            textarea.value = '폴백에서 쓴 글';

            expect(getTextareaFallbackValues(container)).toBe('폴백에서 쓴 글');
        });

        it('폴백에 입력한 내용을 편집기로 넘길 수 있다 (다국어 — 커밋 전 현재 탭 포함)', () => {
            renderTextareaFallback({
                container, name: 'content', height: 400, readOnly: false,
                multilingual: true, locales: ['ko', 'en'], activeLocale: 'ko',
                contentMap: { en: '이미 있던 영어' },
            });

            const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
            textarea.value = '아직 커밋 안 한 한국어';

            expect(getTextareaFallbackValues(container)).toEqual({
                ko: '아직 커밋 안 한 한국어',
                en: '이미 있던 영어',
            });
        });

        it('제거하면 컨테이너가 비고 폴백 판정이 false 가 된다', () => {
            renderTextareaFallback({
                container, name: 'content', height: 400, readOnly: false,
                multilingual: false, initialContent: '내용',
            });

            expect(hasTextareaFallback(container)).toBe(true);
            expect(removeTextareaFallback(container)).toBe(true);
            expect(hasTextareaFallback(container)).toBe(false);
            expect(container.innerHTML).toBe('');
        });

        it('폴백이 없는 컨테이너에서는 승계할 값이 없다', () => {
            expect(getTextareaFallbackValues(container)).toBeNull();
            expect(removeTextareaFallback(container)).toBe(false);
        });
    });
});
