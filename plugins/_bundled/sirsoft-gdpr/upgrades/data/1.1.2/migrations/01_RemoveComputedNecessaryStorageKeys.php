<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_2\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 설정 파일에 굳은 계산값 키(`necessary_storage_locked`·`necessary_storage_provided`)를 지웁니다.
 *
 * 배경:
 *
 * 이 플러그인의 설정 기본값에는 요청마다 계산하는 두 목록이 들어 있다 — 잠금 목록(세션 쿠키 이름 등으로 계산)과
 * 제공 목록(확장이 계약 훅으로 제공한 키). 설정 기본값 절이 없어 설치 때 코어가 기본값 전체를 설정 파일에 써서,
 * 그 순간의 두 목록이 파일에 굳었다. 읽을 때는 저장값이 기본값을 이기므로, 그 뒤 설치된 확장이 제공한 키·바뀐
 * 쿠키 이름이 브라우저 판정(`G7Config.plugins['sirsoft-gdpr']`)에 반영되지 않았다. 저장 요청은 두 키를 걸러
 * 운영자가 고칠 방법도 없었다(초기화만 지움). 1.1.2 부터 두 키는 계산값 키로 선언돼 코어가 저장하지 않고 늘 실시간
 * 값을 쓴다 — 이미 굳은 사본만 지운다.
 *
 * 다른 설정 키는 건드리지 않는다. 멱등: 설정 파일이 없거나 JSON 이 깨졌거나 두 키가 없으면 skip 한다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + 코어 경로 해석기 + FilePermissionHelper 만 사용 (1.1.0/04 와 같은 파일 처리)
 *   - Service / Manager / Repository 컨테이너 해석 없음 (플러그인 클래스도 해석하지 않는다)
 */
final class RemoveComputedNecessaryStorageKeys implements DataMigration
{
    /**
     * 지울 계산값 키 (1.1.2 시점 값 동결).
     *
     * @var array<int, string>
     */
    private const COMPUTED_KEYS = ['necessary_storage_locked', 'necessary_storage_provided'];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'RemoveComputedNecessaryStorageKeys';
    }

    /**
     * 설정 파일에서 계산값 키를 지웁니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-gdpr] 설정 파일 없음 — 계산값 키 정리 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-gdpr] 설정 JSON 형식 비정상 — 계산값 키 정리 skip');

            return;
        }

        $present = array_values(array_filter(self::COMPUTED_KEYS, static fn (string $key) => array_key_exists($key, $settings)));

        if ($present === []) {
            $context->logger->info('[sirsoft-gdpr] 설정 파일에 계산값 키 없음 — 변경 없음');

            return;
        }

        foreach ($present as $key) {
            unset($settings[$key]);
        }

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-gdpr] 설정 파일에 굳은 계산값 키를 지웠습니다 (이후 늘 실시간 값)', [
            'removed' => $present,
        ]);
    }
}
