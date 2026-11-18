// e2e:allow 레이아웃 정적 구조(성공 갈래 액션 순서) 단언 — 브라우저 축은(저장 직후 목록 다시 받는 동안 창을 다시 열어 새 창 상태가 남는지)이 덮는다.
/**
 * @file admin-modal-success-clear-before-refetch.test.ts
 * @description 창을 닫는 성공 갈래는 창 상태를 다시 받기(refetchDataSource)보다 **먼저** 비운다
 *
 * `refetchDataSource` 는 응답을 기다리는(await) 액션이라, 성공 갈래가 `closeModal → refetchDataSource → setState <ns>=null`
 * 순서면 창이 닫힌 뒤 목록을 다시 받는 동안 상태 비우기가 늦어지고, 그 사이 같은 창(게시판 본인인증 정책 추가·편집)을 다시
 * 열면 늦은 비우기가 새 창 상태를 지운다. 비우기를 `closeModal` 바로 뒤로 옮긴다(docs/frontend/modal-usage.md
 * 「저장 onSuccess — 명시적 정리」).
 *
 * @scenario-manifest tests/scenarios/admin-modal-success-clear-before-refetch.yaml
 */

import { describe, it, expect } from 'vitest';

type Json = Record<string, any>;

const LAYOUTS: Record<string, Json> = import.meta.glob('../../../layouts/**/*.json', { eager: true, import: 'default' });

/**
 * 액션이 전역 상태의 키를 비우는(null·빈 객체) setState 이면 그 키 목록을 돌려준다.
 *
 * @param a 액션
 * @returns 비우는 키 목록(아니면 빈 배열)
 */
function clearedKeys(a: Json): string[] {
    if (a?.handler !== 'setState' || a.params?.target !== 'global') return [];

    return Object.entries(a.params)
        .filter(([k, v]) => k !== 'target' && k !== 'merge' && (v === null || (v && typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0)))
        .map(([k]) => k);
}

/**
 * 문서 안 모든 액션 목록(원소가 handler 를 가진 배열)에서 「closeModal → refetchDataSource → 비우기」 를 찾는다.
 *
 * @param doc 레이아웃 문서
 * @param file 파일 표시 이름
 * @returns 위반 목록(`파일: 키`)
 */
function violations(doc: unknown, file: string): string[] {
    const out: string[] = [];
    const walk = (x: unknown): void => {
        if (Array.isArray(x)) {
            if (x.some((a) => a && typeof a === 'object' && typeof (a as Json).handler === 'string')) {
                const close = x.findIndex((a: Json) => a?.handler === 'closeModal');
                const refetch = x.findIndex((a: Json, i: number) => i > close && a?.handler === 'refetchDataSource');
                if (close >= 0 && refetch > close) {
                    x.slice(refetch + 1).forEach((a: Json) => clearedKeys(a).forEach((k) => out.push(`${file}: ${k}`)));
                }
            }
            x.forEach(walk);

            return;
        }
        if (x && typeof x === 'object') Object.values(x as Json).forEach(walk);
    };
    walk(doc);

    return out;
}

describe('게시판 관리자 창 성공 갈래 — 창 상태 비우기가 다시 받기보다 앞', () => {
    /**
     * 판정이 공허하지 않은지 — 본인인증 정책 창 JSON 을 실제로 훑는다.
     *
     * @scenario surface=identity_policy_modal, branch=success
     * @effects modal_state_cleared_before_refetch
     * @return void 훑은 문서에 게시판 본인인증 정책 창이 있으면 통과
     */
    it('게시판 레이아웃을 훑는다(본인인증 정책 창 포함)', () => {
        expect(Object.keys(LAYOUTS).some((f) => f.endsWith('admin_board_settings/_modal_identity_policy_form.json'))).toBe(true);
    });

    /**
     * @scenario surface=identity_policy_modal, branch=success
     * @effects modal_state_cleared_before_refetch
     * @return void 위반 목록이 비어 있으면 통과
     */
    it('closeModal → refetchDataSource 뒤에 창 상태를 비우는 목록이 없다', () => {
        const found = Object.entries(LAYOUTS).flatMap(([file, doc]) => violations(doc, file.replace(/^\.\.\/\.\.\/\.\.\/layouts\//, '')));
        expect(found).toEqual([]);
    });
});
