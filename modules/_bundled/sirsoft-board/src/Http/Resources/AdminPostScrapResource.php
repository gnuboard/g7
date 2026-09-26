<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Enums\UserStatus;
use App\Http\Resources\BaseApiResource;
use App\Http\Resources\Traits\ExposesMemberContact;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;
use Modules\Sirsoft\Board\Traits\ResolvesScrapAuthorName;

/**
 * 관리자 스크랩 목록 행 리소스
 *
 * 사용자 카드(PostScrapResource)와 달리 "누가 무엇을 스크랩했는가" 가 행의 주어이므로
 * 회원 정보를 함께 싣는다. 이메일은 관리자 응답에서만 노출한다
 * (docs/api/README.md 「회원 이메일 노출 범위」).
 *
 * 삭제된 원글의 제목도 낸다 — 관리자는 "어떤 글이 스크랩됐다가 삭제됐는지" 를 알아야
 * 스크랩을 정리할 수 있고, 상태는 post_status 배지가 구분한다. 탈퇴 회원의 이름과
 * 작성자명은 사용자 응답과 동일하게 익명화한다.
 */
class AdminPostScrapResource extends BaseApiResource
{
    use ChecksBoardPermission;
    use ExposesMemberContact;
    use FormatsBoardDate;
    use ResolvesScrapAuthorName;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 관리자 스크랩 행
     */
    public function toArray(Request $request): array
    {
        $post = $this->relationLoaded('post') ? $this->post : null;
        $board = $this->relationLoaded('board') ? $this->board : null;
        $member = $this->relationLoaded('user') ? $this->user : null;
        $isDeleted = $post === null || $post->deleted_at !== null;
        $status = $post?->status instanceof PostStatus
            ? $post->status
            : PostStatus::tryFrom((string) ($post->status ?? ''));

        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'board_id' => $this->board_id,
            'member' => $member !== null ? $this->resolveMember($member, $request) : null,
            'board' => $board !== null ? [
                'slug' => $board->slug,
                'name' => $board->getLocalizedName(),
                'is_active' => (bool) $board->is_active,
                'use_scrap' => (bool) $board->use_scrap,
            ] : null,
            // 관리자 화면은 삭제글 제목도 보인다 (상태는 post_status 배지가 구분)
            'post_title' => $post?->title,
            'post_status' => $isDeleted ? PostStatus::Deleted->value : ($status?->value ?? PostStatus::Published->value),
            'post_status_label' => $isDeleted ? PostStatus::Deleted->label() : ($status?->label() ?? PostStatus::Published->label()),
            'is_deleted' => $isDeleted,
            'author_name' => $post !== null ? $this->resolveAuthorName($post) : null,
            'scrapped_at' => $this->created_at ? $this->formatCreatedAtFormat(
                $this->created_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ) : null,
            // 표시 설정이 「상대 표기」면 scrapped_at 은 "2시간 전" 이라 두 스크랩의 선후를 가리지
            // 못한다. 관리 목록은 초까지 필요하므로 회원 표시 시간대로 환산한 절대 시각을 함께 싣는다
            // (저장은 UTC, 환산은 표시 시점 — AdminReactionResource 와 같은 규약).
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'abilities' => [
                'can_delete' => $this->checkModulePermission('scraps', 'manage'),
            ],
        ];
    }

    /**
     * 회원 블록을 구성합니다 (탈퇴 회원은 이름 익명화).
     *
     * 이메일은 canExposeMemberEmail 게이트를 통과할 때만 싣는다. 이 Resource 는 관리자
     * 컨트롤러에서만 쓰이지만, 게이트를 거치지 않으면 나중에 사용자 경로가 이 Resource 를
     * 재사용하는 순간 회원 주소록이 그대로 새어나간다 — 판정은 한 곳(트레이트)에 맡긴다.
     *
     * @param  mixed  $member  회원 모델
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 회원 정보
     */
    private function resolveMember($member, Request $request): array
    {
        $isWithdrawn = UserStatus::tryFrom((string) $member->status) === UserStatus::Withdrawn;

        return [
            'uuid' => $member->uuid,
            'name' => $isWithdrawn ? __('user.withdrawn_user') : $member->name,
            'email' => $this->canExposeMemberEmail($request, (int) $member->id) ? $member->email : null,
            'is_withdrawn' => $isWithdrawn,
        ];
    }
}
