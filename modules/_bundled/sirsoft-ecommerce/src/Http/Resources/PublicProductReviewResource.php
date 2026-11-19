<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Support\AuthorNameMasker;

/**
 * 공개 상품 후기 리소스
 *
 * 공개 후기 목록은 인증 없이 열린다. 관리자·본인 응답과 같은 모양을 쓰되 회원 uuid 는 빼고,
 * 작성자 이름은 환경설정(리뷰 설정 → 작성자 이름 가림)에 따라 가린다.
 *
 * 작성자 이름은 후기 영역의 표시 이름(부모 리소스가 계산한 값)이다. 가림은 실명을 가리는 장치라 표시 이름 안의
 * 회원 실명만 가린다 — 닉네임, 이미 가린 종류(가린 이름·가린 아이디), 탈퇴 문구는 그대로 두고(두 번 가리지 않는다),
 * 회원 없는 후기(이관 후기)의 저장된 이름은 7.1.6 처럼 이름 전체를 가린다. 응답의 `user.name` 도 같은 값이다.
 */
class PublicProductReviewResource extends ProductReviewResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 공개 후기 응답
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        unset($data['user_id']);

        $author = $this->resource->isImported() ? null : $this->resource->user;
        $data['author_name'] = $this->maskForPublic($author, (string) ($data['author_name'] ?? ''));

        if ($this->resource->relationLoaded('user')) {
            $user = $this->resource->user;
            $shown = is_array($data['user'] ?? null) ? (string) ($data['user']['name'] ?? '') : '';

            $data['user'] = $user === null ? null : [
                'name' => $this->maskForPublic($user, $shown),
                'email' => $this->canExposeMemberEmail($request, $user->id)
                    ? $user->email
                    : null,
            ];
        }

        return $data;
    }

    /**
     * 공개 후기에 보일 작성자 이름에 작성자 이름 가림 설정을 적용합니다.
     *
     * @param  User|null  $user  작성 회원 (회원 없는 후기면 null)
     * @param  string  $shown  후기 영역의 표시 이름
     * @return string 공개 응답에 실을 이름
     */
    private function maskForPublic(?User $user, string $shown): string
    {
        if ($user === null) {
            return AuthorNameMasker::forPublicReview($shown);
        }

        $realName = (string) ($user->name ?? '');
        if ($shown === '' || $realName === '' || AuthorNameMasker::reviewMode() === AuthorNameMasker::MODE_NONE) {
            return $shown;
        }

        return str_contains($shown, $realName)
            ? str_replace($realName, AuthorNameMasker::mask($realName), $shown)
            : $shown;
    }
}
