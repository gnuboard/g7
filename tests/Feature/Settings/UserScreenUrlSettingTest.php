<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 환경설정 > 일반 「사용자 화면 URL」 저장 테스트
 *
 * http·https 전체 주소만 받고(상대 경로·javascript:·ftp: 는 422), 끝 슬래시를 떼어 저장하며, 비우는 것을 허용하는지 잠근다.
 */
class UserScreenUrlSettingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    /**
     * 설정 권한을 가진 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $ids = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ])->id;
        }
        $role = Role::create(['identifier' => 'usu_admin_'.uniqid(), 'name' => json_encode(['ko' => '시험', 'en' => 'Test']), 'is_active' => true]);
        $role->permissions()->sync($ids);
        $base = Role::firstOrCreate(['identifier' => 'admin'], ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'is_active' => true]);
        $user->roles()->attach([$role->id => ['assigned_at' => now()], $base->id => ['assigned_at' => now()]]);
        $this->admin = $user->fresh();
    }

    /**
     * 잘못된 주소는 422 와 칸 오류 문구를 돌려줍니다.
     *
     * @scenario case=invalid
     *
     * @effects invalid_user_screen_url_rejected
     *
     * @param  string  $value  입력값
     * @return void 반환값 없음
     */
    #[DataProvider('invalidProvider')]
    public function test_invalid_values_are_rejected(string $value): void
    {
        $this->save($value)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['general.user_screen_url']);
    }

    /**
     * 거부 입력 3종.
     *
     * @return array<string, array{0: string}> 데이터셋
     */
    public static function invalidProvider(): array
    {
        return [
            'relative' => ['/front'],
            'javascript' => ['javascript:alert(1)'],
            'ftp' => ['ftp://front.example.test'],
        ];
    }

    /**
     * 올바른 주소는 끝 슬래시를 떼어 저장하고, 빈 값도 저장됩니다.
     *
     * @scenario case=valid
     *
     * @effects user_screen_url_saved_trimmed, empty_user_screen_url_allowed
     *
     * @return void 반환값 없음
     */
    public function test_valid_value_is_saved_trimmed_and_empty_allowed(): void
    {
        $repo = app(ConfigRepositoryInterface::class);
        $before = $repo->getCategory('general');

        try {
            $this->save('https://front.example.test/')->assertOk();
            $this->assertSame('https://front.example.test', $repo->getCategory('general')['user_screen_url'] ?? null);

            $this->save('')->assertOk();
            $this->assertSame('', (string) ($repo->getCategory('general')['user_screen_url'] ?? ''));
        } finally {
            $repo->saveCategory('general', $before);
        }
    }

    /**
     * 일반 탭 저장 요청.
     *
     * @param  string  $userScreenUrl  사용자 화면 URL
     * @return TestResponse 응답
     */
    private function save(string $userScreenUrl): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => '그누보드7',
                'site_url' => 'https://site.example.test',
                'admin_email' => 'admin@example.test',
                'timezone' => 'Asia/Seoul',
                'language' => 'ko',
                'user_screen_url' => $userScreenUrl,
            ],
        ]);
    }
}
