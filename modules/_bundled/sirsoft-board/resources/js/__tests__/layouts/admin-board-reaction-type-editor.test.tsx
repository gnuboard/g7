// e2e:allow 가드 판별력 보강 전용(7차 검증, 부록 B D10) — 공허했던 negative 단언을 행 순회 서브트리 한정 검사로 교체했을 뿐, 대상 레이아웃·핸들러·런타임 동작은 한 줄도 바뀌지 않았다. 브라우저 흐름은 admin/board-reaction-type-editor.spec.ts 가 그대로 담당한다.
/**
 * 반응 유형 인라인 편집기 — 레이아웃 계약 회귀 (이슈 #525 WP-A)
 *
 * @description
 * 이 편집기가 지키는 계약:
 *  - 편집 대상은 **저장 전 폼 배열**(`form.basic_defaults.reaction_types`)이다. 데이터소스를
 *    보면 방금 추가한 유형을 저장 전에 고를 수 없고, 지운 유형이 선택지에 남는다.
 *  - 추가는 임시 폼에 담았다가 확인할 때 배열에 붙인다 — 취소해도 원본 배열은 그대로다.
 *  - 코드는 만든 뒤 바꿀 수 없다(기존 행의 코드 칸은 읽기 전용). 이미 쌓인 반응 행과
 *    게시판 설정이 코드로 이어져 있어, 바꾸면 그 연결이 끊긴다.
 *  - 행 편집은 **멤버 대입 없이** `map((r, i) => i === idx ? {...r, …} : r)` 로 한다.
 *  - 반응이 쌓인 유형은 지울 수 없고, 지울 때는 게시판이 고른 코드 목록에서도 함께 뺀다.
 *  - 넓은 화면(표)과 좁은 화면(카드)은 **같은 배열**을 편집한다 — 한쪽만 고치면 화면 폭에
 *    따라 다르게 동작한다.
 *
 * 저작본(.g7.html)의 기계 산출물인 `.json` 을 직접 읽는다 — 손으로 만든 fixture 를 검사하면
 * 실제 화면이 바뀌어도 초록이 유지된다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import editor from '../../../layouts/admin/partials/admin_board_settings/_reaction_type_editor.json';
import cards from '../../../layouts/admin/partials/admin_board_settings/_reaction_type_cards.json';
import settingsLayout from '../../../layouts/admin/admin_board_settings.json';

const editorJson = JSON.stringify(editor);
const cardsJson = JSON.stringify(cards);
const layoutJson = JSON.stringify(settingsLayout);

/** 편집기와 카드가 같은 규칙을 쓰는지 볼 때 두 벌을 함께 본다. */
const bothViews: Array<[string, string]> = [
    ['표(넓은 화면)', editorJson],
    ['카드(좁은 화면)', cardsJson],
];

type Node = Record<string, unknown>;

/**
 * 행을 순회하는 iteration 노드를 찾는다 — 행 편집 계약은 이 하위 트리 안에서만 성립한다.
 * 트리 전체를 문자열로 훑으면 「추가 폼」쪽 setState 까지 섞여 들어와, 행 편집 규칙을
 * 어긴 변이도 초록으로 통과한다.
 */
function findRowIteration(node: unknown): Node | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findRowIteration(child);
            if (found) return found;
        }
        return null;
    }
    if (node && typeof node === 'object') {
        const obj = node as Node;
        const iter = obj.iteration as Record<string, unknown> | undefined;
        if (iter && String(iter.source ?? '').includes('reaction_types')) {
            return obj;
        }
        // 끌어서 순서를 바꾸는 표는 iteration 이 아니라 sortable + itemTemplate 로 굽힌다.
        // 두 모양을 모두 받아야 순회 방식을 바꿔도 이 검사가 계속 같은 명제를 잰다.
        const sortable = obj.sortable as Record<string, unknown> | undefined;
        if (sortable && String(sortable.source ?? '').includes('reaction_types')) {
            return obj;
        }
        for (const value of Object.values(obj)) {
            const found = findRowIteration(value);
            if (found) return found;
        }
    }
    return null;
}

/** 하위 트리 안의 setState 액션을 모두 모은다. */
function collectSetStates(node: unknown): Node[] {
    const result: Node[] = [];
    const walk = (cur: unknown): void => {
        if (Array.isArray(cur)) {
            cur.forEach(walk);
            return;
        }
        if (cur && typeof cur === 'object') {
            const obj = cur as Node;
            if (obj.handler === 'setState') result.push(obj);
            Object.values(obj).forEach(walk);
        }
    };
    walk(node);
    return result;
}

/**
 * 행 순회 하위 트리에서 **유형 배열**(`reaction_types`)을 건드리는 setState 의 (키, 값) 쌍.
 *
 * 같은 하위 트리의 `active_reaction_types`(게시판이 고른 코드 목록)는 별개의 배열이므로
 * 제외한다 — 그쪽은 「행을 지우면 코드 목록에서도 뺀다」 테스트가 따로 본다.
 */
function rowReactionTypeWrites(layout: unknown): Array<[string, string]> {
    const rows = findRowIteration(layout);
    if (!rows) return [];
    const writes: Array<[string, string]> = [];
    for (const action of collectSetStates(rows)) {
        const params = (action.params ?? {}) as Record<string, unknown>;
        for (const [key, value] of Object.entries(params)) {
            if (/(^|\.)reaction_types($|[.[])/.test(key)) writes.push([key, String(value)]);
        }
    }
    return writes;
}

const bothTrees: Array<[string, unknown]> = [
    ['표(넓은 화면)', editor],
    ['카드(좁은 화면)', cards],
];

describe('반응 유형 편집기 — 편집 대상과 추가 폼', () => {
    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_iterates_form_reaction_types_not_datasource
    it('표와 카드가 같은 폼 배열을 순회한다', () => {
        for (const [label, json] of bothViews) {
            expect(/_local\.form\?\.basic_defaults\?\.reaction_types/.test(json), label).toBe(true);
            // 자리 번호를 함께 들고 다녀야 map-by-index 편집이 가능하다.
            expect(/_idx: i/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_iterates_form_reaction_types_not_datasource
    it('편집기는 별도 유형 데이터소스를 쓰지 않는다', () => {
        expect(/reactionTypes\?\.data/.test(editorJson)).toBe(false);
        expect(/"id":\s*"reactionTypes"/.test(layoutJson)).toBe(false);
    });

    // @scenario case=settings_editor_add_form_validates
    // @effects settings_editor_add_form_checks_required_and_duplicate
    it('추가는 임시 폼에 담았다가 확인할 때 배열에 붙인다', () => {
        // 임시 폼이 없으면 취소가 원본 배열을 되돌려야 하는데, 되돌릴 기준이 없다.
        expect(/"isAddingReactionType"/.test(editorJson)).toBe(true);
        expect(/"reactionTypeForm"/.test(editorJson)).toBe(true);
        // 확인 시 기존 배열 뒤에 붙인다.
        expect(/\.\.\.\(_local\.form\?\.basic_defaults\?\.reaction_types \?\? \[\]\)/.test(editorJson)).toBe(true);
    });

    // @scenario case=settings_editor_add_form_validates
    // @effects settings_editor_add_form_checks_required_and_duplicate
    it('코드와 사이트 기본 언어 이름이 있어야 하고 코드가 겹치면 거절한다', () => {
        // 통과 조건 — 세 가지를 모두 본다.
        expect(/reactionTypeForm\?\.code \?\? ''\)\.trim\(\)/.test(editorJson)).toBe(true);
        expect(editorJson).toContain("reactionTypeForm?.name?.[_global.appConfig?.siteLocale ?? $locale ?? 'ko'] ?? '').trim()");
        expect(/\.some\(t => t\.code ===/.test(editorJson)).toBe(true);
        // 실패하면 배열은 건드리지 않고 어느 칸이 문제인지만 세운다.
        expect(/'required'/.test(editorJson)).toBe(true);
        expect(/'duplicate'/.test(editorJson)).toBe(true);
    });

    // @scenario case=settings_editor_add_form_validates
    // @effects settings_editor_add_form_checks_required_and_duplicate
    it('새 행은 순서를 배열 길이로, 반응 수를 0 으로 시작한다', () => {
        expect(/reaction_count: 0/.test(editorJson)).toBe(true);
        expect(/display_order: \(_local\.form\?\.basic_defaults\?\.reaction_types \?\? \[\]\)\.length/.test(editorJson)).toBe(true);
    });

    // @scenario case=settings_editor_add_form_validates
    // @effects settings_editor_add_form_checks_required_and_duplicate
    it('코드는 앞뒤 공백을 떼고 소문자로 맞춰 담는다', () => {
        expect(/\.trim\(\)\.toLowerCase\(\)/.test(editorJson)).toBe(true);
    });
});

describe('반응 유형 편집기 — 행 편집·순서·삭제', () => {
    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_row_edit_uses_map_by_index
    it('행 편집은 멤버 대입이 아니라 map-by-index 로 새 배열을 만든다', () => {
        for (const [label, json] of bothViews) {
            expect(/\.map\(\(r, i\) => i === row\._idx \?/.test(json), label).toBe(true);
        }

        // 행 순회 하위 트리로 좁혀서 본다 — 트리 전체를 문자열로 훑으면 「추가 폼」의
        // setState 까지 섞여, 행 편집이 멤버 대입으로 바뀌어도 초록이 유지된다.
        for (const [label, tree] of bothTrees) {
            const writes = rowReactionTypeWrites(tree);
            expect(writes.length, `${label}: 행 편집 setState 가 하나도 없다`).toBeGreaterThan(0);

            for (const [key, value] of writes) {
                // (1) 쓰기 대상은 **배열 전체** 한 칸이어야 한다.
                //     `…reaction_types.code` / `…reaction_types[0]` 같은 멤버·인덱스 경로로
                //     쓰면 배열이 통째로 갈리지 않아 상태 갱신을 놓친다.
                expect(key, `${label}: setState 대상 「${key}」 가 멤버·인덱스 경로다`).toBe(
                    'form.basic_defaults.reaction_types',
                );

                // (2) 값은 원본 배열에서 새 배열을 만드는 식이어야 한다 (map/filter).
                expect(
                    /\.(map|filter)\(/.test(value),
                    `${label}: 「${key}」 값이 map/filter 로 새 배열을 만들지 않는다 — ${value}`,
                ).toBe(true);

                // (3) 배열 요소·속성에 직접 대입하는 식이 섞이면 안 된다
                //     (a[i] = …, r.name = …, row.name = … 등). `===`/`<=` 등 비교나
                //     `=>` 화살표는 대입이 아니므로 제외한다.
                expect(
                    /(\]|\.\w+)\s=(?![=>])/.test(value),
                    `${label}: 「${key}」 값에 멤버·인덱스 대입이 있다 — ${value}`,
                ).toBe(false);
            }
        }
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_reorder_is_drag_and_drop
    it('순서는 끌어서 바꾼다 — 끌기 손잡이와 정렬 목록이 붙어 있다', () => {
        // 형제 화면(배송유형 설정)과 같은 방식이다 — 행을 sortable 로 순회하고
        // 손잡이를 따로 두어, 행 안의 입력·토글을 누를 때 끌기가 시작되지 않게 한다.
        expect(/"sortable":\{/.test(editorJson)).toBe(true);
        expect(/"handle":"\[data-drag-handle\]"/.test(editorJson)).toBe(true);
        expect(/"wrapperElement":"tr"/.test(editorJson)).toBe(true);
        expect(/"itemTemplate":\{/.test(editorJson)).toBe(true);
        expect(/data-drag-handle/.test(editorJson)).toBe(true);
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_reorder_is_drag_and_drop
    it('끌어 놓은 결과를 배열 순서로 반영하고 순서 값을 다시 매긴다', () => {
        // 순서 값(display_order)이 배열 순서와 어긋나면 저장 뒤 관리자 화면과
        // 회원 화면의 차례가 달라진다 — 끌기가 끝날 때 반드시 다시 매긴다.
        expect(/\$sortedItems\.map\(\(r, i\) => \(\{\.\.\.r, display_order: i\}\)\)/.test(editorJson)).toBe(true);
        // 좁은 화면 카드에도 순서를 바꿀 수단이 있어야 한다 — 없으면 휴대폰에서 차례를 고칠
        // 길이 없다. 다만 손잡이 끌기는 스크롤과 다투므로 그쪽은 위/아래 버튼으로 둔다.
        expect(/reaction-type-up-m-/.test(cardsJson)).toBe(true);
        expect(/reaction-type-down-m-/.test(cardsJson)).toBe(true);
        expect(/display_order: i/.test(cardsJson)).toBe(true);
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_delete_is_confirmed_and_blocked_by_count
    it('삭제는 확인을 거치고, 반응이 쌓인 유형은 아예 잠긴다', () => {
        for (const [label, json] of bothViews) {
            expect(/reaction_types\.confirm_delete/.test(json), label).toBe(true);
            expect(/\(row\.reaction_count \?\? 0\) > 0/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_delete_is_confirmed_and_blocked_by_count
    it('잠긴 삭제 버튼의 안내는 래퍼에 건다 — disabled 버튼은 hover 이벤트가 죽는다', () => {
        for (const [label, json] of bothViews) {
            expect(/reaction-type-delete-wrap-/.test(json), label).toBe(true);
            expect(/reaction_types\.delete_blocked/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_delete_strips_code_from_active_types
    it('행을 지우면 게시판이 고른 코드 목록에서도 같이 뺀다', () => {
        for (const [label, json] of bothViews) {
            expect(/"form\.basic_defaults\.active_reaction_types"/.test(json), label).toBe(true);
            expect(/filter\(c => c !== row\.code\)/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_delete_strips_code_from_active_types
    it('삭제 뒤 남은 행의 순서 값을 다시 매긴다', () => {
        for (const [label, json] of bothViews) {
            expect(/filter\(\(_, i\) => i !== row\._idx\)/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_row_edit_uses_map_by_index
    it('코드 칸은 읽기 전용이다 — 바꾸면 이미 쌓인 반응과의 연결이 끊긴다', () => {
        // 추가 폼에는 코드 입력이 있다 (새로 만들 때만 정한다).
        expect(/reactionTypeForm\.code=/.test(editorJson) || /"reactionTypeForm\.code"/.test(editorJson)).toBe(true);

        // 기존 행에서는 코드를 되받아 쓰지 않는다. 행 순회 하위 트리의 setState 값 중
        // 어떤 것도 `code:` 를 다시 써서는 안 된다 — `{...r, code: …}` 가 생기면 이미
        // 쌓인 반응 행·게시판 설정과의 코드 연결이 끊긴다.
        for (const [label, tree] of bothTrees) {
            const writes = rowReactionTypeWrites(tree);
            expect(writes.length, label).toBeGreaterThan(0);

            for (const [key, value] of writes) {
                expect(
                    /\bcode:/.test(value),
                    `${label}: 「${key}」 가 기존 행의 code 를 다시 쓴다 — ${value}`,
                ).toBe(false);
            }
        }
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_row_edit_uses_map_by_index
    it('행이 없으면 빈 상태를, 있으면 목록을 낸다', () => {
        expect(/reaction-type-empty/.test(editorJson)).toBe(true);
        expect(/reaction_types\.empty/.test(editorJson)).toBe(true);
    });
});

describe('반응 유형 편집기 — 아이콘 업로드', () => {
    // @scenario case=settings_editor_icon_uploader_wired
    // @effects settings_editor_icon_uploader_declares_module_endpoints
    it('업로더가 모듈 전용 주소를 쓴다', () => {
        for (const [label, json] of bothViews) {
            expect(/\/api\/modules\/sirsoft-board\/admin\/reaction-types\/icons/.test(json), label).toBe(true);
            expect(/\/api\/modules\/sirsoft-board\/admin\/reaction-types\/icons\/:id/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_icon_uploader_wired
    // @effects settings_editor_icon_uploader_declares_module_endpoints
    it('한 장만·1MB 이하·비트맵만 받는다 (svg 는 받지 않는다)', () => {
        for (const [label, json] of bothViews) {
            expect(/"collection":\s*"reaction_type_icon"/.test(json), label).toBe(true);
            expect(/\.png,\.jpg,\.jpeg,\.gif,\.webp/.test(json), label).toBe(true);
            // svg 는 스크립트를 품을 수 있어 아이콘으로 받지 않는다.
            expect(/\.svg/.test(json), label).toBe(false);
        }
    });

    // @scenario case=settings_editor_icon_uploader_wired
    // @effects settings_editor_icon_callbacks_write_three_fields
    it('업로드가 끝나면 첨부 셋(id·url·원본)을 그 행에 심는다', () => {
        for (const [label, json] of bothViews) {
            expect(/icon_attachment_id: \$args\[0\]\?\.\[0\]\?\.id/.test(json), label).toBe(true);
            expect(/icon_url: \$args\[0\]\?\.\[0\]\?\.download_url/.test(json), label).toBe(true);
            expect(/icon_attachment: \$args\[0\]\?\.\[0\]/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_icon_uploader_wired
    // @effects settings_editor_icon_callbacks_write_three_fields
    it('아이콘을 떼면 세 칸을 모두 비워 글꼴 아이콘 폴백으로 돌아간다', () => {
        for (const [label, json] of bothViews) {
            expect(/icon_attachment_id: null, icon_url: null, icon_attachment: null/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_icon_uploader_wired
    // @effects settings_editor_icon_callbacks_write_three_fields
    it('업로드·삭제 실패는 각각의 문구로 알린다', () => {
        for (const [label, json] of bothViews) {
            expect(/reaction_types\.messages\.icon_upload_failed/.test(json), label).toBe(true);
            expect(/reaction_types\.messages\.icon_delete_failed/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_icon_uploader_wired
    // @effects settings_checkbox_renders_image_icon_before_font_icon
    it('행 미리보기는 이미지가 있으면 이미지로, 없으면 글꼴 아이콘으로 그린다', () => {
        for (const [label, json] of bothViews) {
            expect(/!!row\.icon_url/.test(json), label).toBe(true);
            expect(/!row\.icon_url/.test(json), label).toBe(true);
        }
    });
});

describe('반응 유형 편집기 — 읽기 전용·저장 페이로드', () => {
    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_row_edit_uses_map_by_index
    it('모든 컨트롤이 읽기 전용 판정을 따른다', () => {
        for (const [label, json] of bothViews) {
            expect(/_computed\.isReadOnly/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects save_payload_carries_reaction_types
    it('편집이 일어나면 저장 대기 표시를 세운다', () => {
        for (const [label, json] of bothViews) {
            expect(/"hasChanges":\s*true/.test(json), label).toBe(true);
        }
    });

    // @scenario case=settings_editor_renders_form_rows
    // @effects settings_editor_row_edit_uses_map_by_index
    it('좁은 화면 카드의 testid 는 -m- 접미로 갈린다', () => {
        // 넓은/좁은 두 벌이 같은 testid 를 쓰면 E2E 가 어느 쪽을 집었는지 알 수 없다.
        expect(/reaction-type-row-m-/.test(cardsJson)).toBe(true);
        expect(/reaction-type-delete-m-/.test(cardsJson)).toBe(true);
        expect(/reaction-type-row-m-/.test(editorJson)).toBe(false);
    });
});

/** 하위 트리에서 키가 key 이고 값이 pred 를 만족하는 문자열 값을 모두 모은다. */
function collectStringValues(node: unknown, key: string, pred: (v: string) => boolean, out: string[] = []): string[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collectStringValues(child, key, pred, out));
        return out;
    }
    if (node && typeof node === 'object') {
        for (const [k, v] of Object.entries(node as Node)) {
            if (k === key && typeof v === 'string' && pred(v)) out.push(v);
            collectStringValues(v, key, pred, out);
        }
    }
    return out;
}

/** `{{ … }}` 표현식을 주어진 지역 상태로 평가한다. */
function evaluate(expr: string, local: Record<string, unknown>, locale = 'ko', global: Record<string, unknown> = { appConfig: { siteLocale: 'ko' } }): unknown {
    const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return new Function('_local', '$locale', '_global', `return (${body});`)(local, locale, global);
}

describe('반응 유형 편집기 — 추가 폼 코드 형식 검사', () => {
    const existing = [{ code: 'like' }, { code: 'dislike' }];
    const stateFor = (code: string) => ({
        reactionTypeForm: { code, name: { ko: '이름' } },
        form: { basic_defaults: { reaction_types: existing } },
    });

    // 서버 규칙(StoreBoardSettingsRequest): ^[a-z][a-z0-9_]{1,49}$ — 추가 단계에서 거르지 않으면
    // 「Bad Code!」 같은 코드가 목록에 붙고(만든 뒤엔 코드 칸을 고칠 수 없다) 저장에서야 422 가 났다(K1 실측 2026-10-06).
    const cases: Array<[string, boolean, string | null]> = [
        ['qa_type1', true, null],
        ['ab', true, null],
        ['', false, 'required'],
        ['like', false, 'duplicate'],
        ['bad code!', false, 'format'],
        ['1abc', false, 'format'],
        ['a', false, 'format'],
        ['a'.repeat(51), false, 'format'],
        ['abc-def', false, 'format'],
    ];

    for (const [label, tree] of bothTrees) {
        // @scenario case=settings_editor_add_form_validates
        // @effects settings_editor_add_form_checks_code_format
        it(`${label} — 확인 조건과 오류 갈래가 서버 코드 규칙을 따른다`, () => {
            const passIf = collectStringValues(tree, 'if', (v) => v.includes('reactionTypeForm?.code') && v.includes('.some(t =>'));
            const errorValue = collectStringValues(tree, 'code', (v) => v.includes("'required'") && v.includes("'duplicate'"));

            expect(passIf.length, '확인 조건').toBe(1);
            expect(errorValue.length, '오류 갈래').toBe(1);

            for (const [code, ok, error] of cases) {
                expect(Boolean(evaluate(passIf[0], stateFor(code))), `통과 ${JSON.stringify(code)}`).toBe(ok);
                expect(evaluate(errorValue[0], stateFor(code)), `오류 ${JSON.stringify(code)}`).toBe(error);
            }
        });

        // @scenario case=settings_editor_add_form_validates
        // @effects settings_editor_add_form_checks_code_format
        it(`${label} — 형식 오류 문구가 코드 칸 아래에 선다`, () => {
            expect(JSON.stringify(tree)).toContain('reaction_types.validation.code_format');
        });
    }
});

describe('반응 유형 편집기 — 아이콘 업로드 실패 안내', () => {
    // @scenario case=settings_editor_icon_uploader_wired
    // @effects settings_editor_icon_upload_failure_names_limits
    it('업로드 실패 문구가 허용 형식과 1MB 한도를 함께 말한다', () => {
        // 좁은 칸(size xs)의 업로더는 빈 상태에서 한도 안내를 숨긴다 — 1MB 초과·SVG 를 고르면 문구가 유일한 안내였는데
        // 「아이콘 업로드에 실패했습니다」만 떠서 왜 거절됐는지 알 수 없었다(K1 실측 2026-10-06).
        for (const locale of ['ko', 'en']) {
            // eslint-disable-next-line @typescript-eslint/no-var-requires
            const settings = require(`../../../lang/partial/${locale}/admin/settings.json`);
            const message: string = settings.reaction_types.messages.icon_upload_failed;

            expect(message, locale).toMatch(/PNG/);
            expect(message, locale).toMatch(/WEBP/);
            expect(message, locale).toMatch(/1MB/);
        }
    });
});

/**
 * 반응 유형 이름의 필수 언어는 사이트 기본 언어다 — 게시판 유형 이름과 같은 계약(서버 LocaleRequiredTranslatable,
 * 2026-10-09 PO 확정). 화면 언어와 무관하게 사이트 기본 언어 칸이 비면 추가하지 않고, 서버가 이름 칸 전체 키(name)나
 * 사이트 기본 언어 키(name.{언어})로 돌려준 오류를 그 행 이름 칸에 붙인다.
 */
describe('반응 유형 편집기 — 이름 필수 언어는 사이트 기본 언어', () => {
    const stateWith = (name: Record<string, string>) => ({
        reactionTypeForm: { code: 'qa_type1', name },
        form: { basic_defaults: { reaction_types: [{ code: 'like' }] } },
    });

    for (const [label, tree] of bothTrees) {
        // @scenario case=settings_editor_add_form_validates
        // @effects add_requires_code_and_default_name
        it(`${label} — 추가 확인 조건과 이름 오류 갈래가 사이트 기본 언어 칸을 본다`, () => {
            const passIf = collectStringValues(tree, 'if', (v) => v.includes('reactionTypeForm?.code') && v.includes('.some(t =>'));
            const nameError = collectStringValues(tree, 'name', (v) => v.includes('reactionTypeForm?.name'));
            expect(passIf.length, '확인 조건').toBe(1);
            expect(nameError.length, '이름 오류 갈래').toBe(1);

            const cases: Array<[string, string, Record<string, string>, boolean]> = [
                ['en', 'ko', { ko: '', en: 'Cheer' }, false],
                ['en', 'ko', { ko: '응원' }, true],
                ['ja', 'ko', { ja: '応援' }, false],
                ['ja', 'ko', { ko: '응원', en: 'Cheer' }, true],
                ['ko', 'ko', { en: 'Cheer' }, false],
                ['ko', 'ko', { ko: '응원' }, true],
                ['ko', 'en', { en: 'Cheer' }, true],
                ['ko', 'en', { ko: '응원' }, false],
            ];
            for (const [locale, site, name, ok] of cases) {
                const tag = `${locale}/${site} ${JSON.stringify(name)}`;
                const g = { appConfig: { siteLocale: site } };
                expect(Boolean(evaluate(passIf[0], stateWith(name), locale, g)), `통과 ${tag}`).toBe(ok);
                expect(evaluate(nameError[0], stateWith(name), locale, g), `오류 ${tag}`).toBe(ok ? null : true);
            }
            expect(JSON.stringify(tree)).not.toContain('name_ko');
        });

        // @scenario case=settings_missing_default_name_rejected
        // @effects add_requires_code_and_default_name
        it(`${label} — 저장 422 의 이름 오류(name 또는 name.{사이트 기본 언어})가 그 행 이름 칸에 붙는다`, () => {
            const rowError = collectStringValues(tree, 'error', (v) => v.includes("'.name"));
            expect(rowError.length, '행 이름 칸 오류').toBe(1);
            const rowErr = (errors: Record<string, string[]>, locale: string, site = 'ko') => {
                const body = rowError[0].trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
                // eslint-disable-next-line no-new-func
                return new Function('_local', '$locale', 'row', '_global', `return (${body});`)(
                    { errors }, locale, { _idx: 2 }, { appConfig: { siteLocale: site } }
                );
            };
            expect(rowErr({ 'basic_defaults.reaction_types.2.name': ['한국어 값은 필수'] }, 'en')).toBe('한국어 값은 필수');
            expect(rowErr({ 'basic_defaults.reaction_types.2.name.ko': ['50자 초과'] }, 'en')).toBe('50자 초과');
            expect(rowErr({ 'basic_defaults.reaction_types.2.name.en': ['화면 언어 키'] }, 'en')).toBe('');
            expect(rowErr({ 'basic_defaults.reaction_types.2.name.en': ['영어 값'] }, 'ko', 'en')).toBe('영어 값');
            expect(rowErr({}, 'ko')).toBe('');
        });
    }
});
