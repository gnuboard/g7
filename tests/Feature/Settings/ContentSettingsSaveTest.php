<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 환경설정 「본문 콘텐츠」 탭 저장·화면 규칙 (#883 5단계)
 *
 * 거절 태그는 사유와 함께 422, svg 는 저장(경고는 화면 몫), 직접 추가 서비스는 필드별 문구로 거절, 정상 값은 저장되고 공개 설정의
 * `content.render_policy` 에 반영된다(원본 설정은 공개하지 않는다).
 *
 * @scenario-manifest tests/scenarios/content-render-policy.yaml
 */
class ContentSettingsSaveTest extends TestCase
{
    use DatabaseTransactions;

    /** 관리자 토큰 */
    private string $token;

    /**
     * 설정 권한 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['email' => 'content-settings-'.Str::lower(Str::random(8)).'@example.com']);
        $ids = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => ExtensionOwnerType::Core, 'extension_identifier' => 'core', 'type' => 'admin',
            ])->id;
        }
        $role = Role::create(['identifier' => 'content_settings_test_'.Str::lower(Str::random(6)), 'name' => json_encode(['ko' => 't', 'en' => 't']), 'is_active' => true]);
        $role->permissions()->sync($ids);
        $admin = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']), 'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core', 'is_active' => true,
        ]);
        $user->roles()->attach($admin->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $this->token = $user->createToken('content-settings')->plainTextToken;
    }

    /**
     * 본문 콘텐츠 탭을 저장합니다.
     *
     * @param  array<string, mixed>  $content  content 값
     * @return TestResponse 응답
     */
    private function save(array $content): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/settings', ['_tab' => 'content', 'content' => $content]);
    }

    /**
     * 거절 태그(iframe)는 사유와 함께 422, svg 는 저장된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=save_custom_tags
     *
     * @effects rejected_custom_tag_with_reason, svg_allowed
     */
    public function test_custom_tags_reject_and_allow(): void
    {
        $this->save(['custom_tags' => ['iframe']])->assertStatus(422)->assertJsonValidationErrors('content.custom_tags');

        $this->save(['custom_tags' => ['svg'], 'allow_uploaded_video' => true, 'media_url_ttl_minutes' => 60])->assertStatus(200);
        $stored = app(ConfigRepositoryInterface::class)->getCategory('content');
        $this->assertSame(['svg'], $stored['custom_tags']);
        $this->assertTrue($stored['allow_uploaded_video']);
        $this->assertSame(60, (int) $stored['media_url_ttl_minutes']);
    }

    /**
     * 직접 추가 서비스 — http 플레이어·{id} 없음·프리셋 id 는 422, 정상 항목은 저장되고 화면 규칙에 서비스가 나타난다. 유효 시간 범위 밖은 422.
     *
     * @return void 반환값 없음
     *
     * @scenario case=save_custom_provider
     *
     * @effects custom_provider_rule_rejections, render_policy_reflects_settings
     */
    public function test_custom_provider_validation_and_render_policy(): void
    {
        $entry = ['id' => 'chzzk_clip', 'name' => '치지직 클립', 'enabled' => true, 'url_patterns' => ['https://chzzk.naver.com/clips/{id}'],
            'player_url' => 'https://chzzk.naver.com/embed/clip/{id}', 'id_rule' => 'alnum_dash', 'aspect' => '16:9', 'allow' => ['fullscreen']];

        $this->save(['embed_custom_providers' => [['player_url' => 'http://x.test/{id}'] + $entry]])->assertStatus(422);
        $this->save(['embed_custom_providers' => [['player_url' => 'https://x.test/embed'] + $entry]])->assertStatus(422);
        $this->save(['embed_custom_providers' => [['id' => 'youtube'] + $entry]])->assertStatus(422);
        $this->save(['media_url_ttl_minutes' => 2])->assertStatus(422)->assertJsonValidationErrors('content.media_url_ttl_minutes');

        $this->save(['embed_custom_providers' => [$entry], 'embed_presets' => ['youtube' => true, 'vimeo' => false]])->assertStatus(200)
            ->assertJsonPath('data.settings._meta.embed_extension_providers', []);
        $this->save(['embed_presets' => ['nope' => true]])->assertStatus(422);

        $policy = app(SettingsService::class)->getFrontendSettings()['content']['render_policy'] ?? null;
        $this->assertIsArray($policy);
        $this->assertSame(['youtube', 'chzzk_clip'], array_column($policy['embed_providers'], 'id'));
        $this->assertArrayNotHasKey('embed_custom_providers', app(SettingsService::class)->getFrontendSettings()['content'], '원본 설정은 공개하지 않는다');
    }

    /**
     * 저장한 본문 콘텐츠 설정이 서버 쪽 판정(봇 정화·저장 정화·업로드 허용·재생 헤더·주소 유효 시간)에도 쓰인다.
     *
     * 서버 코드는 `g7_core_settings('content.*')`(설정 미러 `g7_settings.core`)로 읽는다. 미러 카테고리 목록에서 `content` 가
     * 빠지면 저장은 성공하고 화면 규칙(`render_policy`)도 바뀌지만 서버는 늘 기본값을 읽는다(실측 2026-11-08: 업로드 동영상을
     * 켜도 서빙이 attachment·다운로드 CSP 로 나감).
     *
     * @return void 반환값 없음
     *
     * @scenario case=save_reaches_server_mirror
     *
     * @effects server_reads_saved_content_settings
     */
    public function test_saved_settings_reach_server_side_policy(): void
    {
        $this->save(['allow_uploaded_video' => true, 'media_url_ttl_minutes' => 30, 'custom_tags' => ['svg']])->assertStatus(200);

        $this->assertTrue((bool) g7_core_settings('content.allow_uploaded_video'), '미러가 저장값을 읽는다');
        $this->assertTrue(\App\Support\ContentMedia\ContentMediaPolicy::coreAllows(\App\Enums\ContentMediaKind::Video));
        $this->assertSame(30, \App\Support\ContentMedia\ContentMediaUrlRewriter::ttlMinutes());
        $this->assertContains('svg', \App\Support\Content\ContentHtmlPolicy::current()->customTags());
    }
}
