<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;

/**
 * 클레임 신청서 목록 컬렉션 (유저 신청내역 — 유형 서브탭 단위 페이지네이션)
 *
 * pagination 블록은 `paginationMeta()` 표준을 쓴다 — 손조립하면 `from`/`to`/`has_more_pages`
 * 가 빠지고, 저장소가 `BoundedPaginator` 로 바뀔 때 `total_relation`/`total_is_exact` 가 통째로
 * 빠져 잘린 총 건수가 정확한 값처럼 나간다 (형제 3종과 동형 — pagination.md).
 */
class ClaimCollection extends BaseApiCollection
{
    /**
     * 주문 스코프 목록의 유형 요약 — 이 응답이 실은 유형과 유형별 접수 건수 (없으면 null)
     *
     * @var array{claim_type: string, counts: array<string, int>}|null
     */
    protected ?array $typeSummary = null;

    /**
     * 유형 요약을 싣습니다. (주문상세 섹션 — 서브탭 뱃지와 기본 유형 판정의 근거)
     *
     * 회원 신청내역(주문 횡단 목록)은 싣지 않는다 — 그 화면은 주소의 유형이 늘 있고 뱃지도 없다.
     *
     * @param  ClaimTypeEnum  $type  이 응답이 실은 유형 (요청 미지정 시 서버가 고른 값)
     * @param  array<string, int>  $counts  유형별 접수 건수 (네 유형 전부)
     * @return static
     */
    public function withTypeSummary(ClaimTypeEnum $type, array $counts): static
    {
        $this->typeSummary = ['claim_type' => $type->value, 'counts' => $counts];

        return $this;
    }

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 응답 배열
     */
    public function toArray(Request $request): array
    {
        $reasonLabels = $this->resolveReasonLabels();

        return array_merge([
            // $this->collection 원소는 이미 ClaimResource 로 래핑되어 있다 ($collects 자동 추론) — 다시 감싸면
            // resource 가 ClaimResource 자신이 되어 toArray 의 모델 타입 판정이 TypeError 로 죽는다.
            // 목록 표현을 **명시 호출**한다 — 리소스가 목록·상세 겸용이라, 컬렉션이 그냥
            // `toArray()` 를 부르면 상세용 필드(첨부 전량·주소/배송비 스냅샷·상세 사유·
            // 환불 계좌)까지 행마다 실린다. 그 필드를 그리는 칸은 목록에 없다.
            'data' => $this->collection
                ->map(fn (ClaimResource $claim) => $claim->forList()->withReasonLabels($reasonLabels)->toArray($request))
                ->toArray(),
        ], $this->paginationMeta(), $this->typeSummary ?? []);
    }

    /**
     * 이 페이지에 실린 사유 코드의 라벨 맵을 한 번에 만듭니다.
     *
     * 행마다 `ClaimReason` 을 되읽으면 목록 1회가 페이지 크기만큼의 추가 쿼리가 된다
     * (`per_page=50` 이면 50회). 유형별로 코드를 모아 `whereIn` 한 번씩만 친다.
     *
     * @return array<string, string> `"{유형}:{코드}" => 라벨` 맵
     */
    protected function resolveReasonLabels(): array
    {
        // 맵 생성은 `UserClaimService::reasonLabelsFor()` 단일 출처 — 비회원 주문상세 목록도 같은
        // 메서드를 쓴다. 여기서 다시 구현하면 두 목록의 라벨 해석이 갈릴 수 있다.
        return app(UserClaimService::class)->reasonLabelsFor([
            $this->collection->map(fn (ClaimResource $claim) => $claim->resource),
        ]);
    }
}
