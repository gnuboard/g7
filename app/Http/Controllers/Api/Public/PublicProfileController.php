<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

/**
 * 공개 사용자 프로필 컨트롤러
 *
 * 타인의 공개 프로필 정보를 조회하는 API를 제공합니다.
 * 인증 없이 접근 가능합니다.
 *
 * 게시글 관련 API는 게시판 모듈(sirsoft-board)에서 제공합니다:
 * - 게시글 목록: GET /api/modules/sirsoft-board/users/{userId}/posts
 * - 게시글 통계: GET /api/modules/sirsoft-board/users/{userId}/posts/stats
 */
class PublicProfileController extends PublicBaseController
{
    public function __construct(
        private UserService $userService
    ) {
        parent::__construct();
    }

    /**
     * 사용자의 공개 프로필을 조회합니다.
     *
     * 사용자 상태에 따라 차등 데이터를 반환합니다:
     * - active: 전체 정보 (name, avatar, bio, created_at)
     * - inactive: 기본 정보만 (bio 제외)
     * - blocked: 최소 정보만 (avatar, bio, created_at 제외)
     * - withdrawn/미존재: 404 에러
     *
     * 게시글 통계(posts_count, comments_count)는 게시판 모듈 API를 통해 별도 조회합니다.
     *
     * @param  User  $user  사용자 모델 (Route Model Binding, uuid 기반)
     * @return JsonResponse 공개 프로필 정보
     */
    public function show(User $user): JsonResponse
    {
        $profileData = $this->userService->getPublicProfile($user->id);

        if ($profileData === null) {
            return $this->error('user.not_found', 404);
        }

        return $this->success('user.profile_success', $profileData);
    }
}
