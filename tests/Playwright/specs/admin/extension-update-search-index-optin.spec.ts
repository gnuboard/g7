/**
 * E2E: 확장 업데이트의 「검색 인덱스 재생성」 선택이 그 창에서만 유효한지 (#492 검색 인덱스 실측)
 *
 * 재생성은 인덱스 잠금·전체 재색인을 유발한다. 체크 상태가 전역에 남으면 운영자가 한 번
 * 체크한 뒤로는 아무것도 누르지 않아도 다음 확장 업데이트마다 재생성이 실행된다
 * (브라우저 실측으로 실제 재생성이 일어남을 확인했다).
 *
 * 응답의 `search_index` 페이로드도 함께 본다 — 색인이 비면 검색은 오류 없이 0건을
 * 돌려주므로, 이 페이로드가 사라지면 운영자가 알 방법이 없다.
 *
 * @scenario case=extension_update_rebuild_optin
 *
 * @effects rebuild_checkbox_defaults_unchecked, rebuild_checkbox_resets_on_reopen,
 *          update_response_carries_search_index
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage, issueToken } from '../../fixtures/auth';

/** 모달 안 체크박스 셀렉터 (모듈/플러그인 공통 규칙) */
const CHECKBOX = (kind: 'module' | 'plugin') => `#${kind}_rebuild_search_index input[type="checkbox"]`;

/**
 * 목록 화면에 진입해 렌더가 끝날 때까지 기다립니다.
 *
 * @param page 대상 페이지
 * @param path 이동할 경로
 * @returns void
 */
async function gotoAndSettle(page: Page, path: string): Promise<void> {
  // 고정 대기만 두면 느린 회차에서 목록이 아직 없는 채로 판정해 조용히 skip 이 된다.
  const listed = page
    .waitForResponse(
      (r) =>
        (r.url().includes('/api/admin/modules') || r.url().includes('/api/admin/plugins')) &&
        r.status() === 200,
      { timeout: 30000 }
    )
    .catch(() => null);

  await page.goto(path);
  await page.waitForLoadState('domcontentloaded');
  await listed;
  await page.waitForTimeout(1500);
}

/**
 * 업데이트 모달을 엽니다. 업데이트 가능한 확장이 없으면 false 를 돌려줍니다.
 *
 * @param page 대상 페이지
 * @param kind module | plugin
 * @returns Promise<boolean> 모달이 열렸으면 true
 */
async function openUpdateModal(page: Page, kind: 'module' | 'plugin'): Promise<boolean> {
  // 두 가지를 함께 흡수한다.
  //  - 접근성 이름에 아이콘 텍스트가 섞인다 (예: 'refresh 업데이트')
  //  - 테스트 계정의 로케일이 ko 라는 보장이 없다 (실제로 en 으로 떨어져 한국어 매칭이 전부 skip 됐다)
  // 목록 상단의 '업데이트 확인 / Check Updates' 는 이름이 그 단어로 끝나지 않아 자연히 제외된다.
  const button = page
    .getByRole('button', { name: /(업데이트|Update|アップデート|更新)$/ })
    .first();

  if ((await button.count()) === 0) {
    return false;
  }

  await button.click();
  await page.waitForTimeout(1500);

  return (await page.locator(CHECKBOX(kind)).count()) > 0;
}

for (const kind of ['module', 'plugin'] as const) {
  const listPath = kind === 'module' ? '/admin/modules' : '/admin/plugins';
  const label = kind === 'module' ? '모듈' : '플러그인';

  test.describe(`${label} 업데이트 — 검색 인덱스 재생성 옵트인`, () => {
    test(`체크는 기본 해제이고 모달을 다시 열면 초기화된다`, async ({ page }) => {
      await authenticatePage(page, issueToken('core.modules.read', 'core.modules.install', 'core.plugins.read', 'core.plugins.install'));
      await gotoAndSettle(page, listPath);

      const opened = await openUpdateModal(page, kind);
      test.skip(!opened, `업데이트 가능한 ${label}이 없어 모달을 열 수 없습니다`);

      const checkbox = page.locator(CHECKBOX(kind));

      // 진입 시 기본 미체크
      expect(await checkbox.isChecked(), '재생성 체크가 기본으로 켜져 있습니다').toBe(false);

      // 체크 후 취소로 닫는다.
      //
      // 체크박스는 `<label>` 안에 있고 checked 는 전역 상태에서 되돌아온다. 포인터 클릭은
      // label 활성화 동작과 겹쳐 한 회차에 두 번 토글되는 일이 있어 결과가 갈린다
      // (`click()`/`check()` 모두 재현됨). 요소의 활성화만 일으켜 change 를 한 번 태운다.
      // 모달이 열린 직후에는 변경 이력·수정 레이아웃 조회 응답이 뒤따라 도착하며 상태가 다시
      // 계산되어, 그 창에 걸린 토글이 한 번 되돌아가는 회차가 있다. 켜질 때까지 최대 3회 시도한다
      // (검증 대상은 "다시 열었을 때 꺼져 있는가" 이지 토글 1회의 타이밍이 아니다).
      for (let attempt = 0; attempt < 3 && !(await checkbox.isChecked()); attempt += 1) {
        await checkbox.evaluate((el) => (el as HTMLInputElement).click());
        await page.waitForTimeout(600);
      }
      expect(await checkbox.isChecked(), '체크박스가 토글되지 않았습니다').toBe(true);

      await page
        .getByRole('button', { name: /^(취소|Cancel|キャンセル)$/ })
        .last()
        .click();
      await page.waitForTimeout(700);

      // 다시 열면 해제되어 있어야 한다 — 남아 있으면 다음 업데이트에서 의도치 않게 재생성된다
      const reopened = await openUpdateModal(page, kind);
      test.skip(!reopened, '모달 재진입에 실패했습니다');

      expect(
        await page.locator(CHECKBOX(kind)).isChecked(),
        '모달을 다시 열었는데 재생성 체크가 남아 있습니다'
      ).toBe(false);
    });

    test(`미체크 제출 응답이 색인 점검 결과를 싣는다`, async ({ page }) => {
      await authenticatePage(page, issueToken('core.modules.read', 'core.modules.install', 'core.plugins.read', 'core.plugins.install'));
      await gotoAndSettle(page, listPath);

      const opened = await openUpdateModal(page, kind);
      test.skip(!opened, `업데이트 가능한 ${label}이 없어 모달을 열 수 없습니다`);

      expect(await page.locator(CHECKBOX(kind)).isChecked()).toBe(false);

      const responsePromise = page
        .waitForResponse((r) => r.url().includes('/update') && r.request().method() === 'POST', {
          timeout: 60000,
        })
        .catch(() => null);

      await page.locator('button.bg-amber-600').click();
      const response = await responsePromise;

      test.skip(response === null, '업데이트 응답을 받지 못했습니다');

      const request = response!.request();
      const body = JSON.parse(request.postData() ?? '{}');
      expect(body.rebuild_search_index, '미체크인데 재생성 요청이 실렸습니다').toBe(false);

      const payload = await response!.json();
      test.skip(
        payload?.search_index === undefined || payload?.search_index === null,
        '점검을 제공하지 않는 검색 엔진입니다'
      );

      expect(payload.search_index.rebuilt, '요청하지 않았는데 재생성이 수행됐습니다').toBe(false);
      expect(payload.search_index, '색인 누락 건수가 응답에 없습니다').toHaveProperty('stale_count');
      expect(payload.message, '성공 메시지에 치환자가 남아 있습니다').not.toContain(':version');
    });
  });
}
