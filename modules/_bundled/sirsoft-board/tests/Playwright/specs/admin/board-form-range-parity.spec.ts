/**
 * 개별 게시판 수정 화면의 숫자 입력 경계값이 서버 한계값과 일치하는지 검증 (공개 이슈 #81).
 *
 * 결함: 같은 설정 항목이 두 화면에 있는데 경계 안내가 한쪽에만 있었다.
 *   - 게시판 환경설정(모듈 기본값): 숫자 입력에 허용 범위가 붙어 있음
 *   - 개별 게시판 수정: min/max 가 아예 없어 범위를 알 수 없음
 *   저장은 서버가 동일하게 막으므로 잘못된 값이 들어가지는 않았지만, 사용자는 저장을
 *   눌러 422 를 받아야만 범위를 알 수 있었다.
 *
 * 단위/정적:
 *   - modules/_bundled/sirsoft-board/resources/js/__tests__/layouts/board-form-range-contract.test.ts
 *     가 레이아웃 JSON 의 표현식(_local.form._meta.limits.*)과 폴백 값을 전수 고정
 *   이 spec 은 브라우저 수준 — 서버가 실제로 내려준 한계값이 DOM 속성으로 해석되는지를 담당한다.
 *   (표현식이 잘못된 경로를 가리켜도 폴백 값이 서버 기본치와 같으면 화면만 봐서는 구분되지
 *    않으므로, 응답의 `_meta.limits` 를 읽어 그 값과 대조한다.)
 *
 * 시드 의존: 샘플 시드의 자유게시판(`free`).
 *
 * @scenario board-form-range-parity
 * @axes screen=per_board screen=module_defaults
 * @effects form_boundary_matches_server_limits
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SLUG = 'free';

/** 개별 게시판 화면의 입력 name → 서버 limits 키 prefix (per_page_mobile 은 per_page 범위 공유) */
const FIELD_TO_LIMIT: Record<string, string> = {
    per_page: 'per_page',
    per_page_mobile: 'per_page',
    min_title_length: 'min_title_length',
    max_title_length: 'max_title_length',
    min_content_length: 'min_content_length',
    max_content_length: 'max_content_length',
    new_display_hours: 'new_display_hours',
    min_comment_length: 'min_comment_length',
    max_comment_length: 'max_comment_length',
    max_comment_depth: 'max_comment_depth',
    max_file_size: 'max_file_size',
    max_file_count: 'max_file_count',
};

test('#81 - 개별 게시판 수정 화면의 숫자 경계값이 서버 한계값과 일치한다', async ({ page, boardManageToken }) => {
    await authenticatePage(page, boardManageToken);

    await page.goto(`/admin/boards/${SLUG}/edit`);
    await expect(page.locator('input[name="per_page"]')).toBeAttached({ timeout: 20_000 });

    // 서버가 실제로 내려주는 한계값을 그대로 읽는다 (화면 폴백과 구분하기 위함)
    const limits = await page.evaluate(async ({ slug, bearer }) => {
        const response = await fetch(
            `/api/modules/sirsoft-board/admin/boards/form-data?board_slug=${slug}`,
            { headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' } }
        );
        const body = await response.json();

        return (body?.data?._meta?.limits ?? {}) as Record<string, number>;
    }, { slug: SLUG, bearer: boardManageToken });

    expect(
        Object.keys(limits).length,
        '게시판 한계값이 폼 데이터 응답에 실려 있지 않습니다 — 화면 바인딩이 영구히 폴백으로 떨어집니다.'
    ).toBeGreaterThan(0);

    for (const [field, limitKey] of Object.entries(FIELD_TO_LIMIT)) {
        const input = page.locator(`input[name="${field}"]`).first();

        // 토글 뒤에 숨은 입력(예: 답글 사용)은 이 화면 기본 상태에서 렌더되지 않을 수 있다
        if ((await input.count()) === 0) {
            continue;
        }

        await expect(input, `${field}: min 이 서버 한계값과 다릅니다`)
            .toHaveAttribute('min', String(limits[`${limitKey}_min`]));
        await expect(input, `${field}: max 가 서버 한계값과 다릅니다`)
            .toHaveAttribute('max', String(limits[`${limitKey}_max`]));
    }
});
