<?php

namespace Tests\Feature\Api\Public;

use App\Contracts\Extension\CacheInterface;
use App\Models\Template;
use App\Models\TemplateCustomTranslation;
use App\Services\TemplateCustomTranslationService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * serveLanguage 공개 엔드포인트 커스텀 다국어 키 통합 테스트.
 *
 * `GET /api/templates/{identifier}/lang/{locale}.json` 응답이
 * MergeCustomTranslations 필터를 거쳐 커스텀 키를 실제로 포함하는지,
 * 캐시 무효화(?v= 파라미터)가 동작하는지를 end-to-end 로 검증합니다.
 *
 * (단위 합성 — 필터 직접 호출(MergeCustomTranslationsTest) + cache_version bump
 *  (Controller/Service 테스트) — 의 통합 경로를 보강하는 테스트.)
 */
class PublicTemplateLanguageMergeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 DB 테스트와 migrate:fresh 정합성을 위해 일관 선언.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private TemplateService $templateService;

    private Template $template;

    /**
     * 실제 활성 템플릿을 설치·활성화하고 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->templateService = app(TemplateService::class);

        // 실제 활성 템플릿 — serveLanguage 가 status=Active + template.json 을 요구한다.
        $this->templateService->installTemplate('sirsoft-admin_basic');
        $this->template = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $this->templateService->activateTemplate($this->template->id);

        Cache::flush();
    }

    /**
     * 커스텀 다국어 키가 점 경로로 응답 트리에 병합된다.
     *
     * @effects merge_custom_key_into_language_tree_by_dot_path
     *
     * @return void 반환값 없음
     */
    public function test_serve_language_response_includes_custom_translation_key(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '커스텀안녕', 'en' => 'custom hello'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/ko.json');

        $response->assertStatus(200);
        // 점 경로 custom.home.1 이 응답 트리에 병합되어 ko 값으로 해석됨
        $response->assertJsonPath('custom.home.1', '커스텀안녕');
    }

    /**
     * 커스텀 키는 요청 로케일의 값으로 해석된다.
     *
     * @effects merge_resolves_locale_value
     *
     * @return void 반환값 없음
     */
    public function test_serve_language_resolves_locale_specific_value(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '커스텀안녕', 'en' => 'custom hello'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/en.json');

        $response->assertStatus(200);
        $response->assertJsonPath('custom.home.1', 'custom hello');
    }

    /**
     * 고아(orphaned) 상태의 커스텀 키는 응답에 실리지 않는다.
     *
     * @effects merge_excludes_orphaned_keys
     *
     * @return void 반환값 없음
     */
    public function test_orphaned_custom_key_not_served(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '고아', 'en' => 'orphan'],
            'status' => 'orphaned',
            'lock_version' => 0,
        ]);

        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/ko.json');

        $response->assertStatus(200);
        $this->assertNull(data_get($response->json(), 'custom.home.1'));
    }

    /**
     * 키 생성으로 캐시 버전이 오르면 다음 요청은 새 키가 포함된 신선한 응답을 받는다.
     *
     * @effects serve_language_cache_invalidation_serves_fresh_keys_on_new_version
     *
     * @return void 반환값 없음
     */
    public function test_cache_invalidation_serves_fresh_keys_on_new_cache_version(): void
    {
        // 초기 응답 — 커스텀 키 없음, 서버 현재 버전 키에 캐시됨 (클라이언트 `?v` 는 키가 아니다 — KVE-2026-2336)
        $first = $this->getJson('/api/templates/sirsoft-admin_basic/lang/ko.json?v=0');
        $first->assertStatus(200);
        $this->assertNull(data_get($first->json(), 'custom.home.1'));

        // 커스텀 키 생성 → Service 가 ext.cache_version 을 bump
        app(TemplateCustomTranslationService::class)->createKey(
            $this->template->id,
            'home',
            'ko',
            '신규키',
        );

        $newVersion = (int) app(CacheInterface::class)->get('ext.cache_version', 0);
        $this->assertGreaterThan(0, $newVersion);

        // 새 캐시 버전으로 재요청 → 서버 버전이 올라 키가 바뀜 → 캐시 미스 → 커스텀 키 포함된 신선한 응답
        // (같은 초 안의 bump 도 값이 반드시 커져야 한다 — incrementExtensionCacheVersion 단조 증가)
        $second = $this->getJson("/api/templates/sirsoft-admin_basic/lang/ko.json?v={$newVersion}");
        $second->assertStatus(200);
        $second->assertJsonPath('custom.home.1', '신규키');
    }

    /**
     * 캐시 버전 bump 없이 DB 만 바뀌면 같은 서버 키의 캐시 스냅샷이 유지된다.
     *
     * @effects serve_language_stale_cache_version_serves_old_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_stale_cache_version_still_serves_old_snapshot(): void
    {
        // 응답을 먼저 서버 현재 버전 키에 적재 (커스텀 키 없음)
        $this->getJson('/api/templates/sirsoft-admin_basic/lang/ko.json?v=0')->assertStatus(200);

        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '나중', 'en' => 'later'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        // 버전 bump 없이 재요청 → 같은 서버 키 캐시 히트 → 옛 스냅샷(커스텀 키 부재)
        $cached = $this->getJson('/api/templates/sirsoft-admin_basic/lang/ko.json?v=0');
        $cached->assertStatus(200);
        $this->assertNull(data_get($cached->json(), 'custom.home.1'));
    }
}
