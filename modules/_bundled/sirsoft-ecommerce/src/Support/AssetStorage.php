<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\ModuleStorageDriver;

/**
 * 자산 행 disk 기준 스토리지 드라이버 팩토리
 *
 * 자산 행마다 기록된 disk 가 다를 수 있어(디스크 전환 후 혼재 운용),
 * 행 disk 별 ModuleStorageDriver 인스턴스를 memoize 하여 제공합니다.
 * 모델 accessor(HasDirectAssetUrl)가 직렬화 루프에서 반복 호출하므로
 * 드라이버 생성 비용을 요청 단위 1회로 고정합니다.
 */
class AssetStorage
{
    /**
     * 디스크명 키 드라이버 캐시
     *
     * @var array<string, StorageInterface>
     */
    private static array $drivers = [];

    /**
     * 지정 디스크의 ecommerce 모듈 스토리지 드라이버를 반환합니다.
     *
     * @param  string  $disk  디스크 이름 (자산 행의 disk 컬럼 값)
     * @return StorageInterface 해당 디스크의 스토리지 드라이버
     */
    public static function forDisk(string $disk): StorageInterface
    {
        return self::$drivers[$disk] ??= new ModuleStorageDriver('sirsoft-ecommerce', $disk);
    }

    /**
     * memoize 캐시를 비웁니다 (테스트 격리용).
     */
    public static function flush(): void
    {
        self::$drivers = [];
    }
}
