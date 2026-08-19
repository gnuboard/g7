/**
 * 보안 설정 탭 — 비밀번호 정책 입력 계약 테스트 (#493 C4)
 *
 * @description
 * 비밀번호 최소 길이 입력의 경계값은 서버가 내려주는 `_meta.limits` 를 바인딩해야 한다.
 * 리터럴로 두면 저장 규칙(`SaveSettingsRequest`)이 바뀌어도 화면이 따라오지 않아,
 * 화면이 권하는 값이 저장 단계에서 422 가 되거나 반대로 저장 가능한 값을 입력조차 못 한다.
 *
 * 바인딩이 있다는 것만으로는 부족하다 — 참조하는 한계값 키가 서버 응답에 실제로 존재해야
 * 한다. `_meta.limits` 는 `config('core.settings_limits')` 를 그대로 실어 내리므로, 오타난
 * 키는 예외 없이 조용히 `undefined` 가 되어 항상 폴백으로 떨어진다. 그러면 바인딩이 있어도
 * 리터럴을 쓴 것과 결과가 같아지고, 이 계약이 막으려던 결함이 그대로 재현된다.
 * 그래서 세 지점(설정 파일 · 서버 규칙 · 화면 바인딩)이 같은 키·같은 값을 가리키는지 대조한다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import tabSecurity from '../../layouts/partials/admin_settings/_tab_security.json';

/**
 * 저장소 루트를 위로 훑어 찾는다 (`_bundled` 여부로 깊이가 달라지므로 상대경로 고정 금지).
 *
 * @returns 저장소 루트 절대경로
 */
function repositoryRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'config/core.php'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('config/core.php 를 가진 저장소 루트를 찾지 못했습니다.');
}

const root = repositoryRoot();
const configSource = fs.readFileSync(path.join(root, 'config/core.php'), 'utf-8');
const requestSource = fs.readFileSync(
    path.join(root, 'app/Http/Requests/Settings/SaveSettingsRequest.php'),
    'utf-8',
);

/**
 * `config/core.php` 의 `settings_limits` 블록을 키 → 값으로 읽는다.
 *
 * @returns 한계값 키 맵
 */
function configuredLimits(): Record<string, number> {
    const block = /'settings_limits'\s*=>\s*\[([\s\S]*?)\n\s*\],/.exec(configSource);
    expect(block, "config/core.php 에서 'settings_limits' 블록을 찾지 못했습니다.").not.toBeNull();

    const limits: Record<string, number> = {};

    for (const line of block![1].split('\n')) {
        const matched = /'([a-z0-9_]+)'\s*=>\s*(-?[\d.]+)\s*,/.exec(line);
        if (matched) {
            limits[matched[1]] = Number(matched[2]);
        }
    }

    expect(Object.keys(limits).length).toBeGreaterThan(0);

    return limits;
}

/**
 * 레이아웃 트리에서 props.name 이 일치하는 Input 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param fieldName 찾을 필드명
 * @returns 찾은 노드 또는 null
 */
function findByName(node: any, fieldName: string): any {
    if (!node || typeof node !== 'object') return null;

    if (node.name === 'Input' && node.props?.name === fieldName) {
        return node;
    }

    for (const child of Object.values(node)) {
        if (Array.isArray(child)) {
            for (const item of child) {
                const found = findByName(item, fieldName);
                if (found) return found;
            }
        } else if (child && typeof child === 'object') {
            const found = findByName(child, fieldName);
            if (found) return found;
        }
    }

    return null;
}

/**
 * 화면 바인딩 표현식에서 참조 한계값 키와 폴백을 뽑는다.
 *
 * @param expression 레이아웃 min/max 표현식
 * @returns 한계값 키와 폴백 숫자
 */
function parseBinding(expression: string): { limitKey: string; fallback: number } {
    const matched = /_meta\??\.limits\??\.([a-z0-9_]+)\s*\?\?\s*(-?[\d.]+)/.exec(expression);
    expect(matched, `한계값 바인딩 형태가 아닙니다: ${expression}`).not.toBeNull();

    return { limitKey: matched![1], fallback: Number(matched![2]) };
}

/**
 * @scenario entry_point=admin_update_user, min_length_setting=raised, special_char_setting=required
 * @effects admin_screen_min_length_bounds_follow_settings_limits
 */
describe('보안 설정 — 비밀번호 최소 길이', () => {
    const limits = configuredLimits();
    const input = findByName(tabSecurity, 'security.password_min_length');

    it('입력 필드가 존재한다', () => {
        expect(input).not.toBeNull();
        expect(input.props.type).toBe('number');
    });

    it.each([
        ['min', 'security_password_min_length_min'],
        ['max', 'security_password_min_length_max'],
    ])('%s 이 서버가 실제로 내려주는 한계값 키를 가리킨다', (bound, expectedKey) => {
        const { limitKey } = parseBinding(String(input.props[bound]));

        // 오타난 키는 응답에 없어 항상 undefined → 영구 폴백. 바인딩 존재만으로는 못 잡는다.
        expect(limits, `config/core.php 에 '${limitKey}' 가 없습니다 — 화면이 영구히 폴백으로 떨어집니다.`)
            .toHaveProperty(limitKey);
        expect(limitKey).toBe(expectedKey);
    });

    it.each([
        ['min', 'security_password_min_length_min'],
        ['max', 'security_password_min_length_max'],
    ])('%s 의 폴백이 설정 파일 값과 같다', (bound, key) => {
        const { fallback } = parseBinding(String(input.props[bound]));

        expect(fallback).toBe(limits[key]);
    });

    it('서버 저장 규칙이 화면과 같은 한계값 키를 읽는다', () => {
        const rule = /'security\.password_min_length'\s*=>\s*\[([\s\S]*?)\],/.exec(requestSource);
        expect(rule, 'SaveSettingsRequest 에서 password_min_length 규칙을 찾지 못했습니다.').not.toBeNull();

        for (const bound of ['min', 'max'] as const) {
            const { limitKey } = parseBinding(String(input.props[bound]));
            expect(rule![1]).toContain(`core.settings_limits.${limitKey}`);
        }
    });

    it('특수문자 필수 토글이 함께 노출된다', () => {
        const toggle = findByName(tabSecurity, 'security.require_password_special_char')
            ?? JSON.stringify(tabSecurity).includes('security.require_password_special_char');

        expect(toggle).toBeTruthy();
    });
});
