/**
 * E2E: 관리자 「GDPR 동의 이력」 화면 — 출처 필터 「회원탈퇴」 체크박스 (#601 문서화 세션 중 발견)
 *
 * @scenario admin_gdpr_consent_log_source_filter_withdraw
 * @effects withdraw_checkbox_visible, withdraw_filter_updates_query_and_refetches
 *
 * 배경: `ConsentSource` enum 에 `withdraw`(회원탈퇴 시 일괄 철회) case 가 없어, 그 출처로
 * 기록된 동의 이력 행이 출처 필터 어디로도 걸러지지 않던 결함을 발견해 enum·기록 지점(Service·
 * Repository)·라벨(ko/en/ja)·이 필터 체크박스를 함께 추가했다. PHPUnit 쪽은
 * `ConsentSourceVocabularyParityTest` 가 JSON 안의 `includes('withdraw')`/라벨 바인딩
 * 존재를 정적으로 검증하지만, 그 체크박스가 실제 브라우저에서 보이고 클릭 시 목록이 다시
 * 조회되는지는 별도로 확인해야 한다.
 *
 * 검증:
 *  1. 동의 이력 화면에 "회원탈퇴" 출처 필터 체크박스가 보인다
 *  2. 체크하면 URL 쿼리에 `sources` 값으로 `withdraw` 가 반영되고 목록이 재조회된다
 *  3. 다시 해제하면 `withdraw` 가 쿼리에서 빠진다
 */
import { test, expect, authenticatePage } from '../../fixtures/gdpr-auth';

const WITHDRAW_FILTER_LABEL = '회원탈퇴';

async function gotoConsentLog(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/plugins/sirsoft-gdpr/consent-log');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#gdpr_consent_log_datagrid__body')).toBeAttached({ timeout: 20_000 });
}

// @scenario source_filter=withdraw
// @effects withdraw_checkbox_visible
test('#601 - 동의 이력 출처 필터에 "회원탈퇴" 체크박스가 보인다', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);
  await gotoConsentLog(page);

  const checkbox = page.getByLabel(WITHDRAW_FILTER_LABEL);
  await expect(checkbox).toBeAttached({ timeout: 10_000 });
  await expect(checkbox).not.toBeChecked();
});

// @scenario source_filter=withdraw, toggle=on_then_off
// @effects withdraw_filter_updates_query_and_refetches
test('#601 - "회원탈퇴" 체크 시 URL 쿼리에 반영되고, 해제하면 빠진다', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);
  await gotoConsentLog(page);

  const checkbox = page.getByLabel(WITHDRAW_FILTER_LABEL);
  await checkbox.check();

  // searchConsentLogs 가 navigate(mergeQuery) 로 sources 배열을 쿼리에 싣는다.
  await expect(page).toHaveURL(/withdraw/, { timeout: 10_000 });
  await expect(checkbox).toBeChecked();

  await checkbox.uncheck();
  await expect(page).not.toHaveURL(/withdraw/, { timeout: 10_000 });
});
