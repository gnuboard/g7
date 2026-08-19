/**
 * E2E: 탭 왕복 후 먼저 편집한 입력칸에 옛 값이 남는 결함 (engine-v1.54.7)
 *
 * 시나리오 매니페스트: `tests/scenarios/localinit-tab-roundtrip-store-sync.yaml`
 * (케이스 마킹은 각 test 의 docblock 에 있다 — 파일 헤더에 두면 축 값이 비어 매칭되지 않는다)
 *
 * @effects earlier_edited_field_resets_to_server_value_after_tab_roundtrip,
 *          last_edited_field_still_resets_after_tab_roundtrip,
 *          untouched_field_unaffected_by_tab_roundtrip,
 *          prune_never_reintroduces_values,
 *          readonly_permission_disables_inputs,
 *          tab_roundtrip_emits_no_console_errors
 *
 * 배경: `_localInit` 적용 여부를 전역 해시(`__g7LocalInitTracking`)로만 판정했는데, 실제 리셋
 * 대상인 `localDynamicState`(저장소 A)는 렌더러 인스턴스별이다. 먼저 effect 가 도는 인스턴스가
 * 전역 토큰을 소비하면 나머지 루트 렌더러의 저장소 A 는 리셋되지 않고, 병합이 A 우선이라
 * 갱신된 저장소 B 위에 stale A 가 덮였다.
 *
 * 순서 의존성: 자동바인딩은 키입력마다 `_local` 전체 스냅샷을 A 에 쓰는데 정리는 마지막 leaf 만
 * 지운다 → A 에 "직전까지 편집한 필드들"의 사본이 남는다. 그래서 **마지막 편집 칸만** 정상으로
 * 보이고 그 이전 칸들만 옛 값이 남는 비대칭이 생겼다.
 *
 * 재현 조건 3가지가 모두 필요하다:
 *   ① 폼 데이터소스가 initLocal + refetchOnMount: true
 *   ② 탭 전환이 URL(?tab=)을 바꿔 remount + refetch 유발
 *   ③ 되돌아오기 전 2개 이상의 입력칸을 실제 타이핑으로 편집
 *
 * 대상 화면은 이커머스 환경설정 "주문설정" 탭이다 — 브라우저 계측으로 결함이 실제로 재현된
 * 화면이며, 자동바인딩(`name` prop) 숫자 입력칸이 두 개 있어 순서 의존성을 그대로 노출한다.
 * 저장은 하지 않으므로 사이트 설정을 바꾸지 않는다.
 *
 * 단위 테스트는 이 결함을 잡지 못한다 — React 렌더 사이클과 복수 루트 렌더러의 effect 실행
 * 순서를 모사하지 못하기 때문이다.
 *
 * 회귀 배경: 탭 왕복 후 initLocal 동기화가 편집 중이던 입력칸의 값을 되돌려, 먼저 편집한
 * 입력칸에만 옛 값이 남던 결함 (engine-v1.54.7 에서 수정).
 */
import { test, expect, issueToken, issueScopedToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const ORDER_TAB = '/admin/ecommerce/settings?tab=order_settings';

const FIELD_A = 'input[name="order_settings.auto_cancel_days"]';
const FIELD_B = 'input[name="order_settings.cart_expiry_days"]';

const SETTINGS_PERMISSIONS = [
  'sirsoft-ecommerce.settings.read',
  'sirsoft-ecommerce.settings.update',
] as const;

/**
 * 주문설정 탭 진입 — 폼 데이터가 `_local` 에 실제로 실릴 때까지 기다린다.
 *
 * DOM attach 만 기다리면 바인딩 전 빈 값을 서버값으로 오인해 단언이 무의미해진다.
 * 엔진의 `getLocal()` 로 폼 적재를 직접 확인한다.
 */
async function gotoOrderTab(page: Page): Promise<void> {
  await page.goto(ORDER_TAB);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // 폼 적재를 먼저 확인한다. DOM attach 만 기다리면 조건부 렌더 입력칸(auto_cancel_days 는
  // auto_cancel_expired 토글에 걸려 있다)이 "아직 안 그려진 것"과 "꺼져서 없는 것"을 구분하지
  // 못해, 부재 단언이 렌더 전에 통과해 버린다.
  await expect
    .poll(
      async () =>
        await page.evaluate(() => {
          const local = (window as any).G7Core?.state?.getLocal?.();
          return local?.form?.order_settings !== undefined;
        }),
      { timeout: 20_000 }
    )
    .toBe(true);
  await expect(page.locator(FIELD_A)).toBeAttached({ timeout: 20_000 });
  await expect(page.locator(FIELD_B)).toBeAttached({ timeout: 20_000 });
}

/**
 * 서버값과 반드시 다른 숫자 문자열을 만든다 — 같은 값을 넣으면 리셋 여부를 구분할 수 없다.
 * 두 필드의 허용 범위(1~30 / 1~365) 안에 함께 들어가는 작은 수만 쓴다.
 */
function other(current: string): string {
  return current.trim() === '7' ? '9' : '7';
}

/** 실제 타이핑으로 값을 바꾼다 — fill 은 자동바인딩의 키입력 경로를 그대로 타지 않는다 */
async function typeInto(page: Page, selector: string, value: string): Promise<void> {
  const input = page.locator(selector);
  await input.click();
  await input.press('ControlOrMeta+a');
  await input.pressSequentially(value, { delay: 30 });
  await input.blur();
}

/**
 * 다른 탭에 갔다가 주문설정 탭으로 돌아온다.
 *
 * `page.goto` 를 쓰면 안 된다 — 전체 새로고침이라 모든 렌더러가 새로 마운트되고 전역 추적도
 * 초기화되어 재현 조건이 통째로 사라진다(실측: goto 왕복으로는 수정 전에도 결함이 안 뜬다).
 * 결함은 SPA 라우팅(탭 클릭)으로 URL 만 바뀌어 보존 컴포넌트의 저장소 A 가 살아남을 때 드러난다.
 */
async function tabRoundTrip(page: Page): Promise<void> {
  await page.getByRole('tab', { name: '마일리지' }).click();
  await expect(page).toHaveURL(/tab=mileage/, { timeout: 15_000 });
  await expect(page.locator(FIELD_B)).toHaveCount(0, { timeout: 15_000 });

  await page.getByRole('tab', { name: '주문설정' }).click();
  await expect(page).toHaveURL(/tab=order_settings/, { timeout: 15_000 });
  await expect(page.locator(FIELD_B)).toBeAttached({ timeout: 20_000 });
  await expect(page.locator(FIELD_A)).toBeAttached({ timeout: 20_000 });
}

/**
 * 이 스펙은 직렬로 실행한다 (@since engine-v1.54.8).
 *
 * 5개 테스트가 모두 관리자 이커머스 환경설정 화면을 띄우는데, 병렬(워커 5)로 돌리면
 * `_local` 폼 적재(20초 poll)가 타임아웃되어 전건이 진입 단계에서 실패한다 —
 * 실측: `--workers=5` → 3~5건 실패 / `--workers=1` → 5건 통과, 동일 빌드·동일 코드.
 * 실패 시 4xx/5xx 응답은 0이고 화면은 렌더되므로 제품 결함이 아니라 동시 세션 부하다.
 * 직렬 고정을 빼면 이 스펙은 회귀를 잡는 대신 거짓 실패를 만들어낸다.
 */
test.describe.configure({ mode: 'serial' });

test.describe('탭 왕복 후 저장소 A/B 동기화 (engine-v1.54.7)', () => {
  /**
   * @scenario edit_order=first_edited
   *
   * @effects earlier_edited_field_resets_to_server_value_after_tab_roundtrip
   */
  test('두 칸을 편집하고 탭을 왕복하면 먼저 편집한 칸도 서버값으로 돌아온다', async ({ page }) => {
    await authenticatePage(page, issueToken(...SETTINGS_PERMISSIONS));
    await gotoOrderTab(page);

    // 저장하지 않은 상태의 서버값 — 왕복 후 이 값으로 돌아와야 한다
    const serverA = await page.locator(FIELD_A).inputValue();
    const serverB = await page.locator(FIELD_B).inputValue();

    const typedA = other(serverA);
    const typedB = other(serverB);

    await typeInto(page, FIELD_A, typedA); // ← 먼저 편집 (수정 전 결함 대상)
    await typeInto(page, FIELD_B, typedB); // ← 마지막 편집 (수정 전에도 정상)

    await expect(page.locator(FIELD_A)).toHaveValue(typedA);
    await expect(page.locator(FIELD_B)).toHaveValue(typedB);

    await tabRoundTrip(page);

    // 수정 전에는 FIELD_A 만 typedA 가 남아 화면과 저장값이 어긋났다
    await expect(page.locator(FIELD_A)).toHaveValue(serverA);
    await expect(page.locator(FIELD_B)).toHaveValue(serverB);
  });

  /**
   * @scenario edit_order=last_edited
   *
   * @effects last_edited_field_still_resets_after_tab_roundtrip
   */
  test('입력 순서를 뒤집어도 두 칸 모두 서버값으로 돌아온다 (순서 비의존)', async ({ page }) => {
    await authenticatePage(page, issueToken(...SETTINGS_PERMISSIONS));
    await gotoOrderTab(page);

    const serverA = await page.locator(FIELD_A).inputValue();
    const serverB = await page.locator(FIELD_B).inputValue();

    // 앞선 테스트와 순서만 뒤집는다 — 결함이 입력 순서를 따라 움직이던 축을 고정한다
    await typeInto(page, FIELD_B, other(serverB));
    await typeInto(page, FIELD_A, other(serverA));

    await tabRoundTrip(page);

    await expect(page.locator(FIELD_A)).toHaveValue(serverA);
    await expect(page.locator(FIELD_B)).toHaveValue(serverB);
  });

  /**
   * @scenario edit_order=untouched
   *
   * @effects untouched_field_unaffected_by_tab_roundtrip, prune_never_reintroduces_values
   */
  test('편집하지 않은 칸은 탭 왕복 전후로 값이 변하지 않는다 (제거가 값을 도입하지 않음)', async ({
    page,
  }) => {
    await authenticatePage(page, issueToken(...SETTINGS_PERMISSIONS));
    await gotoOrderTab(page);

    const untouchedBefore = await page.locator(FIELD_B).inputValue();

    // 한 칸만 편집 → 나머지 칸은 손대지 않는다
    const serverA = await page.locator(FIELD_A).inputValue();
    await typeInto(page, FIELD_A, other(serverA));

    await tabRoundTrip(page);

    await expect(page.locator(FIELD_B)).toHaveValue(untouchedBefore);
    await expect(page.locator(FIELD_A)).toHaveValue(serverA);
  });

  /**
   * @scenario edit_order=untouched
   *
   * @effects readonly_permission_disables_inputs
   */
  test('읽기 전용 권한에서는 입력칸이 비활성이라 저장소 오염 경로 자체가 없다', async ({ page }) => {
    // issueScopedToken 필수 — 기본 issueToken 은 admin 역할을 함께 부여해(전체 권한 보유)
    // 권한을 좁혀 넘겨도 화면이 최대 권한으로 렌더되므로 이 분기를 만들 수 없다.
    await authenticatePage(page, issueScopedToken('sirsoft-ecommerce.settings.read'));
    await gotoOrderTab(page);

    await expect(page.locator(FIELD_A)).toBeDisabled();
    await expect(page.locator(FIELD_B)).toBeDisabled();

    const before = await page.locator(FIELD_A).inputValue();
    const beforeB = await page.locator(FIELD_B).inputValue();

    // 권한 밖 탭(마일리지)은 403 이므로, 읽기 권한으로 접근 가능한 탭으로 왕복한다.
    await page.getByRole('tab', { name: '클레임' }).click();
    await expect(page).toHaveURL(/tab=claim/, { timeout: 15_000 });
    await page.getByRole('tab', { name: '주문설정' }).click();
    await expect(page).toHaveURL(/tab=order_settings/, { timeout: 15_000 });

    await expect(page.locator(FIELD_A)).toHaveValue(before);
    await expect(page.locator(FIELD_B)).toHaveValue(beforeB);
    await expect(page.locator(FIELD_A)).toBeDisabled();
  });

  /**
   * @scenario edit_order=last_edited
   *
   * @effects tab_roundtrip_emits_no_console_errors
   */
  test('탭 왕복 중 콘솔 에러가 발생하지 않는다', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', msg => {
      if (msg.type() === 'error') errors.push(msg.text());
    });

    await authenticatePage(page, issueToken(...SETTINGS_PERMISSIONS));
    await gotoOrderTab(page);

    const serverA = await page.locator(FIELD_A).inputValue();
    await typeInto(page, FIELD_A, other(serverA));
    await tabRoundTrip(page);

    expect(errors).toEqual([]);
  });
});
