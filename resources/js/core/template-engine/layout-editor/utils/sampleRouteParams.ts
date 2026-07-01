/**
 * sampleRouteParams.ts — 편집기 캔버스의 path parameter 샘플 값 자동 주입
 *
 * 배경:
 *   라우트 path 가 `/board/:slug/:id` 같이 path parameter 를 포함할 때,
 *   데이터소스 endpoint 가 `/api/boards/{{route.slug}}/posts/{{route.id}}` 처럼
 *   route param 에 의존하면 편집기 캔버스에서는 path param 값이 없어 URL 이
 *   `/api/boards//posts/` 같이 깨지고 본문이 빈 상태로 떨어진다.
 *   (Phase 2 결함 카테고리 C: 게시글/상품 상세/페이지 본문 미렌더)
 *
 *   샘플 모드에서는 실제 fetch 가 아닌 sampleDataProvider 가 응답을 만들지만,
 *   조건부 데이터소스(`if: "{{route.id}}"`) 분기와 표현식 평가에서 truthy
 *   route param 이 필요한 경우가 많다. 본 헬퍼는 `/board/:slug/:id` 같은
 *   path 에서 `:slug`, `:id` 토큰을 뽑아 안전한 샘플 값으로 채운다.
 *
 *   샘플 값 규칙:
 *   - 숫자형 param (`:id`, `:userId`, `:postId`, `:orderId`, `:productId` 등): "1"
 *   - 슬러그/문자형 (`:slug`, `:code`, `:key`): "sample"
 *   - 그 외 알 수 없는 param: "sample"
 *
 * @since engine-v1.50.0
 */

const NUMERIC_PARAM_HINTS = new Set([
    'id',
    'userId',
    'postId',
    'orderId',
    'productId',
    'categoryId',
    'commentId',
    'noticeId',
    'inquiryId',
    'addressId',
    'wishlistId',
    'notificationId',
    'roleId',
    'menuId',
]);

/**
 * 라우트 path 에서 `:param` 토큰을 추출하여 샘플 값 객체로 반환.
 *
 * @param routePath `/board/:slug/:id` 같은 path 문자열 (없으면 빈 문자열)
 * @returns `{ slug: 'sample', id: '1' }` 같은 샘플 routeParams 객체
 */
export function deriveSampleRouteParams(routePath: string | null | undefined): Record<string, string> {
    if (!routePath) return {};
    const result: Record<string, string> = {};

    // `:paramName` 토큰 모두 추출 (param 이름은 영문자/숫자/언더스코어 허용)
    const tokenRegex = /:([a-zA-Z_][a-zA-Z0-9_]*)/g;
    let match: RegExpExecArray | null;
    while ((match = tokenRegex.exec(routePath)) !== null) {
        const paramName = match[1];
        result[paramName] = pickSampleValue(paramName);
    }

    return result;
}

/**
 * Param 이름 휴리스틱에 따라 샘플 값을 선택.
 *
 * - NUMERIC_PARAM_HINTS 에 정의된 이름: "1" 반환 (숫자 ID 류)
 * - `*Id` / `*_id` 접미사: "1" 반환
 * - 그 외: "sample" 반환 (슬러그/문자형 ID 류)
 */
function pickSampleValue(paramName: string): string {
    if (NUMERIC_PARAM_HINTS.has(paramName)) {
        return '1';
    }
    // *Id (camelCase) 또는 *_id (snake_case) 접미사 — 숫자 ID
    if (/Id$|_id$/.test(paramName)) {
        return '1';
    }
    return 'sample';
}
