<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Enums\UserStatus;
use App\Http\Resources\BaseApiResource;
use App\Http\Resources\Traits\ExposesMemberContact;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;

/**
 * 관리자 상품문의 목록 행 리소스
 *
 * “누가 어떤 상품에 무엇을 물었는가” 가 행의 주어다. 본문은 게시판 글(inquirable)에
 * 있으므로 그 관계에서 읽어 목록용으로 잘라 싣는다 — 전문을 목록에 실으면 응답이 무거워진다.
 * 이메일은 canExposeMemberEmail 게이트를 통과할 때만 싣는다
 * (docs/api/README.md 「회원 이메일 노출 범위」).
 */
class AdminInquiryResource extends BaseApiResource
{
    use ExposesMemberContact;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 관리자 목록 행
     */
    public function toArray(Request $request): array
    {
        $member = $this->relationLoaded('user') ? $this->user : null;

        return [
            'id' => $this->id,
            'member' => $member !== null ? $this->resolveMember($member, $request) : null,
            'product' => $this->resolveProduct($this->relationLoaded('product') ? $this->product : null),
            'content_excerpt' => $this->resolveContentExcerpt(),
            'is_answered' => (bool) $this->is_answered,
            'answered_at' => $this->formatDateTimeStringForUser($this->answered_at),
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            // 행 동작 「답변」「삭제」 의 disabledField 근거 — 라우트 권한과 같은 식별자다.
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 행 단위 능력(can_*) 매핑을 반환합니다.
     *
     * 답변 등록은 `inquiries.update`, 삭제는 `inquiries.delete` 라우트 권한이다. 목록 컬렉션의
     * `can_manage`·`can_reply` 와 같은 식별자를 쓴다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_reply' => 'sirsoft-ecommerce.inquiries.update',
            'can_delete' => 'sirsoft-ecommerce.inquiries.delete',
        ];
    }

    /**
     * 회원 블록을 구성합니다 (탈퇴 회원은 이름 익명화, 이메일은 게이트 통과 시에만).
     *
     * 이 Resource 는 관리자 컨트롤러에서만 쓰이지만 게이트를 거치지 않으면, 나중에
     * 사용자 경로가 이 Resource 를 재사용하는 순간 회원 주소록이 그대로 새어나간다.
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

    /**
     * 상품 블록을 구성합니다 (전시·판매 상태 라벨 포함).
     *
     * 숨김·판매중지 상품도 관리자 목록에는 남긴다 — "왜 사용자 화면에서 사라졌는가" 를
     * 설명하는 것이 이 화면의 일이라 상태를 감추면 안 된다.
     *
     * @param  mixed  $product  상품 모델
     * @return array<string, mixed>|null 상품 정보
     */
    private function resolveProduct($product): ?array
    {
        if ($product === null) {
            return null;
        }

        $display = $product->display_status instanceof ProductDisplayStatus
            ? $product->display_status
            : ProductDisplayStatus::tryFrom((string) $product->display_status);
        $sales = $product->sales_status instanceof ProductSalesStatus
            ? $product->sales_status
            : ProductSalesStatus::tryFrom((string) $product->sales_status);

        return [
            'id' => $product->id,
            'name' => $product->getLocalizedName(),
            'display_status' => $display?->value,
            'display_status_label' => $display?->label(),
            'sales_status' => $sales?->value,
            'sales_status_label' => $sales?->label(),
        ];
    }

    /**
     * 문의 본문을 목록용으로 잘라 돌려줍니다.
     *
     * 본문은 문의 테이블이 아니라 연결된 게시판 글에 있다. 원글이 삭제됐으면 null 이
     * 되는데, 그 상태도 화면이 구분해야 하므로 빈 문자열로 덮지 않는다.
     *
     * @return string|null 요약 본문 (원글이 없으면 null)
     */
    private function resolveContentExcerpt(): ?string
    {
        // morph 별칭이 실제 클래스로 풀리지 않으면(그 모듈이 없거나 map 미등록) 관계를 만지는
        // 순간 "Class not found" 가 난다 — 목록 전체를 죽이지 않도록 먼저 확인하고 포기한다.
        $type = $this->inquirable_type;
        $class = $type !== null ? (Relation::getMorphedModel($type) ?? $type) : null;

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        // 로드돼 있을 때만 읽는다 — 여기서 관계를 부르면 행마다 쿼리가 하나씩 붙는다.
        // 목록 저장소가 풀리는 타입의 행에만 원글을 묶어 싣는다(ProductInquiryRepository::
        // loadResolvableInquirables). 원글이 지워졌거나 타입이 풀리지 않으면 null 이고,
        // 화면은 그 경우 "본문을 불러올 수 없습니다" 로 그린다.
        $source = $this->relationLoaded('inquirable') ? $this->inquirable : null;

        if ($source === null || ! isset($source->content)) {
            return null;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $source->content)) ?? '');

        return mb_strlen($plain) > 100 ? mb_substr($plain, 0, 100).'…' : $plain;
    }
}
