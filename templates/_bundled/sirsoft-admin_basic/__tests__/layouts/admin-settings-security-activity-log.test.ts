/**
 * 보안 설정 탭 — 활동 로그(접속기록) 보관 카드 계약 테스트 (#736 D14)
 *
 * @description
 * 보관 기간 칸의 경계값은 서버가 내려주는 `_meta.limits` 를 바인딩해야 한다 — 하한 365일은 법정 최소라
 * 화면이 그보다 작은 값을 권하면 저장 단계에서 422 가 된다. 바인딩이 가리키는 키가 설정 파일에 실제로 있고
 * 서버 저장 규칙이 같은 키를 읽는지(세 지점 정합)와, 카드가 접근 제한 카드 뒤·앱 키 카드 앞에 들어가는지,
 * 카드가 쓰는 다국어 키가 ko·en 에 모두 있는지 본다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import tabSecurity from '../../layouts/partials/admin_settings/_tab_security.json';
import activityLogCard from '../../layouts/partials/admin_settings/_tab_security_activity_log.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

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
 * `config/core.php` 의 `settings_limits` 블록에서 한계값 하나를 읽는다.
 *
 * @param key 한계값 키
 * @returns 값 (없으면 undefined)
 */
function configuredLimit(key: string): number | undefined {
    const block = /'settings_limits'\s*=>\s*\[([\s\S]*?)\n\s*\],/.exec(configSource);
    expect(block, "config/core.php 에서 'settings_limits' 블록을 찾지 못했습니다.").not.toBeNull();

    const matched = new RegExp(String.raw`'${key}'\s*=>\s*(-?[\d.]+)\s*,`).exec(block![1]);

    return matched ? Number(matched[1]) : undefined;
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
        const found = Array.isArray(child)
            ? child.map((item) => findByName(item, fieldName)).find(Boolean)
            : findByName(child, fieldName);
        if (found) return found;
    }

    return null;
}

/**
 * 점 경로로 다국어 값을 읽는다 — `admin.json` 은 `admin.` 네임스페이스 아래 내용이라 첫 마디를 뗀다.
 *
 * @param bundle 다국어 묶음
 * @param dotted 점 경로 (`admin.` 으로 시작)
 * @returns 값 (없으면 undefined)
 */
function lookup(bundle: any, dotted: string): unknown {
    return dotted.replace(/^admin\./, '').split('.').reduce((acc, part) => (acc == null ? undefined : acc[part]), bundle);
}

/**
 * @scenario entry_point=settings_save, retention_source=setting
 * @effects retention_setting_bounds_enforced
 */
describe('보안 설정 — 활동 로그 보관 카드', () => {
    const FIELD = 'security.activity_log_retention_days';

    /** @effects retention_setting_bounds_enforced */
    it('보관 기간이 숫자 칸으로 있다', () => {
        const input = findByName(activityLogCard, FIELD);

        expect(input, `${FIELD} 입력이 없습니다`).not.toBeNull();
        expect(input.props.type).toBe('number');
    });

    /** @effects retention_setting_bounds_enforced */
    it.each(['min', 'max'] as const)('%s 가 서버 한계값 키·같은 폴백을 가리키고 저장 규칙도 같은 키를 읽는다', (bound) => {
        const input = findByName(activityLogCard, FIELD);
        const matched = /_meta\??\.limits\??\.([a-z0-9_]+)\s*\?\?\s*(-?[\d.]+)/.exec(String(input.props[bound]));

        expect(matched, `한계값 바인딩 형태가 아닙니다: ${input.props[bound]}`).not.toBeNull();
        const [, limitKey, fallback] = matched!;

        expect(limitKey).toBe(`security_activity_log_retention_days_${bound}`);
        expect(configuredLimit(limitKey), `config/core.php 에 '${limitKey}' 가 없습니다`).toBe(Number(fallback));
        expect(requestSource).toMatch(
            /'security\.activity_log_retention_days'\s*=>\s*\$this->securityIntegerRules\('activity_log_retention_days'\)/,
        );
    });

    /** @effects retention_setting_bounds_enforced */
    it('하한 폴백이 법정 최소 365일이다', () => {
        expect(configuredLimit('security_activity_log_retention_days_min')).toBe(365);
    });

    /** @effects retention_setting_bounds_enforced */
    it('보안 탭이 카드를 접근 제한 카드 뒤, 앱 키 카드 앞에 포함한다', () => {
        // 머리 주석에도 조각 이름이 나오므로 include 노드(`"partial":"…json"`)로 위치를 잰다.
        const json = JSON.stringify(tabSecurity);
        const ipAt = json.indexOf('"partial":"partials/admin_settings/_tab_security_ip_restriction.json"');
        const logAt = json.indexOf('"partial":"partials/admin_settings/_tab_security_activity_log.json"');
        const appKeyAt = json.indexOf('"id":"card_app_key"');

        expect(ipAt).toBeGreaterThan(-1);
        expect(appKeyAt).toBeGreaterThan(-1);

        expect(logAt).toBeGreaterThan(ipAt);
        expect(logAt).toBeLessThan(appKeyAt);
    });

    /** @effects retention_setting_bounds_enforced */
    it('카드가 쓰는 다국어 키가 ko·en 모두에 있다', () => {
        const keys = [...JSON.stringify(activityLogCard).matchAll(/\$t:(admin\.settings\.security\.activity_log_retention\.[a-z_]+)/g)]
            .map((m) => m[1]);

        expect(keys.length).toBeGreaterThanOrEqual(5);
        for (const key of keys) {
            expect(lookup(adminKo, key), `ko 에 ${key} 가 없습니다`).toEqual(expect.any(String));
            expect(lookup(adminEn, key), `en 에 ${key} 가 없습니다`).toEqual(expect.any(String));
        }
    });
});
