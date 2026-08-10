import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * DataGrid 의 모든 Pagination 렌더 경로가 hasMorePages 를 넘기는지 전수 확인 (#519)
 *
 * DataGrid 는 표 뷰와 카드/모바일 뷰에서 Pagination 을 각각 렌더한다. 총 건수를 상한까지만
 * 센 목록은 `last_page` 가 null 로 오므로 "다음" 이동 가능 여부는 `hasMorePages` 로만
 * 판정된다. 렌더 경로 중 하나라도 그 값을 넘기지 않으면 그 뷰에서만 이전·다음이 모두
 * 비활성이 되어 1 페이지에 갇힌다 — 예외도 경고도 없이 화면에서만 드러난다.
 *
 * 실측 회귀 (2026-08-03): 관리자 활동 로그(88,792 건)에서 표 뷰 경로만 이 값을 빠뜨려
 * 이전·다음이 둘 다 비활성이었다. 한 곳을 고쳐도 다음에 추가되는 렌더 경로가 또 빠질 수
 * 있으므로 **개별 지점을 열거하지 않고 소스를 스캔해 전수로 강제한다**.
 */
describe('DataGrid — Pagination 렌더 경로의 hasMorePages 전달', () => {
  const source = readFileSync(
    resolve(dirname(fileURLToPath(import.meta.url)), '../DataGrid.tsx'),
    'utf8'
  );

  /**
   * `<Pagination ... />` 블록을 모두 잘라냅니다.
   */
  const paginationBlocks = (): string[] => {
    const blocks: string[] = [];
    let from = 0;

    for (;;) {
      const start = source.indexOf('<Pagination', from);
      if (start === -1) break;

      const end = source.indexOf('/>', start);
      expect(end, 'Pagination 태그가 닫히지 않았습니다').toBeGreaterThan(start);

      blocks.push(source.slice(start, end + 2));
      from = end + 2;
    }

    return blocks;
  };

  it('Pagination 렌더 경로가 하나 이상 존재한다', () => {
    // 스캔 대상이 0 개면 아래 단언이 조용히 통과한다 — 모집단부터 고정한다.
    expect(paginationBlocks().length).toBeGreaterThan(0);
  });

  it('모든 Pagination 렌더 경로가 hasMorePages 를 넘긴다', () => {
    const missing = paginationBlocks()
      .map((block, index) => ({ index, block }))
      .filter(({ block }) => !block.includes('hasMorePages'));

    expect(
      missing.map(({ index }) => `#${index + 1}`),
      `hasMorePages 를 넘기지 않는 Pagination 렌더 경로가 있습니다:\n${missing
        .map(({ block }) => block)
        .join('\n---\n')}`
    ).toEqual([]);
  });

  it('모든 Pagination 렌더 경로가 totalPages 로 null 을 보존한다', () => {
    // last_page 를 1 로 채우면 페이저가 접혀 1 페이지 밖 행에 도달할 수 없다.
    const blocks = paginationBlocks();

    for (const block of blocks) {
      expect(block).toContain('totalPages');
      expect(block, `totalPages 에 1 을 폴백으로 채우면 안 됩니다:\n${block}`).not.toMatch(
        /totalPages=\{[^}]*\?\?\s*1\s*\}/
      );
    }
  });
});
