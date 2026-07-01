/**
 * Smoke: 페이지 관리 목록의 발행일 정렬이 서버가 내려준 순서를 그대로 출력하는지 검증.
 *
 * 회귀 커버:
 * - DataGrid 는 onSortChange(controlled, 서버 정렬) 가 연결된 경우 서버가 내려준 순서를
 *   클라이언트에서 다시 정렬하지 않아야 한다. 과거 클라이언트 재정렬 comparator 는 null
 *   값을 항상 앞으로 밀어, "오래된 발행순(published_at asc)" 정렬 시 발행일이 없는
 *   (published_at = null) 건이 목록 맨 앞으로 튀어나왔다.
 * - 본 spec 은 서버 API 응답 순서와 화면 행 순서가 1:1 일치하는지 실측 비교한다.
 * -
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test('@smoke /admin/pages 발행일 오래된순 정렬 시 화면 행 순서가 서버 응답 순서와 일치한다 (null 발행일 앞으로 안 밀림)', async ({
  page,
}) => {
  const token = issueToken('sirsoft-page.pages.read');
  await authenticatePage(page, token);

  // 서버가 내려주는 정렬 순서(published_at asc)를 캡처한다.
  const apiResponse = page.waitForResponse(
    (res) =>
      res.url().includes('/api/modules/sirsoft-page/admin/pages') &&
      res.url().includes('sort_by=published_at') &&
      res.url().includes('sort_order=asc') &&
      res.request().method() === 'GET',
    { timeout: 30_000 },
  );

  await page.goto('/admin/pages?sort_by=published_at&sort_order=asc');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 인증 가드 — 권한 부족 시 로그인으로 리다이렉트됨
  expect(page.url(), '권한 보유 토큰임에도 /admin/login 으로 리다이렉트되었습니다').not.toMatch(/\/admin\/login/);

  const res = await apiResponse;
  const body = await res.json();
  const serverRows: Array<{ slug?: string }> = body?.data?.data ?? [];

  // 데이터가 없으면 검증 의미가 없으므로, 최소 2건 이상의 페이지가 필요하다.
  // (실측 데이터 부족 시 테스트가 조용히 통과하지 않도록 명시적으로 가드)
  expect(serverRows.length, '페이지 데이터가 2건 미만이라 정렬 순서를 검증할 수 없습니다').toBeGreaterThanOrEqual(2);

  const serverSlugOrder = serverRows.map((r) => r.slug).filter((s): s is string => typeof s === 'string');

  // 화면에 렌더된 행의 slug 순서를 추출한다.
  // 페이지 목록 각 행에는 /page/{slug} 링크가 존재한다.
  await page.locator('tbody tr').first().waitFor({ state: 'visible', timeout: 15_000 });

  const renderedSlugOrder: string[] = await page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('tbody tr'));
    return rows
      .map((tr) => {
        const link = tr.querySelector('a[href^="/page/"]') as HTMLAnchorElement | null;
        if (!link) return null;
        const href = link.getAttribute('href') ?? '';
        const m = href.match(/^\/page\/(.+)$/);
        return m ? m[1] : null;
      })
      .filter((s): s is string => typeof s === 'string');
  });

  // 서버가 내려준 순서(현재 페이지분)와 화면 행 순서가 동일해야 한다.
  const compareLength = Math.min(serverSlugOrder.length, renderedSlugOrder.length);
  expect(compareLength, '화면에 렌더된 행이 없습니다').toBeGreaterThanOrEqual(2);
  expect(
    renderedSlugOrder.slice(0, compareLength),
    '화면 행 순서가 서버 응답 순서와 다릅니다 (클라이언트 재정렬로 순서 훼손)',
  ).toEqual(serverSlugOrder.slice(0, compareLength));
});
