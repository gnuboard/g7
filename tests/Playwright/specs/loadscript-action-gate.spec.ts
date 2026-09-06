/**
 * E2E: loadScript 액션의 출처 게이트 (배포 번들 기준)
 *
 * 배경: 레이아웃 `scripts[]` 경로에는 원격 스크립트 차단 게이트가 있었는데, 같은
 * `<script>` 주입인 `loadScript` **액션**에는 게이트가 없었다. 저장측 검증
 * (SafeLayoutExpressions·NoExternalUrls)이 외부 URL 저장을 막아도, 그 액션을 런타임에
 * 디스패치하면 임의 원격 코드가 그대로 로드됐다 — 실제 브라우저에서 미신뢰 CDN 스크립트가
 * 로드되어 전역이 생성되는 것을 실측했다.
 *
 * 이 스펙은 배포 번들에서 그 게이트가 실제로 동작하는지를 **네트워크 레벨**로 잠근다:
 * 미신뢰 src 는 요청 자체가 나가지 않고(0건) 액션이 실패해야 하며, same-origin src 는
 * 종전대로 로드돼야 한다(과차단 없음).
 *
 * 판정 함수 자체(정규화·authority 우회·신뢰 호스트 비교)는 minify 된 번들에서 이름으로
 * 호출할 수 없어 단위 스위트(scriptSrcPolicy.test.ts)가 고정한다. 여기서는 그 결정의
 * 관찰 가능한 결과만 측정한다.
 *
 * 시나리오 축(case)·효과는 매니페스트 tests/scenarios/trusted-script-hosts.yaml 참조.
 * 마커는 test 레벨에만 둔다 — 파일 레벨에 두면 test 를 지워도 효과가 "언급됨" 으로 집계돼
 * 삭제가 무증상 green 이 된다.
 */
import { test, expect } from '../fixtures/auth';

/** 미신뢰 CDN — 어떤 번들 확장도 선언하지 않은 호스트 */
const UNTRUSTED_SRC = 'https://cdn.jsdelivr.net/npm/lodash@4.17.21/lodash.min.js';

/** same-origin 경로 — 코어가 항상 게시하는 번들 */
const SAME_ORIGIN_SRC = '/build/core/devtools.min.js';

test.describe('loadScript 액션 출처 게이트', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/login');
    await page.waitForFunction(
      () => typeof (window as any).G7Core?.dispatch === 'function',
      null,
      { timeout: 30_000 },
    );
  });

  // @scenario case=loadscript-action-untrusted
  // @effects loadscript_action_gate_wired, untrusted_external_script_blocked
  test('미신뢰 외부 src 는 네트워크 요청 0건으로 차단되고 액션이 실패한다', async ({ page }) => {
    const requested: string[] = [];
    page.on('request', (req) => {
      if (req.url().includes('cdn.jsdelivr.net')) {
        requested.push(req.url());
      }
    });

    const result = await page.evaluate(async (src) => {
      try {
        const value = await (window as any).G7Core.dispatch({
          handler: 'loadScript',
          params: { src, id: 'e2e_untrusted_probe' },
        });

        return {
          threw: false,
          success: value?.success ?? null,
          message: String(value?.error?.message ?? ''),
        };
      } catch (e) {
        return { threw: true, success: false, message: String((e as Error)?.message ?? e) };
      }
    }, UNTRUSTED_SRC);

    // 게이트가 살아 있으면 액션은 성공으로 끝나지 않는다
    expect(result.success).not.toBe(true);
    expect(result.message).toContain('Blocked untrusted script src');

    // 그리고 그 도메인으로 요청 자체가 나가지 않는다
    expect(requested, `미신뢰 CDN 으로 요청이 나감: ${requested.join(', ')}`).toEqual([]);

    // 스크립트 태그도 만들어지지 않는다
    const injected = await page.evaluate(
      () => document.querySelectorAll('script#e2e_untrusted_probe').length,
    );
    expect(injected).toBe(0);
  });

  // @scenario case=loadscript-action-sameorigin
  // @effects loadscript_action_gate_wired
  test('same-origin src 는 종전대로 로드된다 (과차단 없음)', async ({ page }) => {
    const requested: string[] = [];
    page.on('request', (req) => {
      if (req.resourceType() === 'script' && req.url().includes('devtools.min.js')) {
        requested.push(req.url());
      }
    });

    const result = await page.evaluate(async (src) => {
      try {
        const value = await (window as any).G7Core.dispatch({
          handler: 'loadScript',
          params: { src, id: 'e2e_same_origin_probe' },
        });

        return { success: value?.success ?? null, message: String(value?.error?.message ?? '') };
      } catch (e) {
        return { success: false, message: String((e as Error)?.message ?? e) };
      }
    }, SAME_ORIGIN_SRC);

    expect(
      result.success,
      `same-origin 스크립트가 차단됨(과차단): ${result.message}`,
    ).toBe(true);

    // 공허 통과 방지 — 실제로 태그가 붙었는지 확인한다
    const injected = await page.evaluate(
      () => document.querySelectorAll('script#e2e_same_origin_probe').length,
    );
    expect(injected).toBe(1);
  });
});
