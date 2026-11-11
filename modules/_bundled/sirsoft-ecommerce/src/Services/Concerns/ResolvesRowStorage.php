<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use App\Contracts\Extension\StorageInterface;
use App\Support\PublicAssetDisk;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;

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
     * 올릴 이미지의 저장 확장자에 맞는 스토리지를 반환합니다.
     *
     * 주입 스토리지가 공개 자산 디스크여도, 확장자가 「공개 디스크로 내보낼 확장자」 밖이면 모듈 기본
     * 디스크의 스토리지를 돌려준다 — 그 파일은 서버를 거쳐 내려간다. 행의 disk 는 돌려받은 스토리지의
     * `getDisk()` 로 기록한다.
     *
     * @param  string  $extension  저장 확장자 (내용 기준)
     * @return StorageInterface 이 이미지를 저장할 스토리지
     */
    protected function storageForUpload(string $extension): StorageInterface
    {
        return PublicAssetDisk::storageForExtension($this->storage, $extension, AssetStorage::defaultDisk());
    }

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
