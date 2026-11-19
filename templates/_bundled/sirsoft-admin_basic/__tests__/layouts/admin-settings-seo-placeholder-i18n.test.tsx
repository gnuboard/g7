/**
 * @file admin-settings-seo-placeholder-i18n.test.tsx
 * @description 환경설정 > SEO 탭 입력 칸 자리 표시 문구의 다국어 계약
 *
 * 타이틀 접미사·메타 키워드 칸의 예시 문구가 한국어로 박혀 있어 영어·일본어 화면에서도
 * 「 | 그누보드7」「CMS, 그누보드7, 오픈소스」가 보였다. 한국어 예시는 다국어 키로 두고
 * ko/en 언어 파일이 같은 키를 갖는다.
 *
 * @scenario-manifest tests/scenarios/admin-settings-seo-placeholder-i18n.yaml
 */

import { describe, it, expect } from 'vitest';

const seoTab = require('../../layouts/partials/admin_settings/_tab_seo.json');
const ko = require('../../lang/partial/ko/admin.json');
const en = require('../../lang/partial/en/admin.json');

/**
 * 트리에서 조건에 맞는 노드를 모두 수집합니다.
 *
 * @param node 시작 노드
 * @param predicate 노드 매칭 조건
 * @returns 매칭된 노드 배열
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const result: any[] = [];
    const visit = (n: any) => {
        if (!n || typeof n !== 'object') return;
        if (Array.isArray(n)) {
            n.forEach(visit);
            return;
        }
        if (predicate(n)) result.push(n);
        if (n.children) visit(n.children);
        if (n.slots) Object.values(n.slots).forEach(visit);
    };
    visit(node);
    return result;
}

/**
 * 점 경로로 언어 파일 값을 읽습니다.
 *
 * @param bundle 언어 파일 객체
 * @param key 점 경로 키
 * @returns 값 (없으면 undefined)
 */
function t(bundle: any, key: string): unknown {
    return key.split('.').reduce((acc: any, part) => (acc == null ? undefined : acc[part]), bundle);
}

const placeholderOf = (name: string): string | undefined =>
    collectNodes(seoTab, (n) => n?.props?.name === name)[0]?.props?.placeholder;

/**
 * 칸의 자리 표시 문구가 다국어 키이고 ko/en 에 그 키가 있는지 단언합니다.
 *
 * @param name 칸 name
 * @param key 다국어 키
 */
function expectTranslatedPlaceholder(name: string, key: string): void {
    expect(placeholderOf(name), name).toBe(`$t:${key}`);
    const path = key.replace(/^admin\./, '');
    expect(t(ko, path), `ko ${key}`).toBeTypeOf('string');
    expect(t(en, path), `en ${key}`).toBeTypeOf('string');
    expect(String(t(en, path)), `en ${key}`).not.toMatch(/[가-힣]/);
}

describe('환경설정 > SEO 탭 > 자리 표시 문구', () => {
    // @scenario field=other
    // @effects placeholder_has_no_hardcoded_korean
    it('어느 입력 칸의 placeholder 에도 한국어를 직접 쓰지 않는다 (언어 중립 예시는 그대로)', () => {
        const fields = collectNodes(seoTab, (n) => typeof n?.props?.placeholder === 'string');

        expect(fields.length).toBeGreaterThan(0);
        for (const field of fields) {
            expect(field.props.placeholder, String(field.props?.name ?? field.id)).not.toMatch(/[가-힣]/);
        }
    });

    // @scenario field=meta_title_suffix
    // @effects placeholder_follows_locale, placeholder_has_no_hardcoded_korean
    it('타이틀 접미사 예시는 다국어 키이고 ko/en 에 모두 있다', () => {
        expectTranslatedPlaceholder('seo.meta_title_suffix', 'admin.settings.seo.meta_title_suffix_placeholder');
    });

    // @scenario field=meta_keywords
    // @effects placeholder_follows_locale, placeholder_has_no_hardcoded_korean
    it('메타 키워드 예시는 다국어 키이고 ko/en 에 모두 있다', () => {
        expectTranslatedPlaceholder('seo.meta_keywords', 'admin.settings.seo.meta_keywords_placeholder');
    });
});
