<?php

namespace Tests\Unit\Services;

use App\Models\Template;
use App\Models\TemplateCustomTranslation;
use App\Services\TemplateCustomTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TemplateCustomTranslationService 단위 테스트.
 *
 * 키 네이밍 정규화 / 활성 로케일 시드 / 폴백 / seq 증가를 검증합니다.
 */
class TemplateCustomTranslationServiceTest extends TestCase
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

    private TemplateCustomTranslationService $service;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TemplateCustomTranslationService::class);
        $this->template = Template::factory()->create();
    }

    public function test_create_key_normalizes_layout_name_with_slash(): void
    {
        $model = $this->service->createKey($this->template->id, 'board/list', 'ko', '값');

        $this->assertSame('custom.board_list.1', $model->translation_key);
        $this->assertSame('board/list', $model->layout_name);
    }

    public function test_create_key_seeds_all_active_locales(): void
    {
        $model = $this->service->createKey($this->template->id, 'home', 'ko', '안녕');

        // config('app.supported_locales') = ['ko', 'en']
        $this->assertArrayHasKey('ko', $model->values);
        $this->assertArrayHasKey('en', $model->values);
        $this->assertSame('안녕', $model->values['ko']);
        $this->assertSame('안녕', $model->values['en']);
    }

    public function test_create_key_increments_seq(): void
    {
        $first = $this->service->createKey($this->template->id, 'home', 'ko', 'a');
        $second = $this->service->createKey($this->template->id, 'home', 'ko', 'b');
        $third = $this->service->createKey($this->template->id, 'home', 'ko', 'c');

        $this->assertSame('custom.home.1', $first->translation_key);
        $this->assertSame('custom.home.2', $second->translation_key);
        $this->assertSame('custom.home.3', $third->translation_key);
    }

    public function test_create_key_seq_is_isolated_per_layout(): void
    {
        $this->service->createKey($this->template->id, 'home', 'ko', 'a');
        $boardKey = $this->service->createKey($this->template->id, 'board/list', 'ko', 'b');

        $this->assertSame('custom.board_list.1', $boardKey->translation_key);
    }

    public function test_create_key_continues_seq_after_existing_rows(): void
    {
        TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.5',
            'values' => ['ko' => 'x', 'en' => 'x'],
            'status' => 'active',
            'lock_version' => 0,
        ]);

        $next = $this->service->createKey($this->template->id, 'home', 'ko', 'y');

        $this->assertSame('custom.home.6', $next->translation_key);
    }

    public function test_update_values_preserves_user_overrides_tracking(): void
    {
        $model = $this->service->createKey($this->template->id, 'home', 'ko', 'orig');

        $updated = $this->service->updateValues(
            $model->id,
            ['ko' => '수정', 'en' => 'edited'],
            0,
        );

        $this->assertSame('수정', $updated->values['ko']);
        $this->assertSame(1, $updated->lock_version);
        // HasUserOverrides — 사용자가 손댄 로케일이 추적됨
        $this->assertNotNull($updated->fresh()->user_overrides);
    }

    public function test_normalize_empty_layout_name_falls_back_to_layout(): void
    {
        $model = $this->service->createKey($this->template->id, '   ', 'ko', 'v');

        $this->assertSame('custom.layout.1', $model->translation_key);
    }
}
