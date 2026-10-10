<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Models\User;
use App\Services\PasswordExpiryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 만료 기준 시작일 기록 테스트 (#736 D3·A2)
 *
 * 시작일은 운영자가 만료를 켠 순간이고 서버만 기록한다. 탭 저장·단건 저장 두 경로 모두 꺼짐→켜짐에서만
 * 기록하고, 켜짐 유지 저장은 건드리지 않으며, 요청으로는 바꿀 수 없다.
 */
class PasswordExpiryStartedAtTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 설정을 저장할 관리자
     */
    private User $admin;

    /**
     * 관리자로 인증합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();
        Sanctum::actingAs($this->admin);
    }

    /**
     * 보안 탭을 저장합니다.
     *
     * @param  array<string, mixed>  $security  보안 설정
     * @return TestResponse 응답
     */
    private function saveSecurity(array $security): TestResponse
    {
        return $this->postJson('/api/admin/settings', [
            '_tab' => 'security',
            'security' => array_merge(['force_https' => false, 'login_attempt_enabled' => true], $security),
        ]);
    }

    /**
     * 저장소에 기록된 시작일을 읽습니다.
     *
     * @return string|null ISO8601 시작일
     */
    private function storedStartedAt(): ?string
    {
        return app(ConfigRepositoryInterface::class)->getCategory('security')['password_expiry_started_at'] ?? null;
    }

    /**
     * 탭 저장에서 꺼짐→켜짐이면 지금을 시작일로 기록하고, 켜짐 유지 저장은 바꾸지 않는다.
     *
     * @scenario path=tab_save, transition=off_to_on|on_to_on
     *
     * @effects started_at_recorded_on_enable_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function tab_save_records_the_start_only_when_turning_on(): void
    {
        $this->saveSecurity(['password_expiry_enabled' => true])->assertOk();
        $first = $this->storedStartedAt();
        $this->assertNotNull($first);
        $this->assertTrue(Carbon::parse($first)->diffInSeconds(now()) < 60);

        $this->travel(2)->days();
        $this->saveSecurity(['password_expiry_enabled' => true, 'password_expiry_days' => 120])->assertOk();
        $this->assertSame($first, $this->storedStartedAt(), '켜짐 유지 저장이 시작일을 바꿨다');
    }

    /**
     * 껐다 다시 켜면 그날부터 다시 계산한다.
     *
     * @scenario path=tab_save, transition=re_enable
     *
     * @effects started_at_resets_on_re_enable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function re_enabling_resets_the_start(): void
    {
        $this->saveSecurity(['password_expiry_enabled' => true])->assertOk();
        $first = $this->storedStartedAt();

        $this->saveSecurity(['password_expiry_enabled' => false])->assertOk();
        $this->travel(3)->days();
        $this->saveSecurity(['password_expiry_enabled' => true])->assertOk();

        $this->assertNotSame($first, $this->storedStartedAt());
        $this->assertTrue(Carbon::parse($this->storedStartedAt())->greaterThan(Carbon::parse($first)));
    }

    /**
     * 단건 저장 경로도 꺼짐→켜짐이면 시작일을 기록한다.
     *
     * @scenario path=single_key, transition=off_to_on
     *
     * @effects started_at_recorded_on_enable_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function single_key_save_records_the_start(): void
    {
        $this->putJson('/api/admin/settings/security.password_expiry_enabled', ['value' => true])->assertOk();

        $this->assertNotNull($this->storedStartedAt());
    }

    /**
     * 요청으로는 시작일을 바꿀 수 없다 — 탭 저장은 버려지고, 단건 저장은 거부된다(null 포함).
     *
     * @scenario path=tab_save|single_key, input=forged_started_at
     *
     * @effects started_at_is_server_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function requests_cannot_write_the_start(): void
    {
        $this->saveSecurity(['password_expiry_enabled' => true, 'password_expiry_started_at' => '2000-01-01T00:00:00+00:00'])->assertOk();
        $this->assertStringStartsNotWith('2000-', (string) $this->storedStartedAt());

        $this->putJson('/api/admin/settings/security.password_expiry_started_at', ['value' => '2000-01-01T00:00:00+00:00'])
            ->assertStatus(422);
        $this->putJson('/api/admin/settings/security.password_expiry_started_at', ['value' => null])
            ->assertStatus(422);
        $this->assertStringStartsNotWith('2000-', (string) $this->storedStartedAt());
    }

    /**
     * 켜져 있는데 시작일이 비어 있으면 판독할 때 지금으로 채운다.
     *
     * @scenario path=read, started_at=empty
     *
     * @effects empty_started_at_filled_on_read
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_empty_start_is_filled_on_read(): void
    {
        $this->setSecurity(['password_expiry_enabled' => true, 'password_expiry_started_at' => null]);

        $startedAt = app(PasswordExpiryService::class)->startedAt();

        $this->assertTrue($startedAt->diffInSeconds(now()) < 60);
        $this->assertNotNull($this->storedStartedAt());
    }
}
