// e2e:allow 표현식(라벨 접미사 중복 방지) 수정의 단위 검증 — 같은 화면은 Playwright 브라우저로 ko/en 마이페이지 카드·동의 확인 창을 재실측해 이력(20261007_1905_710_KLM_M1)에 남김
/**
 * @file mypage-consent-label-suffix.test.ts
 * @description 마이페이지 쿠키 동의 카드가 항목 이름에 「쿠키」 접미사를 한 번만 붙이는지
 *
 * 회귀 배경: 카드는 운영자 라벨이 「기능」처럼 짧아도 「기능 쿠키」로 보이도록 `label_suffix`
 * (`{label} 쿠키`)를 붙인다(#430). 그런데 기본 카탈로그 ko 라벨은 이미 「필수 쿠키」라서 화면에
 * 「필수 쿠키 쿠키」가 나왔다. 라벨이 이미 접미사로 끝나면 그대로 쓴다.
 *
 * 표현식은 소스(`.g7.html`)에서 구운 짝 JSON 에서 꺼내 평가한다 — 소스를 고치면 테스트가 따라간다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const EXTENSION = path.resolve(__dirname, '../../../extensions/mypage_privacy_tab.json');
const tree: unknown = JSON.parse(fs.readFileSync(EXTENSION, 'utf8'));

/** 로케일별 `sirsoft-gdpr.mypage.privacy.label_suffix` 원문(번들 ko/en, ja 팩). */
const SUFFIX: Record<string, string> = {
    ko: '{label} 쿠키',
    en: '{label} cookies',
    ja: '{label}クッキー',
};

/**
 * 라벨 접미사를 다루는 표현식을 모두 찾는다.
 *
 * @returns 중괄호를 벗긴 표현식 목록
 */
function findLabelExpressions(): string[] {
    const found: string[] = [];
    const stack: unknown[] = [tree];
    while (stack.length) {
        const node = stack.pop();
        if (Array.isArray(node)) {
            stack.push(...node);
            continue;
        }
        if (!node || typeof node !== 'object') continue;
        const text = (node as { text?: unknown }).text;
        if (typeof text === 'string' && text.includes('label_suffix')) {
            found.push(text);
        }
        stack.push(...Object.values(node as Record<string, unknown>));
    }
    return found;
}

/**
 * 표현식을 평가한다. 엔진의 `$t(key)` 처럼 자리표시자를 치환하지 않은 원문을 돌려준다.
 *
 * @param text 노드 text(`{{…}}`)
 * @param locale 화면 로케일
 * @param scope 표현식이 참조하는 변수(consent, _global)
 * @returns 화면에 그려질 문자열
 */
function render(text: string, locale: string, scope: { consent?: unknown; _global?: unknown }): string {
    expect(text.startsWith('{{') && text.endsWith('}}')).toBe(true);
    const expr = text.slice(2, -2);
    const $t = (key: string) => (key === 'sirsoft-gdpr.mypage.privacy.label_suffix' ? SUFFIX[locale] : key);
    // eslint-disable-next-line no-new-func
    const fn = new Function('consent', '_global', '$t', 'return (' + expr + ');');
    return fn(scope.consent, scope._global ?? {}, $t) as string;
}

const expressions = findLabelExpressions();
const rowExpr = expressions.find((t) => t.includes('consent?.consent_label')) as string;
const modalExpr = expressions.find((t) => t.includes('_global.gdprGrantTargetLabel')) as string;

describe('마이페이지 쿠키 동의 카드 — 항목 이름 접미사', () => {
    it('카드 행과 동의 확인 창 두 곳이 같은 규칙을 쓴다', () => {
        expect(rowExpr).toBeTruthy();
        expect(modalExpr).toBeTruthy();
    });

    /**
     * @scenario category_label=already_suffixed, viewer_locale=ko
     * @effects mypage_consent_label_has_single_cookie_suffix
     */
    it('라벨이 이미 「쿠키」로 끝나면 접미사를 다시 붙이지 않는다', () => {
        expect(render(rowExpr, 'ko', { consent: { consent_label: '필수 쿠키', consent_key: 'cookie_necessary' } })).toBe('필수 쿠키');
        expect(render(modalExpr, 'ko', { _global: { gdprGrantTargetLabel: '기능 쿠키' } })).toBe('기능 쿠키');
    });

    /**
     * @scenario category_label=short, viewer_locale=ko
     * @effects mypage_consent_label_has_single_cookie_suffix
     */
    it('짧은 운영자 라벨에는 접미사를 붙인다', () => {
        expect(render(rowExpr, 'ko', { consent: { consent_label: '기능', consent_key: 'cookie_functional' } })).toBe('기능 쿠키');
        expect(render(modalExpr, 'ko', { _global: { gdprGrantTargetLabel: '분석' } })).toBe('분석 쿠키');
    });

    /**
     * @scenario category_label=short, viewer_locale=en
     * @effects mypage_consent_label_has_single_cookie_suffix
     */
    it('영어 기본 라벨에는 접미사를 붙인다', () => {
        expect(render(rowExpr, 'en', { consent: { consent_label: 'Strictly Necessary' } })).toBe('Strictly Necessary cookies');
        expect(render(modalExpr, 'en', { _global: { gdprGrantTargetLabel: 'Functional' } })).toBe('Functional cookies');
    });

    /**
     * @scenario category_label=already_suffixed, viewer_locale=en
     * @effects mypage_consent_label_has_single_cookie_suffix
     */
    it('영어 라벨이 이미 cookies 로 끝나면(대소문자 무관) 그대로 둔다', () => {
        expect(render(rowExpr, 'en', { consent: { consent_label: 'Analytics Cookies' } })).toBe('Analytics Cookies');
        expect(render(modalExpr, 'en', { _global: { gdprGrantTargetLabel: 'Marketing cookies' } })).toBe('Marketing cookies');
    });

    /**
     * @scenario category_label=short, viewer_locale=ja
     * @effects mypage_consent_label_has_single_cookie_suffix
     */
    it('접미사 앞에 공백이 없는 로케일에도 짧은 라벨에는 접미사를 붙인다', () => {
        expect(render(rowExpr, 'ja', { consent: { consent_label: '機能' } })).toBe('機能クッキー');
    });

    /**
     * @scenario category_label=already_suffixed, viewer_locale=ja
     * @effects mypage_consent_label_has_single_cookie_suffix
     */
    it('접미사 앞에 공백이 없는 로케일도 이미 붙은 접미사는 다시 붙이지 않는다', () => {
        expect(render(rowExpr, 'ja', { consent: { consent_label: '機能クッキー' } })).toBe('機能クッキー');
    });

    /**
     * @scenario category_label=missing, viewer_locale=ko
     * @effects mypage_consent_label_falls_back_to_key
     */
    it('라벨이 없으면 동의 항목 키에 접미사를 붙인다', () => {
        expect(render(rowExpr, 'ko', { consent: { consent_label: null, consent_key: 'cookie_custom' } })).toBe('cookie_custom 쿠키');
        expect(render(modalExpr, 'ko', { _global: { gdprGrantTargetLabel: null, gdprGrantTargetKey: 'cookie_custom' } })).toBe('cookie_custom 쿠키');
    });

    /**
     * @scenario category_label=missing, viewer_locale=en
     * @effects mypage_consent_label_falls_back_to_key
     */
    it('영어 화면에서도 라벨이 없으면 키에 접미사를 붙인다', () => {
        expect(render(rowExpr, 'en', { consent: { consent_label: null, consent_key: 'cookie_custom' } })).toBe('cookie_custom cookies');
    });

    /**
     * @scenario category_label=missing, viewer_locale=ja
     * @effects mypage_consent_label_falls_back_to_key
     */
    it('일본어 화면에서도 라벨이 없으면 키에 접미사를 붙인다', () => {
        expect(render(modalExpr, 'ja', { _global: { gdprGrantTargetKey: 'cookie_custom' } })).toBe('cookie_customクッキー');
    });
});
