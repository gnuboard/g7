/**
 * E2E: 엔진 7.1.3 묶음(engine-v1.83.0) — 데이터소스 쓰기 뒤 단순 경로 캐시, 코어 링크 API, mergeClasses (배포 번들 기준)
 *
 * 배경:
 * - `G7Core.dataSource.set` 뒤에도 `{{x.data.total}}` 같은 단순 경로가 30초 영구 캐시에서 앞 값으로 그려졌다(E1~E3).
 * - 링크 컴포넌트의 내부 링크 판정·rel 규칙이 템플릿마다 사본이라 갈라졌다 — 코어가 판정 하나를 제공한다(WP8).
 * - `mergeClasses` 가 축 유틸리티(`px-2`)로 전체 유틸리티(`p-4`)를 지웠다(WP10).
 *
 * 화면이 그릴 때 쓰는 그 엔진 인스턴스·공개 API 를 배포 번들에서 직접 부른다.
 *
 * @scenario surface=engine_globals
 * @effects core_link_api_exposed
 */
import { test, expect } from '../fixtures/auth';

test.describe('엔진 7.1.3 — 데이터소스 캐시·링크 API·mergeClasses', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/login');
    await page.waitForFunction(
      () => Boolean((window as any).G7Core?.getDataBindingEngine?.() && (window as any).G7Core?.dataSource?.set),
      null,
      { timeout: 30_000 },
    );
  });

  // @scenario surface=engine_globals
  // @effects core_link_api_exposed
  test('G7Core.dataSource.set 직후 단순 경로가 새 값이다(영구 캐시를 그 데이터소스만 비운다)', async ({ page }) => {
    const result = await page.evaluate(async () => {
      const g7 = (window as any).G7Core;
      const engine = g7.getDataBindingEngine();
      const expr = '{{zz_probe_713.data.total}}';
      const before = engine.resolveBindings(expr, { zz_probe_713: { data: { total: 1 } } });
      await g7.dataSource.set('zz_probe_713', { data: { total: 2 } });
      const after = engine.resolveBindings(expr, { zz_probe_713: { data: { total: 2 } } });
      return { before, after };
    });

    expect(result.before).toBe('1');
    // 수정 전: 30초 영구 캐시 히트로 '1'
    expect(result.after).toBe('2');
  });

  // @scenario surface=engine_globals
  // @effects core_link_api_exposed
  test('코어 링크 API — 같은 출처 //다른호스트 는 내부 링크가 아니고, 새 창 rel 은 빠진 토큰만 더한다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const nav = (window as any).G7Core.navigation;
      const origin = window.location.origin;
      return {
        hasHook: typeof nav.useLocationSearch === 'function',
        internal: nav.resolveInternalHref('/board/free?page=2#c1'),
        doubleSlash: nav.resolveInternalHref(`${origin}//evil.example/x`),
        protocolRelative: nav.resolveInternalHref('//evil.example/x'),
        relKept: nav.mergeRelForTarget('noopener', '_blank'),
        relSameWindow: nav.mergeRelForTarget('nofollow', '_self'),
      };
    });

    expect(result.hasHook).toBe(true);
    expect(result.internal).toBe('/board/free?page=2#c1');
    expect(result.doubleSlash).toBeNull();
    expect(result.protocolRelative).toBeNull();
    expect(result.relKept).toBe('noopener noreferrer');
    expect(result.relSameWindow).toBe('nofollow');
  });

  // @scenario surface=engine_globals
  // @effects core_link_api_exposed
  test('mergeClasses — 축 유틸리티는 전체 유틸리티를 지우지 않고, 전체 유틸리티는 축을 지운다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const merge = (window as any).G7Core.style.mergeClasses;
      return {
        axisKeepsAll: merge('p-4 flex-1', 'px-2 flex-col').split(/\s+/).sort(),
        allReplacesAxis: merge('px-2', 'p-4').split(/\s+/).sort(),
      };
    });

    // 수정 전: px-2 가 p-4 를, flex-col 이 flex-1 을 지웠다
    expect(result.axisKeepsAll).toEqual(['flex-1', 'flex-col', 'p-4', 'px-2']);
    expect(result.allReplacesAxis).toEqual(['p-4']);
  });
});
