/**
 * @file assetOrigin.directAsset.test.ts
 * @description 인증 XHR 없이 그대로 써야 하는 자산 URL 판정(isDirectAssetUrl)
 *
 * 레이아웃 편집기에서 쇼핑몰 브랜드 관리·상품 폼을 열면 표본 그림이 `data:image/svg+xml,…` 인데,
 * 첨부 썸네일·업로더가 이를 인증 XHR 로 가져오려 해 API 기본 주소가 붙은 `/api/data:image/…` 404 가
 * 나고 그림이 비어 보였다(이커머스 전수 실측 S5, 2026-10-07). 문서 안에 이미 담긴 `data:`·`blob:` URL 은
 * 서버에 요청할 대상이 아니므로 교차 출처 URL 처럼 그대로 써야 한다. sirsoft-admin_basic 에만 있던 판정을
 * sirsoft-basic 에 이식했다(7.1.3 D19 — 사용자 글쓰기 업로더·갤러리가 data: 미리보기 주소를 XHR 로 보내 404).
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/asset-origin-direct-asset.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { isDirectAssetUrl, isCrossOriginAssetUrl } from '../assetOrigin';

describe('isDirectAssetUrl', () => {
    /**
     * @scenario case=c01
     * @effects direct_asset_urls_not_fetched
     */
    it('data: URL 은 그대로 쓴다', () => {
        expect(isDirectAssetUrl("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%3E%3C/svg%3E")).toBe(true);
        expect(isDirectAssetUrl('DATA:image/png;base64,iVBORw0KGgo=')).toBe(true);
    });

    /**
     * @scenario case=c02
     * @effects direct_asset_urls_not_fetched
     */
    it('blob: URL 은 그대로 쓴다', () => {
        expect(isDirectAssetUrl(`blob:${window.location.origin}/1f2e3d4c`)).toBe(true);
    });

    /**
     * @scenario case=c03
     * @effects direct_asset_urls_not_fetched
     */
    it('교차 출처 URL 은 기존 판정 그대로 직접 쓴다', () => {
        expect(isDirectAssetUrl('https://cdn.example.test/a.png')).toBe(isCrossOriginAssetUrl('https://cdn.example.test/a.png'));
        expect(isDirectAssetUrl('https://cdn.example.test/a.png')).toBe(true);
    });

    /**
     * @scenario case=c04
     * @effects direct_asset_urls_not_fetched
     */
    it('같은 출처 상대 경로는 인증 XHR 경로를 유지한다', () => {
        expect(isDirectAssetUrl('/api/attachments/1/download')).toBe(false);
        expect(isDirectAssetUrl('api/attachments/1/download')).toBe(false);
    });

    /**
     * @scenario case=c05
     * @effects direct_asset_urls_not_fetched
     */
    it('빈 값은 직접 사용 대상이 아니다', () => {
        expect(isDirectAssetUrl(undefined)).toBe(false);
        expect(isDirectAssetUrl(null)).toBe(false);
        expect(isDirectAssetUrl('')).toBe(false);
    });

    /**
     * @scenario case=c06
     * @effects direct_asset_urls_not_fetched
     */
    it('인증 그림을 가져오는 세 곳이 모두 isDirectAssetUrl 로 판정한다', () => {
        for (const file of ['../FileUploader/SortableThumbnailItem.tsx', '../FileUploader/useFileUploader.ts', '../ImageGallery.tsx']) {
            const source = readFileSync(resolve(__dirname, file), 'utf8');
            expect(source, file).toMatch(/isDirectAssetUrl\(/);
            expect(source, file).not.toMatch(/isCrossOriginAssetUrl\(/);
        }
    });

    /**
     * @scenario case=c07
     * @effects direct_asset_urls_not_fetched
     */
    it('인증이 필요한 그림이어도 data:·blob: 주소는 인증 요청 없이 내려받는다', async () => {
        const apiGet = vi.fn().mockResolvedValue(new Blob(['x']));
        (window as any).G7Core = { t: (key: string) => key, api: { get: apiGet }, toast: { error: vi.fn() } };
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(vi.fn());
        vi.resetModules();
        const { executeImageDownload } = await import('../ImageGallery');

        await executeImageDownload({ src: 'data:image/png;base64,iVBORw0KGgo=', filename: 'a.png', downloadRequiresAuth: true } as any);
        await executeImageDownload({ src: `blob:${window.location.origin}/1f2e`, filename: 'b.png', downloadRequiresAuth: true } as any);

        expect(apiGet).not.toHaveBeenCalled();
    });

    afterEach(() => {
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });
});
