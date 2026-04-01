<?php

namespace App\Repositories;

use App\Contracts\Repositories\SystemConfigRepositoryInterface;
use App\Models\SystemConfig;
use Illuminate\Database\Eloquent\Collection;

class SystemConfigRepository implements SystemConfigRepositoryInterface
{
    /**
     * 모든 시스템 설정을 조회합니다.
     *
     * @return Collection 시스템 설정 컬렉션
     */
    public function getAll(): Collection
    {
        return SystemConfig::all();
    }

    /**
     * 키로 시스템 설정을 찾습니다.
     *
     * @param string $key 설정 키
     * @return SystemConfig|null 찾은 설정 모델 또는 null
     */
    public function findByKey(string $key): ?SystemConfig
    {
        return SystemConfig::where('key', $key)->first();
    }

    /**
     * 새로운 시스템 설정을 생성합니다.
     *
     * @param array $data 설정 생성 데이터
     * @return SystemConfig 생성된 설정 모델
     */
    public function create(array $data): SystemConfig
    {
        return SystemConfig::create($data);
    }

    /**
     * 기존 시스템 설정을 업데이트합니다.
     *
     * @param SystemConfig $config 업데이트할 설정 모델
     * @param array $data 업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(SystemConfig $config, array $data): bool
    {
        return $config->update($data);
    }

    /**
     * 시스템 설정을 삭제합니다.
     *
     * @param SystemConfig $config 삭제할 설정 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(SystemConfig $config): bool
    {
        return $config->delete();
    }

    /**
     * 키로 시스템 설정을 삭제합니다.
     *
     * @param string $key 삭제할 설정 키
     * @return bool 삭제 성공 여부
     */
    public function deleteByKey(string $key): bool
    {
        return SystemConfig::where('key', $key)->delete() > 0;
    }

    /**
     * 주어진 키의 시스템 설정 존재 여부를 확인합니다.
     *
     * @param string $key 확인할 설정 키
     * @return bool 설정 존재 여부
     */
    public function exists(string $key): bool
    {
        return SystemConfig::where('key', $key)->exists();
    }
}
