/**
 * Layout Editor — 번들 분리 (lazy 로드) 검증. (engine-v1.51.0)
 *
 * 코어 번들(template-engine.min.js)에서 편집기 코드를 분리해 별도 layout-editor.min.js 로
 * 빌드하고, `/admin/layout-editor/*` 진입 시에만 런타임 <script> 주입으로 로드한다.
 *
 * 검증:
 *  - 일반 admin 페이지(대시보드) 접속 → layout-editor.min.js 요청이 발생하지 않는다
 *    (초기 접속 payload 축소의 핵심 — 회귀 시 편집기 코드가 다시 모든 페이지에 실린다)
 *  - 편집기 URL 진입 → layout-editor.min.js 요청 발생 + 편집기 마운트(g7le-toolbar +
 *    g7le-preview-frame). React 사본이 둘이면 "Invalid hook call" 로 마운트 자체가 크래시하므로,
 *    마운트 성공이 곧 단일 React 인스턴스 + 컨텍스트/싱글톤 동일성(shim __runtime 공유)을 증명한다.
 *
 * @scenario bundle_split_lazy_editor
 * @effects editor_bundle_absent_on_normal_page + editor_bundle_loaded_and_mounted_on_editor_route
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

type PwPage = import('@playwright/test').Page;

const EDITOR_BUNDLE_RE = /layout-editor\.min\.js/;

/**
 * page 의 네트워크 요청 중 편집기 번들 요청 URL 을 수집하는 리스너를 설치한다.
 *
 * @param page Playwright page
 * @returns 수집된 편집기 번들 요청 URL 배열(참조 — 이후 계속 채워짐)
 */
function collectEditorBundleRequests(page: PwPage): string[] {
  const hits: string[] = [];
  page.on('request', (req) => {
    if (EDITOR_BUNDLE_RE.test(req.url())) {
      hits.push(req.url());
    }
  });
  return hits;
}

test.describe('Layout Editor — 번들 분리 (lazy 로드)', () => {
  test('일반 admin 페이지는 layout-editor.min.js 를 요청하지 않는다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    const editorRequests = collectEditorBundleRequests(page);

    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 초기 접속 payload 에 편집기 번들 미포함 — 회귀 방지 핵심
    expect(editorRequests, '일반 페이지에서 편집기 번들이 로드됨(회귀)').toEqual([]);
  });

  test('편집기 URL 진입 시 layout-editor.min.js 를 로드하고 마운트한다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    const editorRequests = collectEditorBundleRequests(page);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 편집기 셸 마운트 대기 — 마운트 성공 = 단일 React 인스턴스 + 컨텍스트 동일성 증명
    await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    // 편집기 진입 시에는 lazy 번들이 실제로 로드됐어야 한다
    expect(editorRequests.length, '편집기 번들 요청 미발생').toBeGreaterThan(0);
    expect(editorRequests[0]).toMatch(EDITOR_BUNDLE_RE);
  });
});
