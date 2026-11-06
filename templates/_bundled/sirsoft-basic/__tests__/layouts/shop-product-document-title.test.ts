/**
 * @file shop-product-document-title.test.ts
 * @description 상품 상세(`shop/show`) 방문자 탭 제목이 검색엔진 `<title>` 과 같은 답을 내는지 — 실제 레이아웃 선언(짝 .json)과
 *   실제 엔진 식 평가(DataBindingEngine)로 잰다.
 *
 * 종전 1: 상품 고유 검색 제목(meta_title)이 없는 상품에서 탭 제목에 상품명이 빠졌다. 레이아웃 `<vars product_name>` 이
 *   `$localized(meta_title) ?? $localized(name)` 였는데, 화면 `$localized(null)` 은 빈 글자를 돌려줘 `??` 가 상품명으로
 *   넘어가지 않았다(서버 평가기는 null 을 그대로 돌려줘 상품명으로 넘어간다). 틀이 `{commerce_name} | {product_name} 특가` 면
 *   탭에는 「특가」만 남았다. 같은 식을 쓰는 소셜 공유 제목(og)도 확장 틀이 비면 같은 이유로 비었다.
 * 종전 2: 상품을 받기 전(로딩 중)에는 상품명이 비어 있어도 확장 틀을 치환해 고정 글자(「특가」)만 남은 제목을 썼다.
 *   필수(required) 변수가 빈 틀은 아직 준비되지 않은 후보로 보고 건너뛴다 — 받는 동안은 화면 제목(라우트 제목)이다.
 *
 * @scenario-manifest tests/scenarios/shop-product-document-title.yaml
 *
 * @vitest-environment jsdom
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { DocumentTitleController } from '@core/routing/DocumentTitle';
import showLayout from '../../layouts/shop/show.json';

/** 서버 제목 계산 값 — 이커머스 모듈 seoVariables() 선언과 같은 모양 */
function payload(titleTemplate: string | null): any {
    return {
        suffix: '',
        site_name: { ko: '지세븐 상점', en: 'G7 Shop' },
        core_settings: { 'general.site_name': { ko: '지세븐 상점', en: 'G7 Shop' } },
        extensions: {
            'module:sirsoft-ecommerce': {
                variables: {
                    _common: { commerce_name: { source: 'setting', key: 'basic_info.shop_name' } },
                    product: {
                        product_name: { source: 'data', required: true },
                        product_description: { source: 'data' },
                    },
                },
                templates: titleTemplate === null ? {} : { product: { title: titleTemplate, description: '' } },
                // 쇼핑몰명을 비워 둔 사이트 — 빈 변수 앞 구분자째 지워진다
                settings: { 'basic_info.shop_name': '' },
            },
        },
    };
}

const ROUTE = {
    path: '/shop/products/P97',
    layout: 'shop/show',
    params: { product_code: 'P97' },
    query: {},
    meta: { title: '$t:shop.product.title' },
};

const TRANSLATIONS: Record<string, string> = { '$t:shop.product.title': '상품 상세' };

/**
 * 컨트롤러를 켜고 상품 상세로 들어가 데이터 컨텍스트를 연결한 뒤 탭 제목을 돌려줍니다.
 *
 * @param titleTemplate 이커머스 상품 제목 틀 (null 이면 틀 없음)
 * @param context 화면 데이터 컨텍스트
 * @returns document.title
 */
function tabTitle(titleTemplate: string | null, context: Record<string, any>): string {
    const controller = new DocumentTitleController();
    controller.configure({
        payload: payload(titleTemplate),
        getLocale: () => 'ko',
        translate: (text) => TRANSLATIONS[text] ?? text,
    });
    controller.beginRoute(ROUTE as any);
    const layout = JSON.parse(JSON.stringify(showLayout));
    controller.bind(layout, context);

    return document.title;
}

const product = (data: Record<string, unknown>) => ({ product: { data } });

describe('상품 상세 탭 제목 — 실제 shop/show 레이아웃 선언 + 실제 엔진 식 평가', () => {
    beforeEach(() => {
        (window as any).G7Config = { appConfig: { siteLocale: 'ko' } };
        document.title = '셸 제목';
    });

    afterEach(() => {
        delete (window as any).G7Config;
    });

    /**
     * @scenario case=no_meta_title_uses_product_name
     * @effects product_name_var_falls_back_to_name
     */
    it.each([
        ['meta_title 이 null', null],
        ['meta_title 이 빈 다국어 값', { ko: '', en: '' }],
    ])('%s 인 상품 — 틀의 {product_name} 이 상품명이다 (봇 <title> 과 같다)', (_label, metaTitle) => {
        const title = tabTitle('{commerce_name} | {product_name} 특가', product({ name: { ko: '배 1박스 (5kg)', en: 'Pear box' }, meta_title: metaTitle }));

        expect(title).toBe('배 1박스 (5kg) 특가 - 지세븐 상점');
    });

    /**
     * @scenario case=meta_title_wins
     * @effects product_meta_title_first_candidate
     */
    it('meta_title 이 있는 상품은 그 값이 제목이다 (종전과 같다)', () => {
        const title = tabTitle('{commerce_name} | {product_name} 특가', product({ name: { ko: '겨울 패딩 점퍼' }, meta_title: { ko: '겨울 패딩 점퍼 | 추천 상품' } }));

        expect(title).toBe('겨울 패딩 점퍼 | 추천 상품 - 지세븐 상점');
    });

    /**
     * @scenario case=no_template_og_uses_product_name
     * @effects og_title_falls_back_to_name
     */
    it('확장 제목 틀이 없으면 소셜 공유 제목(og) 후보가 상품명이다 — 라우트 제목으로 떨어지지 않는다', () => {
        const title = tabTitle(null, product({ name: { ko: '배 1박스 (5kg)' }, meta_title: null }));

        expect(title).toBe('배 1박스 (5kg) - 지세븐 상점');
    });

    /**
     * @scenario case=loading_skips_unready_template
     * @effects required_var_empty_template_skipped
     */
    it('상품을 받기 전에는 틀의 고정 글자(「특가」)가 아니라 화면 제목이다', () => {
        expect(tabTitle('{commerce_name} | {product_name} 특가', {})).toBe('상품 상세 - 지세븐 상점');
    });
});
