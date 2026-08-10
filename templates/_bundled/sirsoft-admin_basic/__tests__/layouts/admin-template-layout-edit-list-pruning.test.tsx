/**
 * @file admin-template-layout-edit-list-pruning.test.tsx
 * @description 레이아웃 파일트리·확장 라우트트리 — 목록 프루닝 후 화면 계약 회귀 테스트
 *
 * 회귀 배경 (#518 / 공개 #76):
 * 레이아웃 목록(`GET .../layouts`)과 확장 목록(`GET .../layout-extensions`)이 페이지네이션
 * 없이 전 행을 돌려주면서 행마다 본문(`content`)과 파싱된 블록(`components` 등)을 통째로
 * 싣고 있었다. 템플릿 하나에 레이아웃이 수십 개면 편집기 진입만으로 그 전부의 JSON 전문이
 * 전송된다.
 *
 * 목록은 파일트리·라우트트리가 실제로 그리는 필드만 싣고, 본문은 단건 조회가 공급하도록
 * 바꿨다. 이 화면은 두 목록의 **유일한 소비자**이므로, 화면이 무엇을 읽는지가 곧 목록이
 * 무엇을 실어야 하는지의 기준이다.
 *
 * 이 테스트가 고정하는 것은 두 방향이다.
 *
 *  1. 화면이 목록 응답에서 본문을 읽지 않는다 — 읽기 시작하면 프루닝이 곧 기능 삭제가 된다.
 *  2. 본문이 필요한 지점은 단건 데이터소스를 거친다 — 대체 경로가 살아 있다는 증거.
 *
 * 응답만 보는 백엔드 테스트로는 1번이 잡히지 않는다. 목록에서 필드가 사라진 것은 확인해도,
 * 그 필드를 화면이 쓰고 있었다는 사실은 화면 쪽을 봐야 드러난다 — 메뉴(M)가 정확히 그렇게
 * 새어 나갔다.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const LAYOUT = path.resolve(__dirname, '../../layouts/admin_template_layout_edit.json');

/** 목록 데이터소스 id — 이 id 를 통해 들어온 값에는 본문이 없다. */
const LIST_SOURCES = {
    layouts: 'layout_files',
    extensions: 'layout_extensions',
} as const;

/**
 * 레이아웃 JSON 원문을 읽습니다.
 *
 * @returns 파일 원문
 */
function readRaw(): string {
    return fs.readFileSync(LAYOUT, 'utf-8');
}

/**
 * 레이아웃 JSON 을 파싱해 데이터소스 목록을 반환합니다.
 *
 * @returns 데이터소스 배열
 */
function readDataSources(): Array<Record<string, unknown>> {
    const parsed = JSON.parse(readRaw()) as { data_sources?: Array<Record<string, unknown>> };

    return parsed.data_sources ?? [];
}

/**
 * @scenario resource=layout,endpoint=list,observation=consumer_screen
 * @effects list_omits_layout_content, detail_still_returns_full_payload
 */
describe('레이아웃 파일트리 — 목록 프루닝 (#518 / 공개 #76)', () => {
    it('목록 데이터소스가 확장자 없는 목록 엔드포인트를 그대로 쓴다', () => {
        const source = readDataSources().find((d) => d.id === LIST_SOURCES.layouts);

        expect(source, '파일트리 목록 데이터소스가 있어야 한다').toBeDefined();
        expect(source!.endpoint).toBe('/api/admin/templates/{{route.identifier}}/layouts');
    });

    it('목록 응답에서 본문·파싱 블록을 읽지 않는다', () => {
        const raw = readRaw();

        // 목록으로 들어온 값은 `layoutFilesList` / `layout_files` 두 이름으로 참조된다.
        // 어느 쪽이든 본문 키를 뒤에 붙이면 프루닝된 응답에서 undefined 가 된다.
        for (const holder of ['layoutFilesList', 'layout_files']) {
            for (const heavy of ['content', 'components', 'data_sources', 'metadata']) {
                expect(
                    raw,
                    `${holder}.${heavy} — 목록 응답에 없는 필드다. 본문이 필요하면 단건 데이터소스를 쓴다`,
                ).not.toContain(`${holder}?.${heavy}`);
                expect(raw).not.toContain(`${holder}.${heavy}`);
            }
        }
    });

    it('본문은 단건 데이터소스가 공급한다 (대체 경로 보존)', () => {
        const endpoints = readDataSources().map((d) => String(d.endpoint ?? ''));

        // 선택된 레이아웃 1건을 이름으로 조회하는 데이터소스 — 여기가 본문 공급처다.
        const detail = endpoints.find(
            (e) => e.includes('/layouts/') && e.includes('selectedLayoutName') && !e.includes('/versions'),
        );

        expect(detail, '목록에서 본문을 뺐으므로 단건 조회 경로가 반드시 있어야 한다').toBeDefined();
    });
});

/**
 * @scenario resource=layout_extension,endpoint=list,observation=consumer_screen
 * @effects list_omits_extension_content, detail_still_returns_full_payload
 */
describe('확장 라우트트리 — 목록 프루닝 (#518 / 공개 #76)', () => {
    it('확장 목록 데이터소스가 목록 엔드포인트를 그대로 쓴다', () => {
        const source = readDataSources().find((d) => d.id === LIST_SOURCES.extensions);

        expect(source, '라우트트리 확장 목록 데이터소스가 있어야 한다').toBeDefined();
        expect(source!.endpoint).toBe('/api/admin/templates/{{route.identifier}}/layout-extensions');
    });

    it('확장 목록 응답에서 본문을 읽지 않는다', () => {
        const raw = readRaw();

        expect(
            raw,
            'layout_extensions 응답에는 content 가 없다 — 캔버스는 단건 조회로 본문을 받는다',
        ).not.toContain('layout_extensions?.data?.content');
        expect(raw).not.toContain('layout_extensions.data.content');
    });

    it('확장 본문은 단건 데이터소스가 공급한다 (대체 경로 보존)', () => {
        const endpoints = readDataSources().map((d) => String(d.endpoint ?? ''));

        const detail = endpoints.find(
            (e) =>
                e.includes('/layout-extensions/') &&
                e.includes('selectedExtensionId') &&
                !e.includes('/versions'),
        );

        expect(detail, '목록에서 본문을 뺐으므로 확장 단건 조회 경로가 반드시 있어야 한다').toBeDefined();
    });
});
