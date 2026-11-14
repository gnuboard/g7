/**
 * E2E: 본인인증 전체화면의 남은 시간이 1초마다 줄고, 화면을 떠나면 타이머가 멈춘다 (배포 번들 기준, engine-v1.91.1)
 *
 * 배경: 전체화면 init 의 `startInterval`(identity_challenge_tick) 이 등록 문맥(렌더 전 스냅숏·writer 없음)으로 돌아,
 * 남은 시간이 처음부터 「만료」 로 보이고 [재전송] 대기가 줄지 않았다. `stopAllIntervals` 를 부르는 곳이 없어 화면을 떠나도
 * 타이머가 돌며 다음 화면 `_local` 에 앞 화면 키를 썼다. 비회원으로 질의값만(`expires_at`) 준 전체화면을 열어 서버 쓰기 없이 잰다.
 *
 * @scenario case=fullpage_countdown
 * @effects tick_reads_live_local, tick_stops_after_route_change
 */
import { test, expect } from '../fixtures/auth';

test.describe('본인인증 전체화면 카운트다운', () => {
  // @scenario case=fullpage_countdown
  // @effects tick_reads_live_local, tick_stops_after_route_change
  test('남은 시간이 줄고, SPA 로 떠나면 타이머가 멈추고 다음 화면 _local 에 앞 화면 키가 없다', async ({ page }) => {
    const expiresAt = new Date(Date.now() + 15 * 60 * 1000).toISOString();
    await page.goto(`/identity/challenge?expires_at=${encodeURIComponent(expiresAt)}&return_url=%2F`);
    await page.waitForFunction(
      () => Boolean((window as any).G7Core?.getActionDispatcher?.()?.intervals?.has?.('identity_challenge_tick')),
      null,
      { timeout: 30_000 },
    );

    const readSeconds = () => page.evaluate(() => Number((window as any).G7Core.state.getLocal()?.remainingSeconds ?? -1));
    await expect.poll(readSeconds, { timeout: 10_000 }).toBeGreaterThan(800);
    const first = await readSeconds();
    await page.waitForTimeout(2_500);
    const second = await readSeconds();
    // 수정 전: 틱이 렌더 전 스냅숏으로 계산해 화면에 반영되지 않았다(사용자 화면은 「만료」)
    expect(second).toBeLessThan(first);
    expect(second).toBeGreaterThan(first - 6);

    // SPA 로 다른 화면 — 메뉴 클릭과 같은 경로
    await page.evaluate(() => (window as any).__templateApp.router.navigate('/'));
    await page.waitForFunction(() => location.pathname === '/', null, { timeout: 15_000 });
    await page.waitForTimeout(2_500);

    const after = await page.evaluate(() => {
      const local = (window as any).G7Core.state.getLocal() ?? {};

      return {
        intervals: Array.from((window as any).G7Core.getActionDispatcher().intervals.keys()),
        leaked: ['remainingSeconds', 'resendCooldown', 'expiresAt'].filter((key) => key in local),
      };
    });
    // 수정 전: 타이머가 살아 다음 화면 저장소 B 에 remainingSeconds·resendCooldown 을 매초 썼다
    expect(after.intervals).not.toContain('identity_challenge_tick');
    expect(after.leaked).toEqual([]);
  });
});
