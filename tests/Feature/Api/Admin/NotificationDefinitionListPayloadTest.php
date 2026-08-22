<?php

namespace Tests\Feature\Api\Admin;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 관리자 알림 정의 목록 페이로드 프루닝 (#518 / 공개 #76).
 *
 * 알림 정의 화면은 한 번에 **한 채널** 만 그리는데, 종전에는 정의마다 모든 채널의 제목·본문이
 * 통째로 목록 응답에 실렸다. 목록에 실을 템플릿을 `template_channel` 로 좁히고, 채널을 가리지
 * 않는 판정("커스터마이즈된 템플릿이 있는가")은 집계로 대체한다.
 *
 * `channel` 필터와 혼동하지 않는다 — `channel` 은 정의 행 자체를 거르고(그 채널을 지원하지
 * 않는 정의가 목록에서 사라진다), `template_channel` 은 행을 남긴 채 템플릿만 좁힌다.
 *
 */
class NotificationDefinitionListPayloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminWithPermissions();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 인증 헤더가 붙은 요청 빌더를 반환합니다.
     *
     * @return static
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * core.settings 권한을 가진 관리자를 만듭니다.
     *
     * @return User 생성된 관리자
     */
    private function createAdminWithPermissions(): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => ['ko' => '최고관리자'],
            'identifier' => 'super_admin',
            'is_admin' => true,
        ]);

        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $perm = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier], 'type' => 'admin', 'order' => 1]
            );
            $role->permissions()->attach($perm->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * 여러 채널 템플릿을 가진 알림 정의를 만듭니다.
     *
     * @param  string  $type  정의 타입
     * @param  array<int, string>  $channels  만들 채널 목록
     * @param  array<int, string>  $customizedChannels  is_default=false 로 만들 채널 목록
     * @return NotificationDefinition 생성된 정의
     */
    private function makeDefinition(string $type, array $channels, array $customizedChannels = []): NotificationDefinition
    {
        $definition = NotificationDefinition::create([
            'type' => $type,
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => $type],
            'channels' => $channels,
            'hooks' => [],
        ]);

        foreach ($channels as $channel) {
            NotificationTemplate::create([
                'definition_id' => $definition->id,
                'channel' => $channel,
                'subject' => ['ko' => "제목-{$type}-{$channel}"],
                'body' => ['ko' => str_repeat("본문-{$channel} ", 50)],
                'is_default' => ! in_array($channel, $customizedChannels, true),
            ]);
        }

        return $definition;
    }

    /**
     * template_channel 을 주면 그 채널의 템플릿만 실린다.
     *
     * @return void
     *
     * @scenario resource=notification_definition,endpoint=list,observation=response_payload
     * @effects notification_list_carries_only_requested_channel_template
     */
    public function test_list_carries_only_the_requested_channel_template(): void
    {
        $this->makeDefinition('welcome', ['mail', 'sms', 'push']);
        $this->makeDefinition('reset', ['mail', 'sms']);

        $rows = $this->authRequest()
            ->getJson('/api/admin/notification-definitions?template_channel=mail')
            ->assertStatus(200)
            ->json('data.data');

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertCount(
                1,
                $row['templates'],
                '요청한 채널 외의 제목·본문이 실리면 한 페이지를 여는 것만으로 전 채널 본문이 전송된다'
            );
            $this->assertSame('mail', $row['templates'][0]['channel']);
        }
    }

    /**
     * template_channel 은 정의 행을 거르지 않는다 (channel 필터와의 차이).
     *
     * 그 채널 템플릿이 없는 정의도 행은 남아 "채널 미설정" 안내를 띄울 수 있어야 한다.
     *
     * @return void
     *
     * @scenario resource=notification_definition,endpoint=list,observation=response_payload
     * @effects notification_list_carries_only_requested_channel_template
     */
    public function test_template_channel_does_not_filter_out_rows(): void
    {
        $this->makeDefinition('mail_only', ['mail']);
        $this->makeDefinition('sms_only', ['sms']);

        $rows = $this->authRequest()
            ->getJson('/api/admin/notification-definitions?template_channel=mail')
            ->json('data.data');

        $this->assertCount(2, $rows, '행 자체는 남아야 한다 — 거르는 것은 channel 파라미터의 역할이다');

        $byType = collect($rows)->keyBy('type');
        $this->assertCount(1, $byType['mail_only']['templates']);
        $this->assertCount(0, $byType['sms_only']['templates'], '요청 채널 템플릿이 없으면 빈 배열');
    }

    /**
     * template_channel 이 없으면 템플릿 본문을 아예 싣지 않는다.
     *
     * 채널을 지정하지 않은 호출자에게 전 채널 본문을 안기면, 한 페이지를 여는 것만으로
     * 정의 수 × 채널 × 로케일만큼의 제목·본문이 응답에 실린다. 전 채널이 필요하면 단건
     * 조회가 제공한다.
     *
     * @return void
     *
     * @scenario resource=notification_definition,endpoint=list,observation=response_payload
     * @effects notification_list_without_channel_omits_templates
     */
    public function test_list_without_template_channel_omits_templates(): void
    {
        $this->makeDefinition('welcome', ['mail', 'sms', 'push']);

        $row = $this->authRequest()
            ->getJson('/api/admin/notification-definitions')
            ->json('data.data.0');

        $this->assertArrayNotHasKey(
            'templates',
            $row,
            '채널을 지정하지 않으면 템플릿 본문을 싣지 않는다 — 빈 배열도 아니고 키 자체가 없다'
        );

        // 되돌리기 버튼 판정은 템플릿 배열 없이도 성립해야 한다
        $this->assertArrayHasKey('has_customized_templates', $row);
    }

    /**
     * 커스터마이즈 여부는 요청 채널과 무관하게 정확하다.
     *
     * 되돌리기 버튼 노출 조건이므로, 다른 채널만 수정한 경우에도 참이어야 한다.
     * 템플릿 배열만 훑으면 이 경우를 놓친다.
     *
     * @return void
     *
     * @scenario resource=notification_definition,endpoint=list,observation=response_payload
     * @effects notification_customized_flag_spans_every_channel
     */
    public function test_customized_flag_spans_every_channel_not_just_the_requested_one(): void
    {
        // sms 만 커스터마이즈 — mail 채널로 조회해도 "수정됨" 이어야 한다
        $this->makeDefinition('partly_customized', ['mail', 'sms'], ['sms']);
        $this->makeDefinition('untouched', ['mail', 'sms']);

        $rows = $this->authRequest()
            ->getJson('/api/admin/notification-definitions?template_channel=mail')
            ->json('data.data');

        $byType = collect($rows)->keyBy('type');

        $this->assertTrue(
            $byType['partly_customized']['has_customized_templates'],
            '다른 채널만 수정된 경우를 놓치면 되돌리기 버튼이 사라진다'
        );
        $this->assertFalse($byType['untouched']['has_customized_templates']);
    }

    /**
     * 템플릿 조회 쿼리 수가 정의 수에 비례하지 않는다 (N+1 부재).
     *
     * @return void
     *
     * @scenario resource=notification_definition,endpoint=list,observation=response_payload
     * @effects query_count_does_not_scale_with_row_count
     */
    public function test_template_query_count_does_not_grow_with_definition_count(): void
    {
        $this->makeDefinition('first', ['mail', 'sms']);

        // 권한/설정 캐시를 채워 측정에서 제외
        $this->authRequest()->getJson('/api/admin/notification-definitions?template_channel=mail');

        $countTemplateQueries = function (): int {
            $count = 0;
            DB::listen(function ($query) use (&$count) {
                if (str_contains($query->sql, 'notification_templates')) {
                    $count++;
                }
            });

            $this->authRequest()->getJson('/api/admin/notification-definitions?template_channel=mail');

            return $count;
        };

        $withOne = $countTemplateQueries();

        foreach (['b', 'c', 'd', 'e', 'f'] as $type) {
            $this->makeDefinition($type, ['mail', 'sms']);
        }

        $withSix = $countTemplateQueries();

        $this->assertSame(
            $withOne,
            $withSix,
            "정의 1건일 때 {$withOne}회, 6건일 때 {$withSix}회 — 정의 수에 비례하면 N+1 이다"
        );
    }

    /**
     * 단건 조회는 전체 템플릿을 그대로 공급한다 (대체 경로 보존).
     *
     * @return void
     *
     * @scenario resource=notification_definition,endpoint=detail,observation=response_payload
     * @effects notification_detail_returns_every_channel_template, detail_still_returns_full_payload
     */
    public function test_detail_still_returns_every_channel_template(): void
    {
        $definition = $this->makeDefinition('welcome', ['mail', 'sms', 'push']);

        $row = $this->authRequest()
            ->getJson("/api/admin/notification-definitions/{$definition->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(3, $row['templates']);
    }
}
