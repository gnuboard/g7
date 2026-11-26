/**
 * Layout Editor — 스크롤 구조 + 확장 주입 노드 잠금 후속 회귀.
 *
 * **회귀 배경**:
 *  1) 확장 주입 컴포넌트(`__source.kind === 'extension'`)가 일반 컴포넌트처럼 속성 편집됐다 —
 *     잠금 표시는 됐으나 ⓘ 컨텍스트 메뉴(속성 설정/복사/삭제)가 항상 노출돼 속성 모달이 열렸다.
 *     또 확장 조각 내부 자식이 개별 선택돼 통짜(블랙박스) 단위 경계가 깨졌다.
 *  2) 편집 캔버스가 뷰포트 잔여 높이에 갇혀 레이아웃이 길면 캔버스 영역 안에서 또 스크롤되고
 *     그 아래 빈 공간이 남았다 — "별도 스크롤 없이 전체를 한 화면에 보며 편집" 요구.
 *  3) admin 류 풀스크린 레이아웃(`height:100vh` 루트 + 콘텐츠 자체 스크롤)이 편집기에서 842px 로
 *     갇혀 사이드바·푸터까지 한눈에 안 보였다.
 *
 * **검증 범위**: headless 안정 검증 가능한 DOM/스타일/선택 상태만. 리사이즈 후 선택 박스 재측정
 * (결함 3 중 100% 폭 리사이즈)은 dnd-kit 무관 포인터 핸들러라 단위 테스트(useElementSelection /
 * EditorCanvasOverlay)로 커버한다.
 *
 * 효과 요약(마커 아님 — 평문): extension_node_context_menu_blocked, extension_node_blackbox_selection_normalized, editor_canvas_single_window_scroll, fullscreen_layout_expanded_in_editor.
 */
import type { Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** sirsoft-basic 일반 사용자 페이지 편집기 진입 (min-h-screen flex — 풀스크린 아님). */
async function openBasicEditor(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  // 홈(`/`)은 미리보기 내용이 길어(1280×720 에서 문서 약 2,860px) 300px 스크롤 동안 트리가 담긴 본문 영역이 끝나지 않는다.
  // 종전 `/boards` 는 게시판 목록(개발 사이트의 실제 게시판 수)에 따라 문서가 약 980px 이라, sticky 트리가 본문 끝에 걸려
  // 함께 밀려 올라가 「트리 고정」 이 레이아웃 결함 없이도 깨졌다(2026-11-12 실측: 스크롤 257px 에서 트리 top 133 → -63).
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // state 기본값은 'visible' 인데 프레임 하위 첫 노드는 빈 앵커(header_currency_inject_anchor)나
  // `hidden` 클래스 요소일 수 있어 끝내 visible 이 되지 않는다 — 렌더 완료 판정은 존재로 한다.
  await page.waitForSelector('[data-testid="g7le-preview-frame"] [data-editor-path]', {
    state: 'attached',
    timeout: 30_000,
  });
}

/**
 * sirsoft-basic 마이페이지 «내 정보» 편집기 진입 — 화면 소유 노드(`mypage_tab_content`)에 이커머스 「기본 배송지」 카드가
 * `target_layout` 주입(append_child)으로 붙는다. 그 카드는 조건 없이 그려지는 주입 조각이다.
 *
 * 종전에는 관리자 회원 폼에 마케팅 플러그인이 주입하던 동의 구획(`ext_marketing_form_content`)을 썼는데, 회원 항목 설정으로
 * 마케팅 동의가 확장 조각이 아니라 코어 회원 항목 조각이 그리는 칸이 되면서 그 주입 조각이 없어졌다.
 */
async function openProfileEditor(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Fmypage%2Fprofile');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"] [data-editor-path]', {
    state: 'attached',
    timeout: 30_000,
  });
}

/** admin 풀스크린 레이아웃(h-screen 루트) 편집기 진입. */
async function openAdminUserForm(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-admin_basic?route=*%2Fadmin%2Fusers%2Fcreate');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // state 기본값은 'visible' 인데 프레임 하위 첫 노드는 빈 앵커(header_currency_inject_anchor)나
  // `hidden` 클래스 요소일 수 있어 끝내 visible 이 되지 않는다 — 렌더 완료 판정은 존재로 한다.
  await page.waitForSelector('[data-testid="g7le-preview-frame"] [data-editor-path]', {
    state: 'attached',
    timeout: 30_000,
  });
}

test.describe('@layout-editor 스크롤 + 확장 잠금 후속', () => {
  /**
   * @scenario case=extension_blackbox_selection
   * @effects extension_node_context_menu_blocked, extension_node_blackbox_selection_normalized
   */
  test('확장 주입 조각 내부 자식 클릭 → 진입점 통짜 선택 + ⓘ 메뉴 차단 + 확장 편집 어포던스', async ({ page }) => {
    await openProfileEditor(page);

    // 확장 진입점(sirsoft-ecommerce 주입 ext_my_default_address_card) — route 본문 안의 extension 조각.
    // 그 내부 깊은 자식을 클릭해도 통짜(진입점) 선택되어야 한다.
    const result = await page.evaluate(async () => {
      const frame = document.querySelector('[data-testid="g7le-preview-frame"]')!;
      // 확장 조각 진입점은 카드 제목 「기본 배송지」를 포함한다.
      // 제목은 다국어(`sirsoft-ecommerce.user_address_card.title`)라 로케일에 묶지 않는다.
      // 텍스트 기반으로 영역을 찾은 뒤 그 안 가장 깊은 자식(개별 텍스트 노드)을 클릭한다 —
      // 깊은 자식이 통짜(진입점) 단위로 정규화되어 선택되는지 검증.
      const candidates = Array.from(frame.querySelectorAll('[data-editor-path]')).filter(
        (e) =>
          /기본 배송지|Default shipping address/.test(e.textContent || '') &&
          (e.textContent || '').length < 30,
      ) as HTMLElement[];
      const labelNode = candidates[candidates.length - 1];
      if (!labelNode) return { found: false };
      labelNode.scrollIntoView({ block: 'center' });
      await new Promise((r) => setTimeout(r, 150));
      const r = labelNode.getBoundingClientRect();
      for (const type of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
        labelNode.dispatchEvent(
          new MouseEvent(type, { bubbles: true, cancelable: true, clientX: r.left + 5, clientY: r.top + 5, view: window }),
        );
      }
      await new Promise((res) => setTimeout(res, 400));
      const sel = document.querySelector('[data-testid="g7le-overlay-selected"]');
      return {
        found: true,
        lockKind: sel?.getAttribute('data-lock-kind') ?? null,
        infoButton: !!document.querySelector('[data-testid="g7le-overlay-info-button"]'),
        editExtension: !!document.querySelector('[data-testid="g7le-overlay-edit-extension"]'),
      };
    });

    expect(result.found).toBe(true);
    // 확장 조각은 잠금(extension) — ⓘ 속성 메뉴 미표시, 확장 편집 어포던스만.
    expect(result.lockKind).toBe('extension');
    expect(result.infoButton).toBe(false);
    expect(result.editExtension).toBe(true);
  });

  /**
   * @scenario case=single_window_scroll
   * @effects editor_canvas_single_window_scroll
   */
  test('일반(min-h-screen) 페이지 — 캔버스 별도 스크롤 없음 + 브라우저 단일 스크롤 + 헤더/트리 고정', async ({ page }) => {
    await openBasicEditor(page);

    const metrics = await page.evaluate(async () => {
      const canvas = document.querySelector('[data-testid="g7le-preview-canvas"]') as HTMLElement;
      const header = document.querySelector('[data-testid="g7le-chrome-header"]') as HTMLElement;
      const tree = document.querySelector('[data-testid="g7le-route-tree-panel"]') as HTMLElement;
      // 캔버스 자체 세로 스크롤 없음(overflow visible)
      const canvasOverflowY = getComputedStyle(canvas).overflowY;
      // 헤더 sticky top:0
      const headerSticky = getComputedStyle(header).position === 'sticky';
      // 스크롤 시 헤더/트리 화면 고정
      const h0 = header.getBoundingClientRect().top;
      const t0 = tree.getBoundingClientRect().top;
      window.scrollTo(0, 300);
      await new Promise((r) => setTimeout(r, 200));
      const h1 = header.getBoundingClientRect().top;
      const t1 = tree.getBoundingClientRect().top;
      window.scrollTo(0, 0);
      return {
        canvasOverflowY,
        headerSticky,
        headerStaysTop: Math.abs(h1) < 4,
        treeStaysFixed: Math.abs(t1 - t0) < 6,
      };
    });

    expect(metrics.canvasOverflowY).toBe('visible');
    expect(metrics.headerSticky).toBe(true);
    expect(metrics.headerStaysTop).toBe(true);
    expect(metrics.treeStaysFixed).toBe(true);
  });

  /**
   * @scenario case=fullscreen_expand
   * @effects fullscreen_layout_expanded_in_editor
   */
  test('admin 풀스크린(h-screen) 레이아웃 — 편집 캔버스에서 콘텐츠 자연 높이로 펼침(내부 스크롤 없음)', async ({ page }) => {
    await openAdminUserForm(page);

    const metrics = await page.evaluate(async () => {
      // vh 펼침 마킹 적용 대기
      for (let i = 0; i < 40; i++) {
        if (document.querySelector('[data-testid="g7le-preview-frame"] [data-g7le-vh-expanded]')) break;
        await new Promise((r) => setTimeout(r, 250));
      }
      const frame = document.querySelector('[data-testid="g7le-preview-frame"]') as HTMLElement;
      const vh = window.innerHeight;
      // 펼침 후 frame 높이가 뷰포트보다 충분히 큼(콘텐츠 전체)
      const frameH = Math.round(frame.getBoundingClientRect().height);
      // 캔버스 내부에 콘텐츠 길이만큼 갇힌 스크롤러가 남아있지 않은지
      let remainingInnerScroll = false;
      frame.querySelectorAll('*').forEach((e) => {
        const s = getComputedStyle(e);
        if (
          (s.overflowY === 'auto' || s.overflowY === 'scroll') &&
          (e as HTMLElement).scrollHeight > (e as HTMLElement).clientHeight + 5 &&
          (e as HTMLElement).clientHeight > 120
        ) {
          remainingInnerScroll = true;
        }
      });
      return {
        vhExpandedCount: frame.querySelectorAll('[data-g7le-vh-expanded]').length,
        frameTallerThanViewport: frameH > vh + 100,
        remainingInnerScroll,
      };
    });

    // h-screen 컨테이너가 펼쳐졌고(마킹 ≥1), frame 이 뷰포트보다 훨씬 커졌으며, 내부 스크롤러가 없다.
    expect(metrics.vhExpandedCount).toBeGreaterThanOrEqual(1);
    expect(metrics.frameTallerThanViewport).toBe(true);
    expect(metrics.remainingInnerScroll).toBe(false);
  });
});
