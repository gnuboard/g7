<?php

namespace Modules\Gnuboard7\HelloModule\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;

/**
 * 메모 목록 API 컬렉션
 */
class MemoCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션이 포함하는 리소스 클래스
     *
     * @var string
     */
    public $collects = MemoResource::class;

    /**
     * 권한-ability 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'gnuboard7-hello_module.memos.create',
            'can_update' => 'gnuboard7-hello_module.memos.update',
            'can_delete' => 'gnuboard7-hello_module.memos.delete',
        ];
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
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
