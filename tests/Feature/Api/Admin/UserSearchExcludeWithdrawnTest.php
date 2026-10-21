<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\UserStatus;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 회원 검색(`GET /api/admin/users/search`)의 `exclude_withdrawn` 옵션을 검증합니다.
 *
 * 2026-10-18 관찰: 관리자 알림 문안 편집 창의 「특정 회원」 수신자 검색에 탈퇴 회원이 나와 고를 수 있었다.
 * 같은 창의 사본(게시판·이커머스·GDPR·수출입은행)도 같다. 탈퇴 회원은 연락처가 익명화되고 바깥 채널 발송이 막혀(`NotificationWithdrawnRecipientPolicy`) 골라도 받지 못한다 —
 * 운영자는 보낸 줄 알지만 아무에게도 가지 않는다. 같은 API 를 쓰는 활동 로그·주문·쿠폰·배송지 목록의 「회원」 필터는
 * 탈퇴 회원의 기록도 찾아야 하므로 기본 동작은 그대로 두고, 고르는 자리만 옵션으로 좁힌다.
 *
 * @scenario-manifest tests/scenarios/user-search-exclude-withdrawn.yaml
 */
class UserSearchExcludeWithdrawnTest extends TestCase
{
    use RefreshDatabase;

    /** 알림 수신자 창 사본 수 하한 — 코어·게시판·이커머스·GDPR·수출입은행(2026-10-18). 탐색이 어긋나 모집단이 비면 조용히 통과한다. */
    private const MIN_RECIPIENT_PICKER_LAYOUTS = 5;

    private string $token;

    /**
     * 회원 조회 권한을 가진 운영자와 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $admin = User::factory()->create(['name' => 'Operator', 'email' => 'operator@example.test']);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.read'],
            [
                'name' => json_encode(['ko' => 'core.users.read', 'en' => 'core.users.read']),
                'description' => json_encode(['ko' => '회원 조회 권한', 'en' => 'Read users']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ],
        );
        $role = Role::factory()->create();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $admin->roles()->syncWithoutDetaching([$role->id]);

        $this->token = $admin->fresh()->createToken('test-token')->plainTextToken;
    }

    /**
     * `exclude_withdrawn=1` 이면 탈퇴 회원은 빠지고, 탈퇴가 아닌 회원(차단·비활성 포함)은 그대로 나온다.
     *
     * 수정 전: 옵션을 무시해 탈퇴 회원이 결과에 섞였다.
     *
     * @scenario mode=exclude_withdrawn
     *
     * @effects withdrawn_members_excluded_from_recipient_search
     *
     * @return void 반환값 없음
     */
    public function test_exclude_withdrawn_drops_withdrawn_members_only(): void
    {
        $uuids = $this->seedMembers();

        $found = $this->withToken($this->token)
            ->getJson('/api/admin/users/search?keyword=G26RCPT&exclude_withdrawn=1')
            ->assertOk()
            ->json('data.*.uuid');

        $this->assertNotContains($uuids['withdrawn'], $found, '알림 수신자 검색에 탈퇴 회원이 나왔다');
        foreach (['active', 'blocked', 'inactive'] as $kind) {
            $this->assertContains($uuids[$kind], $found, "탈퇴가 아닌 회원({$kind})까지 빠졌다");
        }
    }

    /**
     * 옵션이 없으면 종전처럼 탈퇴 회원도 찾는다 — 활동 로그·주문 목록의 「회원」 필터가 탈퇴 회원의 기록을 찾는 자리다.
     *
     * @scenario mode=default
     *
     * @effects default_search_still_finds_withdrawn_members
     *
     * @return void 반환값 없음
     */
    public function test_default_search_still_finds_withdrawn_members(): void
    {
        $uuids = $this->seedMembers();

        $found = $this->withToken($this->token)
            ->getJson('/api/admin/users/search?keyword=G26RCPT')
            ->assertOk()
            ->json('data.*.uuid');

        $this->assertContains($uuids['withdrawn'], $found, '필터용 기본 검색에서 탈퇴 회원이 사라졌다 — 탈퇴 회원의 기록을 찾을 수 없다');
        $this->assertCount(4, $found);
    }

    /**
     * 옵션 값은 불리언만 받는다.
     *
     * @scenario mode=invalid_option
     *
     * @effects exclude_withdrawn_option_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_exclude_withdrawn_rejects_non_boolean(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/admin/users/search?keyword=G26RCPT&exclude_withdrawn=maybe')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['exclude_withdrawn']);
    }

    /**
     * 검증 오류 문구는 필드 키가 아니라 칸 표시 이름을 싣는다 (`exclude_withdrawn`·`uuid`).
     *
     * @scenario mode=invalid_option
     *
     * @effects search_validation_messages_use_field_labels
     *
     * @return void 반환값 없음
     */
    public function test_validation_messages_use_field_labels(): void
    {
        app()->setLocale('ko');

        $flag = $this->withToken($this->token)
            ->getJson('/api/admin/users/search?keyword=G26RCPT&exclude_withdrawn=maybe')
            ->assertStatus(422)
            ->json('errors.exclude_withdrawn.0');
        $this->assertStringContainsString(__('validation.attributes.exclude_withdrawn'), (string) $flag);
        $this->assertStringNotContainsString('exclude', (string) $flag);

        $uuid = $this->withToken($this->token)
            ->getJson('/api/admin/users/search?uuid=not-a-uuid')
            ->assertStatus(422)
            ->json('errors.uuid.0');
        $this->assertStringContainsString(__('validation.user_search.attributes.uuid'), (string) $uuid);
        $this->assertStringNotContainsString('uuid', (string) $uuid);
    }

    /**
     * 「특정 회원」 수신자(`specific_users`)를 고르는 레이아웃 전부(코어·게시판·이커머스·GDPR·수출입은행 알림 문안 창)의
     * 회원 검색 호출은 `exclude_withdrawn` 을 싣는다 — 새 사본이 생겨도 같은 규칙을 지키도록 번들 레이아웃을 훑어 찾는다.
     *
     * @scenario mode=recipient_picker_layouts
     *
     * @effects withdrawn_members_excluded_from_recipient_search
     *
     * @return void 반환값 없음
     */
    public function test_notification_recipient_pickers_request_exclusion(): void
    {
        $layouts = $this->recipientPickerLayouts();
        $this->assertGreaterThanOrEqual(self::MIN_RECIPIENT_PICKER_LAYOUTS, count($layouts), '알림 수신자 창 레이아웃을 찾지 못했다: '.implode(', ', $layouts));

        foreach ($layouts as $relative) {
            $layout = json_decode((string) file_get_contents(base_path($relative)), true);
            $this->assertIsArray($layout, "{$relative} 를 읽지 못했다");

            $calls = $this->findUserSearchCalls($layout);
            $this->assertNotEmpty($calls, "{$relative}: 회원 검색 호출을 찾지 못했다 — 경로가 바뀌었으면 이 목록을 갱신할 것");
            foreach ($calls as $call) {
                $this->assertSame(
                    '1',
                    (string) ($call['params']['query']['exclude_withdrawn'] ?? ''),
                    "{$relative}: 알림 수신자 회원 검색이 탈퇴 회원을 빼지 않는다(query.exclude_withdrawn 없음)"
                );
            }
        }
    }

    /**
     * 키워드 `G26RCPT` 를 이름에 담은 활성·차단·비활성·탈퇴 회원을 하나씩 만들고 UUID 를 돌려줍니다.
     *
     * 탈퇴 회원은 실제 탈퇴 처리(`User::withdraw()` — 이름에 접미사, 상태·시각 기록)를 거친다.
     *
     * @return array{active: string, blocked: string, inactive: string, withdrawn: string} 종류별 UUID
     */
    private function seedMembers(): array
    {
        $active = User::factory()->create(['name' => 'G26RCPT Active', 'status' => UserStatus::Active->value]);
        $blocked = User::factory()->create(['name' => 'G26RCPT Blocked', 'status' => UserStatus::Blocked->value]);
        $inactive = User::factory()->create(['name' => 'G26RCPT Inactive', 'status' => UserStatus::Inactive->value]);
        $withdrawn = User::factory()->create(['name' => 'G26RCPT Gone', 'status' => UserStatus::Active->value]);
        $withdrawn->withdraw();
        $this->assertTrue($withdrawn->fresh()->isWithdrawn(), '전제 조건 — 탈퇴 처리가 되지 않았다');

        return [
            'active' => $active->uuid,
            'blocked' => $blocked->uuid,
            'inactive' => $inactive->uuid,
            'withdrawn' => $withdrawn->uuid,
        ];
    }

    /**
     * 「특정 회원」 수신자 규칙을 다루면서 회원 검색을 부르는 번들 레이아웃 JSON 을 저장소 기준 상대 경로로 돌려줍니다.
     *
     * @return list<string> 상대 경로 목록
     */
    private function recipientPickerLayouts(): array
    {
        $patterns = [
            'templates/_bundled/*/layouts',
            'modules/_bundled/*/resources/layouts',
            'plugins/_bundled/*/resources/layouts',
        ];
        $root = str_replace('\\', '/', base_path());
        $found = [];
        foreach ($patterns as $pattern) {
            foreach (glob($root.'/'.$pattern, GLOB_ONLYDIR) ?: [] as $dir) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    $path = str_replace('\\', '/', $file->getPathname());
                    if (! str_ends_with($path, '.json')) {
                        continue;
                    }
                    $content = (string) file_get_contents($path);
                    if (str_contains($content, 'specific_users') && str_contains($content, '/api/admin/users/search')) {
                        $found[] = substr($path, strlen($root) + 1);
                    }
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * 레이아웃 트리에서 `/api/admin/users/search` 를 부르는 apiCall 액션을 모두 찾습니다.
     *
     * @param  mixed  $node  레이아웃 노드
     * @return list<array<string, mixed>> 액션 목록
     */
    private function findUserSearchCalls(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        if (($node['handler'] ?? null) === 'apiCall' && ($node['target'] ?? null) === '/api/admin/users/search') {
            $found[] = $node;
        }
        foreach ($node as $child) {
            $found = array_merge($found, $this->findUserSearchCalls($child));
        }

        return $found;
    }
}
