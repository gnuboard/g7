<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Http\Controllers\Admin;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 업로드 관리 목록의 「올린 사람」 이름 (#490)
 *
 * 관리자 화면의 회원 이름은 관리자 영역(`core:admin`) 표시 이름이다. 실명은 회원 조회 권한(`core.users.read`)이 있는 관리자에게,
 * 실명이 표시 이름과 다를 때만 별도 키 `uploader_real_name` 으로 실린다. 설정을 바꾸지 않은 사이트의 응답은 수정 전과 같다.
 */
class UploaderDisplayNameTest extends PluginTestCase
{
    private const INDEX_URL = '/api/plugins/sirsoft-ckeditor5/admin/uploads';

    /**
     * 관리자 영역을 닉네임으로 고정하면 올린 사람 이름이 닉네임이 되고, 회원 조회 권한이 있는 관리자에게만 실명 키가 실린다.
     *
     * @scenario case=nickname_admin_area_with_member_read
     *
     * @effects uploader_name_follows_admin_area, uploader_real_name_added_for_member_readers
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function nickname_admin_area_shows_nickname_and_real_name_for_member_readers(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $this->makeUpload($this->member('홍길동', '길동이'));

        $row = $this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::INDEX_URL)
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame('길동이', $row['uploader_name']);
        $this->assertSame('홍길동', $row['uploader_real_name']);
    }

    /**
     * 회원 조회 권한이 없는 관리자에게는 표시 이름만 가고 실명 키 자체가 없다.
     *
     * @scenario case=nickname_admin_area_without_member_read
     *
     * @effects uploader_name_follows_admin_area, uploader_real_name_hidden_without_member_read
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function real_name_key_is_absent_without_member_read_permission(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $this->makeUpload($this->member('홍길동', '길동이'));

        $row = $this->actingAs($this->viewer(withMemberRead: false))
            ->getJson(self::INDEX_URL)
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame('길동이', $row['uploader_name']);
        $this->assertArrayNotHasKey('uploader_real_name', $row);
    }

    /**
     * 설정을 바꾸지 않은 사이트는 수정 전과 같이 올린 사람의 이름을 싣고, 표시 이름과 실명이 같아 실명 키를 더하지 않는다.
     * 올린 사람이 없는 행(비회원 업로드, 지워진 회원)은 이름이 null 이다.
     *
     * @scenario case=default_site_unchanged
     *
     * @effects uploader_name_unchanged_on_default_site, uploader_real_name_omitted_when_same, uploader_name_null_without_uploader
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function default_site_keeps_member_name_without_real_name_key(): void
    {
        $this->makeUpload($this->member('홍길동', '길동이'));
        $this->makeUpload(null);

        $rows = collect($this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::INDEX_URL.'?sort_by=original_name&sort_order=asc')
            ->assertOk()
            ->json('data.data'));

        $withUploader = $rows->firstWhere('uploaded_by', '!==', null);
        $this->assertSame('홍길동', $withUploader['uploader_name']);
        $this->assertArrayNotHasKey('uploader_real_name', $withUploader);

        $withoutUploader = $rows->firstWhere('uploaded_by', null);
        $this->assertNull($withoutUploader['uploader_name']);
        $this->assertArrayNotHasKey('uploader_real_name', $withoutUploader);
    }

    /**
     * 참조 상태 필터(스캔 창 경로)도 같은 규칙으로 이름을 싣는다.
     *
     * @scenario case=reference_filter_path
     *
     * @effects uploader_name_follows_admin_area_in_reference_filter
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reference_filter_path_uses_same_name_rule(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $this->makeUpload($this->member('홍길동', '길동이'));

        $row = $this->actingAs($this->viewer(withMemberRead: true))
            ->getJson(self::INDEX_URL.'?referenced=unreferenced')
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame('길동이', $row['uploader_name']);
        $this->assertSame('홍길동', $row['uploader_real_name']);
    }

    /**
     * 영역 설정을 바꿉니다.
     *
     * @param  array<string, array<string, mixed>>  $areas  영역 키 => 설정
     * @return void 반환값 없음
     */
    private function areas(array $areas): void
    {
        config(['g7_settings.core.member.display_name_areas' => $areas + [
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
        ]]);
        app(DisplayNameAreaRegistry::class)->flush();
        app(NicknameRequirement::class)->flush();
    }

    /**
     * 이름과 닉네임을 정한 회원을 만듭니다.
     *
     * @param  string  $name  이름
     * @param  string  $nickname  닉네임
     * @return User 회원
     */
    private function member(string $name, string $nickname): User
    {
        return User::factory()->create(['name' => $name, 'nickname' => $nickname]);
    }

    /**
     * 업로드 목록을 볼 수 있는 관리자를 만듭니다.
     *
     * @param  bool  $withMemberRead  회원 조회 권한(`core.users.read`)을 줄지
     * @return User 관리자
     */
    private function viewer(bool $withMemberRead): User
    {
        $role = Role::query()->create([
            'identifier' => 'ckeditor5-dn-'.Str::lower(Str::random(8)),
            'name' => ['ko' => '시험 역할', 'en' => 'Test Role'],
            'description' => ['ko' => '', 'en' => ''],
        ]);

        $identifiers = ['admin.access', 'sirsoft-ckeditor5.uploads.read'];
        if ($withMemberRead) {
            $identifiers[] = 'core.users.read';
        }

        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create(['name' => '보는관리자', 'nickname' => '보는이']);
        $user->roles()->attach($role->id);
        $user->flushPermissionCaches();

        return $user;
    }

    /**
     * 업로드 기록을 만듭니다 (파일 없이 기록만 — 목록 응답만 본다).
     *
     * @param  User|null  $uploader  올린 사람
     * @return Ckeditor5ImageUpload 기록
     */
    private function makeUpload(?User $uploader): Ckeditor5ImageUpload
    {
        $filename = ($uploader === null ? 'b-' : 'a-').uniqid().'.png';

        return Ckeditor5ImageUpload::create([
            'hash' => Str::lower(Str::random(12)),
            'original_name' => $filename,
            'file_path' => "images/2026/11/12/{$filename}",
            'storage_disk' => 'plugins',
            'file_size' => 5,
            'mime_type' => 'image/png',
            'uploaded_by' => $uploader?->id,
        ]);
    }
}
