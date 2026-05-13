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
     * @param Request $request HTTP 요청 객체
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
}
