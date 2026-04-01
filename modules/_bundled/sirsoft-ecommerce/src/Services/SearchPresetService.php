<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Exceptions\UnauthorizedPresetAccessException;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SearchPresetRepositoryInterface;

/**
 * 검색 프리셋 서비스
 */
class SearchPresetService
{
    public function __construct(
        protected SearchPresetRepositoryInterface $repository
    ) {}

    /**
     * 현재 사용자의 프리셋 목록 조회
     *
     * @param  string  $targetScreen  대상 화면 (예: 'products')
     * @return Collection
     */
    public function getPresets(string $targetScreen): Collection
    {
        return $this->repository->getByUserAndScreen(Auth::id(), $targetScreen);
    }

    /**
     * 프리셋 생성
     *
     * 검증은 FormRequest에서 수행됨 (중복 이름 체크 포함)
     *
     * @param  string  $targetScreen  대상 화면
     * @param  string  $name  프리셋 이름
     * @param  array  $conditions  검색 조건
     * @return SearchPreset
     */
    public function create(string $targetScreen, string $name, array $conditions): SearchPreset
    {
        $data = [
            'user_id' => Auth::id(),
            'target_screen' => $targetScreen,
            'preset_name' => $name,
            'conditions' => $conditions,
        ];

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.preset.before_create', $data);

        // Filter 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.preset.filter_create_data', $data);

        $preset = $this->repository->create($data);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.preset.after_create', $preset, $data);

        return $preset;
    }

    /**
     * 프리셋 수정
     *
     * @param  SearchPreset  $preset  프리셋 모델
     * @param  array  $data  수정 데이터
     * @return SearchPreset
     *
     * @throws UnauthorizedPresetAccessException
     */
    public function update(SearchPreset $preset, array $data): SearchPreset
    {
        $this->ensureOwnership($preset);

        // API 필드명 → DB 컬럼명 변환
        if (isset($data['name'])) {
            $data['preset_name'] = $data['name'];
            unset($data['name']);
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.preset.before_update', $preset, $data);

        // Filter 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.preset.filter_update_data', $data, $preset);

        $result = $this->repository->update($preset, $data);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.preset.after_update', $result, $data);

        return $result;
    }

    /**
     * 프리셋 삭제
     *
     * @param  SearchPreset  $preset  프리셋 모델
     * @return bool
     *
     * @throws UnauthorizedPresetAccessException
     */
    public function delete(SearchPreset $preset): bool
    {
        $this->ensureOwnership($preset);

        $presetId = $preset->id;

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.preset.before_delete', $preset);

        $result = $this->repository->delete($preset);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.preset.after_delete', $presetId);

        return $result;
    }

    /**
     * 프리셋 소유권 확인
     *
     * @param  SearchPreset  $preset  프리셋 모델
     * @return void
     *
     * @throws UnauthorizedPresetAccessException
     */
    private function ensureOwnership(SearchPreset $preset): void
    {
        if ($preset->user_id !== Auth::id()) {
            throw new UnauthorizedPresetAccessException($preset->id);
        }
    }
}
