<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * [case:backend-33] 편집기가 부팅 시점 cache_version 으로 계속 요청해도 저장 뒤에는 최신 content 가 온다
 *
 * 레이아웃 편집기는 `window.G7Config.cache_version`(부팅 시점 값)에 nonce 만 붙여
 * `?v={부팅버전}.{nonce}` 로 재로드한다. 저장·복원은 `ext.cache_version` 을 `time()` 으로
 * 올리고 **서버 현재 버전** 키만 지우므로, 서빙 캐시 키를 클라이언트가 보낸 버전으로 조립하면
 * 두 번째 bump 부터 부팅 버전 키가 영영 지워지지 않아 초기화·복원·409 「최신 불러오기」가
 * 옛 content 를 받는다(실측: 초기화 직후 lock 4 응답, DB 는 lock 7). 서빙 캐시 키는 서버 현재
 * 버전으로만 조립하고 `?v` 는 브라우저 HTTP 캐시 우회용으로만 쓴다.
 */
class LayoutServingStaleClientVersionKeyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    #[Test]
    public function serve_with_boot_time_client_version_returns_fresh_content_after_two_saves(): void
    {
        $template = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);

        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'dashboard',
            'content' => $this->content('Before'),
        ]);

        // 편집기 부팅 시점의 확장 캐시 버전 — 이후 요청은 모두 이 정수에 nonce 만 붙인다.
        Cache::put('g7:core:ext.cache_version', 1000);
        $url = "/api/layouts/{$template->identifier}/dashboard.json?v=1000.";

        $this->assertSame('Before', $this->getJson($url.'0')->assertStatus(200)->json('data.meta.title'));

        $service = app(LayoutService::class);

        // 저장 1 — 서빙 캐시 무효화 + cache_version bump(time()).
        $service->updateLayout($template->id, 'dashboard', [
            'content' => $this->content('After 1'),
            'expected_lock_version' => (int) $layout->fresh()->lock_version,
        ]);
        // 편집기 재로드(초기화·복원 뒤) — 부팅 버전 키가 다시 채워진다.
        $this->assertSame('After 1', $this->getJson($url.'1')->assertStatus(200)->json('data.meta.title'));

        // 저장 2 — 서버는 현재(bump 된) 버전 키만 지운다.
        $service->updateLayout($template->id, 'dashboard', [
            'content' => $this->content('After 2'),
            'expected_lock_version' => (int) $layout->fresh()->lock_version,
        ]);

        // 편집기 재로드 — 부팅 버전으로 요청해도 최신이어야 한다(종전: 'After 1' stale).
        $this->assertSame(
            'After 2',
            $this->getJson($url.'2')->assertStatus(200)->json('data.meta.title'),
            '부팅 시점 cache_version 으로 조립된 서빙 캐시 키가 두 번째 저장 뒤 stale 로 남는다'
        );
    }

    /**
     * @param  string  $title  meta.title
     * @return array<string, mixed>
     */
    private function content(string $title): array
    {
        return [
            'meta' => ['title' => $title],
            'data_sources' => [],
            'components' => [
                ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'container'], 'children' => []],
            ],
        ];
    }
}
