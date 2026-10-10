/**
 * @file board-list-search-field.test.tsx
 * @description 게시판 목록 검색 구분 (#524 WP-B) — 세 가지 형(기본·카드·갤러리)이 같은 계약을 쓴다.
 *
 * 고치기 전에는 검색이 언제나 제목과 내용을 함께 훑었다. 「제목에 그 말이 든 글만」을 고를 수
 * 없어, 본문에 흔한 낱말이 들어간 글까지 걸려 나왔다. 그래서 `title_content` · `title` ·
 * `content` · `author` 네 갈래를 연다.
 *
 * 세 형은 **서로 다른 조각 파일**이다 — 한 곳만 고치면 다른 형의 게시판에서는 예전대로 동작한다.
 * 그 비대칭을 막으려고 세 조각을 같은 표로 검사한다.
 *
 * 고른 값은 제출할 때 주소의 `search_field` 로 옮긴다. 지역 상태로만 두면 뒤로가기·링크 공유에서
 * 고른 대상이 사라지고, 서버는 기본값으로 되돌아간 결과를 준다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { asArray, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const boardIndex = require('../../layouts/board/index.json');
const koLang = require('../../lang/partial/ko/board.json');
const enLang = require('../../lang/partial/en/board.json');

const types: Record<string, any> = {
    basic: require('../../layouts/partials/board/types/basic/index.json'),
    card: require('../../layouts/partials/board/types/card/index.json'),
    gallery: require('../../layouts/partials/board/types/gallery/index.json'),
};

const EXPECTED_FIELDS = ['title_content', 'title', 'content', 'author'];

/** 검색 대상 셀렉트를 조각에서 집는다. */
function searchSelect(layout: any): any {
    return flattenNodes(layout).find((n: any) => asArray(n?.props?.options).some((o: any) => o.value === 'title_content'));
}

/** 검색 제출이 부르는 navigate 액션을 집는다. */
function searchNavigate(layout: any): any {
    const out: any[] = [];
    const visit = (value: any): void => {
        if (!value || typeof value !== 'object') return;
        if (Array.isArray(value)) {
            value.forEach(visit);
            return;
        }
        if (value.handler === 'navigate' && value.params?.query && 'search_field' in value.params.query) out.push(value);
        for (const child of Object.values(value)) visit(child);
    };
    visit(layout);
    return out[0] ?? null;
}

describe('게시판 목록 검색 구분 (#524 WP-B)', () => {
    // @scenario surface=board_list, search_field=title_content
    // @effects board_list_datasource_passes_search_field
    it('목록 데이터소스가 search_field 를 주소에서 읽어 서버로 보낸다', () => {
        const ds = boardIndex.data_sources.find((d: any) => d.id === 'posts');

        expect(ds.params.search_field).toMatch(/query\.search_field/);
        expect(ds.params.search).toMatch(/query\.search/);
    });

    for (const [type, layout] of Object.entries(types)) {
        // @scenario surface=board_list, board_type=basic
        // @effects board_list_search_field_offers_four_targets
        it(`${type} 형 목록이 네 갈래 검색 대상을 준다`, () => {
            const select = searchSelect(layout);

            expect(select).toBeTruthy();
            expect(asArray(select.props.options).map((o: any) => o.value)).toEqual(EXPECTED_FIELDS);
        });

        // @scenario surface=board_list, board_type=card
        // @effects board_list_search_field_defaults_to_title_content
        it(`${type} 형 셀렉트가 주소 값을 초기값으로 되살리고 없으면 제목+내용이다`, () => {
            const select = searchSelect(layout);

            // 뒤로가기·링크 공유에서 고른 대상이 그대로 살아나야 한다.
            expect(select.props.value).toMatch(/query\.search_field/);
            expect(select.props.value).toMatch(/title_content/);
        });

        // @scenario surface=board_list, board_type=gallery
        // @effects board_list_search_submits_field_to_query
        it(`${type} 형 검색 제출이 고른 대상을 주소의 search_field 로 옮긴다`, () => {
            const navigate = searchNavigate(layout);

            expect(navigate).toBeTruthy();
            expect(navigate.params.query.search_field).toMatch(/search_field|SearchField/);
            // 새 검색이므로 첫 쪽으로 되돌린다.
            expect(navigate.params.query.page).toBe(1);
        });
    }

    /*
     * 이 템플릿은 `layouts/**` 를 Tailwind `@source` 로 앵커하지 않는다 — `src/styles/main.css:17`
     * 이 그렇게 적어 두었고, 그래서 레이아웃이 쓰는 클래스는 `safelist.txt` 에 있어야만 CSS 가
     * 만들어진다. 빠지면 **오류 없이 조용히** 스타일만 사라진다.
     *
     * 실측(#524 4차 검증): 검색 줄을 오른쪽으로 미는 `sm:ml-auto` 가 목록에 없어 PC·태블릿에서
     * 줄이 왼쪽에 붙었다. 레이아웃 검사도 vitest 도 전부 초록이었다 — 아무도 이 축을 재지 않았다.
     *
     * 모집단은 세 조각 파일에서 **직접 뽑는다**(손으로 적지 않는다). 조각이 늘거나 새 반응형
     * 클래스가 들어오면 그만큼 자동으로 검사 대상이 된다.
     */
    const RESPONSIVE_PREFIX = /^(sm|md|lg|xl|2xl):/;

    /**
     * 이 검사가 만들어지기 전부터 빠져 있던 것들. #524 가 들인 것이 아니라(diff 에 없다) 고치면
     * 이 계획이 건드리지 않은 자리의 모양이 바뀌므로, 사유를 적어 두고 별건으로 남긴다.
     * 여기에 새 이름을 더하는 것은 «검사를 통과시키려는 것» 이므로 하지 않는다.
     */
    const PRE_EXISTING_GAPS = new Set(['sm:min-w-48', 'sm:w-64']);

    /** 조각 `.json` 들이 쓰는 정적 className 토큰 전량. 식(`{{…}}`)은 건너뛴다. */
    function responsiveClassTokens(): string[] {
        const found = new Set<string>();
        const visit = (value: any): void => {
            if (!value || typeof value !== 'object') return;
            if (Array.isArray(value)) {
                value.forEach(visit);
                return;
            }
            const cls = value?.props?.className;
            if (typeof cls === 'string' && !cls.includes('{{')) {
                for (const token of cls.split(/\s+/)) {
                    if (RESPONSIVE_PREFIX.test(token)) found.add(token);
                }
            }
            for (const child of Object.values(value)) visit(child);
        };
        Object.values(types).forEach(visit);

        return [...found].sort();
    }

    // @scenario surface=board_list, board_type=basic
    // @effects board_list_responsive_classes_are_safelisted
    it('세 조각이 쓰는 반응형 클래스가 전부 Tailwind safelist 에 있다', () => {
        const safelist = new Set(
            fs
                .readFileSync(path.resolve(__dirname, '../../src/styles/safelist.txt'), 'utf8')
                .split(/\s+/)
                .filter(Boolean)
        );
        const tokens = responsiveClassTokens();

        // 모집단이 비면 이 검사는 아무것도 재지 않는다 — 그 상태를 통과로 읽지 않는다.
        expect(tokens.length).toBeGreaterThan(0);

        const missing = tokens.filter((t) => !safelist.has(t) && !PRE_EXISTING_GAPS.has(t));

        expect(missing).toEqual([]);
    });

    // @scenario surface=board_list, search_field=content
    // @effects board_list_search_field_labels_have_ko_en
    it('네 갈래 라벨이 ko·en 양쪽에 있다', () => {
        for (const key of EXPECTED_FIELDS) {
            expect(koLang.search_fields?.[key]).toBeTruthy();
            expect(enLang.search_fields?.[key]).toBeTruthy();
        }
    });
});
