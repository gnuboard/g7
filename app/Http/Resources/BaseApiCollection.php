<?php

namespace App\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\Traits\HasRowNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * API 컬렉션 리소스 기본 클래스
 *
 * 모든 API Collection은 이 클래스를 상속해야 합니다.
 * - HasRowNumber: 순번 부여
 * - abilityMap(): 컬렉션 레벨 abilities (페이지 버튼 제어)
 */
abstract class BaseApiCollection extends ResourceCollection
{
    use HasRowNumber;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * 페이지 레벨 버튼 제어용 (생성, 일괄삭제 등).
     * abilities가 불필요한 컬렉션은 오버라이드하지 않습니다.
     *
     * @return array<string, string> ['can_delete' => 'permission.identifier', ...]
     */
    protected function abilityMap(): array
    {
        return [];
    }

    /**
     * 컬렉션 레벨 abilities를 해석합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, bool>
     */
    public function resolveCollectionAbilities(Request $request): array
    {
        $map = $this->abilityMap();
        if (empty($map)) {
            return [];
        }

        $user = $request->user();
        $abilities = [];

        foreach ($map as $key => $permission) {
            $abilities[$key] = PermissionHelper::check($permission, $user);
        }

        return $abilities;
    }

    /**
     * paginator 인 경우 표준 pagination 메타를 반환합니다.
     *
     * 무한스크롤 화면이 전체 개수(total)와 다음 페이지 존재 여부(has_more_pages)를
     * 정확히 판정할 수 있도록 노출합니다. 전체 조회(get) 등 paginator 가 아닌
     * 경우에는 빈 배열을 반환하므로 toArray 에서 array_merge 로 안전하게 합칠 수 있습니다.
     *
     * @return array<string, mixed> ['pagination' => [...]] 또는 빈 배열
     */
    protected function paginationMeta(): array
    {
        if (! method_exists($this->resource, 'currentPage')) {
            return [];
        }

        return [
            'pagination' => [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ],
        ];
    }
}
