<?php

namespace Tests\Feature\Listeners;

use App\Contracts\Repositories\TemplateCustomTranslationRepositoryInterface;
use App\Extension\HookManager;
use App\Listeners\LanguagePack\MergeCustomTranslations;
use App\Models\Template;
use App\Models\TemplateCustomTranslation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * MergeCustomTranslations 리스너 테스트.
 *
 * `template.language.merge` 필터 결과에 커스텀 키가 점 경로로 병합되며,
 * 언어팩 데이터보다 높은 우선순위(나중 실행)로 덮어쓰는지 검증합니다.
 */
class MergeCustomTranslationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 DB 테스트와 migrate:fresh 정합성을 위해 일관 선언
     * (requiredExtensions 누락 시 migrate:fresh 1회 함정 — feedback_required_extensions_all_db_tests).
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::factory()->create();
    }

    private function applyMerge(array $base, string $locale = 'ko'): array
    {
        return HookManager::applyFilters(
            'template.language.merge',
            $base,
            $this->template->identifier,
            $locale,
        );
    }

    public function test_custom_key_is_merged_into_language_tree_by_dot_path(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '안녕', 'en' => 'hello'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $result = $this->applyMerge([], 'ko');

        $this->assertSame('안녕', data_get($result, 'custom.home.1'));
    }

    public function test_custom_key_resolves_locale_value(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '안녕', 'en' => 'hello'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $result = $this->applyMerge([], 'en');

        $this->assertSame('hello', data_get($result, 'custom.home.1'));
    }

    public function test_custom_key_overrides_existing_language_pack_value(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '커스텀', 'en' => 'custom'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        // 언어팩이 먼저 채운 동일 경로를 커스텀 키가 덮어써야 한다 (priority 20 > 10).
        $base = ['custom' => ['home' => ['1' => '언어팩값']]];
        $result = $this->applyMerge($base, 'ko');

        $this->assertSame('커스텀', data_get($result, 'custom.home.1'));
    }

    public function test_orphaned_keys_are_not_merged(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '고아', 'en' => 'orphan'],
            'status' => 'orphaned',
            'lock_version' => 0,
        ]);

        $result = $this->applyMerge([], 'ko');

        $this->assertNull(data_get($result, 'custom.home.1'));
    }

    public function test_missing_locale_falls_back_to_fallback_locale(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => ['ko' => '폴백'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        // en 값 부재 → fallback_locale(ko) 값으로 폴백
        $result = $this->applyMerge([], 'en');

        $this->assertSame('폴백', data_get($result, 'custom.home.1'));
    }

    /**
     * 회귀 — repository 조회가 실패해도(테이블 미적용·DB 장애) 전체 언어
     * 파이프라인이 무너지지 않고 입력 데이터를 그대로 반환한다(무손실 디그레이드).
     *
     * 배경: `template_custom_translations` 테이블이 없는 환경에서 본 리스너가 던진
     * QueryException 이 `serveLanguage` 전체를 500 으로 무너뜨려 모든 admin/user 화면의
     * 다국어 로드가 깨졌다. 리스너는 코어 언어 파이프라인의 후행 부가 단계이므로 자신의
     * 실패를 흡수해야 한다.
     */
    public function test_repository_failure_degrades_to_input_data_without_throwing(): void
    {
        $base = ['nav' => ['home' => '홈']];

        // repository 가 QueryException(테이블 부재 등)을 던지도록 모킹.
        $failingRepo = Mockery::mock(TemplateCustomTranslationRepositoryInterface::class);
        $failingRepo->shouldReceive('getActiveByTemplateId')
            ->andThrow(new QueryException(
                'mysql',
                'select * from `g7_template_custom_translations`',
                [],
                new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'g7.g7_template_custom_translations' doesn't exist"),
            ));

        $listener = new MergeCustomTranslations(
            app(\App\Contracts\Repositories\TemplateRepositoryInterface::class),
            $failingRepo,
        );

        // 예외가 전파되지 않고 입력이 그대로 반환되어야 한다.
        $result = $listener($base, $this->template->identifier, 'ko');

        $this->assertSame('홈', data_get($result, 'nav.home'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
