// e2e:allow 레이아웃 정적 구조(성공 갈래 액션 순서) 단언 — 브라우저 축은(저장·삭제 직후 표 다시 받는 동안 창을 다시 열어 새 창 상태가 남는지)이 덮는다.
/**
 * @file admin-modal-success-clear-before-refetch.test.ts
 * @description 창을 닫는 성공 갈래는 창 상태를 다시 받기(refetchDataSource)보다 **먼저** 비운다
 *
 * `refetchDataSource` 는 응답을 기다리는(await) 액션이라, 성공 갈래가 `closeModal → refetchDataSource → setState <ns>=null`
 * 순서면 창이 닫힌 뒤 표를 다시 받는 몇 초 동안 상태 비우기가 늦어진다. 그 사이 관리자가 같은 창(「항목 추가」·「삭제」)을
 * 다시 열면 늦게 도는 비우기가 새 창의 상태를 지워, 빈 「항목 설정」 창(저장하면 PUT …/fields/undefined)이나
 * 「「{{name}}」 항목을 삭제합니다」 창이 남았다. 비우기를 `closeModal` 바로 뒤(다시 받기 앞)로 옮긴다 — 같은 창의 취소 단추가
 * 이미 쓰는 순서이고, 창 닫기보다 먼저 비우지 않는 규칙(docs/frontend/modal-usage.md 「창을 닫을 때 이름 공간 비우기」)도 지킨다.
 *
 * 레이아웃 전체(관리자 템플릿의 컴파일된 JSON)를 훑어 같은 액션 목록 안에서 `closeModal` 뒤 `refetchDataSource` 다음에 오는
 * 전역 비우기(`setState target:global <키>: null | {}`)를 찾는다.
 *
 * @scenario-manifest tests/scenarios/admin-modal-success-clear-before-refetch.yaml
 */

import { describe, it, expect } from 'vitest';

type Json = Record<string, any>;

const LAYOUTS: Record<string, Json> = import.meta.glob('../../layouts/**/*.json', { eager: true, import: 'default' });

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

/**
 * 문서 안에서 closeModal 과 refetchDataSource 를 함께 가진 액션 목록마다 「닫기 < 비우기 < 다시 받기」 인지 단언한다.
 *
 * @param doc 레이아웃 문서
 * @param key 비워야 하는 전역 키
 * @returns 없음
 */
function assertCloseClearRefetch(doc: unknown, key: string): void {
    const lists: Json[][] = [];
    const walk = (x: unknown): void => {
        if (Array.isArray(x)) {
            if (x.some((a: Json) => a?.handler === 'refetchDataSource') && x.some((a: Json) => a?.handler === 'closeModal')) lists.push(x as Json[]);
            x.forEach(walk);

            return;
        }
        if (x && typeof x === 'object') Object.values(x as Json).forEach(walk);
    };
    walk(doc);
    expect(lists.length).toBeGreaterThan(0);
    for (const list of lists) {
        const close = list.findIndex((a) => a.handler === 'closeModal');
        const clear = list.findIndex((a) => clearedKeys(a).includes(key));
        const refetch = list.findIndex((a) => a.handler === 'refetchDataSource');
        expect([close < clear, clear < refetch]).toEqual([true, true]);
    }
}

describe('창을 닫는 성공 갈래 — 창 상태 비우기가 다시 받기보다 앞', () => {
    /**
     * 판정이 공허하지 않은지 — 회원 항목 추가·삭제 창의 JSON 을 실제로 훑는다.
     *
     * @scenario surface=member_field_modal
     * @effects modal_state_cleared_before_refetch
     * @return void 훑은 문서에 회원 항목 추가·삭제 창이 있으면 통과
     */
    it('관리자 템플릿 레이아웃을 훑는다(회원 항목 창 포함)', () => {
        const files = Object.keys(LAYOUTS);
        expect(files.some((f) => f.endsWith('_modal_member_field_form.json'))).toBe(true);
        expect(files.some((f) => f.endsWith('_modal_member_field_delete.json'))).toBe(true);
    });

    /**
     * 회원 항목 추가·수정·삭제·필수로 바꾸기 창(성공 갈래와 409 충돌로 닫는 갈래)을 포함한 레이아웃 전체.
     *
     * @scenario surface=member_field_modal
     * @effects modal_state_cleared_before_refetch
     * @return void 위반 목록이 비어 있으면 통과
     */
    it('closeModal → refetchDataSource 뒤에 창 상태를 비우는 목록이 없다(레이아웃 전체)', () => {
        const found = Object.entries(LAYOUTS).flatMap(([file, doc]) => violations(doc, file.replace(/^\.\.\/\.\.\/layouts\//, '')));
        expect(found).toEqual([]);
    });

    /**
     * 순서를 옮긴 뒤에도 창 닫기보다 먼저 비우지 않는다(닫히기 전 한 번 빈 값으로 다시 그려지지 않게).
     *
     * @scenario surface=member_field_modal
     * @effects modal_state_cleared_after_close
     * @return void 회원 항목 추가 저장 성공 갈래가 close → 비우기 → 다시 받기 순서면 통과
     */
    it('회원 항목 추가 저장 성공 갈래: closeModal → 비우기 → refetchDataSource', () => {
        assertCloseClearRefetch(Object.entries(LAYOUTS).find(([f]) => f.endsWith('_modal_member_field_form.json'))![1], 'member_field_form_modal');
    });

    /**
     * 환경설정 본인인증 정책 추가·편집 창의 저장 성공 갈래.
     *
     * @scenario surface=identity_policy_modal
     * @effects modal_state_cleared_before_refetch, modal_state_cleared_after_close
     * @return void 정책 창 저장 성공 갈래가 close → 비우기 → 다시 받기 순서면 통과
     */
    it('본인인증 정책 창 저장 성공 갈래: closeModal → 비우기 → refetchDataSource', () => {
        assertCloseClearRefetch(Object.entries(LAYOUTS).find(([f]) => f.endsWith('admin_settings/_modal_identity_policy_form.json'))![1], 'identity_policy_form_modal');
    });

    /**
     * 저장·삭제·필수로 바꾸기 성공 갈래는 표를 다시 받기 **전에** 응답의 새 정의 버전(`fields_version`)을 표 데이터 소스에 바로 싣는다.
     * 창은 열 때 표 버전(`_computed.memberFieldsVersion`)을 복사하므로, 다시 받기 응답 전에 연 다음 창이 옛 버전을 들고 저장하면
     * 다른 관리자가 없는데도 409 `fields_changed` 로 한 번 거절됐다(로 그 창이 살아남으며 드러남).
     *
     * @scenario surface=member_field_modal
     * @effects modal_success_carries_new_fields_version_before_refetch
     * @return void 세 창의 성공 갈래가 close → updateDataSource(memberFieldDefs, 응답 버전) → refetch 순서면 통과
     */
    it('회원 항목 창 성공 갈래: 다시 받기 전에 응답의 새 정의 버전을 표 데이터 소스에 싣는다', () => {
        for (const tail of ['_modal_member_field_form.json', '_modal_member_field_delete.json', '_modal_member_field_required.json']) {
            const doc = Object.entries(LAYOUTS).find(([f]) => f.endsWith(tail))![1];
            const success: Json[][] = [];
            const walk = (x: unknown): void => {
                if (Array.isArray(x)) {
                    x.forEach(walk);

                    return;
                }
                if (x && typeof x === 'object') {
                    const node = x as Json;
                    if (node.handler === 'apiCall' && Array.isArray(node.onSuccess)) success.push(node.onSuccess);
                    Object.values(node).forEach(walk);
                }
            };
            walk(doc);
            expect(success.length, tail).toBeGreaterThan(0);
            for (const list of success) {
                const close = list.findIndex((a) => a.handler === 'closeModal');
                const update = list.findIndex((a) => a.handler === 'updateDataSource' && a.params?.dataSourceId === 'memberFieldDefs');
                const refetch = list.findIndex((a) => a.handler === 'refetchDataSource');
                expect([tail, close >= 0 && close < update, update >= 0 && update < refetch]).toEqual([tail, true, true]);
                expect(String(list[update].params.data)).toContain('response?.data?.fields_version');
            }
        }
    });

    /**
     * 표·카드 판의 즉시 저장(사용·필수·가입 표시 스위치, 순서 바꾸기) 성공 갈래도 다시 받기 바로 앞에서 새 정의 버전을 싣는다 —
     * 스위치를 바꾼 직후 연 「항목 추가」 창이 옛 버전을 복사하는 같은 꼴.
     *
     * @scenario surface=member_field_modal
     * @effects modal_success_carries_new_fields_version_before_refetch
     * @return void 표·카드 판 성공 갈래의 memberFieldDefs 다시 받기마다 바로 앞에 버전 싣기가 있으면 통과
     */
    it('표·카드 판 즉시 저장 성공 갈래: 다시 받기 바로 앞에서 새 정의 버전을 싣는다', () => {
        for (const tail of ['admin_settings/_tab_member_fields.json', 'admin_settings/_tab_member_fields_cards.json']) {
            const doc = Object.entries(LAYOUTS).find(([f]) => f.endsWith(tail))![1];
            let checked = 0;
            const walk = (x: unknown, inSuccess: boolean): void => {
                if (Array.isArray(x)) {
                    if (inSuccess) {
                        x.forEach((a: Json, i: number) => {
                            if (a?.handler === 'refetchDataSource' && a.params?.dataSourceId === 'memberFieldDefs') {
                                checked += 1;
                                const prev = x[i - 1] as Json | undefined;
                                expect([tail, prev?.handler, prev?.params?.dataSourceId]).toEqual([tail, 'updateDataSource', 'memberFieldDefs']);
                            }
                        });
                    }
                    x.forEach((c) => walk(c, inSuccess));

                    return;
                }
                if (x && typeof x === 'object') {
                    for (const [k, v] of Object.entries(x as Json)) walk(v, (inSuccess && k !== 'onError') || k === 'onSuccess');
                }
            };
            walk(doc, false);
            expect(checked, tail).toBeGreaterThan(0);
        }
    });
});
