/**
 * @file downloadFile.test.ts
 * @description sirsoft-ecommerce 파일 내려받기 핸들러 — 토큰 동반 blob 요청(GET/POST)·지정 파일명 저장·실패 시 예외.
 *
 * 관리자 주문 목록의 엑셀 내려받기 창이 접두어 없는 `downloadFile` 을 불렀는데 그런 핸들러는 코어에도
 * 관리자 템플릿에도 없어 눌러도 파일이 저장되지 않았다(레이아웃 검사 「미등록 핸들러」 경고). 또 코어
 * apiCall 은 응답을 JSON 으로만 읽어 blob 을 넘겨주지 못한다. 이 모듈이 요청과 저장을 함께 하는 핸들러를 소유한다.
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { downloadFileHandler } from '../downloadFile';

describe('sirsoft-ecommerce downloadFile 핸들러', () => {
    const originalCreateObjectURL = URL.createObjectURL;
    const originalRevokeObjectURL = URL.revokeObjectURL;
    let originalG7Core: unknown;

    beforeEach(() => {
        originalG7Core = (window as any).G7Core;
        URL.createObjectURL = vi.fn().mockReturnValue('blob:orders-excel');
        URL.revokeObjectURL = vi.fn();
    });

    afterEach(() => {
        URL.createObjectURL = originalCreateObjectURL;
        URL.revokeObjectURL = originalRevokeObjectURL;
        (window as any).G7Core = originalG7Core;
        vi.restoreAllMocks();
    });

    /**
     * @scenario outcome=success
     * @effects handler_posts_body_with_token_and_saves_blob_as_filename
     */
    it('POST 로 본문을 실어 blob 을 받아 지정한 파일명으로 저장한다 (토큰 동반 경로)', async () => {
        const blob = new Blob(['xlsx']);
        const post = vi.fn().mockResolvedValue(blob);
        const get = vi.fn();
        (window as any).G7Core = { api: { get, post } };

        let clicked: HTMLAnchorElement | null = null;
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            clicked = this;
        });

        const body = { type: 'selected', order_ids: [3, 7], filters: {} };
        const result = await downloadFileHandler({
            params: {
                url: '/api/modules/sirsoft-ecommerce/admin/orders/export-excel',
                method: 'post',
                body,
                filename: 'orders_20260928_101500.xlsx',
            },
        });

        expect(result).toBe(true);
        expect(post).toHaveBeenCalledWith('/api/modules/sirsoft-ecommerce/admin/orders/export-excel', body, { responseType: 'blob' });
        expect(get).not.toHaveBeenCalled();
        expect(URL.createObjectURL).toHaveBeenCalledWith(blob);
        expect(clicked).not.toBeNull();
        expect(clicked!.download).toBe('orders_20260928_101500.xlsx');
        expect(clicked!.href).toBe('blob:orders-excel');
        // 임시 링크와 object URL 은 남기지 않는다
        expect(document.querySelector('a[download]')).toBeNull();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:orders-excel');
    });

    /**
     * @scenario outcome=success
     * @effects handler_posts_body_with_token_and_saves_blob_as_filename
     */
    it('method 가 없으면 GET 으로 받는다', async () => {
        const get = vi.fn().mockResolvedValue(new Blob(['x']));
        const post = vi.fn();
        (window as any).G7Core = { api: { get, post } };
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);

        await downloadFileHandler({ params: { url: '/api/x/file', filename: 'a.xlsx' } });

        expect(get).toHaveBeenCalledWith('/api/x/file', { responseType: 'blob' });
        expect(post).not.toHaveBeenCalled();
    });

    /**
     * @scenario outcome=missing_url
     * @effects handler_skips_request_without_url
     */
    it('주소가 없으면 요청하지 않고 건너뛴다', async () => {
        const post = vi.fn();
        (window as any).G7Core = { api: { get: vi.fn(), post } };

        const result = await downloadFileHandler({ params: { url: '', method: 'POST', filename: 'a.xlsx' } });

        expect(result).toBe(false);
        expect(post).not.toHaveBeenCalled();
    });

    /**
     * @scenario outcome=failure
     * @effects handler_throws_on_failure
     */
    it('요청이 실패하면 예외를 던져 레이아웃의 실패 안내(onError)로 넘긴다', async () => {
        (window as any).G7Core = { api: { get: vi.fn(), post: vi.fn().mockRejectedValue(new Error('404')) } };

        await expect(
            downloadFileHandler({ params: { url: '/api/modules/sirsoft-ecommerce/admin/orders/export-excel', method: 'POST', filename: 'a.xlsx' } })
        ).rejects.toThrow('404');
        expect(URL.createObjectURL).not.toHaveBeenCalled();
    });

    /**
     * @scenario outcome=failure
     * @effects handler_throws_on_failure
     */
    it('코어 ApiClient 가 없으면 예외를 던진다', async () => {
        (window as any).G7Core = {};

        await expect(
            downloadFileHandler({ params: { url: '/api/x/file', method: 'POST', filename: 'a.xlsx' } })
        ).rejects.toThrow();
    });
});
