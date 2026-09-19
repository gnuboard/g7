/**
 * @file admin-template-layout-edit-panels-and-versions.test.tsx
 * @description 레이아웃 코드 편집 화면 — 패널 폭 규약 / 저장 후 목록 갱신 / 버전 이력 상한 회귀 테스트
 *
 * 브라우저 실측(2026-08-05)에서 이 화면의 결함 3건이 드러났다. 셋 다 응답이나 콘솔에는
 * 아무 신호가 없고 화면에서만 드러나는 종류라, 계약을 레이아웃 JSON 수준에서 고정한다.
 *
 *  1. **패널 폭 규약 부재** — 파일 목록과 편집기 카드 어느 쪽에도 폭 지정이 없어 flex 가
 *     두 카드의 max-content 비율로 폭을 나눴다. 모듈 파티션 레이아웃 이름은 공백이 없는
 *     80자 이상이라 목록의 max-content 를 900px 이상으로 밀어 올렸고, 편집기가 473px 로
 *     눌렸다(실측 목록 814 / 편집기 473). 이름이 길어질수록 편집기가 좁아지는 구조다.
 *
 *  2. **저장 후 목록 미갱신** — 저장 성공 시 상세(`current_layout`)만 다시 불러서, 방금
 *     저장한 파일의 목록 행이 저장 전 설명·크기·수정일을 그대로 달고 있었다. 목록 응답이
 *     본문을 싣지 않게 된 뒤로 재조회가 수십 KB 라 매 저장마다 불러도 된다.
 *
 *  3. **버전 이력 상한 도달 불가** — 서버가 버전 목록에 기본 상한 100 을 도입(#518)했는데
 *     이 화면의 데이터소스는 `limit` 도 '더 보기' 도 없었다. 실측: DB 114건 중 100건만
 *     내려오고 나머지 14건은 어떤 조작으로도 열 수 없었다.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const LAYOUT = path.resolve(__dirname, '../../layouts/admin_template_layout_edit.json');
const VERSION_MODAL = path.resolve(
    __dirname,
    '../../layouts/partials/admin_template_layout_edit/_modal_version_history.json',
);

/** 서버 `LayoutVersionListRequest` 의 DEFAULT_LIMIT / MAX_LIMIT 과 같아야 하는 값 */
const VERSION_PAGE_SIZE = 100;
const VERSION_MAX_LIMIT = 500;

/**
 * JSON 레이아웃을 읽어 파싱합니다.
 *
 * @param file 대상 파일 절대경로
 * @returns 파싱된 레이아웃 객체
 */
function readLayout(file: string): any {
    return JSON.parse(fs.readFileSync(file, 'utf-8'));
}

/**
 * 트리에서 조건을 만족하는 첫 노드를 찾습니다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findNode(node: any, predicate: (n: any) => boolean): any | null {
    if (!node || typeof node !== 'object') return null;
    if (!Array.isArray(node) && predicate(node)) return node;

    for (const value of Array.isArray(node) ? node : Object.values(node)) {
        const hit = findNode(value, predicate);
        if (hit) return hit;
    }

    return null;
}

/**
 * 트리에서 조건을 만족하는 모든 노드를 모읍니다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @returns 찾은 노드 배열
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const found: any[] = [];

    const walk = (n: any): void => {
        if (!n || typeof n !== 'object') return;
        if (!Array.isArray(n) && predicate(n)) found.push(n);
        for (const value of Array.isArray(n) ? n : Object.values(n)) walk(value);
    };

    walk(node);

    return found;
}

describe('레이아웃 코드 편집 화면 — 패널 폭 규약', () => {
    /**
     * @scenario promise=panel_width, surface=layout_declaration
     *
     * @effects file_list_and_editor_keep_their_own_width_rules
     */
    it('파일 목록과 편집기가 각자 폭 규약을 갖는다 (내용 길이로 폭이 갈리지 않는다)', () => {
        const layout = readLayout(LAYOUT);

        const picker = findNode(layout, (n) => n.name === 'FileTreePicker');
        const editorCard = findNode(layout, (n) => n.id === 'editor_card');

        expect(picker, 'file-tree-picker 노드가 사라졌다').toBeTruthy();
        expect(editorCard, 'editor_card 노드가 사라졌다').toBeTruthy();

        // 목록: 줄어들지 않는다. 폭 **값**은 이제 컴포넌트가 소유하고(240~480px 드래그 + 기억),
        // 레이아웃은 "이 칸은 내용 길이로 밀리지 않는다" 만 선언한다.
        expect(
            String(picker.props?.className ?? ''),
            '파일 목록이 shrink 되면 긴 파일명이 편집기 폭을 빼앗는다',
        ).toContain('shrink-0');

        // 폭을 기억할 자리가 있어야 드래그 결과가 다음 진입까지 남는다.
        expect(String(picker.props?.storageKey ?? ''), '트리 폭·접힘을 적어 둘 키가 없다').toBeTruthy();

        // 편집기: 남는 폭 전부 + 최소폭 해제
        const editorClass = String(editorCard.props?.className ?? '');
        expect(editorClass, '편집기가 flex-1 이 아니면 남는 폭을 가져가지 못한다').toContain('flex-1');
        expect(editorClass, 'min-w-0 이 없으면 코드의 긴 줄이 편집기 최소폭을 밀어 올린다').toContain('min-w-0');
    });

    /**
     * 편집기 높이는 **고정 계산식이 아니다**(D13).
     *
     * 종전에는 `calc(100vh - 420px)` 이라 툴바가 한 줄만 늘어도 모바일에서 편집 영역이 과소해졌고,
     * iOS 주소창이 수축하면 `vh` 자체가 어긋났다.
     *
     * @scenario promise=editor_height, surface=layout_declaration
     *
     * @effects editor_height_flexes_instead_of_fixed_calc
     */
    it('편집 영역이 남는 높이를 가져간다 (고정 calc 금지 · dvh 기준)', () => {
        const layout = readLayout(LAYOUT);

        const editorCard = findNode(layout, (n) => n.id === 'editor_card');
        expect(String(editorCard.props?.className ?? '')).toContain('flex-col');

        const workspace = findNode(layout, (n) => n.name === 'CodeWorkspace');
        expect(workspace, 'code-workspace 노드가 사라졌다').toBeTruthy();
        expect(String(workspace.props?.className ?? '')).toContain('flex-1');
        expect(String(workspace.props?.className ?? '')).toContain('min-h-0');

        const grid = findNode(layout, (n) => n.id === 'main_grid');
        const gridClass = String(grid.props?.className ?? '');
        expect(gridClass, '높이 기준은 dvh 다 — vh 는 iOS 주소창 수축에서 어긋난다').toContain('dvh');
        expect(gridClass).not.toContain('100vh');
    });

    /**
     * 긴 파일명은 이제 **폴더로 쪼개져** 마지막 세그먼트만 보인다.
     *
     * 줄바꿈 규칙(`break-all`)으로 버티던 자리를 구조가 대신한다 — 트리 행은 자르고(`truncate`)
     * 전체 경로는 툴팁으로 남긴다.
     *
     * @scenario promise=panel_width, surface=component_contract
     *
     * @effects long_file_name_wraps_instead_of_widening_the_list
     */
    it('긴 파일명이 목록 폭을 넘지 않는다 (폴더 분할 + 마지막 세그먼트)', () => {
        const layout = readLayout(LAYOUT);

        const picker = findNode(layout, (n) => n.name === 'FileTreePicker');

        // 트리는 평면 이름을 받아 **컴포넌트가** 폴더로 쪼갠다 — 레이아웃에 목록 iteration 이 없다.
        expect(String(picker.props?.nodes ?? '')).toContain('layout_files');
        expect(
            findNode(layout, (n) => Boolean(n.iteration?.source?.includes('layout_files'))),
            '평면 파일 목록 iteration 이 남아 있다 (트리로 대체됐어야 한다)',
        ).toBeFalsy();
    });
});

describe('레이아웃 코드 편집 화면 — 저장 후 목록 갱신', () => {
    /**
     * @scenario promise=list_refresh_after_save, surface=layout_declaration
     *
     * @effects successful_save_refetches_both_detail_and_list
     */
    it('저장 성공 시 상세와 목록을 모두 다시 부른다', () => {
        const layout = readLayout(LAYOUT);

        const saveCall = findNode(
            layout,
            (n) => n.handler === 'apiCall' && n.params?.method === 'PUT' && String(n.target ?? '').includes('/layouts/'),
        );
        expect(saveCall, '저장 apiCall 액션을 찾지 못했다').toBeTruthy();

        const refetched = collectNodes(saveCall.onSuccess ?? [], (n) => n.handler === 'refetchDataSource').map(
            (n) => n.params?.dataSourceId,
        );

        expect(refetched, '저장 후 상세를 다시 부르지 않으면 lock_version 이 낡는다').toContain('current_layout');
        expect(
            refetched,
            '저장 후 목록을 다시 부르지 않으면 방금 저장한 행이 저장 전 설명·크기·수정일을 그대로 단다',
        ).toContain('layout_files');
    });
});

describe('레이아웃 코드 편집 화면 — 버전 이력 상한', () => {
    /**
     * @scenario promise=version_limit, surface=layout_declaration
     *
     * @effects version_query_carries_a_limit_and_resets_on_open
     */
    it('버전 목록 조회에 limit 이 붙고 열 때마다 첫 묶음으로 초기화된다', () => {
        const layout = readLayout(LAYOUT);

        const source = (layout.data_sources ?? []).find((d: any) => d.id === 'layout_versions');
        expect(source, 'layout_versions 데이터소스가 사라졌다').toBeTruthy();
        expect(
            String(source.endpoint),
            'limit 이 없으면 서버 기본 상한을 넘는 버전은 어떤 조작으로도 열 수 없다',
        ).toContain('limit=');

        // 모달 진입 시 상한 초기화 — 남겨두면 다른 레이아웃 이력 조회로 이월된다
        const openSequence = collectNodes(
            layout,
            (n) => n.handler === 'setState' && n.params?.layoutVersionLimit !== undefined,
        );
        expect(openSequence.length, '모달을 열 때 조회 상한을 초기화하지 않는다').toBeGreaterThan(0);
        expect(openSequence.some((n) => n.params.layoutVersionLimit === VERSION_PAGE_SIZE)).toBe(true);
    });

    /**
     * @scenario promise=version_limit, surface=layout_declaration
     *
     * @effects load_more_widens_the_limit_one_page_at_a_time_and_stops_at_the_server_cap
     */
    it('더 보기 버튼이 상한을 한 묶음씩 넓히고 서버 상한에서 멈춘다', () => {
        const modal = readLayout(VERSION_MODAL);

        const loadMore = findNode(modal, (n) => n.props?.['data-testid'] === 'layout-version-history-load-more');
        expect(loadMore, '더 보기 버튼이 없으면 상한 밖 버전은 도달 불가다').toBeTruthy();

        const wrapper = findNode(
            modal,
            (n) => typeof n.if === 'string' && n.if.includes('layoutVersionLimit') && n.if.includes('layout_versions'),
        );
        expect(wrapper, '더 보기 노출 조건이 없다').toBeTruthy();
        expect(
            String(wrapper.if),
            `서버 상한(${VERSION_MAX_LIMIT})에서 멈추지 않으면 그 이상 요청이 422 가 되어 목록 전체가 사라진다`,
        ).toContain(String(VERSION_MAX_LIMIT));

        const raw = JSON.stringify(loadMore);
        expect(raw, '더 보기가 상한을 넓히지 않는다').toContain('layoutVersionLimit');
        expect(raw, '넓힌 뒤 다시 조회하지 않으면 화면이 그대로다').toContain('layout_versions');
        expect(raw, `한 묶음(${VERSION_PAGE_SIZE})씩 넓혀야 한다`).toContain(String(VERSION_PAGE_SIZE));
    });
});
