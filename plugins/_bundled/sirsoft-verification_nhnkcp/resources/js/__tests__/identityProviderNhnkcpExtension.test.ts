/**
 * @file identityProviderNhnkcpExtension.test.ts
 * @description 본인확인 안내 창 확장 조각 구조 검증
 *
 * 코어 모달의 provider 슬롯에 다른 본인확인 플러그인과 공존 가능한 방식으로 붙는지,
 * 상태 분기(시작 전/진행 중)가 본 provider 로 한정되는지, 화면 문구가 모두 다국어 키로
 * 작성되고 그 키가 실제 언어 파일에 존재하는지 확인한다.
 */

import { describe, it, expect } from 'vitest';
import extension from '../../extensions/identity_provider_nhnkcp.json';
import cardExtension from '../../extensions/mypage_identity_card.json';
import settingsLayout from '../../layouts/admin/plugin_settings.json';
import ko from '../../lang/ko.json';
import en from '../../lang/en.json';

const NAMESPACE = 'sirsoft-verification_nhnkcp';

/** 조각에서 사용된 모든 Icon 의 glyph 이름을 수집한다. */
function collectIconNames(node: unknown, names: string[] = []): string[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collectIconNames(child, names));

        return names;
    }
    if (node && typeof node === 'object') {
        const record = node as Record<string, unknown>;
        if (record.name === 'Icon' && record.props && typeof record.props === 'object') {
            const glyph = (record.props as Record<string, unknown>).name;
            if (typeof glyph === 'string') {
                names.push(glyph);
            }
        }
        Object.values(record).forEach((child) => collectIconNames(child, names));
    }

    return names;
}

/** 레이아웃 조각에서 사용된 모든 `$t:` 키를 수집한다. */
function collectI18nKeys(node: unknown, keys: Set<string> = new Set()): Set<string> {
    if (typeof node === 'string') {
        const matches = node.match(/\$t:[A-Za-z0-9_.-]+/g) ?? [];
        matches.forEach((match) => keys.add(match.slice(3)));

        return keys;
    }
    if (node && typeof node === 'object') {
        Object.values(node as Record<string, unknown>).forEach((child) => collectI18nKeys(child, keys));
    }

    return keys;
}

/** 점 표기 경로로 번역 값을 찾는다. */
function lookup(dictionary: Record<string, unknown>, path: string): unknown {
    return path.split('.').reduce<unknown>((carry, segment) => {
        if (carry && typeof carry === 'object') {
            return (carry as Record<string, unknown>)[segment];
        }

        return undefined;
    }, dictionary);
}

describe('본인확인 안내 창 확장 조각', () => {
    // @scenario surface=identity_modal,concern=injection_contract
    // @effects extension_appends_to_core_provider_slot
    it('코어 provider 슬롯에 공존 가능한 방식으로 주입된다', () => {
        expect(extension.extension_point).toBe('identity_provider_ui:provider');
        // replace 는 다른 본인확인 플러그인의 UI 까지 지워 공존이 불가능하다.
        expect(extension.mode).toBe('append');
    });

    // @scenario surface=identity_modal,concern=injection_contract
    // @effects state_branches_are_gated_by_provider_id
    it('모든 상태 분기가 본 provider 로 한정된다', () => {
        expect(extension.components).toHaveLength(2);

        extension.components.forEach((component: Record<string, any>) => {
            expect(component.if).toContain("_global.identityChallenge?.provider_id === 'nhnkcp'");
        });

        const [initial, inProgress] = extension.components as Array<Record<string, any>>;
        expect(initial.if).toContain('!(_global.identityChallenge?.providerInProgress)');
        expect(inProgress.if).toContain('(_global.identityChallenge?.providerInProgress)');
    });

    // @scenario surface=identity_modal,concern=injection_contract
    // @effects start_button_dispatches_plugin_handler
    it('시작 버튼이 본 플러그인 핸들러를 호출한다', () => {
        const serialized = JSON.stringify(extension);

        expect(serialized).toContain(`${NAMESPACE}.startAuth`);
    });

    // @scenario surface=mypage_card,concern=injection_contract
    // @effects mypage_card_uses_plugin_api_data_source
    it('마이페이지 카드가 본 플러그인 API 를 데이터 소스로 사용한다', () => {
        expect(cardExtension.data_sources[0].id).toBe('nhnkcpRecord');
        expect(cardExtension.data_sources[0].endpoint).toBe(`/api/plugins/${NAMESPACE}/me/identity/nhnkcp`);
        expect(cardExtension.data_sources[0].auth_required).toBe(true);
    });

    // @scenario surface=identity_modal,concern=injection_contract
    // @effects icon_names_carry_no_style_prefix
    // @scenario surface=mypage_card,concern=injection_contract
    // @effects icon_names_carry_no_style_prefix
    it('Icon 이름에 스타일 접두사를 붙이지 않는다', () => {
        const names = [
            ...collectIconNames(extension),
            ...collectIconNames(cardExtension),
            ...collectIconNames(settingsLayout),
        ];

        expect(names.length).toBeGreaterThan(0);

        names.forEach((glyph) => {
            // Icon 은 선행 `fa-` 만 떼고 나머지를 통째로 `fa-` 뒤에 붙인다. 접두사를 함께 적으면
            // `fa-fas` 같은 존재하지 않는 클래스가 만들어진다 — 스타일은 iconStyle prop 소관.
            expect(glyph, `스타일 접두사가 붙은 아이콘: ${glyph}`).not.toMatch(/^(fas|far|fab|fal|fad)\s/);
        });
    });

    // @scenario surface=identity_modal,concern=i18n_completeness
    // @effects all_labels_resolve_in_ko_and_en
    // @scenario surface=mypage_card,concern=i18n_completeness
    // @effects all_labels_resolve_in_ko_and_en
    it('화면 문구는 모두 다국어 키이며 ko/en 에 존재한다', () => {
        const keys = [
            ...collectI18nKeys(extension),
            ...collectI18nKeys(cardExtension),
        ];

        expect(keys.length).toBeGreaterThan(0);

        keys.forEach((key) => {
            expect(key.startsWith(`${NAMESPACE}.`)).toBe(true);
            const path = key.slice(NAMESPACE.length + 1);

            expect(lookup(ko as Record<string, unknown>, path), `ko 누락: ${key}`).toBeTruthy();
            expect(lookup(en as Record<string, unknown>, path), `en 누락: ${key}`).toBeTruthy();
        });
    });
});
