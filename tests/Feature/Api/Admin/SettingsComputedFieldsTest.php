<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Attachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 관리자 설정 조회·저장 응답의 화면용 계산 값 (#887)
 *
 * 화면은 저장 응답(`data.settings`)으로 전역 설정을 병합한다. 응답에 공개 설정의 계산 값(`frontend_schema` 의 `computed: true`)이
 * 없으면 같은 탭에서는 페이지 로드 때 받은 옛 계산 값이 남는다(실측: 동영상 업로드 허용을 켜고 저장 → 새로고침 없이 게시글 상세 →
 * `render_policy.allow_uploaded_video=false` → 본문 video 0개). 조회·저장 응답이 공개 설정과 같은 계산으로 저장 뒤 값을 실어야 하고,
 * 화면이 그 값을 저장 본문에 되돌려 보내도 저장소에 남기지 않아야 한다.
 *
 * @scenario-manifest tests/scenarios/admin-settings-computed-fields.yaml
 */
class SettingsComputedFieldsTest extends TestCase
{
    use DatabaseTransactions;

    /** 응답에 실려야 하는 계산 값 (카테고리 => 키) — `config/settings/defaults.json` 의 `frontend_schema` 기준 */
    private const COMPUTED_KEYS = [
        ['general', 'site_logo_url'],
        ['upload', 'allowed_extensions_formatted'],
        ['seo', 'og_image_default_url'],
        ['content', 'render_policy'],
    ];

    /** 설정 권한 관리자 토큰 */
    private string $token;

    /**
     * 설정 읽기·수정 권한 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['email' => 'computed-settings-'.Str::lower(Str::random(8)).'@example.com']);
        $ids = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => ExtensionOwnerType::Core, 'extension_identifier' => 'core', 'type' => 'admin',
            ])->id;
        }
        $role = Role::create(['identifier' => 'computed_settings_test_'.Str::lower(Str::random(6)), 'name' => json_encode(['ko' => 't', 'en' => 't']), 'is_active' => true]);
        $role->permissions()->sync($ids);
        $admin = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']), 'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core', 'is_active' => true,
        ]);
        $user->roles()->attach($admin->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $this->token = $user->createToken('computed-settings')->plainTextToken;
    }

    /**
     * 관리자 설정을 조회합니다.
     *
     * @return TestResponse 응답
     */
    private function fetch(): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/admin/settings');
    }

    /**
     * 한 탭을 저장합니다.
     *
     * @param  string  $tab  탭 이름
     * @param  array<string, mixed>  $values  그 탭 값
     * @return TestResponse 응답
     */
    private function save(string $tab, array $values): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/settings', ['_tab' => $tab, $tab => $values]);
    }

    /**
     * 설정 묶음에 계산 값 4종 키가 모두 있는지 단언합니다.
     *
     * @param  array<string, mixed>  $settings  응답의 설정 묶음
     * @param  string  $where  실패 문구에 쓸 위치
     * @return void 반환값 없음
     */
    private function assertComputedKeysPresent(array $settings, string $where): void
    {
        foreach (self::COMPUTED_KEYS as [$category, $key]) {
            $this->assertIsArray($settings[$category] ?? null, "{$where}: {$category} 묶음이 없다");
            $this->assertArrayHasKey($key, $settings[$category], "{$where}: {$category}.{$key} 계산 값이 빠졌다");
        }
    }

    /**
     * 사이트 로고·기본 OG 이미지용 이미지 첨부를 만듭니다.
     *
     * @param  string  $collection  첨부 컬렉션 (`site_logo` | `og_image_default`)
     * @return Attachment 첨부
     */
    private function createImageAttachment(string $collection): Attachment
    {
        return Attachment::factory()->create([
            'source_type' => 'core',
            'source_identifier' => $collection,
            'collection' => $collection,
            'mime_type' => 'image/png',
            'original_filename' => $collection.'.png',
        ]);
    }

    /**
     * 조회 응답에 계산 값 4종이 공개 설정과 같은 값으로 실린다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=index_parity
     *
     * @effects admin_index_carries_computed_fields
     */
    public function test_index_carries_computed_fields_equal_to_public_settings(): void
    {
        $response = $this->fetch()->assertStatus(200);
        $data = $response->json('data');
        $this->assertComputedKeysPresent($data, '조회 응답');

        // 공개 설정과 같은 계산 — 응답 JSON 과 같은 모양으로 맞춰 비교한다(빈 맵·정수 키 표현 차이 제거)
        $public = json_decode(json_encode(app(SettingsService::class)->getFrontendSettings()), true);
        $this->assertSame($public['general']['site_logo_url'], $data['general']['site_logo_url']);
        $this->assertSame($public['upload']['allowed_extensions_formatted'], $data['upload']['allowed_extensions_formatted']);
        $this->assertEquals($public['content']['render_policy'], $data['content']['render_policy']);

        // seo 는 공개 범주가 아니라 공개 설정에 없다 — 같은 계산(`getOgDefaultImageUrl`)과 대조한다
        $this->assertSame(app(SettingsService::class)->getOgDefaultImageUrl(), $data['seo']['og_image_default_url']);
    }

    /**
     * 저장 응답의 계산 값은 방금 저장한 값으로 계산된다 — 본문 콘텐츠 동영상 업로드 허용 켜기·끄기, 업로드 허용 확장자.
     *
     * @return void 반환값 없음
     *
     * @scenario case=store_after_save
     *
     * @effects admin_store_computed_uses_saved_values
     */
    public function test_store_response_computes_with_saved_values(): void
    {
        $on = $this->save('content', ['allow_uploaded_video' => true])->assertStatus(200);
        $this->assertComputedKeysPresent($on->json('data.settings'), '저장 응답');
        $this->assertTrue($on->json('data.settings.content.render_policy.allow_uploaded_video'), '켜고 저장한 값이 저장 응답 계산 값에 실려야 한다');

        $off = $this->save('content', ['allow_uploaded_video' => false])->assertStatus(200);
        $this->assertFalse($off->json('data.settings.content.render_policy.allow_uploaded_video'), '끄고 저장한 값이 저장 응답 계산 값에 실려야 한다');

        $upload = $this->save('upload', ['max_file_size' => 10, 'allowed_extensions' => ['jpg', 'png', 'webp']])->assertStatus(200);
        $formatted = $upload->json('data.settings.upload.allowed_extensions_formatted');
        $this->assertSame(app(SettingsService::class)->getFrontendSettings()['upload']['allowed_extensions_formatted'], $formatted);
        $this->assertStringContainsString('.webp', (string) $formatted, '저장한 확장자가 계산 값에 실려야 한다');
    }

    /**
     * 저장 응답의 사이트 로고·기본 OG 이미지 주소는 방금 저장한 첨부를 가리킨다(관리자 상단 로고 즉시 반영).
     *
     * @return void 반환값 없음
     *
     * @scenario case=store_attachment_urls
     *
     * @effects attachment_urls_follow_saved_attachments
     */
    public function test_store_response_attachment_urls_follow_saved_attachments(): void
    {
        $logo = $this->createImageAttachment('site_logo');
        $general = $this->save('general', [
            'site_name' => 'Test Site',
            'site_url' => 'https://test.example.com',
            'admin_email' => 'admin@example.com',
            'timezone' => 'Asia/Seoul',
            'language' => 'ko',
            'site_logo' => [$logo->id],
        ])->assertStatus(200);
        $this->assertSame($logo->fresh()->download_url, $general->json('data.settings.general.site_logo_url'));

        $ogImage = $this->createImageAttachment('og_image_default');
        $seo = $this->save('seo', ['og_image_default' => [$ogImage->id]])->assertStatus(200);
        $this->assertSame($ogImage->fresh()->download_url, $seo->json('data.settings.seo.og_image_default_url'));

        $this->save('general', [
            'site_name' => 'Test Site',
            'site_url' => 'https://test.example.com',
            'admin_email' => 'admin@example.com',
            'timezone' => 'Asia/Seoul',
            'language' => 'ko',
            'site_logo' => [],
        ])->assertStatus(200)->assertJsonPath('data.settings.general.site_logo_url', null);
    }

    /**
     * 화면이 응답의 계산 값을 탭 값과 함께 되돌려 보내도 저장소에 남지 않는다.
     *
     * 화면은 조회 응답으로 만든 탭 값 전체를 저장 본문에 싣는다 — 계산 값이 저장되면 원본처럼 굳어 다음 계산과 갈라진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=echo_not_persisted
     *
     * @effects computed_fields_not_persisted_on_save
     */
    public function test_echoed_computed_fields_are_not_persisted(): void
    {
        $repository = app(ConfigRepositoryInterface::class);

        $content = $this->fetch()->assertStatus(200)->json('data.content');
        $content['render_policy'] = ['allow_uploaded_video' => true];
        $this->save('content', $content)->assertStatus(200);
        $this->assertArrayNotHasKey('render_policy', $repository->getCategory('content'));

        $upload = $this->fetch()->json('data.upload');
        $upload['allowed_extensions_formatted'] = '.exe';
        $this->save('upload', $upload)->assertStatus(200);
        $this->assertArrayNotHasKey('allowed_extensions_formatted', $repository->getCategory('upload'));

        // 화면 검증을 거치지 않는 저장 경로(설정 명령 등)도 같은 서비스로 저장한다
        app(SettingsService::class)->saveSettings(['_tab' => 'general', 'general' => ['site_logo_url' => 'https://evil.test/logo.png']]);
        $this->assertArrayNotHasKey('site_logo_url', $repository->getCategory('general'));

        app(SettingsService::class)->saveSettings(['_tab' => 'seo', 'seo' => ['og_image_default_url' => 'https://evil.test/og.png']]);
        $this->assertArrayNotHasKey('og_image_default_url', $repository->getCategory('seo'));
    }
}
