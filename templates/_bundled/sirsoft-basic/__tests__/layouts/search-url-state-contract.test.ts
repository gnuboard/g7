import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * 통합검색 화면 상태의 SSoT 계약 (#519)
 *
 * 탭·게시판필터·정렬·페이지·커서를 전역 상태(`_global.search*`)에만 두면 주소가 바뀌지
 * 않는다. 그러면 새로고침·뒤로가기에서 전부 초기값으로 돌아가고, 보고 있던 결과를 주소로
 * 공유할 수도 없다. 화면은 정상으로 보이고 에러도 남지 않아 코드만 봐서는 드러나지 않는다.
 *
 * 이 계약은 다음 셋을 고정한다.
 *   1. 검색 데이터소스는 URL 쿼리를 읽는다 (전역 상태 아님)
 *   2. 검색 화면 어디에도 `_global.search*` 잔재가 없다
 *   3. 상태를 바꾸는 이동은 mergeQuery 로 나머지 상태를 승계한다
 *      (검색어를 새로 실행하는 의도적 리셋만 예외이며, 그 사유가 코드에 적혀 있어야 한다)
 *
 * 파일을 열거하지 않고 검색 레이아웃 디렉토리를 훑는다. 열거하면 partial 이 늘어날 때
 * 그 파일만 조용히 검사 밖에 남는다.
 */
describe('통합검색 — 화면 상태의 SSoT 는 URL 쿼리', () => {
  const layoutsDir = resolve(dirname(fileURLToPath(import.meta.url)), '../../layouts');
  const indexPath = join(layoutsDir, 'search/index.json');

  /** 디렉토리를 재귀로 훑어 .json 파일 경로를 모읍니다. */
  const collectJson = (dir: string): string[] => {
    const found: string[] = [];

    for (const entry of readdirSync(dir)) {
      const full = join(dir, entry);

      if (statSync(full).isDirectory()) {
        found.push(...collectJson(full));
      } else if (entry.endsWith('.json')) {
        found.push(full);
      }
    }

    return found;
  };

  const searchFiles = [indexPath, ...collectJson(join(layoutsDir, 'partials/search'))].sort();
  const read = (path: string) => readFileSync(path, 'utf8');
  const rel = (path: string) => path.slice(layoutsDir.length + 1).replace(/\\/g, '/');

  /** JSON 안의 모든 액션 노드를 깊이 우선으로 평탄화합니다. */
  const collectActions = (node: unknown, out: any[] = []): any[] => {
    if (Array.isArray(node)) {
      for (const child of node) collectActions(child, out);

      return out;
    }

    if (node && typeof node === 'object') {
      const record = node as Record<string, unknown>;

      if (typeof record.handler === 'string') out.push(record);

      for (const value of Object.values(record)) collectActions(value, out);
    }

    return out;
  };

  it('검색 레이아웃 모집단이 디렉토리에서 도출된다', () => {
    // 스캔이 빗나가면 모집단이 비고, 아래 단언들은 루프를 돌지 않아 전부 통과한다.
    expect(searchFiles.length, '발견된 검색 레이아웃 파일').toBeGreaterThanOrEqual(8);
    expect(searchFiles.map(rel)).toContain('search/index.json');
    expect(searchFiles.map(rel)).toContain('partials/search/_search_tabs.json');
    expect(searchFiles.map(rel)).toContain('partials/search/_search_filters.json');
  });

  it('검색 데이터소스가 탭·필터·정렬·페이지·커서를 URL 쿼리에서 읽는다', () => {
    const layout = JSON.parse(read(indexPath));
    const source = layout.data_sources?.find((ds: any) => ds.id === 'searchResults');

    expect(source, 'searchResults 데이터소스').toBeDefined();

    for (const key of ['type', 'board_slug', 'sort', 'page', 'cursor']) {
      expect(source.endpoint, `${key} 바인딩`).toContain(`query?.${key}`);
    }

    expect(source.endpoint, '엔드포인트에 남은 전역 상태').not.toMatch(/_global\.search/);
  });

  it('검색 데이터소스는 로그인 회원의 토큰을 실어 보낸다 (auth_mode=optional)', () => {
    // 검색 라우트는 optional.sanctum 으로 로그인 회원의 열람 범위(회원 전용 게시판 포함)를
    // 해석한다. 데이터소스 auth_mode 기본값은 'none' 이라 미선언이면 토큰이 실리지 않아,
    // 로그인 회원도 비회원 범위로 검색되고 서버측 권한 해석이 통째로 무력화된다
    // (7.0.7 사전점검 브라우저 실측 발견 — 서버는 Bearer 를 해석하도록 고쳐졌지만
    //  화면이 보내지 않아 계약의 반대편 끝이 끊겨 있었다).
    const layout = JSON.parse(read(indexPath));
    const source = layout.data_sources?.find((ds: any) => ds.id === 'searchResults');

    expect(source?.auth_mode, 'searchResults auth_mode').toBe('optional');
  });

  it('검색 화면 어디에도 전역 검색 상태가 남아 있지 않다', () => {
    const leftovers = searchFiles.filter((path) =>
      /_global\??\.search(ActiveTab|SortBy|BoardFilter|Page|Cursor)|"search(ActiveTab|SortBy|BoardFilter|Page|Cursor)"/.test(read(path))
    );

    expect(leftovers.map(rel), '전역 검색 상태 잔재').toEqual([]);
  });

  it('상태를 바꾸는 이동은 mergeQuery 로 나머지 상태를 승계한다', () => {
    const offenders: string[] = [];

    for (const path of searchFiles) {
      for (const action of collectActions(JSON.parse(read(path)))) {
        if (action.handler !== 'navigate') continue;
        if (action.params?.path !== '/search') continue;
        if (action.params?.mergeQuery === true) continue;

        // 의도적 리셋은 허용하되, 그 사유가 코드에 남아 있어야 한다.
        const declared = String(action.comment ?? '').includes(
          'audit:allow layout-list-context-navigate-merge-query'
        );

        if (!declared) offenders.push(`${rel(path)} → ${JSON.stringify(action.params?.query ?? {})}`);
      }
    }

    expect(offenders, 'mergeQuery 없이 검색 상태를 덮어쓰는 이동').toEqual([]);
  });

  it('탭·필터·정렬을 바꾸는 이동은 페이지와 커서를 함께 비운다', () => {
    const facetKeys = ['type', 'board_slug', 'sort'];
    const checked: string[] = [];
    const offenders: string[] = [];

    for (const path of searchFiles) {
      for (const action of collectActions(JSON.parse(read(path)))) {
        if (action.handler !== 'navigate') continue;
        if (action.params?.mergeQuery !== true) continue;

        const query = action.params?.query ?? {};

        if (!facetKeys.some((key) => key in query)) continue;

        checked.push(rel(path));

        // 결과 집합이 달라지므로 이전 페이지 번호와 커서를 그대로 들고 가면
        // 존재하지 않는 페이지나 남의 커서를 서버에 보내게 된다.
        if (query.page !== '' || query.cursor !== '') {
          offenders.push(`${rel(path)} → ${JSON.stringify(query)}`);
        }
      }
    }

    // 검사 대상이 하나도 없으면 위 단언은 아무것도 증명하지 못한다.
    expect(checked.length, '검사한 탭·필터·정렬 이동').toBeGreaterThanOrEqual(7);
    expect(offenders, '페이지·커서를 비우지 않는 이동').toEqual([]);
  });

  it('페이지 이동은 이동 방향에 맞는 커서를 싣는다', () => {
    const layout = JSON.parse(read(indexPath));
    const pageChange = collectActions(layout).find((action: any) => action.event === 'onPageChange');

    expect(pageChange, 'onPageChange 액션').toBeDefined();
    expect(pageChange.handler).toBe('navigate');
    expect(pageChange.params?.mergeQuery).toBe(true);
    expect(pageChange.params?.query?.page).toContain('$args[0]');

    const cursor = String(pageChange.params?.query?.cursor ?? '');

    expect(cursor, '앞으로 갈 때의 커서').toContain('next_cursor');
    expect(cursor, '뒤로 갈 때의 커서').toContain('prev_cursor');
    // 이동 전 페이지 번호는 URL 에서 읽어야 방향 판정이 성립한다.
    expect(cursor, '이동 방향 판정 기준').toContain('query?.page');
  });
});
