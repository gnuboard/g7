<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Support\ClaimAdminAbilities;

/**
 * 관리자 클레임 목록 컬렉션 (유형 서브탭 단위 페이지네이션 + 유형별 건수)
 *
 * 서브탭 뱃지 건수(`counts`)는 `withCounts()` 로 주입한다 — 계산은
 * `AdminClaimService::counts()` 가 담당하며, 여기서는 실어 보내기만 한다.
 */
class ClaimAdminCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 행 래핑 리소스 (클래스명 자동 추론이 목록 전용 리소스를 찾지 못하므로 명시)
     *
     * @var string
     */
    public $collects = ClaimAdminListResource::class;

    /**
     * 주입된 유형별 건수 (`AdminClaimService::counts()` 결과)
     *
     * @var array<string, int>|null
     */
    protected ?array $counts = null;

    /**
     * 완료 처리 모달의 초기 선택값 (`AdminClaimService::completionDefaults()` 결과)
     *
     * @var array<string, bool>|null
     */
    protected ?array $completeDefaults = null;

    /**
     * 회수중·발송중 일괄 전이 모달의 택배사 선택지 (`ShippingCarrierService::getActiveCarrierOptions()` 결과)
     *
     * @var array<int, array{value: int, label: string}>|null
     */
    protected ?array $carriers = null;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * 매핑 자체는 `ClaimAdminAbilities` 가 단일 출처다 — 상세 응답도 같은 축을 그리므로
     * 여기에 사본을 두면 같은 권한의 운영자에게 화면마다 다른 버튼이 보이게 된다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return ClaimAdminAbilities::map();
    }

    /**
     * 서브탭 뱃지용 유형별 건수를 주입합니다.
     *
     * @param  array<string, int>|null  $counts  유형별 건수
     * @return static 자기 자신
     */
    public function withCounts(?array $counts): static
    {
        $this->counts = $counts;

        return $this;
    }

    /**
     * 완료 처리 모달(D26)의 초기 선택값을 주입합니다.
     *
     * 이 목록 응답이 그 값의 전달 통로다 — 설정 엔드포인트는 `settings.read` 게이트라
     * 클레임 권한만 가진 운영자에게 도달하지 않는다 (`AdminClaimService::completionDefaults()`
     * docblock 참조).
     *
     * @param  array<string, bool>|null  $defaults  초기 선택값
     * @return static 자기 자신
     */
    public function withCompleteDefaults(?array $defaults): static
    {
        $this->completeDefaults = $defaults;

        return $this;
    }

    /**
     * 회수중·발송중 일괄 전이 모달의 택배사 선택지를 주입합니다.
     *
     * 상세 응답과 같은 이유로 목록 응답이 전달 통로다 — 배송사 엔드포인트는 `settings.read`
     * 게이트라 클레임 권한만 가진 운영자에게 403 이 되어 모달의 선택지가 조용히 빈다.
     *
     * @param  array<int, array{value: int, label: string}>|null  $carriers  택배사 선택지 (회수·발송 축이 없는 유형이면 빈 배열)
     * @return static 자기 자신
     */
    public function withCarriers(?array $carriers): static
    {
        $this->carriers = $carriers;

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
            'data' => $this->collection
                ->map(fn (ClaimAdminListResource $claim) => $claim->withReasonLabels($reasonLabels)->toArray($request))
                ->toArray(),
            'counts' => $this->counts,
            'complete_defaults' => $this->completeDefaults,
            'carriers' => $this->carriers ?? [],
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ], $this->paginationMeta());
    }

    /**
     * 이 페이지에 실린 사유 코드의 라벨 맵을 한 번에 만듭니다.
     *
     * 맵 생성은 `UserClaimService::reasonLabelsFor()` 단일 출처 — 유저 목록(`ClaimCollection`)과
     * 같은 해석을 쓴다. 행마다 되읽으면 페이지 크기만큼의 추가 쿼리가 된다.
     *
     * @return array<string, string> `"{유형}:{코드}" => 라벨` 맵
     */
    protected function resolveReasonLabels(): array
    {
        return app(UserClaimService::class)->reasonLabelsFor([
            $this->collection->map(fn (ClaimAdminListResource $claim) => $claim->resource),
        ]);
    }
}
