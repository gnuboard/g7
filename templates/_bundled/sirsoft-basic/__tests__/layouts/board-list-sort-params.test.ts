import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * 사용자 게시판 목록 — 정렬 쿼리 파라미터 전달 (#534 검수 발견)
 *
 * 서버 계약(`PostService::extractSortParams`)의 정렬 우선순위는
 * **쿼리 파라미터(sort_by/sort_order) > 게시판 설정(order_by/order_direction) > 기본값** 이다.
 * 그런데 사용자 목록 레이아웃의 데이터소스가 이 두 파라미터를 아예 싣지 않아,
 * `/board/{slug}?sort_by=view_count` 로 들어가도 요청은 `posts?page=1` 로 나갔다.
 * 관리자 목록은 정상 동작하는데 사용자 화면만 조용히 무시됐다 — 예외도 경고도 없다.
 *
 * 폴백을 빈 문자열로 두는 것이 이 테스트의 핵심이다. 관리자 목록처럼
 * `{{query.sort_by || 'created_at'}}` 로 기본값을 박으면, URL 에 정렬이 없을 때
 * `sort_by=created_at` 이 **항상** 실려 나가 게시판 설정(`order_by`)을 덮어쓴다.
 * 게시판 관리자가 "조회순" 으로 설정해도 목록이 최신순으로 나오게 된다.
 * 빈 값이면 템플릿 엔진이 파라미터를 생략하고, 설령 실려도 Laravel 의
 * ConvertEmptyStringsToNull 이 null 로 바꿔 `??` 사슬이 게시판 설정으로 떨어진다.
 */
describe('사용자 게시판 목록 — 정렬 쿼리 파라미터', () => {
  const layoutsDir = resolve(dirname(fileURLToPath(import.meta.url)), '../../layouts');

  /**
   * 레이아웃 디렉토리를 훑어 게시글 목록 엔드포인트를 쓰는 데이터소스를 전부 모읍니다.
   *
   * 파일명을 열거하지 않는다 — 목록 레이아웃이 늘어날 때 그 레이아웃만 조용히
   * 검사 밖에 남으면, 지금 고치는 결함이 새 화면에서 그대로 재발한다.
   */
  const collectPostListSources = (): Array<{ file: string; source: Record<string, unknown> }> => {
    const found: Array<{ file: string; source: Record<string, unknown> }> = [];

    const walk = (dir: string): void => {
      for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name);

        if (entry.isDirectory()) {
          walk(full);
          continue;
        }
        if (!entry.name.endsWith('.json')) continue;

        let parsed: { data_sources?: Array<Record<string, unknown>> };
        try {
          parsed = JSON.parse(readFileSync(full, 'utf8'));
        } catch {
          continue;
        }

        for (const ds of parsed.data_sources ?? []) {
          const endpoint = String(ds.endpoint ?? '');
          // 게시판별 게시글 목록만 — 회원 프로필의 작성글 목록(users/{id}/posts)은 별개 화면이다.
          if (/\/boards\/[^/]+\/posts$/.test(endpoint)) {
            found.push({ file: full.slice(layoutsDir.length + 1).replace(/\\/g, '/'), source: ds });
          }
        }
      }
    };

    walk(layoutsDir);

    return found;
  };

  const sources = collectPostListSources();

  it('게시글 목록 데이터소스가 레이아웃에서 도출된다', () => {
    // 스캔이 빗나가면 sources 가 비고 아래 단언들이 루프를 돌지 않아 전부 통과한다.
    // 모집단을 먼저 고정해 그 조용한 통과를 막는다.
    expect(sources.length, '발견된 게시글 목록 데이터소스').toBeGreaterThan(0);
    expect(sources.map((s) => s.file)).toContain('board/index.json');
  });

  it('정렬 파라미터(sort_by/sort_order)를 서버로 전달한다', () => {
    for (const { file, source } of sources) {
      const params = (source.params ?? {}) as Record<string, string>;

      expect(Object.keys(params), `${file} 의 params`).toContain('sort_by');
      expect(Object.keys(params), `${file} 의 params`).toContain('sort_order');
    }
  });

  it('정렬 파라미터를 URL 쿼리에서 읽는다', () => {
    for (const { file, source } of sources) {
      const params = (source.params ?? {}) as Record<string, string>;

      expect(params.sort_by, `${file} 의 sort_by`).toMatch(/query[.[]'?sort_by/);
      expect(params.sort_order, `${file} 의 sort_order`).toMatch(/query[.[]'?sort_order/);
    }
  });

  it('정렬 파라미터에 기본 컬럼을 박지 않는다 (게시판 설정이 이겨야 한다)', () => {
    for (const { file, source } of sources) {
      const params = (source.params ?? {}) as Record<string, string>;

      // 'created_at' / 'desc' 같은 리터럴 폴백이 있으면 URL 에 정렬이 없을 때도
      // 그 값이 실려 나가 게시판 설정(order_by)을 덮어쓴다.
      expect(params.sort_by, `${file} 의 sort_by 폴백`).not.toMatch(
        /created_at|view_count|title|author/
      );
      expect(params.sort_order, `${file} 의 sort_order 폴백`).not.toMatch(/asc|desc/);
    }
  });

  it('정렬이 없을 때 빈 값으로 떨어진다', () => {
    for (const { file, source } of sources) {
      const params = (source.params ?? {}) as Record<string, string>;

      // 같은 데이터소스의 search/category 와 동일한 관례 — 빈 문자열 폴백.
      expect(params.sort_by, `${file} 의 sort_by`).toMatch(/\?\?\s*''/);
      expect(params.sort_order, `${file} 의 sort_order`).toMatch(/\?\?\s*''/);
    }
  });
});
