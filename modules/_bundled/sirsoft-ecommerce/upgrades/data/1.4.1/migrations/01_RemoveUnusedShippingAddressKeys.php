<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_4_1\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 배송 설정 저장값에서 쓰이지 않는 키 2개를 지웁니다. (#952)
 *
 * `shipping.address_validation_enabled`·`shipping.address_api_provider` 는 화면도 읽는 코드도 없이 저장만 되던 잔재다.
 * idempotent: 키가 없으면 아무것도 하지 않는다. 파일이 없거나 읽을 수 없으면 건너뛴다.
 * V-1 안전: Illuminate\Support\Facades\File + 코어 헬퍼만 사용 — 모듈 클래스를 참조하지 않는다.
 */
class RemoveUnusedShippingAddressKeys implements DataMigration
{
    /** 모듈 식별자 */
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    /** 1.4.1 시점 제거 대상 키 동결본 */
    private const KEYS = ['address_validation_enabled', 'address_api_provider'];

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RemoveUnusedShippingAddressKeys';
    }

    /**
     * 배송 설정 파일에서 대상 키를 지웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = ExtensionStoragePath::module(self::MODULE_IDENTIFIER, 'settings').'/shipping.json';

        if (! File::exists($path)) {
            $context->logger->info('[ecommerce:1.4.1] shipping.json 미존재 — 배송 키 정리 건너뜀');

            return;
        }

        $settings = json_decode((string) File::get($path), true);
        if (! is_array($settings)) {
            $context->logger->warning('[ecommerce:1.4.1] shipping.json 파싱 불가 — 배송 키 정리 건너뜀');

            return;
        }

        $present = array_values(array_filter(self::KEYS, fn (string $key) => array_key_exists($key, $settings)));
        if ($present === []) {
            $context->logger->info('[ecommerce:1.4.1] 배송 설정에 정리할 키 없음 — 이미 정리됨');

            return;
        }

        foreach ($present as $key) {
            unset($settings[$key]);
        }

        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // sudo 코어 업데이트 경로에서 root 로 실행되면 새로 쓴 파일이 root 소유로 남는다 — 부모 소유권 상속 (#651)
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[ecommerce:1.4.1] 쓰이지 않는 배송 설정 키 정리: '.implode(', ', $present));
    }
}
