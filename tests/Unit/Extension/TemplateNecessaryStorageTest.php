<?php

namespace Tests\Unit\Extension;

use App\Extension\Support\LifecycleWarnings;
use App\Extension\TemplateManager;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 템플릿 필수 저장 키 선언 — template.json `necessary_storage` (7.1.3).
 *
 * 쿠키 동의 플러그인이 동의 전 지우지 않는 「필수」 저장 키를, PHP 리스너가 없는 템플릿도 선언할 수 있게 한다. 코어는
 * 활성 사용자·관리자 템플릿의 선언을 모아 주고(형식이 틀린 항목은 무시·경고), 동의 플러그인이 그 결과를 제공 목록에 더한다.
 *
 * @scenario-manifest tests/scenarios/template-necessary-storage.yaml
 */
class TemplateNecessaryStorageTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 시험이 만든 템플릿 디렉터리 */
    private array $dirs = [];

    /**
     * 경고 수집기를 비우고 템플릿 상태 캐시를 무효화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        LifecycleWarnings::current()->clear();
        TemplateManager::invalidateTemplateStatusCache();
    }

    /**
     * 시험 템플릿 디렉터리를 지우고 캐시를 무효화합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        TemplateManager::invalidateTemplateStatusCache();
        parent::tearDown();
    }

    /**
     * 활성 사용자·관리자 템플릿의 선언을 저장소 종류별로 합친다.
     *
     * @scenario declaration=valid, template_state=active
     *
     * @effects active_template_declarations_are_merged
     *
     * @return void 반환값 없음
     */
    public function test_active_template_declarations_are_merged(): void
    {
        $this->activeTemplate('zztest-ns-user', 'user', ['localStorage' => ['g7_color_scheme'], 'sessionStorage' => [], 'cookie' => []]);
        $this->activeTemplate('zztest-ns-admin', 'admin', ['localStorage' => ['g7_color_scheme', 'g7_view_mode_*'], 'sessionStorage' => ['g7_dismissed_warnings']]);

        $merged = app(TemplateManager::class)->getActiveTemplatesNecessaryStorage();

        $this->assertSame(['g7_color_scheme', 'g7_view_mode_*'], $merged['localStorage']);
        $this->assertSame(['g7_dismissed_warnings'], $merged['sessionStorage']);
        $this->assertSame([], $merged['cookie']);
    }

    /**
     * 비활성 템플릿의 선언은 모으지 않는다.
     *
     * @scenario declaration=valid, template_state=inactive
     *
     * @effects inactive_template_declarations_ignored
     *
     * @return void 반환값 없음
     */
    public function test_inactive_template_declarations_are_ignored(): void
    {
        $this->activeTemplate('zztest-ns-off', 'user', ['localStorage' => ['zz_only_when_active']], 'inactive');

        $merged = app(TemplateManager::class)->getActiveTemplatesNecessaryStorage();

        $this->assertNotContains('zz_only_when_active', $merged['localStorage']);
    }

    /**
     * 형식이 틀린 항목(`*` 단독·공백·모르는 저장소 종류)은 무시하고, 설치처럼 알릴 작업이면 경고 목록에 남긴다.
     *
     * @scenario declaration=invalid_items, template_state=active
     *
     * @effects invalid_declaration_items_ignored_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_invalid_items_are_ignored_with_warning(): void
    {
        $normalized = TemplateManager::normalizeNecessaryStorage([
            'localStorage' => ['ok_key', '*', 'has space', str_repeat('a', 129)],
            'indexedDB' => ['x'],
        ], 'zztest-ns-bad', reportWarnings: true);

        $this->assertSame(['ok_key'], $normalized['localStorage']);
        $this->assertArrayNotHasKey('indexedDB', $normalized);
        $this->assertSame('necessary_storage', LifecycleWarnings::current()->all()[0]['step'] ?? null);
    }

    /**
     * 선언이 없는 템플릿은 빈 목록이다.
     *
     * @scenario declaration=absent, template_state=active
     *
     * @effects absent_declaration_is_empty
     *
     * @return void 반환값 없음
     */
    public function test_absent_declaration_is_empty(): void
    {
        $this->activeTemplate('zztest-ns-none', 'user', null);

        $merged = app(TemplateManager::class)->getActiveTemplatesNecessaryStorage();

        $this->assertSame(['localStorage' => [], 'sessionStorage' => [], 'cookie' => []], $merged);
    }

    /**
     * 시험 템플릿 디렉터리·DB 행을 만듭니다 (같은 종류의 다른 활성 템플릿은 이 트랜잭션 안에서 끈다).
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $type  템플릿 종류 (user 또는 admin)
     * @param  array<string, array<int, string>>|null  $declaration  necessary_storage 선언 (null 이면 키 없음)
     * @param  string  $status  DB 상태
     * @return void 반환값 없음
     */
    private function activeTemplate(string $identifier, string $type, ?array $declaration, string $status = 'active'): void
    {
        $dir = base_path('templates/'.$identifier);
        $this->dirs[] = $dir;
        File::ensureDirectoryExists($dir);
        $manifest = ['identifier' => $identifier, 'type' => $type, 'version' => '1.0.0'];
        if ($declaration !== null) {
            $manifest['necessary_storage'] = $declaration;
        }
        File::put($dir.'/template.json', json_encode($manifest));

        if ($status === 'active') {
            Template::query()->where('type', $type)->update(['status' => 'inactive']);
        }
        Template::factory()->create(['identifier' => $identifier, 'type' => $type, 'status' => $status]);
        TemplateManager::invalidateTemplateStatusCache();
    }
}
