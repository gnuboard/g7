/**
 * 클립보드 핸들러 테스트.
 *
 * 이 파일만 jsdom 에서 돈다 — 폴백 경로가 `document.createElement`·`execCommand` 를 쓰므로
 * DOM 이 필요하다. 형제 테스트(QR·Base32)는 순수 계산이라 설정 기본값(node)을 그대로 둔다.
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { copyToClipboardHandler } from '../../handlers/copyToClipboard';

/**
 * 클립보드 객체를 갈아끼운다. `navigator.clipboard` 는 읽기 전용이라 defineProperty 로만 바뀐다.
 *
 * @param value 심을 값 — `undefined` 면 비보안 컨텍스트처럼 클립보드가 없는 상태가 된다
 * @returns 반환값 없음
 */
function stubClipboard(value: unknown): void {
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value });
}

describe('copyToClipboardHandler', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        stubClipboard(undefined);
    });

    it('넘겨받은 텍스트를 클립보드에 담는다', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        stubClipboard({ writeText });

        const copied = await copyToClipboardHandler({
            params: { text: 'K7QM-2FRT\n9XCV-PL2A' },
        });

        expect(writeText).toHaveBeenCalledWith('K7QM-2FRT\n9XCV-PL2A');
        expect(copied).toBe(true);
    });

    it('텍스트가 비면 클립보드를 건드리지 않는다', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        stubClipboard({ writeText });

        // 백업 코드가 아직 안 실린 상태에서 눌린 경우 — 빈 문자열을 붙여넣게 두지 않는다.
        const copied = await copyToClipboardHandler({ params: { text: '' } });

        expect(writeText).not.toHaveBeenCalled();
        expect(copied).toBe(false);
    });

    it('클립보드 API 가 없으면 execCommand 폴백으로 복사한다', async () => {
        // 사내망 HTTP 처럼 보안 컨텍스트가 아닌 설치본을 흉내낸다.
        stubClipboard(undefined);
        const execCommand = vi.fn().mockReturnValue(true);
        (document as unknown as { execCommand: unknown }).execCommand = execCommand;

        const copied = await copyToClipboardHandler({ params: { text: 'MB4N-QW8Z' } });

        expect(execCommand).toHaveBeenCalledWith('copy');
        expect(copied).toBe(true);
    });

    it('클립보드 쓰기가 거부되면 폴백으로 내려간다', async () => {
        // 권한 거부는 예외로 온다 — 여기서 멈추면 운영자는 복사된 줄 알고 화면을 닫는다.
        const writeText = vi.fn().mockRejectedValue(new Error('denied'));
        stubClipboard({ writeText });
        const execCommand = vi.fn().mockReturnValue(true);
        (document as unknown as { execCommand: unknown }).execCommand = execCommand;

        const copied = await copyToClipboardHandler({ params: { text: 'T3HJ-6DSE' } });

        expect(writeText).toHaveBeenCalled();
        expect(execCommand).toHaveBeenCalledWith('copy');
        expect(copied).toBe(true);
    });

    it('폴백까지 실패하면 false 를 돌려준다', async () => {
        stubClipboard(undefined);
        (document as unknown as { execCommand: unknown }).execCommand = vi.fn().mockReturnValue(false);

        const copied = await copyToClipboardHandler({ params: { text: 'UY5R-PA9K' } });

        expect(copied).toBe(false);
    });
});
