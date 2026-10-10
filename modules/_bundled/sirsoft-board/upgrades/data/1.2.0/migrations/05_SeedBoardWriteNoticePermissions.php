<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 게시판마다 공지 등록 권한(sirsoft-board.{slug}.posts.write-notice)을 만들고 기본 역할을 붙입니다.
 *
 * 1.2.0 부터 사용자 글 작성·수정에서 공지 지정은 게시판 관리 권한 또는 공지 등록 권한 보유자만
 * 할 수 있다. 게시판별 권한 행은 게시판 생성·수정 시에만 만들어지므로, 기존 게시판에는 이 권한
 * 행이 없어 게시판 스텝처럼 관리 권한이 없는 운영 역할이 공지를 등록할 수 없게 된다. 이 스텝이
 * 그 공백을 메운다.
 *
 * 붙이는 역할은 게시판 생성 경로와 같다 — 기본값 admin 에 게시판 관리자(manager)·스텝(step)
 * 역할 자동 주입분을 더한다. 역할이 존재하지 않으면 그 역할만 건너뛴다.
 *
 * idempotent: 권한 행이 이미 있는 게시판은 건드리지 않는다. 재실행해도 role_permissions 건수가
 * 변하지 않고, 운영자가 이미 조정한 역할 구성을 덮지 않는다.
 * V-1 안전: Facades\DB/Schema 만 사용하고 Permission·Role·Board 모델을 참조하지 않는다.
 * name·description 은 다국어 JSON 컬럼이라 모델 캐스팅 대신 직접 인코딩한다(1.2.0 시점 값 동결).
 */
class SeedBoardWriteNoticePermissions implements DataMigration
{
    private const MODULE_IDENTIFIER = 'sirsoft-board';

    private const PERMISSION_KEY = 'posts.write-notice';

    /** config/board.php 의 1.2.0 시점 정의 동결본 */
    private const NAME = ['ko' => '공지 등록', 'en' => 'Create Notice Posts'];

    private const DESCRIPTION = ['ko' => '게시글을 공지로 등록/해제', 'en' => 'Set and unset posts as notice'];

    /** defaults.json 의 1.2.0 시점 기본 역할 동결본 */
    private const DEFAULT_ROLES = ['admin'];

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'SeedBoardWriteNoticePermissions';
    }

    /**
     * 기존 게시판마다 공지 등록 권한을 만들고 기본 역할을 붙입니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (['boards', 'permissions', 'roles', 'role_permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[board:1.2.0] {$table} 테이블 미존재 — 공지 등록 권한 시드 스킵");

                return;
            }
        }

        $created = 0;
        $attached = 0;

        foreach (DB::table('boards')->orderBy('id')->pluck('slug') as $slug) {
            $identifier = self::MODULE_IDENTIFIER.".{$slug}.".self::PERMISSION_KEY;

            if (DB::table('permissions')->where('identifier', $identifier)->exists()) {
                continue;
            }

            // 게시판 카테고리 권한이 없으면 권한 체계가 아직 만들어지지 않은 게시판이다 —
            // 다음 게시판 저장 때 전체 권한이 함께 생성되므로 여기서 부분 생성하지 않는다.
            $categoryId = DB::table('permissions')
                ->where('identifier', self::MODULE_IDENTIFIER.".{$slug}")
                ->value('id');

            if ($categoryId === null) {
                continue;
            }

            $attached += DB::transaction(function () use ($identifier, $categoryId, $slug) {
                $now = now();

                $permissionId = DB::table('permissions')->insertGetId([
                    'parent_id' => $categoryId,
                    'identifier' => $identifier,
                    'name' => json_encode(self::NAME, JSON_UNESCAPED_UNICODE),
                    'description' => json_encode(self::DESCRIPTION, JSON_UNESCAPED_UNICODE),
                    'extension_type' => 'module',
                    'extension_identifier' => self::MODULE_IDENTIFIER,
                    'type' => 'user',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $roleIdentifiers = array_merge(self::DEFAULT_ROLES, [
                    self::MODULE_IDENTIFIER.".{$slug}.manager",
                    self::MODULE_IDENTIFIER.".{$slug}.step",
                ]);

                $roleIds = DB::table('roles')->whereIn('identifier', $roleIdentifiers)->pluck('id');

                foreach ($roleIds as $roleId) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'granted_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $roleIds->count();
            });

            $created++;
        }

        $context->logger->info("[board:1.2.0] 공지 등록 권한 시드 완료: 게시판 {$created}개, 역할 연결 {$attached}건");
    }
}
