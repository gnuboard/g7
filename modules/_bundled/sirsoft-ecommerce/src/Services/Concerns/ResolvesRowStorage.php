<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use App\Contracts\Extension\StorageInterface;

/**
 * 이미지 행 disk 기준 스토리지 해석 trait (서비스 공용)
 *
 * 디스크 전환 이후에도 전환 이전 행(구 disk)의 서빙/삭제/이동이 그 행의
 * 실제 저장 위치를 향하도록, 행에 기록된 disk 의 스토리지를 돌려줍니다.
 * 사용 서비스는 `protected StorageInterface $storage` 주입을 전제로 합니다.
 */
trait ResolvesRowStorage
{
    /**
     * 이미지 행에 기록된 disk 기준 스토리지를 반환합니다.
     *
     * @param  string|null  $disk  이미지 행의 disk 컬럼 값 (비어 있으면 주입 스토리지)
     * @return StorageInterface 행 disk 의 스토리지
     */
    protected function storageForRow(?string $disk): StorageInterface
    {
        if ($disk === null || $disk === '' || $disk === $this->storage->getDisk()) {
            return $this->storage;
        }

        // 고아 disk 방어 — 디스크를 제공하던 플러그인이 비활성화되면 그 disk 가
        // config 에서 사라지는데, 미등록 disk 로 withDisk 를 만들면 이후 exists/response/
        // delete 가 InvalidArgumentException 을 던져 서빙·삭제가 500 이 된다.
        // AbstractModule::resolvePublicAssetDisk() 와 동일한 존재 검증으로 주입 스토리지에
        // 폴백한다 (파일은 도달 불가이므로 404 가 정상 degradation).
        if (config("filesystems.disks.{$disk}") === null) {
            return $this->storage;
        }

        return $this->storage->withDisk($disk);
    }
}
