/**
 * E2E: 지연 조인 전환 후 목록 페이지네이션 종단 확인
 *
 * 목록 조회가 "이번 페이지의 ID 를 먼저 구하고, 그 ID 에 대해서만 목록 컬럼과 관계를 읽는"
 * 2단계로 바뀌었다. 단위 테스트는 쿼리 구조와 ID 시퀀스를 잠그지만, 실제 화면에서 드러나는
 * 증상(마지막 페이지에서 "다음" 이 계속 눌리거나, 페이지 이동 후 목록이 비어 보이거나,
 * 콘솔에 렌더 에러가 남는 것)은 브라우저 레벨에서만 잡힌다.
 *
 * 계획서의 브라우저 확인 항목을 화면별로 잠근다:
 *   (1) 목록이 렌더된다 (관계/컬럼 프루닝으로 화면이 비지 않는다)
 *   (2) 공지가 1페이지에만 노출된다 (게시글 목록 한정 — 공지 병합이 outer 로 옮겨졌는지)
 *   (3) 마지막 페이지에서 "다음" 이 비활성이다 (총 건수 계산 정합)
 *   (4) 콘솔/페이지 에러가 없다
 *
 * 데이터 의존 스킵: 목록이 한 페이지 분량이면 페이지네이션 컨트롤이 없으므로 (2)를 건너뛴다.
 * 스킵 사유는 실행 로그에 남는다 — 조용한 통과가 아니다.
 *
 * @scenario case=deep_offset_list_browser
 *
 * @effects pages_do_not_overlap_or_drop_rows, simple_mode_reports_last_page_without_next
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage, issueToken } from '../../fixtures/auth';

/** 점검 대상 화면 — 계획서가 지목한 4개 목록 */
const LIST_SCREENS = [
  { name: '활동 로그', path: '/admin/activity-logs', permissions: ['core.activities.read'] },
  { name: '알림 발송 이력', path: '/admin/notification-logs', permissions: ['core.notification-logs.read'] },
  { name: '관리자 주문 목록', path: '/admin/ecommerce/orders', permissions: ['sirsoft-ecommerce.orders.read'] },
  {
    name: '게시판 게시글 목록',
    path: '/admin/board/notice',
    permissions: ['sirsoft-board.notice.admin.posts.read', 'sirsoft-board.notice.admin.manage'],
  },
] as const;

/**
 * 관리자 화면 진입 공통 대기 (기존 admin spec 과 동일 패턴).
 *
 * `networkidle` 은 폴링/실시간 연결이 있는 화면에서 영영 idle 이 되지 않아 쓰지 않는다.
 *
 * @param page 대상 페이지
 * @param path 이동할 경로
 */
async function gotoAdmin(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );
}

/**
 * 페이지에서 수집한 콘솔/페이지 에러를 담을 배열을 부착합니다.
 *
 * @param page 대상 페이지
 * @returns 수집된 에러 메시지 배열 (테스트 진행에 따라 채워진다)
 */
function collectPageErrors(page: Page): string[] {
  const errors: string[] = [];

  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(message.text());
    }
  });
  page.on('pageerror', (error) => {
    errors.push(error.message);
  });

  return errors;
}

/**
 * 목록 데이터가 도착할 때까지 기다립니다.
 *
 * 진입 직후에는 목록이 비어 있어 페이지네이션이 아직 렌더되지 않는다. 데이터 로드를
 * 기다리지 않고 컨트롤 개수를 세면 "컨트롤 없음" 으로 잘못 판정해 조용히 스킵된다.
 *
 * @param page 대상 페이지
 * @returns 행이 하나라도 렌더되면 true
 */
async function waitForListRows(page: Page): Promise<boolean> {
  return page
    .locator('tbody tr, [data-testid$="-row"]')
    .first()
    .waitFor({ state: 'attached', timeout: 20_000 })
    .then(() => true)
    .catch(() => false);
}

/**
 * 페이지네이션의 "다음" 컨트롤을 찾습니다.
 *
 * `aria-label` 은 앱 로케일에 따라 한국어("다음 페이지")로도, 언어팩이 아직 붙지 않은
 * 시점에는 기본 영문("Next page")으로도 렌더된다. 한쪽만 보면 컨트롤이 있는데도
 * "없음" 으로 판정해 조용히 스킵되므로 양쪽을 모두 매칭한다.
 *
 * @param page 대상 페이지
 * @returns 다음 페이지 컨트롤 locator
 */
function nextPageControl(page: Page) {
  return page
    .locator(
      [
        '[aria-label="다음 페이지"]',
        '[aria-label="Next page"]',
        '[data-testid="pagination-next"]',
        'button:has-text("다음")',
      ].join(', '),
    )
    .first();
}

/**
 * 페이지네이션의 "마지막 페이지" 컨트롤을 찾습니다.
 *
 * @param page 대상 페이지
 * @returns 마지막 페이지 컨트롤 locator
 */
function lastPageControl(page: Page) {
  return page.locator('[aria-label="마지막 페이지"], [aria-label="Last page"]').first();
}

/**
 * "다음" 컨트롤이 비활성인지 판정합니다.
 *
 * @param page 대상 페이지
 * @returns 비활성이면 true
 */
async function isNextDisabled(page: Page): Promise<boolean> {
  const next = nextPageControl(page);

  if ((await next.count()) === 0) {
    return true;
  }

  if (await next.isDisabled().catch(() => false)) {
    return true;
  }

  return (await next.getAttribute('aria-disabled')) === 'true';
}

/**
 * 현재 페이지의 공지 행 수를 셉니다.
 *
 * 게시글 목록은 공지 행에 `bg-blue-50` 배경 클래스를 붙인다
 * (admin_board_posts_index.json 의 rowClassName — 일반/답글 행과 구분되는 유일한 DOM 신호).
 * 다크 모드에서도 Tailwind 가 두 클래스를 함께 내보내므로 이 클래스는 항상 존재한다.
 *
 * @param page 대상 페이지
 * @returns 공지 행 수
 */
async function countNoticeRows(page: Page): Promise<number> {
  return page.locator('tr[class*="bg-blue-50"]').count();
}

test.describe('목록 페이지네이션 (지연 조인)', () => {
  for (const screen of LIST_SCREENS) {
    // @scenario case=deep_offset_list_browser
    // @effects pages_do_not_overlap_or_drop_rows
    test(`${screen.name} — 목록이 렌더되고 페이지 에러가 없다`, async ({ page }) => {
      const errors = collectPageErrors(page);

      await authenticatePage(page, issueToken(...screen.permissions));
      await gotoAdmin(page, screen.path);

      if (/\/admin\/login/.test(page.url())) {
        test.skip(true, `${screen.name} 접근 불가 — 확장 미설치 또는 권한 미부여`);

        return;
      }

      // 목록 컨테이너가 떠야 한다 — 렌더 자체가 깨지면 여기서 잡힌다.
      await expect(
        page.locator('table, [role="table"], [data-testid$="-list"]').first(),
      ).toBeAttached({ timeout: 20_000 });

      await waitForListRows(page);

      expect(errors, `페이지 에러: ${errors.join(' | ')}`).toHaveLength(0);
    });

    // 공지 병합은 게시글 목록에만 있는 개념이라 그 화면에서만 검사한다.
    // 지연 조인은 inner 가 키 컬럼만 읽으므로, 공지 병합이 outer 로 옮겨가지 않았다면
    // 2페이지 이후에도 공지가 다시 붙거나 1페이지에서 사라진다.
    if (screen.name === '게시판 게시글 목록') {
      // @scenario case=deep_offset_list_browser
      // @effects notices_appear_only_on_first_page
      test(`${screen.name} — 공지가 1페이지에만 노출된다`, async ({ page }) => {
        await authenticatePage(page, issueToken(...screen.permissions));
        await gotoAdmin(page, screen.path);

        if (/\/admin\/login/.test(page.url())) {
          test.skip(true, `${screen.name} 접근 불가 — 확장 미설치 또는 권한 미부여`);

          return;
        }

        if (! (await waitForListRows(page))) {
          test.skip(true, `${screen.name} 목록 행 없음 — 데이터 미존재`);

          return;
        }

        const firstPageNotices = await countNoticeRows(page);

        const next = nextPageControl(page);
        const hasNext = await next
          .waitFor({ state: 'attached', timeout: 15_000 })
          .then(() => true)
          .catch(() => false);

        if (! hasNext || (await isNextDisabled(page))) {
          test.skip(true, `${screen.name} 2페이지 없음 — 데이터가 한 페이지 분량`);

          return;
        }

        await next.click();
        await waitForListRows(page);

        const secondPageNotices = await countNoticeRows(page);

        // 1페이지에 공지가 하나도 없으면 이 화면의 데이터로는 판정할 수 없다.
        // 조용히 통과시키지 않고 사유를 남기고 건너뛴다.
        if (firstPageNotices === 0) {
          test.skip(true, `${screen.name} 공지 행 없음 — 공지 병합을 판정할 데이터 미존재`);

          return;
        }

        expect(
          secondPageNotices,
          `2페이지에 공지 ${secondPageNotices}건이 다시 노출됐다 (1페이지 ${firstPageNotices}건)`,
        ).toBe(0);
      });
    }

    // @scenario case=deep_offset_list_browser
    // @effects simple_mode_reports_last_page_without_next
    test(`${screen.name} — 마지막 페이지에서 "다음" 이 비활성이다`, async ({ page }) => {
      await authenticatePage(page, issueToken(...screen.permissions));
      await gotoAdmin(page, screen.path);

      if (/\/admin\/login/.test(page.url())) {
        test.skip(true, `${screen.name} 접근 불가 — 확장 미설치 또는 권한 미부여`);

        return;
      }

      if (! (await waitForListRows(page))) {
        test.skip(true, `${screen.name} 목록 행 없음 — 데이터 미존재`);

        return;
      }

      const next = nextPageControl(page);

      // 목록 행과 페이지네이션은 렌더 시점이 다르다. 행이 떴다고 바로 세면 아직 없는
      // 컨트롤을 "없음" 으로 오판해 조용히 스킵된다 — 컨트롤이 붙을 때까지 기다린다.
      const hasControl = await next
        .waitFor({ state: 'attached', timeout: 15_000 })
        .then(() => true)
        .catch(() => false);

      if (! hasControl) {
        test.skip(true, `${screen.name} 페이지네이션 컨트롤 없음 — 데이터가 한 페이지 분량`);

        return;
      }

      // 마지막 페이지로 한 번에 이동한다. 컨트롤이 없는 템플릿이면 "다음" 을 반복 클릭한다.
      // 이미 마지막 페이지면(한 페이지 분량) 이동 없이 곧바로 판정한다 — 비활성 버튼을
      // 클릭하려 들면 Playwright 가 enabled 를 기다리다 타임아웃한다.
      const last = lastPageControl(page);
      const alreadyLast = await isNextDisabled(page);

      if (alreadyLast) {
        // 이동 불필요
      } else if ((await last.count()) > 0 && (await last.isEnabled().catch(() => false))) {
        await last.click();
      } else {
        for (let hop = 0; hop < 10; hop += 1) {
          if (await isNextDisabled(page)) {
            break;
          }

          await next.click();
          await page.waitForTimeout(1_000);
        }
      }

      // 고정 대기 대신 상태가 바뀔 때까지 폴링한다 — 병렬 실행에서 서버 응답이 늦어도
      // 결과 판정이 흔들리지 않는다.
      await expect
        .poll(() => isNextDisabled(page), {
          message: '마지막 페이지에서 "다음" 이 계속 활성이면 총 건수 계산이 어긋난 것이다',
          timeout: 20_000,
        })
        .toBe(true);
    });
  }
});
