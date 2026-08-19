/**
 * E2E: NHN KCP 휴대폰 본인확인 플러그인 환경설정 화면
 *
 * 운영 모드로 전환하면 경고 배너가 나타나고, 자격증명을 비운 채 저장하면 저장이 막히면서
 * 어떤 항목이 필요한지 입력칸 옆에 표시되는지 브라우저에서 확인한다. 단위/기능 테스트는
 * 서버 규칙만 잠그므로, 화면에서 실제로 안내가 보이는지는 여기서 잠근다.
 *
 * KCP 표준창 내부(외부 도메인)는 자동화 대상이 아니다 — 인증창이 열리는 것까지만 확인 가능하며
 * 통신사 인증 완주는 실 휴대폰이 필요해 사람이 검증한다.
 *
 * 축 요약(마커 아님 — 평문): mode=test / live_credentials=complete 조합과
 * mode=live / live_credentials=missing_site_cd 조합. 두 조합은 각 test 의 마커가 잠그며,
 * 여기에 마커로 다시 적으면 파서가 항목을 쉼표로만 나누어 두 조합이 한 문자열로 뭉친다.
 *
 * 효과 요약(마커 아님 — 평문): live_mode_requires_site_cd_and_enc_key, test_mode_saves_without_live_credentials.
 */
import { test, expect, authenticatePage } from '../../fixtures/nhnkcp-auth';

const SETTINGS_PATH = '/admin/plugins/sirsoft-verification_nhnkcp/settings';

/**
 * 토글 스위치를 조작한다.
 *
 * 실제 checkbox 는 sr-only(1×1, tabindex=-1) 로 숨겨져 있고 사용자가 누르는 대상은
 * 옆의 트랙 요소다. checkbox 를 직접 클릭하면 상태가 바뀌지 않는다.
 *
 * @param page Playwright page
 * @param name checkbox 의 name 속성
 */
async function toggleSwitch(page: import('@playwright/test').Page, name: string): Promise<void> {
  const track = page.locator(`.toggle-switch-wrapper:has(input[name="${name}"]) .toggle-switch-track`).first();
  await expect(track).toBeVisible({ timeout: 20_000 });
  await track.click();
}

test.describe('NHN KCP 본인확인 환경설정', () => {
  // 이 파일의 모든 테스트가 같은 플러그인 설정 파일을 쓴다. config 는 fullyParallel 이라
  // 파일 안의 테스트도 병렬로 갈릴 수 있어, 서로의 저장 결과를 덮어써 원복이 어긋난다.
  // 실행 옵션(--workers=1)에 맡기지 않고 spec 자체가 직렬을 보장한다.
  test.describe.configure({ mode: 'serial' });

  /**
   * 화면 진입 시 서버가 돌려준 설정 스냅샷.
   *
   * 원복 기준값을 DOM 에서 읽으면 폼 바인딩이 끝나기 전에 빈 문자열을 원본으로 잡을 수 있어
   * 응답 본문에서 직접 가져온다.
   */
  let settingsSnapshot: Record<string, unknown> = {};

  test.beforeEach(async ({ page, pluginSettingsToken }) => {
    await authenticatePage(page, pluginSettingsToken);

    // 설정 응답이 도착해야 폼(_local.form)이 채워진다. domcontentloaded 만 기다리면
    // 응답 전에 입력을 시작해 변경분이 초기화되면서 저장 버튼이 잠긴 채로 남는다.
    const settingsLoaded = page.waitForResponse(
      (res) =>
        res.url().includes('/api/admin/plugins/sirsoft-verification_nhnkcp/settings') &&
        res.request().method() === 'GET',
      { timeout: 30_000 },
    );

    await page.goto(SETTINGS_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const body = await (await settingsLoaded).json();
    settingsSnapshot = (body?.data ?? {}) as Record<string, unknown>;

    const siteCd = page.locator('[name="live_site_cd"]').first();
    await expect(siteCd).toBeVisible({ timeout: 20_000 });

    // 폼 바인딩이 응답값에 도달할 때까지 기다린다 (여기까지 오면 입력을 시작해도 안전하다).
    await expect(siteCd).toHaveValue(String(settingsSnapshot.live_site_cd ?? ''), { timeout: 20_000 });
  });

  // @scenario mode=test,live_credentials=complete
  // @effects test_mode_saves_without_live_credentials
  test('설정 화면이 열리고 다국어 원문 키가 노출되지 않는다', async ({ page }) => {
    await expect(page.getByText('NHN KCP').first()).toBeVisible({ timeout: 20_000 });

    // 번역되지 않은 원문 키가 화면에 남아 있으면 안 된다.
    const raw = await page.getByText('$t:').count();
    expect(raw).toBe(0);
  });

  // @scenario mode=live,live_credentials=missing_site_cd
  // @effects live_mode_requires_site_cd_and_enc_key
  test('운영 모드로 전환하면 경고 배너가 나타난다', async ({ page }) => {
    await toggleSwitch(page, 'is_test_mode');

    await expect(page.locator('[name="is_test_mode"]').first()).not.toBeChecked({ timeout: 10_000 });
    await expect(page.getByText(/운영 모드|라이브/).first()).toBeVisible({ timeout: 10_000 });
  });

  // @scenario mode=live,live_credentials=missing_site_cd
  // @effects live_mode_requires_site_cd_and_enc_key
  test('운영 자격증명을 비운 채 저장하면 저장이 막히고 안내가 표시된다', async ({ page }) => {
    await toggleSwitch(page, 'is_test_mode');

    // 운영 자격증명을 비운 상태를 명시적으로 만든다 (저장된 값이 있으면 422 가 나지 않는다).
    await page.locator('[name="live_site_cd"]').first().fill('');
    await page.locator('[name="live_enc_key"]').first().fill('');

    const saveButton = page.getByRole('button', { name: /저장/ }).last();
    await expect(saveButton).toBeEnabled({ timeout: 10_000 });

    const [response] = await Promise.all([
      page.waitForResponse(
        (res) => res.url().includes('/settings') && res.request().method() === 'PUT',
        { timeout: 20_000 },
      ),
      saveButton.click(),
    ]);

    expect(response.status()).toBe(422);
    await expect(page.getByText(/사이트코드|암호화 키/).first()).toBeVisible({ timeout: 10_000 });
  });

  // @scenario mode=live,live_credentials=complete
  // @effects sm_prefix_is_added_once
  test('사이트코드에 SM 을 포함해 입력하면 저장 후 입력칸이 프리픽스 없는 값으로 바뀐다', async ({ page }) => {
    const siteCd = page.locator('[name="live_site_cd"]').first();
    const encKey = page.locator('[name="live_enc_key"]').first();

    // 이 spec 은 실 환경 설정 파일에 쓴다. 암호화 키는 건드리지 않고(화면이 마스크를 그대로
    // 되돌려 보내 종전 값이 보존된다), 바꾼 사이트코드는 끝에 원복한다.
    const originalSiteCd = String(settingsSnapshot.live_site_cd ?? '');

    const save = async (): Promise<number> => {
      const button = page.getByRole('button', { name: /저장/ }).last();
      await expect(button).toBeEnabled({ timeout: 10_000 });
      const [response] = await Promise.all([
        page.waitForResponse(
          (res) => res.url().includes('/settings') && res.request().method() === 'PUT',
          { timeout: 20_000 },
        ),
        button.click(),
      ]);
      return response.status();
    };

    try {
      await siteCd.fill('SMA1B2C');
      expect(await save()).toBe(200);

      // 입력칸 왼쪽에 SM 배지가 따로 있으므로, 프리픽스가 값에 남으면 화면에 SMSMA1B2C 로 보인다.
      await expect(siteCd).toHaveValue('A1B2C', { timeout: 10_000 });

      // 저장된 암호화 키는 화면에 평문으로 돌아오지 않는다 — 비어 있거나 마스크여야 한다.
      expect(await encKey.getAttribute('type')).toBe('password');
      const shownKey = await encKey.inputValue();
      expect(shownKey === '' || /^•+$/.test(shownKey)).toBe(true);
    } finally {
      await siteCd.fill(originalSiteCd);
      await save();
    }
  });
});
