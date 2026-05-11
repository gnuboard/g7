/**
 * 이커머스 모듈 레이아웃 — 활성 언어팩 locale fallback / 정적 ko/en/ja 탭 회귀 가드
 *
 * 이슈 #263 후속 — 두 패턴의 회귀 차단:
 *
 * (1) 단순 fallback: `?? 'ko'` 하드코딩이 활성 locale 무관하게 ko 를 기본 선택/표시함
 *     - _tab_language_currency.json: default_language picker 의 value fallback
 *
 * (2) 정적 ko/en/ja 3버튼 구조: 새 언어팩(ja/zh 등) 추가 시 미반영
 *     - _partial_banner_form.json / _partial_banner_detail.json
 *     - _partial_common_info_form.json / _partial_common_info_detail.json
 *     → $locales iteration 으로 동적 그리도록 재구조화 (참조: sirsoft-page admin_page_detail unified_lang_tabs)
 */

import { describe, it, expect } from 'vitest';

import tabLanguageCurrency from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_language_currency.json';
import bannerForm from '../../../layouts/admin/partials/admin_ecommerce_main_banner_index/_partial_banner_form.json';
import bannerDetail from '../../../layouts/admin/partials/admin_ecommerce_main_banner_index/_partial_banner_detail.json';
import commonInfoForm from '../../../layouts/admin/partials/admin_ecommerce_product_common_info_index/_partial_common_info_form.json';
import commonInfoDetail from '../../../layouts/admin/partials/admin_ecommerce_product_common_info_index/_partial_common_info_detail.json';

describe('이커머스 모듈 — 단순 locale fallback 회귀 가드 (#263 후속)', () => {
    it("default_language picker 의 fallback 이 정적 'ko' 가 아닌 $locale 을 우선 사용함", () => {
        const layoutStr = JSON.stringify(tabLanguageCurrency);
        // 활성 ja 환경에서 환경설정 첫 진입 시에도 ko 가 기본 선택되던 회귀 차단
        expect(layoutStr).not.toMatch(/default_language \?\? 'ko'/);
    });
});

describe('이커머스 모듈 — 정적 ko/en/ja 언어 탭 → $locales iteration 회귀 가드 (#263 후속)', () => {
    const dynamicTabLayouts: Array<[string, unknown]> = [
        ['_partial_banner_form.json', bannerForm],
        ['_partial_banner_detail.json', bannerDetail],
        ['_partial_common_info_form.json', commonInfoForm],
        ['_partial_common_info_detail.json', commonInfoDetail],
    ];

    it.each(dynamicTabLayouts)(
        '%s — 정적 activeLanguageTab === ko/en/ja 분기를 사용하지 않음 (iteration 으로 그려야 함)',
        (_name, layout) => {
            const layoutStr = JSON.stringify(layout);
            // 정적 분기 패턴 금지 — ja 외 신규 언어팩이 추가되어도 동적 반영되어야 함
            expect(layoutStr).not.toMatch(/_local\.activeLanguageTab === 'ko'/);
            expect(layoutStr).not.toMatch(/_local\.activeLanguageTab === 'en'/);
            expect(layoutStr).not.toMatch(/_local\.activeLanguageTab === 'ja'/);
        }
    );

    it.each(dynamicTabLayouts)(
        '%s — 언어 탭 버튼이 $locales iteration 으로 그려짐',
        (_name, layout) => {
            const layoutStr = JSON.stringify(layout);
            // iteration source 가 $locales 이고, item_var 로 활성 비교를 수행하는 패턴이 존재해야 함
            expect(layoutStr).toMatch(/"source":\s*"\$locales"/);
        }
    );
});
