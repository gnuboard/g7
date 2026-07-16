import { afterEach, describe, expect, it, vi } from 'vitest';
import { copyToClipboardHandler } from '../../handlers/copyToClipboard';

describe('copyToClipboardHandler', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('전달받은 text를 브라우저 클립보드에 복사한다', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);

        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });

        await copyToClipboardHandler({
            params: {
                text: '114.207.113.206',
            },
        });

        expect(writeText).toHaveBeenCalledWith('114.207.113.206');
    });

    it('text가 비어 있으면 클립보드를 호출하지 않는다', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);

        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });

        await copyToClipboardHandler({
            params: {
                text: '',
            },
        });

        expect(writeText).not.toHaveBeenCalled();
    });
});
