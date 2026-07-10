/**
 * 게시글 상단 네비게이션 — 긴 게시판명이 좌우 버튼을 압착하지 않는지 검증.
 *
 * 배경 (390px 실측, `flex items-center justify-between` 한 줄에 목록/게시판명/이전글·다음글):
 * - 게시판명 Span 이 `flex-shrink` 를 받아 남은 폭을 먹으면서 형제 버튼을 눌렀다.
 *   '이전글' 버튼 폭: 5자 '자유게시판' 87px → 11자 'API 문서 샘플 게시판' 73px
 *   → 21자 62px. 62px 에서는 chevron 아이콘과 텍스트가 겹친다.
 * - `document.scrollWidth` 는 내내 뷰포트와 같아 오버플로 검사로는 잡히지 않는다.
 *   버튼이 넘치는 게 아니라 *줄어드는* 회귀이므로 폭을 직접 재야 한다.
 *
 * 조치:
 * - 목록 버튼과 이전/다음 그룹에 `shrink-0` → 게시판명 길이와 무관하게 고정폭
 * - 모바일(0~767px)에서 컨테이너 `flex-wrap` + 게시판명 `order-first w-full`
 *   → 게시판명이 첫 줄 전체를 차지하고 버튼은 아래 줄에서 폭 경쟁을 하지 않는다
 * - 태블릿 이상(768px+)은 한 줄 유지. 버튼 3개 고정폭 261px + 21자 제목 자연폭 323px
 *   = 584px < 콘텐츠 735px 이므로 줄바꿈이 필요 없다.
 *
 * 선택자 규율:
 * - 버튼 라벨(`목록`/`이전글`)은 브라우저 로케일에 따라 영문으로 렌더된다(`Back to List`).
 *   로케일 독립적인 Font Awesome 아이콘 클래스(`fa-list`/`fa-chevron-left`)로 찾는다.
 * - 게시판명 자체는 시드 데이터에 따라 짧을 수 있으므로, 실제 게시판을 고르는 대신
 *   제목 노드에 최악 케이스 문자열을 주입해 재측정한다. 회귀는 레이아웃에 있지
 *   특정 게시판에 있지 않다.
 */
import { test, expect, type Page, type Locator } from '@playwright/test';

/** 기준선 게시판명 (5자, 자연폭 83px — 이 폭에서는 회귀 상태에서도 버튼이 안 눌린다) */
const SHORT_BOARD_NAME = '자유게시판';

/** 최악 케이스 게시판명 (21자, 자연폭 323px) */
const LONG_BOARD_NAME = '아주 긴 이름을 가진 커뮤니티 게시판입니다';

/** 상단 네비게이션 컨테이너 — 목록(fa-list) + 게시판명 + 이전/다음 그룹 */
function navLocator(page: Page): Locator {
  return page.locator('div.mb-6').filter({ has: page.locator('button i.fa-list') }).first();
}

/**
 * 이전글/다음글이 모두 있는 게시글을 찾습니다. 시드 데이터에 의존하지 않도록 공개 API 로 탐색합니다.
 *
 * @param page Playwright 페이지
 * @return { slug, postId } 또는 조건을 만족하는 글이 없으면 null
 */
async function findPostWithBothNeighbors(
  page: Page
): Promise<{ slug: string; postId: number } | null> {
  return page.evaluate(async () => {
    const boardsRes = await fetch('/api/modules/sirsoft-board/boards');
    const boards = (await boardsRes.json())?.data ?? [];

    for (const board of boards) {
      if ((board.posts_count ?? 0) < 3) continue;

      const listRes = await fetch(
        `/api/modules/sirsoft-board/boards/${board.slug}/posts?per_page=5`
      );
      const posts = (await listRes.json())?.data?.data ?? [];

      for (const post of posts) {
        const navRes = await fetch(
          `/api/modules/sirsoft-board/boards/${board.slug}/posts/${post.id}/navigation`
        );
        const nav = (await navRes.json())?.data;
        if (nav?.prev?.id && nav?.next?.id) {
          return { slug: board.slug as string, postId: post.id as number };
        }
      }
    }
    return null;
  });
}

/**
 * 짧은 게시판명 → 긴 게시판명으로 바꾸며 기하를 측정합니다.
 *
 * 핵심 단언은 "긴 이름일 때의 버튼 폭 == 짧은 이름일 때의 버튼 폭" 이다. 절대 폭 하한만
 * 보면 시드 데이터의 게시판명 길이에 따라 단언이 무력해진다(짧은 이름 게시판에서는
 * 회귀 상태여도 버튼이 하한을 넘는다). 같은 페이지에서 두 상태를 재 차이를 본다.
 *
 * @param nav 네비게이션 컨테이너 로케이터
 * @param shortName 기준선으로 쓸 짧은 게시판명
 * @param longName 최악 케이스 게시판명
 * @return 짧은/긴 이름 각각의 flex 배치와 버튼 폭
 */
async function measureShortVsLongName(nav: Locator, shortName: string, longName: string) {
  return nav.evaluate(
    (el: HTMLElement, { short, long }: { short: string; long: string }) => {
      const title = el.children[1] as HTMLElement;

      const snapshot = () => ({
        flexWrap: getComputedStyle(el).flexWrap,
        titleOrder: Number(getComputedStyle(title).order),
        titleTop: Math.round(title.getBoundingClientRect().top),
        titleWidth: Math.round(title.getBoundingClientRect().width),
        firstButtonTop: Math.round((el.children[0] as HTMLElement).getBoundingClientRect().top),
        containerWidth: Math.round(el.getBoundingClientRect().width),
        viewportWidth: document.documentElement.clientWidth,
        docOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        buttons: [...el.querySelectorAll('button')].map((b) => ({
          icon: b.querySelector('i')?.className ?? '',
          width: Math.round(b.getBoundingClientRect().width),
          left: Math.round(b.getBoundingClientRect().left),
          right: Math.round(b.getBoundingClientRect().right),
        })),
      });

      title.textContent = short;
      const withShortName = snapshot();
      title.textContent = long;
      const withLongName = snapshot();
      return { withShortName, withLongName };
    },
    { short: shortName, long: longName }
  );
}

/**
 * 이전글·다음글이 모두 있는 게시글로 이동하고 네비 컨테이너를 반환합니다.
 *
 * navigation API 가 이웃을 돌려줘도 화면에는 렌더되지 않을 수 있으므로(비밀글·권한 필터)
 * 실제 버튼 3개가 보이는지까지 확인한다.
 *
 * @param page Playwright 페이지
 * @return 네비게이션 컨테이너 로케이터 (조건을 만족하는 글이 없으면 테스트 skip)
 */
async function gotoPostWithNeighbors(page: Page): Promise<Locator> {
  await page.goto('/');
  const target = await findPostWithBothNeighbors(page);
  test.skip(target === null, '이전글·다음글이 모두 있는 게시글이 없다');

  await page.goto(`/board/${target!.slug}/${target!.postId}`);
  const nav = navLocator(page);
  await expect(nav).toBeVisible({ timeout: 15_000 });

  // 라벨은 브라우저 로케일에 따라 영문이 되므로 아이콘으로 확인한다
  await expect(nav.locator('button i.fa-chevron-left')).toBeVisible();
  await expect(nav.locator('button i.fa-chevron-right')).toBeVisible();
  await expect(nav.locator('button')).toHaveCount(3);
  return nav;
}

/**
 * 게시판명 길이가 버튼 폭에 영향을 주지 않는지 단언합니다 (이 회귀의 본질).
 *
 * @param withShortName 짧은 이름일 때의 측정치
 * @param withLongName 긴 이름일 때의 측정치
 */
function expectButtonsUnaffectedByBoardName(
  withShortName: { buttons: Array<{ width: number }> },
  withLongName: { buttons: Array<{ width: number }> }
): void {
  expect(withShortName.buttons).toHaveLength(3);
  expect(withLongName.buttons).toHaveLength(3);
  // 회귀 상태에서는 87 → 62px 로 줄었다. shrink-0 이면 두 값이 완전히 같다.
  expect(withLongName.buttons.map((b) => b.width)).toEqual(
    withShortName.buttons.map((b) => b.width)
  );
}

test.describe('게시글 상단 네비 — 긴 게시판명', () => {
  test('@smoke 모바일(390px)에서 이전글/다음글이 압착되지 않고 게시판명은 자기 줄을 갖는다', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const nav = await gotoPostWithNeighbors(page);

    const { withShortName, withLongName } = await measureShortVsLongName(
      nav,
      SHORT_BOARD_NAME,
      LONG_BOARD_NAME
    );

    expectButtonsUnaffectedByBoardName(withShortName, withLongName);

    // 긴 이름에서도 세 버튼이 뷰포트 안에 온전히 들어온다
    for (const btn of withLongName.buttons) {
      expect(btn.left).toBeGreaterThanOrEqual(-1);
      expect(btn.right).toBeLessThanOrEqual(withLongName.viewportWidth + 1);
    }

    // 게시판명이 첫 줄 전체(order-first w-full)를 차지하고 버튼 행보다 위에 있다
    expect(withLongName.flexWrap).toBe('wrap');
    expect(withLongName.titleOrder).toBeLessThan(0);
    expect(withLongName.titleWidth).toBe(withLongName.containerWidth);
    expect(withLongName.titleTop).toBeLessThan(withLongName.firstButtonTop);

    expect(withLongName.docOverflow).toBe(0);
  });

  test('320px 에서도 버튼 폭이 게시판명 길이와 무관하다', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 844 });
    const nav = await gotoPostWithNeighbors(page);

    const { withShortName, withLongName } = await measureShortVsLongName(
      nav,
      SHORT_BOARD_NAME,
      LONG_BOARD_NAME
    );

    expectButtonsUnaffectedByBoardName(withShortName, withLongName);
    for (const btn of withLongName.buttons) {
      expect(btn.left).toBeGreaterThanOrEqual(-1);
      expect(btn.right).toBeLessThanOrEqual(withLongName.viewportWidth + 1);
    }
    expect(withLongName.flexWrap).toBe('wrap');
    expect(withLongName.docOverflow).toBe(0);
  });

  test('태블릿(768px)은 한 줄을 유지하면서도 버튼이 압착되지 않는다', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 900 });
    const nav = await gotoPostWithNeighbors(page);

    const { withShortName, withLongName } = await measureShortVsLongName(
      nav,
      SHORT_BOARD_NAME,
      LONG_BOARD_NAME
    );

    // 버튼 3개 고정폭 261px + 21자 제목 자연폭 323px = 584px < 콘텐츠 폭 → 줄바꿈 불필요
    expect(withLongName.flexWrap).toBe('nowrap');
    expect(withLongName.titleOrder).toBe(0);
    expect(withLongName.titleWidth).toBeLessThan(withLongName.containerWidth);
    expectButtonsUnaffectedByBoardName(withShortName, withLongName);
    expect(withLongName.docOverflow).toBe(0);
  });

  test('데스크톱(1280px)은 기존 한 줄 레이아웃을 유지한다 (모바일 오버라이드 누출 없음)', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    const nav = await gotoPostWithNeighbors(page);

    const { withShortName, withLongName } = await measureShortVsLongName(
      nav,
      SHORT_BOARD_NAME,
      LONG_BOARD_NAME
    );

    expect(withLongName.flexWrap).toBe('nowrap');
    expect(withLongName.titleOrder).toBe(0);
    expect(withLongName.titleWidth).toBeLessThan(withLongName.containerWidth);
    expectButtonsUnaffectedByBoardName(withShortName, withLongName);
    expect(withLongName.docOverflow).toBe(0);
  });
});
