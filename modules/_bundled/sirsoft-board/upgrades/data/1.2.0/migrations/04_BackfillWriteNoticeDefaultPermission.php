<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_2_0\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 저장된 게시판 기본 권한 설정에 공지 등록 권한(posts.write-notice) 기본값을 기입합니다.
 *
 * 1.2.0 은 게시판 사용자 권한에 posts.write-notice 를 신설한다. 신규 설치는
 * config/settings/defaults.json 이 기본값을 갖지만, 기존 설치본은 basic_defaults.json 이
 * default_board_permissions 를 통째로 저장하고 있어 defaults 병합(카테고리 1단계 array_merge)이
 * 새 키를 채우지 못한다. 그대로 두면 업그레이드 뒤 새로 만드는 게시판의 공지 등록 권한에 아무
 * 역할도 붙지 않고, 게시판 역할 자동 주입(manager/step)도 기본값에 키가 있어야 동작하므로 스텝은
 * 공지를 등록할 수 없게 된다.
 *
 * 이미 키가 있으면(운영자가 조정했거나 재실행) 건드리지 않는다(idempotent). 파일이 없으면 신규
 * 설치이므로 defaults.json 이 값을 갖고 있어 스킵한다.
 *
 * V-1 안전: Illuminate\Support\Facades\File + 코어 헬퍼만 사용하고 모듈 클래스에 의존하지 않는다.
 */
class BackfillWriteNoticeDefaultPermission implements DataMigration
{
    private const MODULE_IDENTIFIER = 'sirsoft-board';

    private const PERMISSION_KEY = 'posts.write-notice';

    /** 1.2.0 시점 기본 역할 — defaults.json 과 동일 값 동결본 */
    private const DEFAULT_ROLES = ['admin'];

    /** 새 키를 끼워 넣을 기준 키 (권한 설정 화면 정렬과 맞춘다) */
    private const INSERT_AFTER = 'posts.read-secret';

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillWriteNoticeDefaultPermission';
    }

    /**
     * 저장된 기본 권한 설정에 공지 등록 권한 기본값을 기입합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = ExtensionStoragePath::module(self::MODULE_IDENTIFIER, 'settings').'/basic_defaults.json';

        if (! File::exists($path)) {
            $context->logger->info('[board:1.2.0] basic_defaults.json 미존재 — 공지 등록 기본 권한 백필 스킵');

            return;
        }

        $settings = json_decode((string) File::get($path), true);

        if (! is_array($settings) || ! isset($settings['default_board_permissions']) || ! is_array($settings['default_board_permissions'])) {
            $context->logger->info('[board:1.2.0] default_board_permissions 미저장 — 공지 등록 기본 권한 백필 스킵');

            return;
        }

        $permissions = $settings['default_board_permissions'];

        if (array_key_exists(self::PERMISSION_KEY, $permissions)) {
            $context->logger->info('[board:1.2.0] 공지 등록 기본 권한 이미 기입됨 — 스킵 (idempotent)');

            return;
        }

        $settings['default_board_permissions'] = $this->insertAfter($permissions);

        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // sudo 코어 업데이트 경로에서 root 로 실행되면 파일이 root 소유로 남는다 — 부모 소유권 상속 (#651)
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[board:1.2.0] 공지 등록 기본 권한 백필 완료 ('.self::PERMISSION_KEY.' → '.implode(',', self::DEFAULT_ROLES).')');
    }

    /**
     * 기준 키 바로 뒤에 새 권한 키를 끼워 넣습니다. 기준 키가 없으면 맨 뒤에 붙입니다.
     *
     * @param  array<string, mixed>  $permissions  저장된 기본 권한 맵
     * @return array<string, mixed> 새 키가 추가된 기본 권한 맵
     */
    private function insertAfter(array $permissions): array
    {
        if (! array_key_exists(self::INSERT_AFTER, $permissions)) {
            $permissions[self::PERMISSION_KEY] = self::DEFAULT_ROLES;

            return $permissions;
        }

        $result = [];

        foreach ($permissions as $key => $roles) {
            $result[$key] = $roles;

            if ($key === self::INSERT_AFTER) {
                $result[self::PERMISSION_KEY] = self::DEFAULT_ROLES;
            }
        }

        return $result;
    }
}
