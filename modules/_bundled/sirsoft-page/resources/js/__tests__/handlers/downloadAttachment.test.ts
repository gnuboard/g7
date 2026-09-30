/**
 * @file downloadAttachment.test.ts
 * @description sirsoft-page 첨부 내려받기 핸들러 — 토큰 동반 blob 요청·원본 파일명 저장·실패 시 예외.
 *
 * 관리자 페이지 상세의 첨부 카드가 관리자 템플릿(sirsoft-admin_basic)의 `downloadAttachment` 에
 * 기대고 있어 다른 관리자 템플릿에서는 무반응이었다. 이 모듈이 핸들러를 직접 소유한다.
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { downloadAttachmentHandler } from '../../handlers/downloadAttachment';

describe('sirsoft-page downloadAttachment 핸들러', () => {
    const originalCreateObjectURL = URL.createObjectURL;
    const originalRevokeObjectURL = URL.revokeObjectURL;

    beforeEach(() => {
        URL.createObjectURL = vi.fn().mockReturnValue('blob:page-attachment');
        URL.revokeObjectURL = vi.fn();
    });

    afterEach(() => {
        URL.createObjectURL = originalCreateObjectURL;
        URL.revokeObjectURL = originalRevokeObjectURL;
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    /**
     * @scenario outcome=success
     * @effects handler_fetches_blob_with_token_and_saves_original_filename
     */
    it('코어 ApiClient 로 blob 을 받아 원본 파일명으로 저장한다 (토큰 동반 경로)', async () => {
        const blob = new Blob(['pdf']);
        const get = vi.fn().mockResolvedValue(blob);
        (window as any).G7Core = { api: { get } };

        let clicked: HTMLAnchorElement | null = null;
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            clicked = this;
        });

        const result = await downloadAttachmentHandler({
            params: { url: '/api/modules/sirsoft-page/pages/attachment/abcdef123456', filename: '회사소개서.pdf' },
        });

        expect(result).toBe(true);
        expect(get).toHaveBeenCalledWith('/api/modules/sirsoft-page/pages/attachment/abcdef123456', { responseType: 'blob' });
        expect(URL.createObjectURL).toHaveBeenCalledWith(blob);
        expect(clicked).not.toBeNull();
        expect(clicked!.download).toBe('회사소개서.pdf');
        expect(clicked!.href).toBe('blob:page-attachment');
        // 임시 링크와 object URL 은 남기지 않는다
        expect(document.querySelector('a[download]')).toBeNull();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:page-attachment');
    });

    /**
     * @scenario outcome=missing_url
     * @effects handler_skips_request_without_url
     */
    it('주소가 없으면 요청하지 않고 건너뛴다', async () => {
        const get = vi.fn();
        (window as any).G7Core = { api: { get } };

        const result = await downloadAttachmentHandler({ params: { url: '', filename: 'x.pdf' } });

        expect(result).toBe(false);
        expect(get).not.toHaveBeenCalled();
    });

    /**
     * @scenario outcome=failure
     * @effects handler_throws_on_failure
     */
    it('요청이 실패하면 예외를 던져 레이아웃의 실패 안내(onError)로 넘긴다', async () => {
        (window as any).G7Core = { api: { get: vi.fn().mockRejectedValue(new Error('403')) } };

        await expect(
            downloadAttachmentHandler({ params: { url: '/api/modules/sirsoft-page/pages/attachment/abcdef123456', filename: 'a.pdf' } })
        ).rejects.toThrow('403');
        expect(URL.createObjectURL).not.toHaveBeenCalled();
    });

    /**
     * @scenario outcome=failure
     * @effects handler_throws_on_failure
     */
    it('코어 ApiClient 가 없으면 예외를 던진다', async () => {
        (window as any).G7Core = {};

        await expect(
            downloadAttachmentHandler({ params: { url: '/api/modules/sirsoft-page/pages/attachment/abcdef123456', filename: 'a.pdf' } })
        ).rejects.toThrow();
    });
});
