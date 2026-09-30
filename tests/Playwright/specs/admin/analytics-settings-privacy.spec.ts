/**
 * E2E: 방문자 통계 설정 — 개인정보 모드 저장과 소급 창, 검증 오류 표시 (이슈 #682 S3)
 *
 * 레이아웃 계약 테스트는 「저장 성공 뒤 서버가 소급 가능이라 하면 창을 연다」까지만 잠근다. 실제
 * 브라우저에서 모드 카드를 누르고 저장했을 때 서버가 그 신호를 주는지, 창의 실행 버튼이 체크 전에
 * 잠겨 있는지, 건너뛰어도 새 모드는 저장돼 새로고침에 남는지는 이 경로에서만 드러난다. 잘못된
 * 보존일은 서버가 422 로 거절하고 그 입력칸 아래에 오류가 떠야 한다(토스트만 뜨면 어느 칸인지 모른다).
 * 서버가 500 을 낼 때 화면에 남는 말이 입력 탓을 하면 운영자는 고칠 수 없는 것을 고치려 든다 —
 * 그 문구는 단위 테스트가 구조만 보므로 브라우저에서만 드러난다(S3 라운드 11 실측).
 *
 * 설정은 가드가 원래 값으로 되돌리고, 테스트 전에 없던 설정 파일은 지운다.
 */
import { authenticatePage, expect, issueToken, test } from '../../fixtures/auth';
import { ANALYTICS_SETTINGS_API, AnalyticsSettingsGuard, bearer } from '../../fixtures/analytics';

test.describe('방문자 통계 설정 — 개인정보 모드·검증', () => {
  // @scenario screen=settings, state=normal, viewport=1440, locale=ko, e2e=settings_privacy
  // @effects stricter_privacy_save_shows_retro_modal_in_browser
  test('익명 모드로 저장하면 소급 창이 뜨고, 건너뛰어도 새 모드는 저장돼 새로고침에 남는다', async ({ page }) => {
    const token = issueToken('core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, token);
    // 전제 — 더 엄격한 쪽으로 바뀌어야 소급 신호가 온다. 지금 모드를 전체 수집으로 맞춘다.
    await guard.apply({ privacy_mode: 'full' });

    try {
      await authenticatePage(page, token);
      await page.goto('/admin/analytics/settings?tab=privacy');
      const anonymous = page.locator('#mode_anonymous');
      await expect(anonymous).toBeVisible({ timeout: 30_000 });
      await expect(page.locator('#save_button')).toBeDisabled();

      await anonymous.click();
      await expect(page.locator('#save_button')).toBeEnabled();

      const saved = page.waitForResponse(
        (r) => r.url().includes(ANALYTICS_SETTINGS_API) && r.request().method() === 'PUT',
        { timeout: 20_000 },
      );
      await page.locator('#save_button').click();
      const response = await saved;
      expect(response.status()).toBe(200);
      // 저장 본문은 탭과 무관하게 받아 온 값과 달라진 키만 담는다. 이 흐름은 개인정보 모드 하나만 바꿨으므로
      // 손대지 않은 보존 탭 키(retention_days)가 실리면 안 본 값까지 덮어쓴 것이다.
      const sent = JSON.parse(response.request().postData() ?? '{}');
      expect(sent.privacy_mode).toBe('anonymous');
      expect(sent).not.toHaveProperty('retention_days');
      expect((await response.json()).data.meta.retro_anonymize_available).toBe(true);

      // 모달 컴포넌트는 id 를 DOM 에 싣지 않는다 — 제목으로 이름 붙은 dialog 역할로 찾는다.
      const modal = page.getByRole('dialog', { name: /소급 적용/ });
      await expect(modal).toBeVisible();
      // 본문의 건수 자리표시자가 실제 값으로 바뀐다(식 안 `$t()` 는 인자를 버려 `{{visits}}` 가 그대로 보였다).
      await expect(modal).toContainText(/방문 [\d,]+건·액션 [\d,]+건/);
      await expect(modal).not.toContainText('{{');
      // 되돌릴 수 없는 작업이라 체크 전에는 실행 버튼이 잠겨 있다.
      await expect(modal.locator('#retro_apply_button')).toBeDisabled();
      await modal.getByRole('button', { name: '건너뛰기' }).click();
      await expect(modal).toBeHidden();

      await page.reload();
      await expect(page.locator('#mode_anonymous')).toHaveClass(/admin-card-selected/, { timeout: 30_000 });
      expect((await guard.read()).privacy_mode).toBe('anonymous');
    } finally {
      await guard.restore();
    }
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko, e2e=settings_privacy
  // @effects invalid_retention_shows_inline_422
  test('원장 보존일 3 은 422 로 거절되고 그 입력칸 아래에 오류가 뜨며 저장되지 않는다', async ({ page }) => {
    const token = issueToken('core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, token);
    const before = (await guard.read()).retention_days;
    await guard.apply({});

    try {
      await authenticatePage(page, token);
      await page.goto('/admin/analytics/settings?tab=retention');
      const input = page.locator('input[name="settings.retention_days"]');
      await expect(input).toBeVisible({ timeout: 30_000 });
      // 응답이 폼을 채운 뒤에 입력한다. 화면은 첫 응답 전 탭 본문을 흐리게 막아 클릭을 받지 않지만,
      // `fill` 은 포인터를 거치지 않아 그 막을 지나친다 — 사람은 칸을 눌러야 입력할 수 있다.
      await expect(input).toHaveValue(String(before), { timeout: 30_000 });

      await input.fill('3');
      const saved = page.waitForResponse(
        (r) => r.url().includes(ANALYTICS_SETTINGS_API) && r.request().method() === 'PUT',
        { timeout: 20_000 },
      );
      await page.locator('#save_button').click();
      expect((await saved).status()).toBe(422);

      await expect(page.locator('#field_retention_days .form-error')).toBeVisible();
      await expect(page.locator('#validation_error')).toBeVisible();

      const stored = await page.request.get(ANALYTICS_SETTINGS_API, { headers: bearer(token) });
      expect((await stored.json()).data.settings.retention_days).toBe(before);
    } finally {
      await guard.restore();
    }
  });

  // @scenario screen=settings, state=load_failed, viewport=1440, locale=ko, e2e=settings_privacy
  // @effects save_failure_header_tells_which_kind_of_failure
  test('서버 오류로 저장이 실패하면 화면에 남는 말이 입력 탓을 하지 않고 서버가 준 사유를 말한다', async ({ page }) => {
    // 토스트는 사유를 맞게 말하지만 몇 초 뒤 사라진다. 그 뒤 화면에 남는 것은 빨간 상자 하나뿐이라,
    // 거기에 「입력값을 확인하세요」가 적혀 있으면 운영자는 고칠 입력이 없는데 입력을 뒤진다.
    // 422 와 달리 서버 오류에는 필드별 오류가 없다 — 머리글이 그 차이를 말해야 한다.
    const token = issueToken('core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, token);
    const before = (await guard.read()).top_n_detail;
    await guard.apply({});

    const REASON = '저장소에 쓰지 못했습니다 (500)';
    try {
      await authenticatePage(page, token);
      // 저장 요청만 500 으로 돌린다 — 조회는 그대로 둬야 폼이 채워진다.
      await page.route(
        (url) => url.pathname.endsWith('/api/admin/analytics/settings'),
        async (route) => {
          if (route.request().method() !== 'PUT') return route.fallback();
          await route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ message: REASON }),
          });
        },
      );

      await page.goto('/admin/analytics/settings?tab=retention');
      const input = page.locator('input[name="settings.top_n_detail"]');
      await expect(input).toBeVisible({ timeout: 30_000 });
      await expect(input).toHaveValue(String(before), { timeout: 30_000 });

      await input.fill(String(Number(before) + 1));
      await page.locator('#save_button').click();

      const box = page.locator('#validation_error');
      await expect(box).toBeVisible({ timeout: 20_000 });
      await expect(box, '화면에 남는 말이 서버가 준 사유여야 한다').toContainText(REASON);
      await expect(box, '고칠 입력이 없는데 입력 탓을 하면 안 된다').not.toContainText('입력값을 확인하세요');
      // 필드별 오류가 아니므로 어느 칸에도 빨간 줄이 붙지 않는다.
      await expect(page.locator('#field_retention_days .form-error')).toHaveCount(0);

      await page.unroute((url) => url.pathname.endsWith('/api/admin/analytics/settings'));
      const stored = await page.request.get(ANALYTICS_SETTINGS_API, { headers: bearer(token) });
      expect((await stored.json()).data.settings.top_n_detail, '실패한 저장은 값을 바꾸지 않는다').toBe(before);
    } finally {
      await guard.restore();
    }
  });
  // @scenario screen=settings, state=consent_fallback, viewport=1440, locale=ko, e2e=settings_privacy
  // @effects settings_screen_warns_on_server_reported_anonymous_fallback
  test('저장된 모드가 전체 수집이어도 서버가 익명 폴백을 알리면 개인정보 탭이 그 사유를 말한다', async ({ page }) => {
    // 서버는 `consent_plugin_blocks_cookies` 를 **full 모드에서만** 만든다. 종전 화면 조건은 라디오가
    // `consent` 일 때만 경고를 띄워 두 조건이 배타적이었고, 그래서 다른 12면과 진단은 알리는데 정작
    // 그 설정을 고치는 화면만 침묵했다(계획 §4.10 은 「설정 화면만 두 조건을 OR 로 본다」). 단위 가드는
    // 식의 모양만 보므로 「운영자가 그 탭에서 무엇을 읽는가」는 브라우저에서만 확인된다.
    const token = issueToken('core.analytics.update', 'core.analytics.read');
    await authenticatePage(page, token);

    // 진단 응답만 손댄다 — 설정 조회는 그대로 둬야 저장된 모드(full)가 폼에 실린다.
    await page.route(
      (url) => url.pathname.endsWith('/api/admin/analytics/diagnostics'),
      async (route) => {
        const upstream = await route.fetch();
        const body = await upstream.json();
        body.data = body.data ?? {};
        body.data.collection = {
          ...(body.data.collection ?? {}),
          enabled: true,
          mode: 'full',
          effective_mode: 'anonymous',
          anonymous_fallback_reason: 'consent_plugin_blocks_cookies',
          consent_source: 'provider',
        };
        await route.fulfill({ response: upstream, json: body });
      },
    );

    try {
      await page.goto('/admin/analytics/settings?tab=privacy');

      // 저장된 모드가 「전체 수집」인 것을 먼저 확인한다 — 이것이 이 축의 전제다.
      const fullMode = page.locator('[role="radio"]', { hasText: '전체 수집' });
      await expect(fullMode).toBeVisible({ timeout: 30_000 });
      await expect(fullMode, '저장된 모드가 full 이어야 이 분기가 성립한다').toHaveAttribute('aria-checked', 'true');

      const warning = page.locator('#consent_warning');
      await expect(warning, '설정 화면도 익명 폴백을 알려야 한다').toBeVisible({ timeout: 30_000 });
      // 사유별 문구 — 플러그인이 켜져 있는데 「비활성이라」고 하면 운영자가 플러그인을 찾아 헤맨다.
      await expect(warning).toContainText('쿠키');
      await expect(warning, '켜 둔 플러그인을 비활성이라고 말하면 안 된다').not.toContainText('비활성');
      await expect(page.locator('body')).not.toContainText('$t:');
    } finally {
      await page.unroute((url) => url.pathname.endsWith('/api/admin/analytics/diagnostics'));
    }
  });
});
