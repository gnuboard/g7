<?php

namespace App\Extension\Storage;

use App\Contracts\Extension\StorageInterface;

/**
 * 행에 기록된 디스크로 저장소를 고르는 도우미 (#887)
 *
 * 업로드 행은 저장한 디스크(`disk`)를 함께 기록한다. 운영자가 기본 디스크를 바꾼 뒤에도 옛 행은 그 행이 실제로 저장된
 * 디스크에서 읽고·복사하고·지워야 한다. 확장 저장소(`StorageInterface`)는 디스크만 바꾼 사본(`withDisk`)을 만들 수 있으므로
 * 이 도우미가 그 판정을 한 곳에서 한다.
 *
 * - 행 디스크가 비었거나 기본 저장소와 같으면 기본 저장소.
 * - 설정(`filesystems.disks`)에 없는 디스크면 기본 저장소로 물러난다 — 그 디스크를 제공하던 확장이 꺼지면 설정에서 사라지는데,
 *   미등록 디스크로 `withDisk` 를 만들면 이후 읽기·응답·삭제가 예외를 던져 서빙·삭제가 500 이 된다(파일은 닿을 수 없으므로
 *   404 가 정상적인 저하다).
 * - 그 밖에는 `withDisk($disk)`.
 *
 * @since 7.1.5
 */
final class RowDiskStorage
{
    /**
     * 행 디스크 기준 저장소를 돌려줍니다.
     *
     * @param  StorageInterface  $default  기본(주입) 저장소
     * @param  string|null  $disk  행의 disk 값
     * @return StorageInterface 그 행이 저장된 디스크의 저장소
     */
    public static function resolve(StorageInterface $default, ?string $disk): StorageInterface
    {
        if ($disk === null || $disk === '' || $disk === $default->getDisk()) {
            return $default;
        }

        if (config("filesystems.disks.{$disk}") === null) {
            return $default;
        }

        return $default->withDisk($disk);
    }
}
