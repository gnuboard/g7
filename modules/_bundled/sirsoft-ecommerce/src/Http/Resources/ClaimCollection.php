<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;

/**
 * 클레임 신청서 목록 컬렉션 (유저 신청내역 — 유형 서브탭 단위 페이지네이션)
 */
class ClaimCollection extends BaseApiCollection
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 응답 배열
     */
    public function toArray(Request $request): array
    {
        $reasonLabels = $this->resolveReasonLabels();

        return [
            // $this->collection 원소는 이미 ClaimResource 로 래핑되어 있다 ($collects 자동 추론) — 다시 감싸면
            // resource 가 ClaimResource 자신이 되어 toArray 의 모델 타입 판정이 TypeError 로 죽는다.
            'data' => $this->collection
                ->map(fn (ClaimResource $claim) => $claim->withReasonLabels($reasonLabels)->toArray($request))
                ->toArray(),
            'pagination' => $this->resource instanceof LengthAwarePaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
            ] : null,
        ];
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
