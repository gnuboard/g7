/**
 * E2E: `setState target: "_local.xxx"` 로 기록한 값을 커스텀 핸들러가 읽을 수 있는지 (배포 번들 기준)
 *
 * 배경: `_local` 은 저장소가 둘이다 — React `localDynamicState`(A)와 `_global._local`(B).
 * dot notation 분기는 A 만 갱신하고 B 를 갱신하지 않았다. 모듈·플러그인 커스텀 핸들러가 상태를
 * 읽는 공개 통로인 `G7Core.state.getLocal()` 은 B 를 읽으므로, dot notation 으로 기록된 값은
 * 핸들러에게 `undefined` 로 보였다.
 *
 * 화면은 정상으로 보인다 — 선택 표시는 A 만으로 그려지기 때문이다. 예외도 콘솔 경고도 남지 않아
 * 화면 확인만으로는 드러나지 않는다. 실제 발현: 주문서형 결제에서 간편결제(네이버페이 등)를
 * 고르고 결제하면 PG 플러그인이 선택값을 못 읽어 간편결제 자체창 대신 통합결제창이 열렸다.
 *
 * 단위 테스트가 소스를 잠그지만, 실제 사용자가 실행하는 것은 빌드된 번들이므로 브라우저에서
 * 배포 번들의 액션 파이프를 직접 태워 결과를 잠근다. PG 샌드박스는 필요 없다 — 잠그는 계약은
 * "dot notation 기록이 `getLocal()` 에 도달한다" 하나이고, 화면과 무관하게 성립해야 한다.
 *
 * @scenario setstate_dot_path_local_store_sync
 * @effects dot_path_setstate_reaches_getlocal,
 *          dot_path_setstate_preserves_other_local_keys,
 *          dot_path_setstate_accumulates_across_writes
 */
import { test, expect } from '../fixtures/auth';
import type { Page } from '@playwright/test';

/** 엔진이 부팅된 아무 공개 화면. dot notation 계약은 화면과 무관하게 성립해야 한다. */
const ANY_ENGINE_PAGE = '/';

/** 테스트 전용 키 — 어떤 레이아웃도 쓰지 않는 이름이라 기존 상태와 충돌하지 않는다. */
const PROBE_KEY = '__e2eDotPathProbe';

/**
 * 템플릿 렌더가 끝날 때까지 기다린다.
 *
 * `G7Core.dispatch` 는 TemplateApp 부팅 **중간**에 이미 존재하지만, 액션 디스패처에
 * `setGlobalState` 가 주입되는 것은 그보다 뒤다. dispatch 가능 시점만 보고 진행하면
 * 아직 주입 전이라 canonical 저장소 동기화가 통째로 건너뛰어진다(실측으로 이 함정에 빠졌다).
 * 렌더 완료를 게이트로 삼아 부팅이 끝난 뒤에 계약을 검증한다.
 */
async function waitForEngineBoot(page: Page): Promise<void> {
  await page.waitForFunction(() => typeof (window as any).G7Core?.dispatch === 'function');
  await page.locator('footer').first().waitFor({ state: 'attached', timeout: 30_000 });
  await page.waitForLoadState('networkidle');
}

test.describe('setState dot notation 경로의 _local 저장소 동기화', () => {
  test('dot notation 으로 기록한 값이 getLocal() 로 읽힌다', async ({ page }) => {
    await page.goto(ANY_ENGINE_PAGE);
    await waitForEngineBoot(page);

    const result = await page.evaluate(async (key) => {
      const G7Core = (window as any).G7Core;
      const before = G7Core.state.getLocal()?.[key] ?? null;

      await G7Core.dispatch({
        handler: 'setState',
        params: { target: `_local.${key}`, value: 'toss_naverpay' },
      });

      return {
        before,
        getLocal: G7Core.state.getLocal()?.[key] ?? null,
        canonical: (window as any).__templateApp?.getGlobalState?.()?._local?.[key] ?? null,
      };
    }, PROBE_KEY);

    expect(result.before, '프로브 키가 이미 오염돼 있으면 이 테스트는 의미가 없다').toBeNull();
    // 결함이 있으면 여기서 null 이 나온다 — 커스텀 핸들러가 보던 그 값이다
    expect(result.getLocal).toBe('toss_naverpay');
    expect(result.canonical).toBe('toss_naverpay');
  });

  test('동기화가 canonical 저장소의 다른 키를 지우지 않는다', async ({ page }) => {
    await page.goto(ANY_ENGINE_PAGE);
    await waitForEngineBoot(page);

    const result = await page.evaluate(async (key) => {
      const G7Core = (window as any).G7Core;

      // 커스텀 핸들러가 setLocal 로 canonical 저장소에 먼저 기록한 상황을 만든다
      G7Core.state.setLocal({ [`${key}Keep`]: 'must-survive' });

      await G7Core.dispatch({
        handler: 'setState',
        params: { target: `_local.${key}`, value: 'toss_naverpay' },
      });

      const canonical = (window as any).__templateApp?.getGlobalState?.()?._local ?? {};
      return { written: canonical[key] ?? null, kept: canonical[`${key}Keep`] ?? null };
    }, PROBE_KEY);

    expect(result.written).toBe('toss_naverpay');
    // 부분 상태 스냅샷으로 canonical 을 통째 교체하면 여기서 사라진다
    expect(result.kept).toBe('must-survive');
  });

  test('연속 기록이 서로를 지우지 않고 누적된다', async ({ page }) => {
    await page.goto(ANY_ENGINE_PAGE);
    await waitForEngineBoot(page);

    const canonical = await page.evaluate(async (key) => {
      const G7Core = (window as any).G7Core;

      // 결제수단을 골랐다가 다른 것으로 바꾸는 흐름 — 마지막 값이 남고 동반 키는 유지돼야 한다
      await G7Core.dispatch({
        handler: 'setState',
        params: { target: `_local.${key}`, value: 'toss_tosspay' },
      });
      await G7Core.dispatch({
        handler: 'setState',
        params: { target: `_local.${key}Sibling`, value: 'sibling-value' },
      });
      await G7Core.dispatch({
        handler: 'setState',
        params: { target: `_local.${key}`, value: 'toss_naverpay' },
      });

      const local = (window as any).__templateApp?.getGlobalState?.()?._local ?? {};
      return { last: local[key] ?? null, sibling: local[`${key}Sibling`] ?? null };
    }, PROBE_KEY);

    expect(canonical.last).toBe('toss_naverpay');
    expect(canonical.sibling).toBe('sibling-value');
  });
});
