<?php

namespace App\Http\Middleware;

use App\Contracts\Extension\TemplateManagerInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 템플릿 의존성 검증 미들웨어
 *
 * User/Admin 템플릿의 모듈/플러그인 의존성이 충족되었는지 검증합니다.
 * 미충족 시 503 에러 페이지를 반환합니다.
 */
class CheckTemplateDependencies
{
    /**
     * CheckTemplateDependencies 생성자
     *
     * @param  TemplateManagerInterface  $templateManager  템플릿 매니저
     */
    public function __construct(
        protected TemplateManagerInterface $templateManager
    ) {}

    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @param  string  $templateType  템플릿 타입 (user 또는 admin)
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next, string $templateType = 'user'): Response
    {
        // 활성 템플릿 조회 (user 또는 admin)
        $template = $this->templateManager->getActiveTemplate($templateType);

        if (! $template) {
            return $next($request);
        }

        $identifier = $template['identifier'] ?? null;
        if (! $identifier) {
            return $next($request);
        }

        // 의존성 충족 상태 확인
        $unmetDependencies = $this->templateManager->getUnmetDependencies($identifier);

        $hasUnmetModules = ! empty($unmetDependencies['modules']);
        $hasUnmetPlugins = ! empty($unmetDependencies['plugins']);

        if ($hasUnmetModules || $hasUnmetPlugins) {
            // 503 에러 레이아웃을 템플릿 엔진이 렌더링하도록 설정
            // 뷰 파일(app.blade.php 또는 admin.blade.php)에서 에러 레이아웃을 로드
            $viewName = $templateType === 'admin' ? 'admin' : 'app';

            return response()->view($viewName, [
                'errorCode' => 503,
                'errorLayout' => 'errors/503',
                'unmetDependencies' => $unmetDependencies,
            ], 503);
        }

        return $next($request);
    }
}
