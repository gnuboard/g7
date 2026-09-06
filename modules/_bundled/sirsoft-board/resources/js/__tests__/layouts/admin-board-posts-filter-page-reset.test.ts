/**
 * 관리자 게시글 목록 — 필터 변경 시 페이지 초기화 계약 회귀 테스트 (76차)
 *
 * @description
 * 목록을 좁히는 조작(분류·정렬·건수·상태)은 결과 집합의 크기를 바꾸므로 반드시 1페이지로
 * 돌아가야 한다. 그런데 **상태 필터만** `page` 를 그대로 두었다.
 *
 * 브라우저 실측(g7.dev, qna 게시판):
 *   `?page=2&per_page=15` 에서 상태를 「블라인드」로 고르면
 *   → `?page=2&per_page=15&status=blinded` (page 유지) → 결과 0건
 *   → 그런데 총 1페이지뿐이라 **페이지네이션 자체가 사라져** 1페이지로 돌아갈 수단이 없다.
 *   같은 필터의 `?page=1` 은 10건을 반환한다 — 데이터가 아니라 page 파라미터만이 원인.
 *   대조군인 분류 필터는 `?page=2` → `?page=1&category=...` 로 정상 초기화된다.
 *
 * @scenario filter=category
 * @effects narrowing_filter_resets_page_when_merging_query,
 *          rebuilt_url_drops_page_by_construction,
 *          pager_navigation_keeps_its_own_page,
 *          narrowing_filter_overlays_grid_body_only
 *
 * 계약:
 *   ① 결과 집합을 좁히는 모든 필터의 navigate 는 `query.page = 1` 을 함께 보낸다.
 *   ② 페이지 이동 자신(`page: {{$args[0]}}`)은 예외 — 그것이 page 를 정하는 주체다.
 *   ③ 그 필터들은 표 본문만 가리도록 `transition_overlay_target` 을 동일하게 준다
 *      (필터 줄이 사라지면 연속 조작이 끊긴다).
 */

import { describe, it, expect } from 'vitest';
import postsIndex from '../../../layouts/admin/admin_board_posts_index.json';

type NavigateAction = {
  ref: string | null;
  mergeQuery: boolean | null;
  query: Record<string, unknown> | null;
  overlayTarget: string | null;
};

/** 레이아웃 트리에서 navigate 핸들러를 가장 가까운 data-ref 와 함께 모은다. */
function navigateActions(node: any, nearestRef: string | null = null, out: NavigateAction[] = []): NavigateAction[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => navigateActions(n, nearestRef, out));

    return out;
  }

  const ref = typeof node.id === 'string' ? node.id : nearestRef;

  if (node.handler === 'navigate') {
    const params = node.params ?? {};
    out.push({
      ref,
      mergeQuery: params.mergeQuery ?? null,
      query: params.query ?? null,
      overlayTarget: params.transition_overlay_target ?? null,
    });
  }
  Object.values(node).forEach((v) => navigateActions(v, ref, out));

  return out;
}

/**
 * 결과 집합을 좁히는 query 키.
 *
 * `filters[0][value]` 는 검색어다 — 엔터 즉시 검색이 이 키만 얹어 보내면서 page 를 그대로
 * 두던 자리가 있었다(상태 필터와 같은 부류).
 */
const NARROWING_KEYS = ['category', 'status', 'sort_by', 'per_page', 'filters[0][value]'];

/** 그 navigate 가 결과를 좁히는가 (페이지 이동 자신은 제외) */
const isNarrowing = (n: NavigateAction) =>
  !!n.query
  && NARROWING_KEYS.some((k) => k in (n.query as object))
  && (n.query as any).page !== '{{$args[0]}}';

describe('관리자 게시글 목록 — 필터 변경 시 page 초기화', () => {
  const navs = navigateActions(postsIndex);
  const narrowing = navs.filter(isNarrowing);

  it('결과를 좁히는 navigate 를 모두 찾았다 (모집단이 비어 초록이 되는 것을 막는다)', () => {
    // 분류 · 상태 · 정렬 · 건수 · 엔터검색 · 검색버튼
    expect(narrowing.length).toBeGreaterThanOrEqual(6);
  });

  it('page 초기화에는 두 기전이 있고, 좁히는 navigate 는 반드시 둘 중 하나를 탄다', () => {
    // ① mergeQuery=false — URL 을 새로 짜므로 기존 page 가 딸려오지 않는다(자동 초기화)
    // ② mergeQuery=true  — 기존 query 를 이어받으므로 page:1 을 명시해야 한다
    for (const n of narrowing) {
      if (n.mergeQuery === false) {
        expect(
          (n.query as any).page,
          `${n.ref}: URL 을 새로 짜면서 page 를 도로 실어 보내면 초기화가 무산된다`
        ).toBeUndefined();
        continue;
      }

      // 되돌리는 표기는 둘이다 — `1` 은 첫 쪽을 명시하고, `''` 는 그 키를 URL 에서 빼
      // 데이터소스 기본값(1쪽)에 맡긴다. 규정이 적은 정본은 빈 문자열
      // (AGENTS.md "목록 컨텍스트 왕복": 페이지 되돌림은 `{"page": ""}`) 이고 둘 다 같은 쪽에
      // 도착하므로, 이 단언이 지키는 명제는 **표기**가 아니라 "이어받지 않는다" 다.
      expect(
        [1, ''],
        `${n.ref}: mergeQuery 로 기존 page 를 이어받는데 되돌리지 않는다 — `
          + '뒷페이지에서 좁히면 빈 화면이 되고 그때는 페이지네이션까지 사라진다'
      ).toContain((n.query as any).page);
    }
  });

  // 아래 it.each 가 여섯 갈래를 한 번에 돌지만, 커버리지 추출기는 docblock 당 첫 마킹만
  // 읽으므로 갈래별로 나누어 적는다 — 한 줄로 묶으면 다섯 갈래가 집계에서 조용히 빠진다.
  // @scenario filter=status
  // @scenario filter=sort
  // @scenario filter=per_page
  // @scenario filter=enter_search
  // @scenario filter=search_button
  it.each(NARROWING_KEYS)('%s 를 보내는 navigate 가 실재한다 (키 오탈자로 검사가 비는 것을 막는다)', (key) => {
    expect(navs.filter((n) => n.query && key in (n.query as object)).length).toBeGreaterThan(0);
  });

  it('페이지 이동 자신은 page 를 1 로 고정하지 않는다 (초기화 규칙의 예외)', () => {
    const pager = navs.filter((n) => n.query && (n.query as any).page === '{{$args[0]}}');
    expect(pager.length).toBeGreaterThan(0);
  });

  it('기존 화면을 이어받는 좁히기는 표 본문만 가린다 — 필터 줄이 사라지면 연속 조작이 끊긴다', () => {
    // mergeQuery=false 는 화면을 통째로 다시 그리므로 부분 오버레이 대상이 아니다.
    for (const n of narrowing.filter((x) => x.mergeQuery === true)) {
      expect(n.overlayTarget, `${n.ref} 에 오버레이 대상이 없다`).toBe('posts_data_grid__body');
    }
  });
});
