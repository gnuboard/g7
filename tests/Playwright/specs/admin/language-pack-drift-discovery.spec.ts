/**
 * E2E: 관리자 언어팩 목록의 드리프트(설치본 파일 부재) 발견 + 원클릭 복구 — 이슈 #496 Part B3
 *
 * 배경: DB 에 active 로 기록됐지만 설치본 디렉토리(`lang-packs/{id}/`)가 사라진 팩은 런타임에
 * 오류 없이 base locale(ko)로 조용히 폴백한다. 목록에는 정상(active)으로 보였기 때문에 운영자가
 * "번역이 적용되지 않는다"는 증상만 보고 원인을 찾지 못했다(#483 실측). 화면에 `파일 없음` 배지와
 * 재설치 버튼을 노출해 발견·복구가 한 화면에서 끝나게 한다.
 *
 * 계층 분담:
 *   - files_missing / bundled_source_available 파생 판정 → PHPUnit (ListDriftDetectionTest)
 *   - 배지/버튼 조건식 평가 → Vitest (admin-language-pack-drift.test.tsx, createLayoutTest)
 *   - 본 spec → 실제 브라우저에서 목록 화면이 뜨고 조건이 라이브 데이터에 걸리는지 최종 확증
 *
 * @scenario target_locale_source=supported_locales_default, locale_membership=non_base_declared,
 *           pack_state=drifted_missing_files, scope=core
 * @effects admin_list_shows_drift_badge_and_reinstall,
 *          active_row_with_missing_install_dir_flagged_files_missing
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

// 표시 문구는 로케일에 따라 바뀐다(테스트 브라우저는 en 으로 렌더될 수 있다).
// 로케일 무관하게 잠그기 위해 레이아웃의 data-testid 로 집는다.
/** 드리프트 배지 test id */
const DRIFT_BADGE = 'lp-drift-badge';

/** 재설치 버튼 test id */
const REINSTALL_BUTTON = 'lp-reinstall-button';

test.describe('관리자 언어팩 목록 — 드리프트 발견', () => {
  test('배지·재설치 버튼 노출이 API 가 내려준 드리프트 상태와 일치한다', async ({ page }) => {
    const token = issueToken('core.language_packs.read', 'core.language_packs.install');
    await authenticatePage(page, token);

    // 뷰포트가 좁으면 행 액션 버튼이 카드 우측에서 화면 밖으로 밀린다.
    await page.setViewportSize({ width: 1600, height: 1000 });

    const listResponse = page.waitForResponse(
      (r) => r.url().includes('/api/admin/language-packs') && r.status() === 200,
      { timeout: 30_000 },
    );
    await page.goto('/admin/settings?tab=language_packs');
    const rows = (await (await listResponse).json())?.data?.data ?? [];

    // 목록이 비어 있으면 어떤 단언도 의미가 없다 — 화면/권한 회귀를 여기서 잡는다.
    expect(rows.length).toBeGreaterThan(0);

    // 화면에 떠야 할 배지/버튼 수를 응답에서 직접 계산한다.
    // "0건이어야 한다" 만 단언하면 아직 안 그려진 상태에서도 통과해(vacuous) 실제 결손을 놓친다.
    const expectedBadges = rows.filter((r: { files_missing?: boolean }) => r.files_missing === true).length;
    const expectedButtons = rows.filter(
      (r: { files_missing?: boolean; bundled_source_available?: boolean; abilities?: { can_install?: boolean } }) =>
        r.files_missing === true && r.bundled_source_available === true && r.abilities?.can_install === true,
    ).length;

    // 카드가 실제로 그려진 뒤에 세어야 한다. 앵커는 UI 문구가 아니라 응답 데이터(원어 언어명)라
    // 표시 로케일이 ko 든 en 이든 동일하게 걸린다. 배지는 카드와 같은 렌더 패스에서 결정되므로,
    // 카드가 보이는 시점에는 배지 노출 여부도 이미 확정돼 있다.
    const nativeName = rows[0]?.locale_native_name ?? '';
    if (nativeName) {
      await expect(page.getByText(nativeName, { exact: false }).first()).toBeVisible({ timeout: 20_000 });
    }

    await expect
      .poll(() => page.getByTestId(DRIFT_BADGE).count(), { timeout: 20_000 })
      .toBe(expectedBadges);
    await expect
      .poll(() => page.getByTestId(REINSTALL_BUTTON).count(), { timeout: 20_000 })
      .toBe(expectedButtons);
  });

  /**
   * 드리프트 상태 주입에는 서버 파일시스템 조작(설치본 디렉토리 삭제)이 필요하다.
   * 브라우저에서는 만들 수 없는 상태이므로, 아래는 드리프트를 주입한 환경에서만 활성화한다.
   *
   * 활성화 절차:
   *   1. `php artisan language-pack:provision --locale=ja` 로 g7-core-ja 설치
   *   2. `lang-packs/g7-core-ja/` 디렉토리 삭제 (DB active 행은 남김)
   *   3. `test.describe.skip` → `test.describe` 로 전환
   *
   * 복구 확인 후에는 `php artisan language-pack:provision --locale=ja` 로 원상 복구된다.
   *
   * @scenario target_locale_source=explicit_locale_option, locale_membership=non_base_declared,
   *           pack_state=drifted_missing_files, scope=core
   * @effects admin_list_shows_drift_badge_and_reinstall, drifted_pack_repaired_by_provision
   */
  test.describe.skip('드리프트 주입 환경 (수동 활성화)', () => {
    test('드리프트 행에 배지와 재설치 버튼이 뜨고, 재설치로 복구된다', async ({ page }) => {
      const token = issueToken('core.language_packs.read', 'core.language_packs.install');
      await authenticatePage(page, token);

      // 행 액션 버튼은 카드 우측에 놓여 좁은 뷰포트에서 화면 밖으로 밀린다 — 넓게 잡는다.
      await page.setViewportSize({ width: 1600, height: 1000 });

      const listResponse = page.waitForResponse(
        (r) => r.url().includes('/api/admin/language-packs') && r.status() === 200,
        { timeout: 30_000 },
      );
      await page.goto('/admin/settings?tab=language_packs');
      await listResponse;

      await expect(page.getByTestId(DRIFT_BADGE).first()).toBeVisible({
        timeout: 20_000,
      });

      const reinstall = page.getByTestId(REINSTALL_BUTTON).first();
      await expect(reinstall).toBeVisible();
      await reinstall.click();

      // 재설치는 번들 설치 모달(language_pack_install_bundled_modal)을 재사용한다
      const confirm = page.locator('button:visible', { hasText: /^설치$/ }).last();
      await expect(confirm).toBeEnabled({ timeout: 10_000 });

      const install = page.waitForResponse(
        (r) => r.url().includes('install-from-bundled') && r.request().method() === 'POST',
        { timeout: 30_000 },
      );
      await confirm.click();
      expect((await install).status()).toBe(201);

      // 복구 후에는 배지와 재설치 버튼이 사라진다
      await expect(page.getByTestId(DRIFT_BADGE)).toHaveCount(0, { timeout: 20_000 });
    });
  });
});
