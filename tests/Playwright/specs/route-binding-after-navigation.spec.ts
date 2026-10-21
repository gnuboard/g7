/**
 * E2E: 화면 전환 뒤 `{{route.*}}`·`{{query.*}}` 가 새 화면 값으로 그려지는지 (배포 번들 기준)
 *
 * 배경: 정적 경로 영구 캐시(키 = 경로 글자, 30초)가 `route`·`query` 루트도 담아,
 * 글 보기(`/board/notice/226`) → SPA 로 다른 게시판 목록 이동 시 「글쓰기」 링크
 * `/board/{{route.slug}}/write` 가 `/board/notice/write` 로 남았다(누르면 앞 게시판
 * form-data 로 403). 화면 전환은 새 컨텍스트로 다시 그릴 뿐이므로, 같은 페이지에서
 * 배포 번들의 공유 바인딩 엔진에 컨텍스트만 바꿔 두 번 그려 결과를 잠근다.
 *
 * @scenario surface=cache
 * @effects route_query_bindings_follow_current_screen
 */
import { test, expect } from '../fixtures/auth';

test.describe('화면 전환 뒤 route·query 바인딩', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/login');
    await page.waitForFunction(
      () => Boolean((window as any).G7Core?.getDataBindingEngine?.()),
      null,
      { timeout: 30_000 },
    );
  });

  // @scenario surface=cache
  // @effects route_query_bindings_follow_current_screen
  test('앞 화면에서 그린 route.slug·query.tab 이 다음 화면 값으로 바뀐다', async ({ page }) => {
    // 화면이 그릴 때 쓰는 그 엔진 인스턴스 — 화면 전환은 컨텍스트만 바꿔 다시 그린다
    const result = await page.evaluate(() => {
      const engine = (window as any).G7Core.getDataBindingEngine();
      const href = '/board/{{route.slug}}/write';
      const title = '{{query.tab}}';
      const prev = { route: { slug: 'notice', id: '226' }, query: { tab: 'info' } };
      const next = { route: { slug: 'opinion' }, query: { tab: 'orders' } };
      const first = { href: engine.resolveBindings(href, prev), title: engine.resolveBindings(title, prev) };
      engine.startRenderCycle();
      const second = { href: engine.resolveBindings(href, next), title: engine.resolveBindings(title, next) };
      return { first, second };
    });

    expect(result.first).toEqual({ href: '/board/notice/write', title: 'info' });
    // 수정 전: 30초 영구 캐시 히트로 '/board/notice/write'·'info'
    expect(result.second).toEqual({ href: '/board/opinion/write', title: 'orders' });
  });
});
