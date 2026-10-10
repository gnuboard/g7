/**
 * @file copyToClipboard.test.ts
 * @description sirsoft-message_bizppurio 설정 화면의 주소 복사 핸들러 — 플러그인이 직접 소유·등록하는지와 복사 동작.
 *
 * 설정 레이아웃이 네임스페이스 없는 `copyToClipboard` 를 불렀는데 코어·템플릿·확장 어디에도
 * 그런 핸들러가 없어 복사 버튼이 아무 일도 하지 않았다(성공 토스트도 뜨지 않음). 플러그인이
 * 자기 핸들러를 두고 레이아웃이 `sirsoft-message_bizppurio.copyToClipboard` 로 부르도록 고쳤다.
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { copyToClipboardHandler } from '../../handlers/copyToClipboard';
import { handlerMap } from '../../handlers';

describe('sirsoft-message_bizppurio copyToClipboard 핸들러', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
    });

    it('핸들러 맵에 copyToClipboard 로 등록된다 (등록 시 sirsoft-message_bizppurio. 접두)', () => {
        expect(handlerMap.copyToClipboard).toBe(copyToClipboardHandler);
    });

    it('전달받은 text 를 브라우저 클립보드에 복사한다', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        const copied = await copyToClipboardHandler({ params: { text: 'https://example.test/notify' } });

        expect(copied).toBe(true);
        expect(writeText).toHaveBeenCalledWith('https://example.test/notify');
    });

    it('text 가 비어 있으면 클립보드를 호출하지 않는다', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        const copied = await copyToClipboardHandler({ params: { text: '' } });

        expect(copied).toBe(false);
        expect(writeText).not.toHaveBeenCalled();
    });

    it('클립보드 API 가 없으면 execCommand 폴백으로 복사한다', async () => {
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
        const execCommand = vi.fn().mockReturnValue(true);
        Object.defineProperty(document, 'execCommand', { configurable: true, value: execCommand });

        const copied = await copyToClipboardHandler({ params: { text: '/plugins/notify' } });

        expect(copied).toBe(true);
        expect(execCommand).toHaveBeenCalledWith('copy');
        expect(document.querySelector('textarea')).toBeNull();
    });
});
