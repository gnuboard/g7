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
    it('파일 목록과 편집기가 각자 폭 규약을 갖는다 (내용 길이로 폭이 갈리지 않는다)', () => {
        const layout = readLayout(LAYOUT);

        const listCard = findNode(layout, (n) => n.id === 'file_list_card');
        const editorCard = findNode(layout, (n) => n.id === 'editor_card');

        expect(listCard, 'file_list_card 노드가 사라졌다').toBeTruthy();
        expect(editorCard, 'editor_card 노드가 사라졌다').toBeTruthy();

        const listClass = String(listCard.props?.className ?? '');
        const editorClass = String(editorCard.props?.className ?? '');

        // 목록: 고정 폭 + 줄어들지 않음
        expect(listClass, '파일 목록에 고정 폭이 없으면 긴 파일명이 편집기 폭을 빼앗는다').toMatch(/\bw-\d+\b/);
        expect(listClass, '파일 목록이 shrink 되면 고정 폭 선언이 무력해진다').toContain('shrink-0');

        // 편집기: 남는 폭 전부 + 최소폭 해제
        expect(editorClass, '편집기가 flex-1 이 아니면 남는 폭을 가져가지 못한다').toContain('flex-1');
        expect(editorClass, 'min-w-0 이 없으면 코드의 긴 줄이 편집기 최소폭을 밀어 올린다').toContain('min-w-0');
    });

    it('파일명과 설명이 줄바꿈되어 목록 폭을 넘지 않는다', () => {
        const layout = readLayout(LAYOUT);

        // 파일명 노드 — `{{file.name}}.json`
        const nameNode = findNode(layout, (n) => typeof n.text === 'string' && n.text.includes('{{file.name}}.json'));
        expect(nameNode, '파일명 노드를 찾지 못했다').toBeTruthy();
        expect(
            String(nameNode.props?.className ?? ''),
            '모듈 파티션 이름은 공백이 없어 break-all 없이는 끊기지 않는다',
        ).toContain('break-all');

        // 설명 노드 — 설명이 없으면 이름으로 폴백하므로 같은 규칙이 필요하다
        const descNode = findNode(
            layout,
            (n) => typeof n.text === 'string' && n.text.includes('file.description') && n.text.includes('file.name'),
        );
        expect(descNode, '설명 노드를 찾지 못했다').toBeTruthy();
        expect(
            String(descNode.props?.className ?? ''),
            '설명은 이름으로 폴백하므로 파일명과 같은 줄바꿈 규칙이 필요하다',
        ).toContain('break-all');
    });
});

describe('레이아웃 코드 편집 화면 — 저장 후 목록 갱신', () => {
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
