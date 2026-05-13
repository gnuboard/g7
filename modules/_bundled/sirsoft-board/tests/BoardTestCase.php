<?php

namespace Modules\Sirsoft\Board\Tests;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 단일 테이블을 사용하는 테스트 베이스 클래스
 *
 * PostAccessTest, PostGuestTest 등 게시판별 데이터(board_posts, board_comments)를
 * 다루어야 하는 테스트에서 사용합니다.
 *
 * 전략:
 * - RefreshDatabase를 오버라이드하여 비활성화 (TRUNCATE 없이 board_id 기반 삭제)
 * - setUp에서 해당 게시판의 데이터만 삭제하여 초기화
 * - ModuleTestCase의 마이그레이션은 유지
 */
abstract class BoardTestCase extends ModuleTestCase
{
    /**
     * RefreshDatabase 비활성화
     *
     * @return void
     */
    protected function refreshTestDatabase(): void
    {
        // 트랜잭션 대신 수동 DELETE 방식으로 데이터 정리
        // (DDL implicit commit으로 인해 DatabaseTransactions 사용 불가)
    }

    /**
     * 테스트용 게시판
     */
    protected Board $board;

    /**
     * 테스트 게시판 slug (하위 클래스에서 오버라이드 가능)
     */
    protected function getTestBoardSlug(): string
    {
        // 클래스명 기반으로 고유한 slug 생성 (예: PostGuestTest -> post-guest)
        $className = (new \ReflectionClass($this))->getShortName();

        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', str_replace('Test', '', $className)));
    }

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 테스트 게시판 및 데이터 설정
        $this->setupTestBoard();
    }

    /**
     * 테스트 게시판을 설정합니다.
     * 게시판이 이미 존재하면 해당 게시판의 데이터만 초기화합니다.
     */
    protected function setupTestBoard(): void
    {
        $slug = $this->getTestBoardSlug();

        // 게시판 생성 또는 조회 (updateOrCreate로 매번 기본 속성 보장)
        // firstOrCreate는 기존 레코드 속성을 갱신하지 않아 이전 테스트의
        // is_active=false 등이 잔류하는 문제가 있음
        $this->board = Board::updateOrCreate(
            ['slug' => $slug],
            $this->getDefaultBoardAttributes($slug)
        );

        // 해당 게시판의 게시글/댓글/첨부파일/신고 데이터만 삭제
        DB::table('boards_reports')->where('board_id', $this->board->id)->delete();
        DB::table('board_attachments')->where('board_id', $this->board->id)->delete();
        DB::table('board_comments')->where('board_id', $this->board->id)->delete();
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();

        // users 테이블도 초기화 (각 테스트마다 새로운 사용자 생성)
        $this->truncateUserTables();

        // PermissionMiddleware의 static guest role 캐시 초기화
        // DELETE로 roles를 삭제하면 AUTO_INCREMENT가 바뀌므로 캐시된 Role 객체가 stale 됨
        $this->resetPermissionMiddlewareCache();

        // 기본 비회원 권한 부여 (roles/permissions 시스템 사용)
        // truncateUserTables() 이후에 roles가 재생성되므로 여기서 호출
        $this->createDefaultRoles();
        $this->grantDefaultGuestPermissions();
    }

    /**
     * 사용자 관련 테이블 초기화
     */
    protected function truncateUserTables(): void
    {
        // DELETE(DML) 사용 — TRUNCATE(DDL)는 암묵적 커밋 + prepared statement 무효화 문제 유발
        // FK 의존성 역순으로 삭제하여 제약조건 위반 방지
        DB::table('role_permissions')->delete();
        DB::table('user_roles')->delete();
        DB::table('permissions')->delete();
        DB::table('roles')->delete();
        DB::table('users')->delete();
    }

    /**
     * 기본 게시판 속성을 반환합니다.
     * 하위 클래스에서 오버라이드하여 커스터마이징 가능합니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '테스트 게시판', 'en' => 'Test Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 비회원(guest) 역할에 게시판 권한을 정확히 설정합니다.
     *
     * 기존 guest 권한을 모두 제거하고, 지정한 권한만 부여합니다.
     * guest_permissions 컬럼 대신 role/permission 시스템을 사용합니다.
     *
     * @param  array  $permissionKeys  권한 키 배열 (예: ['posts.read', 'posts.write'])
     */
    protected function setGuestPermissions(array $permissionKeys): void
    {
        $guestRole = Role::where('identifier', 'guest')->first();
        if (! $guestRole) {
            return;
        }

        $slug = $this->board->slug;

        // 이 게시판의 기존 guest 권한 모두 해제
        $boardPermIds = $guestRole->permissions()
            ->where('identifier', 'like', "sirsoft-board.{$slug}.%")
            ->pluck('permissions.id');
        $guestRole->permissions()->detach($boardPermIds);

        // 새 권한 부여
        foreach ($permissionKeys as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $guestRole->permissions()->syncWithoutDetaching([$perm->id]);
        }

        // 미들웨어 캐시 초기화 (eager-loaded permissions 갱신)
        $this->resetPermissionMiddlewareCache();
    }

    /**
     * 기본 비회원 권한을 부여합니다.
     *
     * posts.read, posts.write, attachments.upload 권한을 guest 역할에 부여합니다.
     */
    protected function grantDefaultGuestPermissions(): void
    {
        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
    }

    /**
     * PermissionMiddleware의 static guest role 캐시를 초기화합니다.
     *
     * DELETE로 roles 테이블을 비우면 AUTO_INCREMENT가 변경되어
     * 캐시된 Role 객체의 ID와 실제 DB ID가 불일치합니다.
     */
    protected function resetPermissionMiddlewareCache(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Middleware\PermissionMiddleware::class);
        $property = $reflection->getProperty('guestRoleCache');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    /**
     * user role에 게시판 권한을 부여합니다.
     *
     * 인증된 사용자(member)가 게시판에 접근하려면 user 역할에
     * 해당 게시판 권한이 필요합니다.
     *
     * @param  array  $permissionKeys  권한 키 배열 (예: ['posts.read', 'posts.write'])
     */
    protected function grantUserRolePermissions(array $permissionKeys = ['posts.read', 'posts.write', 'attachments.upload']): void
    {
        $userRole = Role::where('identifier', 'user')->first();
        if (! $userRole) {
            return;
        }

        $slug = $this->board->slug;

        foreach ($permissionKeys as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $userRole->permissions()->syncWithoutDetaching([$perm->id]);
        }
    }

    /**
     * 게시판 설정을 업데이트합니다.
     *
     * @param  array  $settings  업데이트할 설정
     */
    protected function updateBoardSettings(array $settings): void
    {
        $this->board->update($settings);
        $this->board->refresh();
    }

    /**
     * 테스트 게시글을 직접 DB에 생성합니다.
     *
     * @param  array  $attributes  게시글 속성
     * @return int 생성된 게시글 ID
     */
    protected function createTestPost(array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'title' => '테스트 게시글',
            'content' => '테스트 내용입니다.',
            'user_id' => null,
            'author_name' => '테스트',
            'password' => null,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_posts')->insertGetId(array_merge($defaults, $attributes));
    }

    /**
     * 테스트 댓글을 직접 DB에 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  array  $attributes  댓글 속성
     * @return int 생성된 댓글 ID
     */
    protected function createTestComment(int $postId, array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'user_id' => null,
            'author_name' => '테스트',
            'content' => '테스트 댓글입니다.',
            'password' => null,
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_comments')->insertGetId(array_merge($defaults, $attributes));
    }
}
