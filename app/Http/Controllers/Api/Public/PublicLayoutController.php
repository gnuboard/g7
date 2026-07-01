<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\ExtensionStatus;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\LayoutService;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * 공개 레이아웃 API 컨트롤러
 *
 * 템플릿 레이아웃 JSON을 프론트엔드에 제공합니다.
 */
class PublicLayoutController extends PublicBaseController
{
    /**
     * 레이아웃 캐시 TTL (초)
     */
    private const CACHE_TTL = 3600;

    /**
     * TemplateService 및 LayoutService 주입
     */
    public function __construct(
        private TemplateService $templateService,
        private LayoutService $layoutService
    ) {
        parent::__construct();
    }

    /**
     * 병합된 레이아웃 JSON 서빙
     *
     * HTTP 캐시 헤더 및 ETag를 지원하여 전송 효율성을 높입니다.
     * 사용자 권한에 따라 컴포넌트를 필터링하여 서빙합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @param  string  $layoutName  레이아웃 이름
     * @return JsonResponse|Response JSON 응답 또는 304 Not Modified
     */
    public function serve(string $templateIdentifier, string $layoutName): JsonResponse|Response
    {
        // API 사용량 기록
        $this->logApiUsage("layouts/{$templateIdentifier}/{$layoutName}", [
            'identifier' => $templateIdentifier,
            'layout_name' => $layoutName,
        ]);

        // 1. 템플릿 조회 (활성화 여부 확인)
        $template = $this->templateService->findByIdentifier($templateIdentifier);

        // 템플릿이 존재하지 않거나 활성화되지 않은 경우
        if (! $template || $template->status !== ExtensionStatus::Active->value) {
            return $this->notFound(__('templates.layout_not_found'));
        }

        try {
            // 캐시 버전을 키에 포함하여 모듈/플러그인 변경 시 캐시 무효화.
            //
            // 서버 캐시 키에는 **정수 버전만** 쓴다(소수 nonce 제거). 레이아웃 편집기는 같은 세션의
            // 저장·버전 복원 직후 브라우저 HTTP 캐시를 우회하려고 `?v={cacheVersion}.{nonce}` 형식으로
            // 요청한다(클라이언트 cache-bust nonce). 그런데 `serve` 가 이 문자열을 그대로 캐시 키에
            // 쓰면 키가 `...v{cacheVersion}.{nonce}.meta` 가 되는데, 저장 경로
            // `LayoutService::clearPublicServingCache` 는 `(int) ext.cache_version` 으로 nonce 없는
            // 키만 forget 하므로 키 형식이 어긋나 무효화가 빗나간다(저장/복원 후 편집기 캔버스만
            // stale). nonce 는 브라우저 HTTP 캐시 우회용(URL·ETag 차이로 이미 달성)이고, 서버 캐시 키
            // 정합은 정수 버전이 SSoT 다. `(int)` 캐스팅은 PHP 가 소수점에서 절단해 정수부만 남긴다.
            $cacheVersion = (int) request()->query('v', 0);

            // 편집기 출처 메타 옵션
            // - 옵션이 truthy 면 각 노드에 `__source` 메타를 부여한 응답을 반환
            // - 일반 사이트 렌더는 옵션을 전달하지 않으므로 응답 형식 종전과 100% 동일
            $withSourceMeta = (bool) request()->query('with_source_meta', false);

            // 출처 메타 요청은 편집 권한 필요 — 일반 사용자가 메타를 보면 안 됨
            // @since engine-v1.50.0
            if ($withSourceMeta) {
                $user = request()->user();
                if ($user === null) {
                    return $this->unauthorized('auth.layout_guest_permission_denied', [
                        'required_permissions' => 'core.templates.layouts.edit',
                    ]);
                }
                if (! PermissionHelper::check('core.templates.layouts.edit', $user)) {
                    return $this->forbidden('auth.layout_permission_denied', [
                        'required_permissions' => 'core.templates.layouts.edit',
                    ]);
                }
            }

            // 서버 측 캐싱 (1시간 유효) — 메타 포함/미포함은 별도 캐시 키
            // getLayout()을 사용하여 레이아웃 로드, 병합, 확장 적용을 한 번에 수행
            $metaSuffix = $withSourceMeta ? '.meta' : '';
            $mergedLayout = $this->cached(
                "layout.{$templateIdentifier}.{$layoutName}.v{$cacheVersion}{$metaSuffix}",
                fn () => $this->layoutService->getLayout($templateIdentifier, $layoutName, $withSourceMeta),
                self::CACHE_TTL
            );

            // 권한 체크 (permissions 필드가 있는 경우)
            $permissionCheckResult = $this->checkLayoutPermissions($mergedLayout);
            if ($permissionCheckResult !== null) {
                return $permissionCheckResult;
            }

            // 컴포넌트별 권한 필터링 (post-cache, 사용자별 동적 처리)
            $mergedLayout = $this->layoutService->filterComponentsByPermissions(
                $mergedLayout,
                request()->user()
            );

            // ETag 및 Cache-Control 헤더와 함께 응답 반환
            return $this->successWithCache(
                'templates.messages.layout_served',
                $mergedLayout,
                self::CACHE_TTL
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // 레이아웃 또는 부모 레이아웃을 찾을 수 없음 - 예외 메시지 전달
            return $this->notFound($e->getMessage());
        }
    }

    /**
     * 레이아웃 권한 체크
     *
     * 레이아웃에 permissions 필드가 있으면 권한을 체크합니다.
     * flat array(AND), 구조화 객체(OR/AND 중첩) 모두 지원합니다.
     *
     * @param  array  $layout  병합된 레이아웃 데이터
     * @return JsonResponse|null 권한 없으면 에러 응답, 있으면 null
     */
    private function checkLayoutPermissions(array $layout): ?JsonResponse
    {
        $permissions = $layout['permissions'] ?? [];

        // 권한 요구사항 없음 (공개 레이아웃)
        if (empty($permissions)) {
            return null;
        }

        // 구조화된 권한 로직(OR/AND) 지원
        if (! PermissionHelper::checkWithLogic($permissions)) {
            $user = request()->user();
            $permissionList = $this->flattenPermissionList($permissions);

            // 비회원이면 401, 회원이면 403
            if ($user === null) {
                return $this->unauthorized('auth.layout_guest_permission_denied', [
                    'required_permissions' => $permissionList,
                ]);
            }

            return $this->forbidden('auth.layout_permission_denied', [
                'required_permissions' => $permissionList,
            ]);
        }

        return null;
    }

    /**
     * 권한 구조에서 모든 권한 식별자를 평탄화하여 문자열로 반환합니다.
     *
     * 에러 메시지에 필요한 권한 목록을 표시하기 위해 사용합니다.
     *
     * @param  array  $permissions  권한 구조 (flat array 또는 구조화 객체)
     * @return string 쉼표로 구분된 권한 식별자 문자열
     */
    private function flattenPermissionList(array $permissions): string
    {
        $flat = [];
        array_walk_recursive($permissions, function ($value) use (&$flat) {
            if (is_string($value)) {
                $flat[] = $value;
            }
        });

        return implode(', ', array_unique($flat));
    }
}
