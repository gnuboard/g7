<?php

namespace App\Services\Concerns;

use App\Contracts\Extension\StorageInterface;

/**
 * 행 `disk` 기준 스토리지 해석 (코어 서비스 공용)
 *
 * 디스크 전환 이후에도 전환 이전 행(구 disk)의 서빙·삭제·이동이 그 행의 실제 저장
 * 위치를 향하도록, 행에 기록된 disk 의 스토리지를 돌려준다. 사용 서비스는
 * `protected StorageInterface $storage` 주입을 전제로 한다.
 *
 * 왜 코어에도 두는가 (12차 G3 라운드 B12-1):
 *   같은 판정이 번들 확장 넷(board·page·ecommerce·ckeditor5)에는 있고 **코어 두 서비스
 *   에만 없었다.** 코어는 행 `disk` 를 존재 검증 없이 `withDisk()` 에 넘겼는데,
 *   `withDisk()` 는 clone 후 대입만 할 뿐 검증하지 않으므로 예외는 그 뒤 `exists()`·
 *   `response()`·`delete()` 가 디스크를 해석하는 순간 터진다. 두 서비스가 소비하는
 *   라우트는 **무인증 공개 서빙**이라, 고아 disk 행 하나로 500 + 스택 로그가 난다.
 *
 *   고아 disk 는 운영자가 만들지 않아도 생긴다 — 그 디스크를 제공하던 플러그인을
 *   비활성화하거나 `FILESYSTEM_DISK` 를 바꾸면 config 에서 사라지고, 행은 남는다.
 *
 *   폴백 대상은 주입 스토리지다. 그 디스크에 파일이 없으므로 결과는 **404** 이고,
 *   그것이 「저장 위치를 잃은 첨부」의 정상 degradation 이다 — 500 은 아니다.
 *   같은 존재 검증을 `AbstractModule::resolvePublicAssetDisk()` 도 쓴다.
 *
 * 사본을 두지 않는다:
 *   판정이 여섯 곳에 흩어지면 한 곳만 고쳐질 때 같은 파일이 통로마다 다른 결과를
 *   받는다. 실제로 그 상태였다 — 넷은 막고 둘은 안 막았다. 확장 넷은 자기 네임스페이스의
 *   사본을 유지하지만(코어 하한을 올리지 않기 위해) 그 여섯의 판정 일치는
 *   `tests/Unit/Services/RowStorageOrphanDiskParityTest.php` 가 잠근다.
 */
trait ResolvesRowStorage
{
    /**
     * 행에 기록된 disk 기준 스토리지를 반환합니다.
     *
     * @param  string|null  $disk  행의 disk 컬럼 값 (비어 있거나 미등록이면 주입 스토리지)
     * @return StorageInterface 행 disk 의 스토리지
     */
    protected function storageForRow(?string $disk): StorageInterface
    {
        if ($disk === null || $disk === '' || $disk === $this->storage->getDisk()) {
            return $this->storage;
        }

        if (config("filesystems.disks.{$disk}") === null) {
            return $this->storage;
        }

        return $this->storage->withDisk($disk);
    }
}
