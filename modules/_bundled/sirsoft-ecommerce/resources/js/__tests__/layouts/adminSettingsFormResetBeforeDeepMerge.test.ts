// e2e:allow 레이아웃 정적 구조(같은 sequence 안 setState 순서) 단언 전용 — 브라우저 축은 통합 재실측(정책 A 편집 → 새 정책 만들기 → 조건·사용자 지정 빈칸)이 덮는다
/**
 * @file adminSettingsFormResetBeforeDeepMerge.test.ts
 * @description 환경설정 창 폼을 새로 세울 때 앞 값이 deep 병합으로 남지 않는다 (7.1.1 출시 전)
 *
 * setState 기본 병합(deep)은 값이 객체이고 기존 값도 객체면 재귀 병합한다 — `{}` 로 「비우기」 해도 기존 키가
 * 지워지지 않고, 다른 객체로 덮어도 그 객체에 없는 키는 남는다(엔진 계약, docs/frontend/actions-handlers-state.md).
 * 7.1.0 의 레이아웃 빈 객체 보존 뒤로 `{}` 가 `[]` 가 아닌 객체로 와서 드러났다.
 *
 * - 본인인증 정책 창: 조건(conditions)이 있는 정책 A 를 편집한 뒤 「정책 만들기」 를 누르면 새 폼에 A 의 조건과
 *   사용자 지정(user_overrides)이 남는다. 정책 A 다음 정책 B 를 편집해도 B 에 없는 A 의 조건 키가 남는다.
 * - 통화 추가 줄: 앞서 연 추가 줄의 통화 이름(name 언어별)이 다음 추가 줄에 남는다.
 *
 * 고친 모양: 같은 sequence 에서 그 폼을 먼저 null 로 덮고 다시 세운다(트러블슈팅 state-global 사례 7). init 경로의
 * shallow 는 `_local` 통째 교체라 쓰지 않는다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const PARTIALS = resolve(__dirname, '../../../layouts/admin/partials/admin_ecommerce_settings');

/** 모든 sequence 의 actions 배열을 모은다 */
function sequences(node: any, acc: any[][] = []): any[][] {
    if (Array.isArray(node)) {
        node.forEach((n) => sequences(n, acc));

        return acc;
    }
    if (node === null || typeof node !== 'object') return acc;
    if (node.handler === 'sequence' && Array.isArray(node.actions)) acc.push(node.actions);
    Object.values(node).forEach((v) => v && typeof v === 'object' && sequences(v, acc));

    return acc;
}

/**
 * 같은 sequence 안에서 path 의 값을 객체로 세우는 setState 마다, 그 앞에 같은 path 를 null 로 덮는 setState 가
 * 있는지 본다.
 *
 * @param layout 레이아웃 JSON
 * @param pick setState params 에서 대상 값을 꺼낸다(없으면 undefined)
 * @return [세우는 setState 수, 앞에 null 덮기가 없는 수]
 */
function resetsBeforeSet(layout: any, pick: (params: any) => any): [number, number] {
    let sets = 0;
    let missing = 0;
    sequences(layout).forEach((actions) => {
        actions.forEach((action: any, i: number) => {
            if (action?.handler !== 'setState') return;
            const value = pick(action.params ?? {});
            if (value === null || typeof value !== 'object') return;
            sets++;
            const resetBefore = actions
                .slice(0, i)
                .some((prev: any) => prev?.handler === 'setState' && pick(prev.params ?? {}) === null);
            if (!resetBefore) missing++;
        });
    });

    return [sets, missing];
}

describe('환경설정 창 폼 — 새로 세우기 전에 null 로 덮는다', () => {
    /**
     * @scenario surface=identity_policy_modal
     * @effects settings_form_nulled_before_rebuild
     */
    it('본인인증 정책 창: 만들기·고치기(표·카드) 3곳 모두 form 을 먼저 null 로 덮는다', () => {
        const layout = JSON.parse(readFileSync(resolve(PARTIALS, '_tab_identity_policies.json'), 'utf-8'));
        const [sets, missing] = resetsBeforeSet(layout, (p) => p.identity_policy_form_modal?.form);

        expect(sets).toBe(3);
        expect(missing).toBe(0);
    });

    /**
     * @scenario surface=currency_add_row
     * @effects settings_form_nulled_before_rebuild
     */
    it('통화 추가 줄: form.newCurrency 를 먼저 null 로 덮는다', () => {
        const layout = JSON.parse(readFileSync(resolve(PARTIALS, '_tab_language_currency.json'), 'utf-8'));
        const [sets, missing] = resetsBeforeSet(layout, (p) => p['form.newCurrency']);

        expect(sets).toBeGreaterThanOrEqual(1);
        expect(missing).toBe(0);
    });
});
