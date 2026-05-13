<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;

/**
 * 검색 프리셋 Repository 인터페이스
 */
interface SearchPresetRepositoryInterface
{
    /**
     * 특정 사용자의 특정 화면 프리셋 목록 조회
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $targetScreen  대상 화면 (예: 'products')
     * @return Collection
     */
    public function getByUserAndScreen(int $userId, string $targetScreen): Collection;

    /**
     * 이름으로 프리셋 조회
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $targetScreen  대상 화면
     * @param  string  $name  프리셋 이름
     * @return SearchPreset|null
     */
    public function findByName(int $userId, string $targetScreen, string $name): ?SearchPreset;

    /**
     * 프리셋 생성
     *
     * @param  array  $data  프리셋 데이터
     * @return SearchPreset
     */
    public function create(array $data): SearchPreset;

    /**
     * 프리셋 수정
     *
     * @param  SearchPreset  $preset  프리셋 모델
     * @param  array  $data  수정 데이터
     * @return SearchPreset
     */
    public function update(SearchPreset $preset, array $data): SearchPreset;

    /**
     * 프리셋 삭제
     *
     * @param  SearchPreset  $preset  프리셋 모델
     * @return bool
     */
    public function delete(SearchPreset $preset): bool;
}
