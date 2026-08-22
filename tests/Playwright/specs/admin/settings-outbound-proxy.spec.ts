/**
 * E2E: 환경설정 > 고급 — 아웃바운드 HTTP 프록시 (#600)
 *
 * 시나리오 매니페스트: tests/scenarios/outbound-http-proxy.yaml — 마킹은 각 테스트의
 * scenario(k=v 조합)·effects 주석이 담당하며, 헤더 요약 마킹은 파서 형식이 아니다
 *
 * 배경: 접속 IP 를 제한하는 결제사 API 를 로컬에서 테스트하려면 서버가 내보내는 요청의
 * 출발지 IP 를 바꿔야 한다. 브라우저 프록시로는 바뀌지 않는 축이라 코어 설정으로 도입했다.
 *
 * 검증:
 *  1. 디버그 모드가 꺼져 있으면 프록시 입력칸이 화면에 없다
 *  2. 디버그 모드를 켜면 프록시 주소 Input 과 예외 목록 TagInput 이 마운트된다
 *  3. 적용할 수 없는 형태의 주소는 저장이 거부되고 해당 필드에 inline 에러가 표시된다
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/**
 * 관리자 환경설정 고급 탭 진입.
 *
 * 이 화면은 하드 로드 시 설정 응답이 도착하기 전에도 폼이 렌더되어 조작할 수 있고,
 * 그 조작은 뒤늦게 도착한 `initLocal` 시드에 덮인다(실측 노출 창 300~430ms).
 * 제품 차원의 잠금은 두지 않는다는 결정이 확정되어 있으므로, 테스트가 시드 완료를
 * 기다린 뒤에 조작한다.
 */
async function gotoAdvancedTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=advanced');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#card_debug_settings')).toBeAttached({ timeout: 20_000 });

  await expect
    .poll(
      () =>
        page.evaluate(
          () => Object.keys((window as any).G7Core?.state?.getLocal?.()?.form?.advanced ?? {}).length
        ),
      { timeout: 20_000 }
    )
    .toBeGreaterThan(0);
}

/** 디버그 모드 토글을 켠다 (이미 켜져 있으면 그대로 둔다) */
async function enableDebugMode(page: import('@playwright/test').Page): Promise<void> {
  const checkbox = page.locator('input[name="advanced.debug_mode"]').first();
  await expect(checkbox).toBeAttached({ timeout: 10_000 });

  if (!(await checkbox.isChecked())) {
    // Toggle 은 sr-only checkbox 를 감싼 wrapper 가 클릭 대상이다
    await page.locator('#toggle_debug_mode .toggle-switch-wrapper').first().click();
  }

  await expect(page.locator('#outbound_proxy_settings')).toBeAttached({ timeout: 10_000 });
}

// @scenario debug_mode=off, proxy_value=valid, bypass_list=empty
// @effects proxy_inputs_hidden_when_debug_mode_off
test('#600 - 디버그 모드가 꺼져 있으면 프록시 입력칸이 없다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoAdvancedTab(page);
  expect(page.url()).not.toMatch(/\/admin\/login/);

  // 존재 확정: 같은 카드의 SQL 쿼리 로그 토글은 조건 없이 렌더된다.
  // 이 확인이 없으면 아래 부재 단언이 "아직 렌더 전" 과 구분되지 않는다.
  await expect(page.locator('[name="advanced.sql_query_log"]').first()).toBeAttached();

  await expect(page.locator('#outbound_proxy_settings')).toHaveCount(0);
  await expect(page.locator('input[name="advanced.outbound_proxy"]')).toHaveCount(0);
});

// @scenario debug_mode=on, proxy_value=valid, bypass_list=empty
// @effects proxy_inputs_visible_when_debug_mode_on
test('@smoke #600 - 디버그 모드를 켜면 프록시 주소와 예외 목록이 마운트된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoAdvancedTab(page);
  await enableDebugMode(page);

  const proxyInput = page.locator('input[name="advanced.outbound_proxy"]').first();
  await expect(proxyInput).toBeAttached();

  await proxyInput.fill('socks5h://127.0.0.1:1080');
  await expect(proxyInput).toHaveValue('socks5h://127.0.0.1:1080');

  // 예외 목록은 TagInput — 자유 입력 후 Enter 로 항목이 된다
  await expect(page.locator('#input_outbound_proxy_bypass')).toBeAttached();
});

// @scenario debug_mode=on, proxy_value=invalid, bypass_list=empty
// @effects proxy_setting_rejects_invalid_url
test('#600 - 허용되지 않는 형태의 프록시 주소는 저장이 거부된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoAdvancedTab(page);
  await enableDebugMode(page);

  const proxyInput = page.locator('input[name="advanced.outbound_proxy"]').first();
  await proxyInput.fill('ftp://proxy.internal:21');

  const saveResponse = page.waitForResponse(
    (response) => response.request().method() === 'POST' && /\/api\/admin\/settings\/?$/.test(new URL(response.url()).pathname),
    { timeout: 20_000 }
  );

  await page.getByRole('button', { name: /저장|Save/ }).first().click();

  const response = await saveResponse;
  expect(response.status()).toBe(422);

  await expect(page.locator('#input_outbound_proxy .form-error')).toBeVisible({ timeout: 10_000 });
});

// @scenario debug_mode=on, proxy_value=valid, bypass_list=empty
// @effects proxy_connection_test_button_wired
test('#600 - 연결 테스트가 프록시를 거친 출발지 IP 를 화면에 보고한다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoAdvancedTab(page);
  await enableDebugMode(page);

  // 프록시 주소를 비운 상태에서는 테스트 버튼이 잠겨 있다 (보낼 값이 없다)
  await expect(page.locator('#btn_test_outbound_proxy')).toBeDisabled();

  await page.locator('input[name="advanced.outbound_proxy"]').first().fill('socks5h://127.0.0.1:1080');
  await expect(page.locator('#btn_test_outbound_proxy')).toBeEnabled();

  // 외부 프록시 서버는 E2E 환경에 없으므로 응답만 스텁한다 —
  // 버튼 배선·요청 페이로드·결과 렌더는 전부 실경로다.
  await page.route('**/api/admin/settings/test-outbound-proxy', async (route) => {
    const body = route.request().postDataJSON();
    expect(body.outbound_proxy).toBe('socks5h://127.0.0.1:1080');

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: '프록시 연결에 성공했습니다. 외부 서비스에는 이 IP 로 보입니다.',
        data: { success: true, egress_ip: '203.0.113.9', elapsed_ms: 120, error: null },
      }),
    });
  });

  await page.locator('#btn_test_outbound_proxy').click();

  const result = page.locator('#outbound_proxy_test_result');
  await expect(result).toBeVisible({ timeout: 10_000 });
  await expect(result).toContainText('203.0.113.9');
});
