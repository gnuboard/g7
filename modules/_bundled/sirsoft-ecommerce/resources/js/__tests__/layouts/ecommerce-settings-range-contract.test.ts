/**
 * 설정 화면 ↔ 서버 검증 규칙 경계값 계약 테스트 (E10)
 *
 * @description
 * 설정 입력 UI 의 min/max/step 은 서버 검증 규칙(StoreEcommerceSettingsRequest)과 같은 값을
 * 말해야 한다. 화면이 더 좁으면 저장 가능한 값을 입력조차 못 하고, 더 넓으면 입력은 되는데
 * 저장 단계에서만 422 가 난다. 어느 쪽도 화면만 보고는 알 수 없어 조용히 어긋난 채로 남는다.
 *
 * 양쪽 모두 `config('sirsoft-ecommerce.limits')` 한 곳을 읽도록 배선했으므로, 세 지점
 * (설정 파일 · 서버 규칙 · 화면 바인딩)이 같은 한계값 키와 같은 폴백을 가리키는지 대조한다.
 * 어느 한 쪽만 고쳐도 실패한다.
 *
 * @vitest-environment node
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import tabMileageBasic from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_basic_card.json';
import tabOrderSettings from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_order_settings.json';
import tabReviewSettings from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_review_settings.json';

const moduleRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
const requestSource = fs.readFileSync(
    path.join(moduleRoot, 'src/Http/Requests/Admin/StoreEcommerceSettingsRequest.php'),
    'utf-8',
);
const configSource = fs.readFileSync(path.join(moduleRoot, 'config/ecommerce.php'), 'utf-8');

/** 한 경계(min 또는 max)의 출처: 어떤 한계값 키를 읽고, 그 키가 없을 때 무엇으로 떨어지는가. */
interface Bound {
    limitKey: string | null;
    fallback: number;
}

/**
 * `config/ecommerce.php` 의 `limits` 블록을 키 → 값으로 읽는다.
 *
 * @returns 한계값 키 맵
 */
function configuredLimits(): Record<string, number> {
    const block = /'limits'\s*=>\s*\[([\s\S]*?)\n\s*\],/.exec(configSource);
    expect(block, "config/ecommerce.php 에서 'limits' 블록을 찾지 못했습니다.").not.toBeNull();

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

const LIMITS = configuredLimits();

/**
 * 규칙/바인딩 문자열에서 한 경계의 출처를 뽑는다.
 *
 * 서버 규칙: `'min:'.config('sirsoft-ecommerce.limits.KEY', N)` 또는 `'min:N'`
 * 화면 바인딩: `{{_local?.form?._meta?.limits?.KEY ?? N}}` 또는 `"N"`
 *
 * @param source 규칙 배열 본문 또는 prop 값
 * @param bound 'min' | 'max'
 * @param isRule 서버 규칙 문자열이면 true
 * @returns 경계 출처 (해당 경계가 없으면 null)
 */
function extractBound(source: string, bound: 'min' | 'max', isRule: boolean): Bound | null {
    if (isRule) {
        const viaConfig = new RegExp(
            `'${bound}:'\\s*\\.\\s*config\\(\\s*'sirsoft-ecommerce\\.limits\\.([a-z0-9_]+)'\\s*,\\s*(-?[\\d.]+)\\s*\\)`,
        ).exec(source);

        if (viaConfig) {
            return { limitKey: viaConfig[1], fallback: Number(viaConfig[2]) };
        }

        const literal = new RegExp(`'${bound}:(-?[\\d.]+)'`).exec(source);

        return literal ? { limitKey: null, fallback: Number(literal[1]) } : null;
    }

    if (source === undefined || source === null || source === '') {
        return null;
    }

    const viaBinding = /limits\?\.([a-z0-9_]+)\s*\?\?\s*(-?[\d.]+)/.exec(source);

    if (viaBinding) {
        return { limitKey: viaBinding[1], fallback: Number(viaBinding[2]) };
    }

    return Number.isNaN(Number(source)) ? null : { limitKey: null, fallback: Number(source) };
}

/**
 * FormRequest 원본에서 해당 설정 키의 규칙 배열 본문을 읽는다.
 *
 * @param key 설정 키
 * @returns 규칙 배열 본문 문자열
 */
function ruleBody(key: string): string {
    const escaped = key.replace(/[.]/g, '\\.');
    const matched = new RegExp(`'${escaped}'\\s*=>\\s*\\[(.*)\\],\\n`).exec(requestSource);

    expect(matched, `${key} 규칙을 FormRequest 에서 찾지 못했습니다.`).not.toBeNull();

    return matched![1];
}

/**
 * 레이아웃 트리에서 해당 설정 키를 다루는 Input 노드를 찾는다.
 *
 * 자동 바인딩을 쓰는 화면은 `props.name`, 수동 바인딩 화면은 `props.value` 의 표현식 경로로
 * 필드를 식별한다 — 두 형태가 같은 탭 안에 섞여 있으므로 둘 다 본다.
 *
 * @param node 탐색 시작 노드
 * @param key 설정 키
 * @returns 매칭된 Input 노드 (없으면 null)
 */
function findInput(node: any, key: string): any {
    if (!node || typeof node !== 'object') return null;

    const boundPath = key.split('.').join('?.');
    const value = node.props?.value;

    if (
        node.name === 'Input' &&
        (node.props?.name === key || (typeof value === 'string' && value.includes(boundPath)))
    ) {
        return node;
    }

    for (const child of Object.values(node)) {
        if (Array.isArray(child)) {
            for (const item of child) {
                const found = findInput(item, key);
                if (found) return found;
            }
        } else if (child && typeof child === 'object') {
            const found = findInput(child, key);
            if (found) return found;
        }
    }

    return null;
}

const FIELDS: Array<{ key: string; layout: any }> = [
    { key: 'review_settings.write_deadline_days', layout: tabReviewSettings },
    { key: 'review_settings.max_images', layout: tabReviewSettings },
    { key: 'review_settings.max_image_size_mb', layout: tabReviewSettings },
    { key: 'order_settings.auto_cancel_days', layout: tabOrderSettings },
    { key: 'order_settings.cart_expiry_days', layout: tabOrderSettings },
    { key: 'mileage.default_earn_rate', layout: tabMileageBasic },
];

describe('설정 화면 경계값 ↔ 서버 규칙 계약', () => {
    it.each(FIELDS)('$key 의 min/max 가 서버 규칙과 같은 한계값을 가리킨다', ({ key, layout }) => {
        const input = findInput(layout, key);
        expect(input, `${key} Input 을 레이아웃에서 찾지 못했습니다.`).not.toBeNull();

        const body = ruleBody(key);
        let compared = 0;

        for (const bound of ['min', 'max'] as const) {
            const server = extractBound(body, bound, true);

            if (server === null) {
                continue;
            }

            const screen = extractBound(String(input.props[bound] ?? ''), bound, false);

            expect(screen, `${key} 화면에 ${bound} 이 없습니다 — 서버는 ${bound} 을 강제합니다.`).not.toBeNull();
            expect(screen!.limitKey, `${key} 의 ${bound} 이 서버 규칙과 다른 한계값을 읽습니다.`).toBe(
                server.limitKey,
            );
            expect(screen!.fallback, `${key} 의 ${bound} 폴백이 서버 규칙과 다릅니다.`).toBe(server.fallback);

            compared++;
        }

        // 규칙 형태가 바뀌어 아무 경계도 못 읽으면 이 테스트는 아무것도 검증하지 못한 채 통과한다.
        expect(compared, `${key} 에서 서버 경계를 하나도 읽지 못했습니다 — 규칙 추출이 깨졌습니다.`).toBeGreaterThan(0);
    });

    it.each(FIELDS)('$key 의 폴백이 설정 파일의 한계값과 같다', ({ key }) => {
        const body = ruleBody(key);
        let checked = 0;

        for (const bound of ['min', 'max'] as const) {
            const server = extractBound(body, bound, true);

            if (server === null || server.limitKey === null) {
                continue;
            }

            expect(LIMITS, `${server.limitKey} 가 config/ecommerce.php 의 limits 에 없습니다.`).toHaveProperty(
                server.limitKey,
            );
            expect(LIMITS[server.limitKey], `${key} 의 ${bound} 폴백이 설정 파일 값과 다릅니다.`).toBe(
                server.fallback,
            );

            checked++;
        }

        expect(checked).toBeGreaterThan(0);
    });

    it.each(FIELDS)('$key 의 step 이 서버 규칙의 정수 여부와 어긋나지 않는다', ({ key, layout }) => {
        const input = findInput(layout, key);
        const body = ruleBody(key);

        if (!/'integer'/.test(body)) {
            return;
        }

        // step 자체가 사라지면 브라우저 기본값 1 로 동작해 당장은 문제가 없지만,
        // 다음 사람이 소수 step 을 다시 넣어도 막을 근거가 없어진다.
        // 부재를 통과시키면 이 가드가 그 순간 무력화되므로 명시 실패시킨다.
        expect(
            input.props.step,
            `${key} 는 서버가 integer 를 요구하므로 step 을 명시해야 합니다 — 부재는 소수 step 회귀를 막지 못합니다.`,
        ).toBeDefined();

        // 서버가 integer 를 요구하는데 소수 step 을 주면, 화면이 권하는 값이 곧 422 가 된다.
        expect(Number(input.props.step)).toBe(1);
    });
});
