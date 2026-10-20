<?php

namespace Modules\Sirsoft\Board\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * 라우트의 `{slug}` 게시판이 있는지 권한 판정보다 먼저 확인하는 미들웨어
 *
 * 게시판 하위 라우트는 `permission:…,sirsoft-board.{slug}.…` 로 게시판별 동적 권한을 본다.
 * 없는 게시판이면 그 권한 식별자도 없으므로 권한 미들웨어가 비회원 401·회원 403 을 돌려
 * 「없는 게시판」이 「권한 없음」으로 보였다. 게시판 slug 는 공개 주소의 일부라 존재 여부를
 * 감출 이유가 없으므로, 권한 미들웨어보다 앞에서 404 로 끝낸다.
 *
 * 부착은 모듈 `getMiddleware()` 선언(코어 self-gate, api 그룹 after_core)이다 — 그룹 미들웨어 단계라
 * 라우트에 붙은 `optional.sanctum`·`admin`·`permission` 보다 먼저 돈다. 그래서 관리자 API 도 없는 게시판이면
 * 관리자 판정 전에 404 다(게시판 slug 는 공개 주소라 존재를 감출 이유가 없다 — 2026-10-18 결정). 단 `auth:sanctum`
 * 은 라라벨 미들웨어 우선순위(AuthenticatesRequests)로 그룹 미들웨어보다 앞으로 옮겨져, 관리자·회원 전용 API 의
 * 비회원은 게시판이 없어도 401 이다.
 * 있는 게시판은 지금처럼 로그인(401)·관리자(403)·권한(401/403) 판정을 받는다.
 *
 * 대상은 라우트명 서브트리 glob 이라 `{slug}` 가 없는 라우트(`boards.posts.recent` 등)도 걸린다 —
 * 그런 라우트는 판정 없이 통과시킨다.
 */
class EnsureBoardExists
{
    /**
     * EnsureBoardExists 생성자
     *
     * @param  BoardRepositoryInterface  $boardRepository  게시판 리포지토리
     */
    public function __construct(
        private BoardRepositoryInterface $boardRepository
    ) {}

    /**
     * 게시판이 없으면 404 로 응답하고, 있으면 다음 단계로 넘깁니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        // `{slug}` 가 없는 라우트(같은 서브트리의 최근글 등)는 게시판을 가리키지 않는다
        if (! $route instanceof Route || ! $route->hasParameter('slug')) {
            return $next($request);
        }

        $slug = $route->parameter('slug');

        if (! is_string($slug) || $slug === '' || ! $this->boardRepository->existsBySlug($slug)) {
            return ResponseHelper::error('sirsoft-board::messages.boards.not_found', 404);
        }

        return $next($request);
    }
}
