<?php

namespace Modules\Sirsoft\Board\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 사용자 화면에서 이 요청이 게시글을 수정·삭제할 수 있는지 판정합니다.
 *
 * 글 수정·삭제(`User\PostController`)와 기존 글에 첨부를 더하는 업로드(`User\AttachmentController::upload` 의 `post_id`)가
 * 같은 판정을 쓴다. 종전에는 업로드가 이 판정을 거치지 않아, 업로드 권한만 있으면 남의 글 번호로 파일을 붙일 수 있었다(#883).
 *
 * 다음 중 하나면 통과한다.
 * - 게시판 관리 권한(admin.manage 또는 사용자 manager)
 * - 회원 글의 작성자 본인
 * - 비회원 글: 비밀번호 확인 뒤 받은 검증 토큰(헤더 `X-Board-Post-Verify-Token` 또는 본문 `verification_token`), 없으면 비밀번호
 */
class PostModifyGate
{
    use ChecksBoardPermission;

    /**
     * @param  PostService  $postService  게시글 서비스 (검증 토큰 조회·소비)
     */
    public function __construct(private PostService $postService) {}

    /**
     * 이 요청이 게시글을 수정·삭제할 수 있는지 판정합니다.
     *
     * 검증 토큰은 상태를 바꾸는 요청(수정·삭제)에서만 소비한다. 첨부 업로드처럼 같은 화면에서 저장(PUT) 전에 오는
     * 요청은 `consume: false` 로 확인만 한다 — 여기서 쓰면 저장 시점에 토큰이 남지 않아 수정이 거부된다.
     *
     * @param  Post  $post  대상 게시글
     * @param  Request  $request  HTTP 요청
     * @param  bool  $consume  검증 토큰을 소비할지 여부
     * @return bool 수정·삭제 가능 여부
     */
    public function allows(Post $post, Request $request, bool $consume): bool
    {
        $slug = $post->board->slug;

        // 1. 게시판 관리 권한 확인 (admin.manage 또는 manager)
        if ($this->hasBoardManagePermission($slug)) {
            return true;
        }

        // 2. 작성자 본인 확인 (회원 게시글)
        if (Auth::check() && $post->user_id && Auth::id() === $post->user_id) {
            return true;
        }

        // 3. 비회원 게시글인 경우 verification_token 또는 비밀번호로 확인
        if (! $post->user_id && $post->password) {
            // 3-1. verification_token 확인 (권장)
            $token = $this->verificationTokenFrom($request);
            if ($token !== '') {
                $valid = $consume
                    ? $this->postService->consumeDeleteVerifyToken($slug, $post->id, $token)
                    : $this->postService->hasValidDeleteVerifyToken($slug, $post->id, $token);

                if ($valid) {
                    return true;
                }
            }

            // 3-2. 비밀번호 확인 (fallback)
            $password = $request->input('password');
            if (is_string($password) && $password !== '' && Hash::check($password, $post->password)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 요청에서 게시글 검증 토큰을 꺼냅니다.
     *
     * 헤더를 먼저 본다 — 자격증명이 주소에 실리면 웹서버 접근 기록과 Referer 에 남는다.
     * 폼 조회(GET)는 헤더로만 보내도록 바꿨지만, 저장·삭제는 이미 본문으로 보내고 있고
     * 외부에서 그 형태로 호출하는 쪽이 있을 수 있어 기존 경로도 계속 받는다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string 검증 토큰 (없으면 빈 문자열)
     */
    public function verificationTokenFrom(Request $request): string
    {
        $header = $request->header(PostService::VERIFY_TOKEN_HEADER);

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $value = $request->input('verification_token');

        return is_string($value) ? $value : '';
    }
}
