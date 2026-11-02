/**
 * 약관 보기 단추의 페이지 모듈 가드 (#822 G1)
 *
 * @description
 * 마케팅 플러그인은 페이지 모듈을 필수가 아니라 선택 연동(`integrations`)으로 선언한다. 페이지 모듈이 꺼진
 * 사이트에서 약관 「보기」 단추를 누르면 `/api/modules/sirsoft-page/pages/{slug}` 가 404 로 끝나 빈 창만 뜬다.
 * 그래서 약관 본문을 부르는 단추는 모두 같은 가드 식을 함께 단다.
 *
 * - 가드 식은 한 모양만 쓴다: `(_global.activeModules?.includes('sirsoft-page') ?? true)`
 *   (활성 모듈 목록이 없는 옛 코어에서는 `undefined ?? true` = 보임 — 지금처럼 동작)
 * - 동의 체크박스는 가드하지 않는다(동의는 페이지 모듈과 무관하다).
 * - 소스(`.g7.html`)와 짝 JSON 이 같은 가드를 갖는다.
 *
 * @scenario-manifest tests/scenarios/terms-page-module-guard.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

import registerLayout from '../../../extensions/user-marketing-register.json';
import profileLayout from '../../../extensions/user-marketing-profile.json';
import formLayout from '../../../extensions/user-marketing-form.json';
import pluginManifest from '../../../../plugin.json';

const GUARD = "(_global.activeModules?.includes('sirsoft-page') ?? true)";
const PAGE_API = '/api/modules/sirsoft-page/';
const EXTENSIONS_DIR = path.resolve(__dirname, '../../../extensions');

const LAYOUTS: Array<[string, any]> = [
    ['user-marketing-register', registerLayout],
    ['user-marketing-profile', profileLayout],
    ['user-marketing-form', formLayout],
];

/**
 * 노드 트리를 훑어 조건에 맞는 노드를 모은다(액션은 자식으로 보지 않는다).
 */
function collectNodes(node: any, predicate: (n: any) => boolean, out: any[] = []): any[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((child) => collectNodes(child, predicate, out));
        return out;
    }
    if (predicate(node)) out.push(node);
    for (const [key, value] of Object.entries(node)) {
        if (key === 'actions') continue;
        if (value && typeof value === 'object') collectNodes(value, predicate, out);
    }
    return out;
}

/**
 * 노드의 액션(하위 sequence 포함)이 페이지 모듈 API 를 부르는지 판정한다.
 */
function callsPageApi(node: any): boolean {
    return JSON.stringify(node.actions ?? []).includes(PAGE_API);
}

/**
 * 가드 식을 실제로 평가한다 — `{{ … }}` 를 벗기고 주어진 문맥으로 계산한다.
 */
function evaluate(expression: string, scope: Record<string, unknown>): unknown {
    const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
    const names = Object.keys(scope);
    // eslint-disable-next-line no-new-func
    return new Function(...names, `return (${body});`)(...names.map((n) => scope[n]));
}

describe('manifest — 페이지 모듈은 선택 연동', () => {
    /**
     * @scenario page_module_state=inactive
     * @effects marketing_manifest_declares_page_as_integration
     */
    it('dependencies 에 sirsoft-page 가 없고 integrations 에 버전 제약으로 있다', () => {
        expect((pluginManifest as any).dependencies?.modules?.['sirsoft-page']).toBeUndefined();
        expect((pluginManifest as any).integrations?.modules?.['sirsoft-page']).toBe('>=1.0.0');
    });
});

describe.each(LAYOUTS)('%s — 약관 보기 단추', (name, layout) => {
    const pageCallers = collectNodes(layout, (n) => Array.isArray(n.actions) && callsPageApi(n));

    /**
     * @scenario page_module_state=active
     * @effects terms_view_buttons_guarded_by_page_module
     */
    it('페이지 모듈 API 를 부르는 단추가 4개다(전체 동의·채널·제3자 제공·정보 공개)', () => {
        expect(pageCallers).toHaveLength(4);
        pageCallers.forEach((node) => expect(node.name).toBe('Button'));
    });

    /**
     * @scenario page_module_state=inactive
     * @effects terms_view_buttons_guarded_by_page_module
     */
    it('모든 단추의 if 에 같은 가드 식이 있다', () => {
        pageCallers.forEach((node) => {
            expect(typeof node.if).toBe('string');
            expect(node.if).toContain(` && ${GUARD}}}`);
            expect(node.if).toContain('terms_slug_set');
        });
    });

    /**
     * @scenario page_module_state=unknown_old_core
     * @effects terms_view_guard_evaluates_per_page_module_state
     */
    it('가드 식이 옛 코어·페이지 켜짐·페이지 꺼짐에서 기대대로 계산된다', () => {
        pageCallers.forEach((node) => {
            const scope = {
                marketing_settings: {
                    data: {
                        marketing_consent_terms_slug_set: true,
                        third_party_consent_terms_slug_set: true,
                        info_disclosure_terms_slug_set: true,
                    },
                },
                channel: { terms_slug_set: true },
                _local: {
                    form: {
                        marketing_consent_terms_slug_set: true,
                        third_party_consent_terms_slug_set: true,
                        info_disclosure_terms_slug_set: true,
                    },
                    profileEditForm: {
                        marketing_consent_terms_slug_set: true,
                        third_party_consent_terms_slug_set: true,
                        info_disclosure_terms_slug_set: true,
                    },
                },
            };
            expect(evaluate(node.if, { ...scope, _global: {} })).toBe(true);
            expect(evaluate(node.if, { ...scope, _global: { activeModules: ['sirsoft-page'] } })).toBe(true);
            expect(evaluate(node.if, { ...scope, _global: { activeModules: ['sirsoft-board'] } })).toBe(false);
        });
    });

    /**
     * @scenario page_module_state=inactive
     * @effects consent_checkboxes_not_guarded_by_page_module
     */
    it('동의 체크박스는 페이지 모듈 가드를 달지 않는다', () => {
        const checkboxes = collectNodes(layout, (n) => n.name === 'Checkbox');
        expect(checkboxes.length).toBeGreaterThan(0);
        checkboxes.forEach((node) => {
            expect(String(node.if ?? '')).not.toContain('sirsoft-page');
        });
    });

    /**
     * @scenario page_module_state=inactive
     * @effects terms_view_guard_present_in_markup_source
     */
    it('소스 .g7.html 도 같은 가드를 4번 쓴다', () => {
        const source = fs.readFileSync(path.join(EXTENSIONS_DIR, `${name}.g7.html`), 'utf8');
        const guardInMarkup = `&amp;&amp; ${GUARD}}}`;
        expect(source.split(guardInMarkup).length - 1).toBe(4);
        expect(source.split(`<api-call target="${PAGE_API}`).length - 1).toBe(4);
    });
});
