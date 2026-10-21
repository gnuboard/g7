// e2e:allow 레이아웃 엔진 렌더(실물 ActionDispatcher 의 deep 병합·자동바인딩·apiCall) + 굽기 산출 정적 잠금 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(마이페이지 배송지 「수정」 → 취소 → 「새 주소 추가」, 주문서 「배송지 관리」 창)이 덮는다.
/**
 * @file address-modal-open-reset.test.tsx
 * @description 배송지 창을 열 때 앞 창의 값이 남지 않는다 (7.1.1)
 *
 * 마이페이지 › 배송지에서 어떤 배송지의 「수정」 창을 열었다가 「취소」 → 「새 주소 추가」 를 누르면 창 제목이 「배송지 수정」 이고
 * 앞 주소 값이 채워진 채 열렸고, 「저장」 하면 `PUT …/user/addresses/{앞 id}` 가 나가 기존 배송지를 덮어썼다. 「새 주소 추가」 가
 * 편집 대상 `_global.editingAddress` 를 `{is_default, country_code}` 부분 객체로 쓰는데, setState 기본(deep) 병합은 이번 객체에 없는
 * 키(id·이름·주소·입력해 둔 칸)를 남기기 때문이다. 창의 등록/수정 분기는 `editingAddress?.id` 다. 닫기 단추·Esc 는 엔진이
 * closeModal 로만 이어 정리 동작을 붙일 수 없으므로, 여는 쪽이 매번 null 로 먼저 비운다.
 *
 * 판정 ①②: 실물 `_list.json` + `_modal_address.json` 을 엔진으로 렌더해 사용자 순서대로 누른다.
 * 판정 ③: basic 굽기 산출 전부에서 「창을 여는 setState(같은 액션 목록에 openModal 또는 폼 모드 전환)」가 객체로 쓰는 상태에, 같은
 *         확장 어디선가 그 상태의 하위 키로 쓰는 것(다른 여는 객체·점 경로 쓰기·자동바인딩 폼 칸) 중 빠진 것이 있으면, 같은 목록
 *         앞에서 그 상태를 null 로 비운다. 모집단은 레이아웃 JSON 전체에서 파생한다(새 창도 자동 편입).
 *
 * @scenario-manifest tests/scenarios/address-modal-open-reset.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import React from 'react';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { fireEvent } from '@testing-library/react';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
    waitFor,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import addressList from '../../../layouts/partials/mypage/addresses/_list.json';
import addressModal from '../../../layouts/partials/mypage/addresses/_modal_address.json';
import addressManageModal from '../../../layouts/partials/shop/_modal_address_manage.json';

type Node = Record<string, any>;

const ROOT = resolve(__dirname, '../../..');

const ADDRESSES = [
    { id: 11, name: '집', recipient_name: '홍길동', recipient_phone: '010-1111-2222', country_code: 'KR', zipcode: '06236', address: '서울 강남구 테헤란로 1', address_detail: '1층', is_default: true, abilities: { can_update: true, can_delete: false } },
    { id: 22, name: '회사', recipient_name: '김영희', recipient_phone: '010-3333-4444', country_code: 'KR', zipcode: '04524', address: '서울 중구 세종대로 2', address_detail: '2층', is_default: false, abilities: { can_update: true, can_delete: true } },
];

/**
 * 부품 레지스트리 — 창(Modal)은 늘 그려 제목을 dialog 이름으로 드러낸다(시험 렌더에는 창 스택 표시 토글이 없다).
 *
 * @returns 레지스트리
 */
function makeRegistry(): MockComponentRegistry {
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Icon', ({ className }: { className?: string }) => React.createElement('i', { className, 'aria-hidden': 'true' }));
    registry.register('composite', 'Modal', ({ title, children }: { title?: string; children?: React.ReactNode }) =>
        React.createElement('section', { role: 'dialog', 'aria-label': title, 'data-title': title }, children));
    registry.register('basic', 'Checkbox', ({ checked, onChange, id, name }: any) =>
        React.createElement('input', { type: 'checkbox', checked: !!checked, onChange, id, name }));

    return registry;
}

/**
 * 운영의 `_globalSetState`(TemplateApp.setGlobalState) 대역 — `data-key="_global.*"` 폼 자동바인딩이 이것으로 전역에 쓴다.
 * 시험 렌더에는 이 문맥 함수가 없어 그대로 두면 입력이 상태에 닿지 않는다.
 *
 * @param updates 객체 또는 함수형 갱신
 */
function globalSetState(updates: any): void {
    const core = (window as any).G7Core;
    const current = core.state.get();
    const next = typeof updates === 'function' ? updates(current) : { ...current, ...updates };
    const { _local: _ignored, ...globalOnly } = next ?? {};
    core.state.set(globalOnly);
}

/** 저장 요청(배송지 쓰기)만 골라 낸다 */
function addressWrites(): Array<{ url: string; method: string; body: any }> {
    const calls = ((globalThis.fetch as any)?.mock?.calls ?? []) as any[][];

    return calls
        .map(([url, init]) => ({ url: String(url), method: String(init?.method ?? 'GET').toUpperCase(), body: init?.body ? JSON.parse(String(init.body)) : null }))
        .filter((c) => c.url.includes('/user/addresses') && c.method !== 'GET');
}

/**
 * 마이페이지 배송지 목록 + 등록·수정 창을 렌더한다.
 *
 * @param registry 부품 레지스트리
 * @returns 테스트 유틸
 */
async function renderMypageAddresses(registry: MockComponentRegistry) {
    const utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'test/address-modal-open-reset',
            data_sources: [{ id: 'addresses', endpoint: '/api/modules/sirsoft-ecommerce/user/addresses', method: 'GET', auto_fetch: true }],
            components: [
                { type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: [addressList, addressModal] },
            ],
        } as any,
        { componentRegistry: registry as any, locale: 'ko', initialState: { _global: { currentUser: { uuid: 'u-1' } } }, initialData: { _globalSetState: globalSetState } },
    );
    utils.mockApi('addresses', { response: { success: true, data: { addresses: { data: ADDRESSES } } } });
    await utils.render();
    await waitFor(() => expect(screen.getAllByText(/홍길동/).length).toBeGreaterThan(0));

    return utils;
}

/** 창 안의 단추 중 문구가 맞는 것 */
const dialogButton = (text: RegExp): HTMLElement => {
    const dialog = screen.getByRole('dialog');
    const found = Array.from(dialog.querySelectorAll('button')).find((b) => text.test(b.textContent ?? ''));
    if (!found) throw new Error(`창 단추 없음: ${text}`);

    return found as HTMLElement;
};

/** 목록(창 밖)의 단추 중 문구가 맞는 것들 */
const pageButtons = (text: RegExp): HTMLElement[] =>
    Array.from(document.querySelectorAll('button')).filter((b) => !b.closest('[role="dialog"]') && text.test(b.textContent ?? '')) as HTMLElement[];

/**
 * 「취소」 의 정리(창을 닫고 편집 대상을 비움)가 끝나기를 기다린다 — 사람이 다음 단추를 누르기 전에 끝나는 짧은 연쇄다.
 * 수정 전 산출에는 취소 정리가 없으므로 창이 닫히기(엔진의 전역 창 스택 비움)만 기다린다.
 *
 * @param utils 테스트 유틸
 */
async function cancelSettled(utils: { getState: () => any }): Promise<void> {
    await waitFor(() => expect(utils.getState()._global.modalStack ?? []).toEqual([]));
    await new Promise((resolve) => setTimeout(resolve, 50));
}

const inputByName = (name: string): HTMLInputElement => {
    const el = screen.getByRole('dialog').querySelector(`input[name="${name}"]`);
    if (!el) throw new Error(`칸 없음: ${name}`);

    return el as HTMLInputElement;
};

describe('배송지 창을 열 때 앞 창의 값이 남지 않는다 (7.1.1)', () => {
    let registry: MockComponentRegistry;

    beforeEach(() => {
        registry = makeRegistry();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    // @scenario case=mypage_edit_then_add
    // @effects add_opens_blank_after_edit, add_save_posts_new_address
    it('「수정」 창을 열었다가 취소한 뒤 「새 주소 추가」 는 빈 등록 창으로 열리고 저장이 POST 다', async () => {
        const utils = await renderMypageAddresses(registry);

        // 앞 배송지(id 11)의 수정 창을 연다
        fireEvent.click(pageButtons(/common\.edit|수정/)[0]);
        await waitFor(() => expect(utils.getState()._global.editingAddress?.id).toBe(11));
        await utils.rerender();
        expect(screen.getByRole('dialog').getAttribute('data-title')).toMatch(/edit_title|수정/);

        // 취소 → 새 주소 추가
        fireEvent.click(dialogButton(/common\.cancel|취소/));
        await cancelSettled(utils);
        fireEvent.click(pageButtons(/mypage\.addresses\.add|새 주소/)[0]);
        await waitFor(() => expect(utils.getState()._global.editingAddress?.is_default).toBe(false));

        const editing = utils.getState()._global.editingAddress;
        expect(editing?.id ?? null).toBeNull();
        expect(editing?.name ?? '').toBe('');
        expect(editing?.recipient_name ?? '').toBe('');
        expect(editing?.address ?? '').toBe('');

        await utils.rerender();
        expect(screen.getByRole('dialog').getAttribute('data-title')).toMatch(/add_title/);
        expect(inputByName('recipient_name').value).toBe('');
        expect(inputByName('recipient_phone').value).toBe('');
        expect(inputByName('zipcode').value).toBe('');

        // 저장 → 새 배송지 등록(POST, 주소에 id 없음) — 앞 배송지를 덮어쓰지 않는다
        fireEvent.click(dialogButton(/mypage\.addresses\.add|새 주소/));
        await waitFor(() => expect(addressWrites().length).toBeGreaterThan(0));
        const write = addressWrites()[0];
        expect(write.method).toBe('POST');
        expect(write.url).toMatch(/\/user\/addresses$/);
        expect(write.body?.id ?? null).toBeNull();
        utils.cleanup();
    });

    // @scenario case=mypage_add_typed_then_cancel
    // @effects add_opens_blank_after_cancelled_input
    it('새 주소 창에 입력하고 취소한 뒤 다시 열면 칸이 비어 있다', async () => {
        const utils = await renderMypageAddresses(registry);

        fireEvent.click(pageButtons(/mypage\.addresses\.add|새 주소/)[0]);
        await waitFor(() => expect(utils.getState()._global.editingAddress?.is_default).toBe(false));
        await utils.rerender();
        fireEvent.change(inputByName('recipient_name'), { target: { value: '이몽룡' } });
        await waitFor(() => expect(utils.getState()._global.editingAddress?.recipient_name).toBe('이몽룡'));
        // 운영에서는 미뤄진 전역 다시 그리기(수십 ms)가 와서 칸의 표시용 대기 값이 확정된다 — 시험 렌더는 스스로 다시
        // 그리지 않으므로 그 한 번을 명시한다
        await utils.rerender();
        expect(inputByName('recipient_name').value).toBe('이몽룡');

        fireEvent.click(dialogButton(/common\.cancel|취소/));
        await cancelSettled(utils);
        fireEvent.click(pageButtons(/mypage\.addresses\.add|새 주소/)[0]);
        await waitFor(() => expect(utils.getState()._global.editingAddress?.is_default).toBe(false));

        expect(utils.getState()._global.editingAddress?.recipient_name ?? '').toBe('');
        await utils.rerender();
        expect(inputByName('recipient_name').value).toBe('');
        utils.cleanup();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// ③ 정적 잠금 — 창을 여는 부분 객체 쓰기는 null 로 먼저 비운다
// ─────────────────────────────────────────────────────────────────────────────

const isPlain = (v: unknown): v is Node => !!v && typeof v === 'object' && !Array.isArray(v);
const META = new Set(['target', 'merge', 'render', 'stopPropagation', 'preventDefault']);
const FORM_FIELDS = new Set(['Input', 'Textarea', 'Select', 'Checkbox', 'Toggle', 'RadioGroup']);

/**
 * 디렉터리 아래 JSON 경로를 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns JSON 경로 목록
 */
function jsonFiles(dir: string): string[] {
    try {
        return readdirSync(dir).flatMap((entry) => {
            const path = join(dir, entry);
            if (statSync(path).isDirectory()) return jsonFiles(path);

            return path.endsWith('.json') ? [path] : [];
        });
    } catch {
        return [];
    }
}

interface Opener { file: string; state: string; keys: string[]; reset: boolean }

/**
 * 창을 여는 부분 객체 쓰기 중 null 선행이 없는 자리를 모은다.
 *
 * 상태 이름은 `대상|키` 다. 지역(`local`) 상태는 화면마다 따로 살므로 같은 파일 안의 쓰기만 하위 키로 센다.
 *
 * @returns 위반 목록과 여는 자리 수
 */
function scanOpeners(): { violations: string[]; openers: Opener[] } {
    const files = [...jsonFiles(join(ROOT, 'layouts')), ...jsonFiles(join(ROOT, 'extensions'))];
    const subkeys = new Map<string, Set<string>>();
    const openers: Opener[] = [];
    const scope = (file: string, state: string): string => (state.startsWith('local|') ? `${file}#${state}` : state);
    const add = (id: string, key: string): void => {
        if (!subkeys.has(id)) subkeys.set(id, new Set());
        subkeys.get(id)!.add(key);
    };
    // 상태 경로는 점 경로 단위다(`global|checkoutAddressModal.editing`) — 창 고유 이름 공간 안의 편집 대상도 같은 규칙으로 본다(7.1.1).
    // 같은 목록 앞에서 그 경로나 그 조상 경로를 null 로 썼으면 비운 것으로 본다.
    const scanList = (list: any[], file: string, entry: boolean): void => {
        const opens = list.some((a) => a?.handler === 'openModal')
            || list.some((a) => a?.handler === 'setState' && isPlain(a.params) && Object.entries(a.params).some(([k, v]) => /(Mode|(^|\.)mode)$/.test(k) && v === 'form'));
        const nulled = new Set<string>();
        const isNulled = (target: string, path: string): boolean => {
            const segs = path.split('.');
            for (let i = 1; i <= segs.length; i += 1) {
                if (nulled.has(`${target}|${segs.slice(0, i).join('.')}`)) return true;
            }

            return false;
        };
        for (const a of list) {
            if (a?.handler !== 'setState' || !isPlain(a.params)) continue;
            const target = String(a.params.target ?? 'local');
            const deep = (a.params.merge ?? 'deep') === 'deep';
            for (const [k, v] of Object.entries(a.params)) {
                if (META.has(k) || k.startsWith('__')) continue;
                const segs = k.split('.');
                for (let i = 1; i < segs.length; i += 1) add(scope(file, `${target}|${segs.slice(0, i).join('.')}`), segs[i]);
                if (v === null) {
                    nulled.add(`${target}|${k}`);
                    continue;
                }
                if (!isPlain(v)) continue;
                const record = (path: string, obj: Node, top: boolean): void => {
                    Object.keys(obj).forEach((s) => add(scope(file, `${target}|${path}`), s));
                    if (top && opens && entry) {
                        openers.push({ file, state: `${target}|${path}`, keys: Object.keys(obj), reset: isNulled(target, path) || !deep });
                    }
                    Object.entries(obj).forEach(([ck, cv]) => { if (isPlain(cv)) record(`${path}.${ck}`, cv, false); });
                };
                record(k, v, true);
            }
        }
    };
    const formNames = (x: any, id: string): void => {
        if (Array.isArray(x)) return x.forEach((y) => formNames(y, id));
        if (!isPlain(x)) return;
        if (FORM_FIELDS.has(x.name) && typeof x.props?.name === 'string' && x.props?.autoBinding !== false) add(id, x.props.name.split('.')[0]);
        if (typeof x.dataKey === 'string') return;
        Object.values(x).forEach((v) => formNames(v, id));
    };
    for (const path of files) {
        const file = relative(ROOT, path).replace(/\\/g, '/');
        const walk = (x: any): void => {
            if (Array.isArray(x)) {
                scanList(x, file, x.length > 1);
                x.forEach(walk);

                return;
            }
            if (!isPlain(x)) return;
            if (x.handler === 'setState' && isPlain(x.params)) scanList([x], file, false);
            if (typeof x.dataKey === 'string') {
                const id = x.dataKey.startsWith('_global.') ? `global|${x.dataKey.slice(8)}` : scope(file, `local|${x.dataKey.replace(/^_local\./, '')}`);
                Object.entries(x).forEach(([k, v]) => k !== 'dataKey' && formNames(v, id));
            }
            Object.values(x).forEach(walk);
        };
        walk(JSON.parse(readFileSync(path, 'utf-8')));
    }
    const violations = openers.flatMap((o) => {
        if (o.reset) return [];
        const missing = [...(subkeys.get(scope(o.file, o.state)) ?? [])].filter((k) => !o.keys.includes(k));

        return missing.length ? [`${o.file} ${o.state} {${o.keys.join(',')}} 빠진 키 [${missing.join(',')}]`] : [];
    });

    return { violations, openers };
}

describe('창을 여는 부분 객체 쓰기는 같은 상태를 null 로 먼저 비운다 (7.1.1 정적 잠금)', () => {
    const { violations, openers } = scanOpeners();

    // @scenario case=static_all_basic_modals
    // @effects partial_open_preceded_by_null
    it('basic 굽기 산출 전부에서 위반이 없다', () => {
        expect(violations, violations.join('\n')).toEqual([]);
    });

    // @scenario case=static_all_basic_modals
    // @effects partial_open_preceded_by_null
    it('모집단에 마이페이지 배송지 새로/수정 열기·주문서 배송지 창 새로/수정 열기·게시판 댓글 삭제·글 신고 열기가 든다', () => {
        const at = (file: string, state: string) => openers.filter((o) => o.file === file && o.state === state);
        expect(at('layouts/partials/mypage/addresses/_list.json', 'global|editingAddress').length).toBe(3);
        // 주문서 창은 7.1.1 에서 창 고유 전역 이름 공간에 쓴다 — 창 안 새로·수정 열기 2곳 + 주문서 「배송지 관리」 단추 1곳
        expect(at('layouts/partials/shop/_modal_address_manage.json', 'global|checkoutAddressModal.editing').length).toBe(2);
        expect(at('layouts/partials/shop/_checkout_shipping.json', 'global|checkoutAddressModal').length).toBe(1);
        expect(at('layouts/partials/board/show/_comment_item.json', 'global|deleteModal').length).toBe(1);
        expect(at('layouts/partials/board/types/basic/show.json', 'global|reportModal').length).toBe(1);
        expect(openers.length).toBeGreaterThan(30);
    });
});
