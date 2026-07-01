/**
 * Layout Editor — 모달 편집 트리 노출 + 딤/DnD confine
 *
 * sirsoft-basic 은 모든 모달을 partial 파일로 분리한다. 종전엔 `[모달]`·"이 화면의 모달" 그룹이
 * 비어 모달을 편집할 경로가 없었고, 모달 편집 진입 시 딤(잠긴 호스트) 영역으로 드래그/드롭이
 * 가능했으며, `_user_base` 모달 저장이 extension_point 검증으로 422 였다.
 *
 * @scenario edit_mode + url_sync + dnd_confine
 * @effects modal_collection_includes_partial_modals + modal_edit_renders_dim_and_blocker + dnd_handles_confined_to_modal_subtree + modal_edit_exit_clears_dim
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const TEMPLATE = 'sirsoft-basic';
const EDITOR_URL = `/admin/layout-editor/${TEMPLATE}`;
// _user_base 호스트의 partial 모달 — 수정 후 트리/편집 노출 대상.
const MODAL_DIRECT_URL = `${EDITOR_URL}?edit=__modal__/notification_delete_all_confirm_modal&host=_user_base`;

async function enterEditor(page: import('@playwright/test').Page, url: string): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(url);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
}

test.describe('@layout-editor 모달 편집 confine', () => {
  test('편집기 routes 응답이 partial 모달을 수집(트리 노출 SSoT)', async ({ page }) => {
    // 백엔드 모달 수집(collectEditorBaseAndModals)이 partial 참조 + 하위 디렉토리까지 읽어
    // 모달을 반환하는지 검증 — 트리 [모달]/"이 화면의 모달" 그룹의 데이터 출처. locale 무관.
    await enterEditor(page, EDITOR_URL);
    const result = await page.evaluate(async (tpl) => {
      // 가드 엔드포인트(editor/routes.json)는 Bearer 토큰 필요 — 편집기 buildAuthHeaders 와 동일.
      const authToken = localStorage.getItem('auth_token');
      const res = await fetch(`/api/admin/templates/${tpl}/editor/routes.json`, {
        headers: {
          Accept: 'application/json',
          ...(authToken ? { Authorization: `Bearer ${authToken}` } : {}),
        },
        credentials: 'same-origin',
      });
      const body = await res.json();
      const modals = body?.data?.modals ?? [];
      return {
        ok: res.ok,
        modalCount: modals.length,
        hosts: [...new Set(modals.map((m: { host_layout: string }) => m.host_layout))],
      };
    }, TEMPLATE);
    expect(result.ok).toBe(true);
    // 종전엔 0(partial 전부 누락). 이제 1건 이상 + _user_base(base 호스트) 포함.
    expect(result.modalCount).toBeGreaterThan(0);
    expect(result.hosts).toContain('_user_base');
  });

  test('모달 편집 진입 시 딤 음영 + 차단 밴드 + 핸들이 모달 서브트리로 confine', async ({ page }) => {
    await enterEditor(page, MODAL_DIRECT_URL);
    // 모달 편집 모드 진입 + 딤 음영 마운트 대기.
    await expect(page.locator('[data-mode="modal"]')).toBeAttached({ timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-source-lock-dim"]', { timeout: 30_000 });

    // 딤 상호작용 차단 밴드 존재(딤 영역 클릭/드래그 삼킴).
    await expect.poll(() => page.locator('[data-testid="g7le-source-lock-block"]').count()).toBeGreaterThan(0);

    // 드래그 핸들이 모달 서브트리(편집 대상) 안에만 존재 — 딤 호스트엔 0.
    const handleClassification = await page.evaluate(() => {
      const titleEl = Array.from(document.querySelectorAll('[data-editor-path]')).find((e) =>
        /삭제하시겠습니까|delete/i.test(e.textContent || '')
      ) as HTMLElement | undefined;
      const modalTop = titleEl?.dataset.editorPath?.split('.children.')[0] ?? null;
      const handles = Array.from(document.querySelectorAll('[data-testid^="g7le-dnd-handle"]')).map(
        (h) => (h.getAttribute('data-testid') || '').replace('g7le-dnd-handle-', '')
      );
      const outModal = handles.filter(
        (p) => !(modalTop && (p === modalTop || p.startsWith(`${modalTop}.`)))
      );
      return { total: handles.length, outModal: outModal.length, modalTop };
    });
    expect(handleClassification.total).toBeGreaterThan(0);
    expect(handleClassification.outModal).toBe(0); // 딤 호스트엔 핸들 없음
  });

  test('모달 편집 종료 → 딤 해제 + 편집 모드 이탈', async ({ page }) => {
    await enterEditor(page, MODAL_DIRECT_URL);
    await expect(page.locator('[data-mode="modal"]')).toBeAttached({ timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-source-lock-dim"]', { timeout: 30_000 });

    // 뒤로가기로 편집 모드 종료(종료 버튼 라벨은 locale 종속이라 popstate 사용).
    await page.goBack();
    await expect(page.locator('[data-testid="g7le-source-lock-dim"]')).toHaveCount(0);
    await expect.poll(() => page.url()).not.toMatch(/edit=__modal__/);
  });
});
