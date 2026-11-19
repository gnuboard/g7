/**
 * @file admin-product-seo-preview-image-alt.test.ts
 * @description 관리자 상품 폼 SEO 탭 — 검색결과 미리보기 이미지의 대체 텍스트가 다국어 키인지 (#869 검토)
 *
 * 배경: 미리보기 이미지에 썸네일 규격을 붙이면서 대체 텍스트가 고정 영문 `OG Image` 로 남아, 한국어·일본어 화면의
 * 화면 낭독기가 영문을 읽었다. 굽힌 `_partial_seo_settings.json` 의 alt 가 모듈 다국어 키이고 ko·en·ja 세 언어에
 * 값이 있는지 고정한다.
 */

import { describe, it, expect } from 'vitest';
import seoPartial from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_seo_settings.json';
import productKo from '../../../lang/partial/ko/admin/product.json';
import productEn from '../../../lang/partial/en/admin/product.json';
import productJa from '../../../../../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/product.json';

describe('상품 폼 SEO 탭 미리보기 이미지 대체 텍스트', () => {
    // @scenario screen=admin_product_form_seo_preview
    // @effects admin_product_seo_preview_alt_translated
    it('검색결과 미리보기 이미지의 alt 는 고정 영문이 아니라 다국어 키이고 세 언어에 값이 있다', () => {
        const s = JSON.stringify(seoPartial);
        expect(s).not.toContain('"alt":"OG Image"');
        expect(s).toContain('"alt":"$t:sirsoft-ecommerce.admin.product.seo.preview_image_alt"');
        for (const [locale, bundle] of [['ko', productKo], ['en', productEn], ['ja', productJa]] as const) {
            expect(typeof (bundle as any).seo?.preview_image_alt, locale).toBe('string');
        }
    });
});
