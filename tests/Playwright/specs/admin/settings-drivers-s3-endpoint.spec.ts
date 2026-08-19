/**
 * E2E: 환경설정 > 드라이버 — S3 리전 자유입력·엔드포인트·path-style (#99/#563)
 *
 * 시나리오 매니페스트: tests/scenarios/s3-storage-driver.yaml — 마킹은 각 테스트의
 * scenario(k=v 조합)·effects 주석이 담당하며, 헤더 요약 마킹은 파서 형식이 아니다
 *
 * 배경: S3 리전이 5개 하드코딩 Select 라 Cloudflare R2(auto)·신규 AWS 리전을 입력할 수
 * 없었고, S3 호환 스토리지의 API 주소(endpoint)를 넣을 자리가 없어 s3_url(공개 URL)에
 * 잘못 넣는 혼란이 있었다. 리전 Input 전환 + endpoint/path-style 신설을 브라우저에서
 * 확인한다.
 *
 * 검증:
 *  1. s3 선택 시 리전이 자유 입력 Input(Select 아님)·endpoint Input·path-style Toggle 이
 *     번역문과 함께 마운트된다
 *  2. 불량 형식 리전은 저장 버튼 클릭이 422 로 거부되고 리전 필드에 inline 에러가
 *     표시된다 (s3 저장 게이트가 요구하는 연결 테스트만 route 스텁으로 성공 처리 —
 *     외부 S3 실연결이 E2E 환경에 없기 때문이며, 저장 요청·에러 렌더는 전부 실경로)
 *  3. local 드라이버 저장 왕복(외부 연결 불요 경로)이 신규 키를 보존한다
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** 관리자 환경설정 드라이버 탭 진입 + s3 선택 */
async function gotoDriversTabWithS3(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=drivers');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 템플릿 Select 는 options 모드에서 네이티브 select 가 아니라
  // 커스텀 드롭다운(button[aria-haspopup=listbox] + role=option)을 렌더한다.
  const storageSelect = page.locator('[name="drivers.storage_driver"]').first();
  await expect(storageSelect).toBeAttached({ timeout: 20_000 });
  await storageSelect.locator('button[aria-haspopup="listbox"]').first().click();
  await page.getByRole('option', { name: 'Amazon S3' }).click();
  await expect(page.locator('#s3_settings')).toBeAttached({ timeout: 10_000 });
}

// @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=local_default
// @effects region_free_input_mounted
test('@smoke #99 - s3 선택 시 리전이 자유 입력 Input 으로 마운트된다 (Select 아님)', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoDriversTabWithS3(page);
  expect(page.url()).not.toMatch(/\/admin\/login/);

  // 존재 확정: 리전 필드는 자유 입력 input 이다 (종전에는 커스텀 드롭다운이라 input 자체가 없었다)
  const regionInput = page.locator('input[name="drivers.s3_region"]').first();
  await expect(regionInput).toBeAttached();

  // 부재 단언: 종전 리전 드롭다운(커스텀 Select 루트)이 제거되었다
  await expect(page.locator('[name="drivers.s3_region"] button[aria-haspopup="listbox"]')).toHaveCount(0);

  // 자유 입력이 실제로 동작한다 (R2 표준값)
  await regionInput.fill('auto');
  await expect(regionInput).toHaveValue('auto');
});

// @scenario region_shape=custom_minio,endpoint=path_style,driver_usability=adapter_present,attachment_disk=local_default
// @effects endpoint_field_mounted, path_style_toggle_mounted
test('#99 - endpoint Input 과 path-style Toggle 이 번역문과 함께 마운트된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoDriversTabWithS3(page);

  const endpointField = page.locator('#s3_endpoint_field');
  await expect(endpointField).toBeAttached();
  await expect(endpointField.locator('input[name="drivers.s3_endpoint"]')).toBeAttached();

  const toggleField = page.locator('#s3_use_path_style_field');
  await expect(toggleField).toBeAttached();
  await expect(toggleField.locator('input[type="checkbox"]').first()).toBeAttached();

  // 다국어 키 미해석 회귀 가드 — s3 블록 전체에 "$t:" 원문이 노출되면 안 된다
  const blockText = (await page.locator('#s3_settings').innerText()).trim();
  expect(blockText).not.toContain('$t:');
  expect(blockText.length).toBeGreaterThan(0);
});

// @scenario region_shape=invalid_format,endpoint=absent,driver_usability=adapter_present,attachment_disk=local_default
// @effects invalid_region_rejected
test('#99 - 불량 형식 리전은 저장 클릭이 422 로 거부되고 리전 필드에 오류가 표시된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  // s3 저장 게이트는 연결 테스트 성공을 요구한다. 외부 S3 실연결이 E2E 환경에
  // 없으므로 연결 테스트 응답만 성공으로 스텁한다 — 저장 요청 자체는 실서버로
  // 나가 422 계약과 inline 에러 렌더를 실경로로 검증한다.
  await page.route('**/api/admin/settings/test-driver', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { results: { s3: { success: true, message: 'stubbed' } } },
      }),
    })
  );

  await gotoDriversTabWithS3(page);

  // s3 필드 입력 — 불량 형식 리전 포함 (대문자·특수문자)
  await page.locator('input[name="drivers.s3_bucket"]').fill('bucket');
  await page.locator('input[name="drivers.s3_region"]').fill('S3!bad');
  await page.locator('input[name="drivers.s3_access_key"]').fill('key');
  await page.locator('input[name="drivers.s3_secret_key"]').fill('secret');

  // 연결 테스트(스텁 성공) → 저장 게이트 해제 → 저장 버튼 활성 전이
  await page.locator('#s3_test_section button').click();
  const saveButton = page.locator('#save_button');
  await expect(saveButton).toBeEnabled({ timeout: 10_000 });

  // 저장 클릭 → 실서버 422
  const [saveResponse] = await Promise.all([
    page.waitForResponse(
      (r) =>
        r.request().method() === 'POST' &&
        r.url().includes('/api/admin/settings') &&
        !r.url().includes('test-driver')
    ),
    saveButton.click(),
  ]);
  expect(saveResponse.status()).toBe(422);

  // 서버가 돌려준 리전 오류 문구가 리전 필드 inline 에러로 렌더된다
  const body = await saveResponse.json();
  const regionMessage = body?.errors?.['drivers.s3_region']?.[0];
  expect(regionMessage).toBeTruthy();
  await expect(page.locator('input[name="drivers.s3_region"]')).toHaveClass(/input-error/);
  await expect(page.locator('#s3_settings')).toContainText(regionMessage);
});

// @scenario region_shape=auto,endpoint=custom_url,driver_usability=adapter_present,attachment_disk=local_default
// @effects local_roundtrip_preserves_new_keys
test('#99 - local 드라이버 저장 왕복이 신규 키(s3_endpoint/path-style)를 보존한다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await page.goto('/admin/settings?tab=drivers');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const result = await page.evaluate(async (bearer) => {
    const headers = {
      Authorization: `Bearer ${bearer}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    };

    // 현재 값을 읽어 그대로 되돌려 놓는 왕복 — 검수 서버 상태를 바꾸지 않는다
    const before = await (
      await fetch('/api/admin/settings', { headers })
    ).json();
    const drivers = before?.data?.drivers ?? {};

    const save = await fetch('/api/admin/settings', {
      method: 'POST',
      headers,
      body: JSON.stringify({
        _tab: 'drivers',
        drivers: {
          ...drivers,
          storage_driver: 'local',
          s3_region: 'auto',
          s3_endpoint: 'http://127.0.0.1:9000',
          s3_use_path_style: true,
        },
      }),
    });

    const after = await (
      await fetch('/api/admin/settings', { headers })
    ).json();

    // 원상 복구 (검수 서버 상태 보존)
    await fetch('/api/admin/settings', {
      method: 'POST',
      headers,
      body: JSON.stringify({ _tab: 'drivers', drivers }),
    });

    return {
      saveStatus: save.status,
      savedEndpoint: after?.data?.drivers?.s3_endpoint,
      savedPathStyle: after?.data?.drivers?.s3_use_path_style,
      savedRegion: after?.data?.drivers?.s3_region,
    };
  }, token);

  expect(result.saveStatus).toBe(200);
  expect(result.savedRegion).toBe('auto');
  expect(result.savedEndpoint).toBe('http://127.0.0.1:9000');
  expect(Boolean(result.savedPathStyle)).toBe(true);
});
