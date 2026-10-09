// e2e:allow 표현식 1줄(설명 폴백 사슬) 수정의 단위 검증 — 같은 화면은 Playwright 브라우저로 ko/en 재실측해 결과를 이력(20261007_1905_710_KLM_M1)에 남김
/**
 * @file policy-snapshot-category-locale.test.ts
 * @description 정책 버전 스냅샷 창의 쿠키 카테고리 설명이 현재 로케일을 먼저 보는지
 *
 * 회귀 배경: 스냅샷 창은 카테고리 **이름**은 `label[$locale]` 로 고쳤지만 **설명**은
 * `description.ko ?? description.en` 그대로라, 영어로 보는 관리자에게 이름은 영어·설명은 한국어가
 * 섞여 나왔다(스냅샷에는 en 설명이 들어 있다). 쿠키 배너가 쓰는 폴백 사슬과 맞춘다.
 *
 * 표현식을 테스트에 옮겨 적지 않고 소스(`.g7.html`)에서 구운 짝 JSON 에서 꺼내 평가한다 —
 * 소스를 고치면 테스트가 함께 따라간다(cookie_banner.test.tsx 와 같은 방식).
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const SNAPSHOT_MODAL = path.resolve(
    __dirname,
    '../../../layouts/admin/partials/_shared/_policy_version_snapshot_modal.json',
);
const tree: unknown = JSON.parse(fs.readFileSync(SNAPSHOT_MODAL, 'utf8'));

/**
 * 배포되는 JSON 트리에서 카테고리 설명을 그리는 노드의 text 표현식을 찾는다.
 *
 * @returns 중괄호를 벗긴 표현식 문자열
 */
function findDescriptionExpression(): string {
    const stack: unknown[] = [tree];
    while (stack.length) {
        const node = stack.pop();
        if (Array.isArray(node)) {
            stack.push(...node);
            continue;
        }
        if (!node || typeof node !== 'object') continue;
        const text = (node as { text?: unknown }).text;
        if (typeof text === 'string' && text.includes('category?.description') && text.startsWith('{{')) {
            return text.replace(/^\{\{/, '').replace(/\}\}$/, '');
        }
        stack.push(...Object.values(node as Record<string, unknown>));
    }
    throw new Error('카테고리 설명 표현식을 스냅샷 창 JSON 에서 찾지 못했다');
}

/**
 * 표현식을 주어진 카테고리·로케일로 평가한다.
 *
 * @param category 스냅샷의 카테고리 항목
 * @param locale 화면 로케일($locale)
 * @returns 화면에 그려질 설명 문자열
 */
function renderDescription(category: Record<string, unknown>, locale: string): string {
    // eslint-disable-next-line no-new-func
    const fn = new Function('category', '$locale', 'return (' + findDescriptionExpression() + ');');
    return fn(category, locale) as string;
}

describe('정책 버전 스냅샷 창 — 카테고리 설명 로케일 해석', () => {
    const category = { key: 'analytics', description: { ko: '분석 설명', en: 'Analytics description' } };

    /**
     * @scenario viewer_locale=en, category_description=present_for_locale
     * @effects snapshot_category_description_uses_current_locale
     */
    it('영어로 보면 영어 설명을 쓴다', () => {
        expect(renderDescription(category, 'en')).toBe('Analytics description');
    });

    /**
     * @scenario viewer_locale=ko, category_description=present_for_locale
     * @effects snapshot_category_description_uses_current_locale
     */
    it('한국어로 보면 한국어 설명을 쓴다', () => {
        expect(renderDescription(category, 'ko')).toBe('분석 설명');
    });

    /**
     * @scenario viewer_locale=en, category_description=missing_for_locale
     * @effects snapshot_category_description_falls_back_when_locale_missing
     */
    it('그 언어 설명이 없으면 한국어 → 영어 순으로 대체한다', () => {
        expect(renderDescription({ key: 'x', description: { ko: '한국어만' } }, 'ja')).toBe('한국어만');
        expect(renderDescription({ key: 'x', description: { en: 'English only' } }, 'ja')).toBe('English only');
    });

    /**
     * @scenario viewer_locale=ko, category_description=missing_for_locale
     * @effects snapshot_category_description_falls_back_when_locale_missing
     */
    it('옛 스냅샷의 문자열 설명은 그대로 쓴다', () => {
        expect(renderDescription({ key: 'x', description: '평문 설명' }, 'en')).toBe('평문 설명');
    });
});
