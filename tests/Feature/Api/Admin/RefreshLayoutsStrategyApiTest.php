<?php

namespace Tests\Feature\Api\Admin;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\BuildsLayoutSyncScenario;
use Tests\Support\Concerns\GrantsExtensionAdminToken;
use Tests\TestCase;

/**
 * 레이아웃 갱신 API 3종(`POST /api/admin/{templates|modules|plugins}/refresh-layouts`)의 전략 값.
 *
 * 기본 keep(운영자 수정 보존), `layout_strategy=overwrite` 면 덮어쓰기, 알 수 없는 값은 422.
 */
class RefreshLayoutsStrategyApiTest extends TestCase
{
    use BuildsLayoutSyncScenario;
    use DatabaseTransactions;
    use GrantsExtensionAdminToken;

    /**
     * 픽스처 디스크 산출물을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 출처별 갱신 API 주소와 식별자 필드.
     *
     * @param  string  $source  출처
     * @return array{0: string, 1: string} [주소, 필드명]
     */
    private function endpoint(string $source): array
    {
        return match ($source) {
            'template' => ['/api/admin/templates/refresh-layouts', 'template_name'],
            'module' => ['/api/admin/modules/refresh-layouts', 'module_name'],
            'plugin' => ['/api/admin/plugins/refresh-layouts', 'plugin_name'],
        };
    }

    /**
     * 갱신 API 를 부릅니다.
     *
     * @param  string  $source  출처
     * @param  array<string, mixed>  $extra  추가 본문
     * @return TestResponse 응답
     */
    private function callRefresh(string $source, array $extra = [])
    {
        [$url, $field] = $this->endpoint($source);
        $token = $this->extensionAdminToken(['core.modules.activate', 'core.plugins.activate', 'core.templates.activate']);

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson($url, [$field => $this->scenarioIdentifier($source)] + $extra);
    }

    // @scenario source=module, entry=api, strategy=default
    // @scenario source=plugin, entry=api, strategy=default

    /**
     * 전략을 넘기지 않으면 keep — 운영자 수정본이 남고 응답에 판정별 개수가 실린다.
     *
     * @scenario source=template, entry=api, strategy=default
     *
     * @effects refresh_default_keeps_operator_edit, refresh_response_carries_verdict_counts
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_default_strategy_keeps_operator_edit(string $source): void
    {
        $this->installScenario($source);
        $row = $this->editScenarioPartA($source);

        $response = $this->callRefresh($source);

        $response->assertOk();
        $this->assertSame('운영자 A', $this->scenarioPartAText($row));
        $this->assertSame('keep', $response->json('data.layout_preview.strategy'));
        $this->assertIsArray($response->json('data.layout_preview.summary'));
    }

    // @scenario source=module, entry=api, strategy=overwrite
    // @scenario source=plugin, entry=api, strategy=overwrite

    /**
     * `layout_strategy=overwrite` 면 파일 판으로 덮는다.
     *
     * @scenario source=template, entry=api, strategy=overwrite
     *
     * @effects refresh_overwrite_replaces_operator_edit
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_overwrite_strategy_replaces_operator_edit(string $source): void
    {
        $this->installScenario($source);
        $row = $this->editScenarioPartA($source);

        $this->callRefresh($source, ['layout_strategy' => 'overwrite'])->assertOk();

        $this->assertSame('A1', $this->scenarioPartAText($row));
    }

    // @scenario source=module, entry=api, strategy=invalid
    // @scenario source=plugin, entry=api, strategy=invalid

    /**
     * 알 수 없는 전략·결정 값은 422 이고 DB 를 건드리지 않는다.
     *
     * @scenario source=template, entry=api, strategy=invalid
     *
     * @effects refresh_invalid_strategy_fails
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_invalid_strategy_or_decision_is_422(string $source): void
    {
        $this->installScenario($source);
        $this->editScenarioPartA($source);
        $before = $this->scenarioChecksum();

        $this->callRefresh($source, ['layout_strategy' => 'merge'])->assertStatus(422)->assertJsonValidationErrors(['layout_strategy']);
        $this->callRefresh($source, ['decisions' => ['layout:x:y' => 'maybe']])->assertStatus(422);

        $this->assertSame($before, $this->scenarioChecksum());
    }
}
