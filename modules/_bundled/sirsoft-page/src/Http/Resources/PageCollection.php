<?php

namespace Modules\Sirsoft\Page\Http\Resources;

use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use App\Http\Resources\BaseApiCollection;

/**
 * 페이지 목록 API 컬렉션 (관리자용)
 */
class PageCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션의 리소스 클래스
     *
     * @var string
     */
    public $collects = PageResource::class;

    /**
     * 권한-ability 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-page.pages.create',
            'can_update' => 'sirsoft-page.pages.update',
            'can_delete' => 'sirsoft-page.pages.delete',
        ];
    }

    /**
     * 리소스를 배열로 변환합니다 (목록용 경량 데이터).
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(fn (PageResource $resource) => $resource->toListArray($request)),
            'meta' => [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
            ],
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ];
    }
}
