/**
 * 배송정책 폼 핸들러가 요청하는 다국어 키가 실제로 존재하는지 검사한다.
 *
 * `G7Core.t()` 는 키를 찾지 못하면 키 문자열을 그대로 돌려준다. 핸들러의
 * `t?.(key) ?? '기본 문구'` 는 이 경우 방어가 되지 않는다 — null 이 아니라 키가
 * 반환되므로 `??` 가 발동하지 않고, 화면에는
 * "sirsoft-ecommerce.validation.shipping_policy.ranges.continuity" 같은 원문 키가 뜬다.
 *
 * 오류도 경고도 나지 않고 문구만 깨지므로, 키 존재 여부는 이렇게 구조적으로 잠근다.
 *
 * @vitest-environment node
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const MODULE_ROOT = resolve(__dirname, '../../../..');
const HANDLER_PATH = resolve(MODULE_ROOT, 'resources/js/handlers/shippingPolicyFormHandlers.ts');

/** 핸들러 소스에서 요청하는 다국어 키를 전수 추출한다. */
function extractRequestedKeys(): string[] {
    const source = readFileSync(HANDLER_PATH, 'utf-8');
    const keys = new Set<string>();
    const re = /t\?\.\(\s*'([^']+)'\s*\)/g;
    let match;

    while ((match = re.exec(source)) !== null) {
        keys.add(match[1]);
    }

    return [...keys];
}

/**
 * `sirsoft-ecommerce.admin.shipping_policy.form.x` 형태의 키를 파일/경로로 해석한다.
 *
 * 프론트 다국어는 `resources/lang/partial/{locale}/admin/shipping_policy.json` 이
 * `admin/shipping_policy` 네임스페이스를 담당한다.
 */
function resolveKey(locale: string, key: string): string | undefined {
    const prefix = 'sirsoft-ecommerce.admin.shipping_policy.';

    if (!key.startsWith(prefix)) {
        return undefined;
    }

    const json = JSON.parse(
        readFileSync(resolve(MODULE_ROOT, `resources/lang/partial/${locale}/admin/shipping_policy.json`), 'utf-8')
    );

    return key
        .slice(prefix.length)
        .split('.')
        .reduce<any>((acc, segment) => (acc == null ? undefined : acc[segment]), json);
}

describe('shippingPolicyFormHandlers 다국어 키', () => {
    const requested = extractRequestedKeys();

    it('핸들러가 다국어 키를 실제로 요청한다 (추출이 비면 이 테스트는 아무것도 검증하지 못한다)', () => {
        expect(requested.length).toBeGreaterThan(5);
    });

    it('모든 요청 키가 프론트엔드 네임스페이스를 사용한다', () => {
        // 백엔드 lang 경로(sirsoft-ecommerce.validation.*)는 프론트 번들에 실리지 않는다
        const backendKeys = requested.filter(k => !k.startsWith('sirsoft-ecommerce.admin.shipping_policy.'));
        expect(backendKeys).toEqual([]);
    });

    it.each(['ko', 'en'])('%s 로케일에 모든 요청 키가 정의되어 있다', (locale) => {
        const missing = requested.filter(key => typeof resolveKey(locale, key) !== 'string');
        expect(missing).toEqual([]);
    });
});
