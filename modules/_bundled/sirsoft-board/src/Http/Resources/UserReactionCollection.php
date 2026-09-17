<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;

/**
 * 마이페이지 「내 반응」 컬렉션
 *
 * 목록·페이지네이션에 더해 필터 셀렉트가 쓸 유형 메타를 함께 싣는다 — 회원 화면은
 * 관리자 유형 API(settings.read 권한 필요)를 부를 수 없으므로 이 응답이 유일한 출처다.
 * 이미 비활성화된 유형으로 남긴 과거 반응도 필터로 골라낼 수 있어야 하므로 전 유형을 낸다.
 */
class UserReactionCollection extends BaseApiCollection
{
    /**
     * 적용된 필터 에코 (화면이 현재 상태를 복원할 때 쓴다).
     *
     * @var array<string, mixed>
     */
    private array $query = [];

    /**
     * 적용된 필터를 응답에 함께 싣습니다.
     *
     * @param  array<string, mixed>  $query  적용된 필터
     * @return $this
     */
    public function withQuery(array $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * 반응 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 반응 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $result = [
            'data' => $this->collection
                ->map(fn ($reaction) => (new UserReactionResource($reaction))->resolve($request))
                ->toArray(),
            'query' => $this->query,
            'reaction_types' => app(ReactionTypeRepositoryInterface::class)->all()
                ->map(fn ($type) => [
                    'id' => (int) $type->id,
                    'code' => $type->code,
                    'name' => $type->getLocalizedName(),
                    'icon_name' => $type->getIconName(),
                    'icon_url' => $type->getIconUrl(),
                ])
                ->values()
                ->all(),
        ];

        if ($this->resource instanceof LengthAwarePaginator) {
            $result['pagination'] = [
                'total' => $this->resource->total(),
                'count' => $this->resource->count(),
                'per_page' => $this->resource->perPage(),
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ];
        }

        return $result;
    }
}
