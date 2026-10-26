<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;

/**
 * 내보내기 작업 목록 컬렉션 (관리 화면)
 *
 * 배송정책 목록과 같은 응답 모양 — `data`·`abilities`(컬렉션 단위)·`pagination` — 에 상단 경고(`warnings`)를 더한다.
 */
class ExportCollection extends BaseApiCollection
{
    /**
     * 상단 경고 코드 (sync·공개 디스크·대기 지연)
     *
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * 컬렉션 단위 권한 매핑
     *
     * @return array<string, string> 권한 맵
     */
    protected function abilityMap(): array
    {
        return [
            'can_delete' => 'sirsoft-ecommerce.exports.delete',
            'can_create' => 'sirsoft-ecommerce.exports.create',
        ];
    }

    /**
     * 상단 경고를 싣습니다.
     *
     * @param  array<int, string>  $warnings  경고 코드
     * @return self 체이닝
     */
    public function withWarnings(array $warnings): self
    {
        $this->warnings = $warnings;

        return $this;
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 목록 응답
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->mapWithRowNumber(fn ($export) => (new ExportResource($export))->toArray($request)),
            'abilities' => $this->resolveCollectionAbilities($request),
            // 대기·진행중 행이 있으면 화면이 몇 초마다 다시 받는다(끝나면 멈춘다) — 판정을 서버 값 하나로 둔다
            'has_active' => $this->collection->contains(fn ($export) => $export->status->isActive()),
            'warnings' => array_map(fn (string $code) => [
                'code' => $code,
                'message' => __('sirsoft-ecommerce::export.warnings.'.$code.'.message'),
            ], $this->warnings),
            ...$this->paginationMeta(),
        ];
    }
}
