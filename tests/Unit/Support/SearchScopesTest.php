<?php

namespace Tests\Unit\Support;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Support\SearchScopes;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Mockery;
use Tests\TestCase;

/**
 * 최근 검색어 범위 집계 (코어 7.1.5)
 *
 * 범위는 코어 예약(`core.site`)과 활성 모듈·플러그인의 manifest `search_scopes` 선언뿐이다. 코어가 `{식별자}.` 를
 * 붙여 확장이 남의 범위를 주장하지 못하게 하고, 비활성 확장의 범위는 집계에서 빠져 저장이 거부된다. 적용값은
 * 설정 행 → 선언값 → 기본값(10개·기한 없음) 순이다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */
class SearchScopesTest extends TestCase
{
    /**
     * 활성 모듈·플러그인을 흉내 낸 매니저를 컨테이너에 넣습니다.
     *
     * @param  array<string, mixed>  $modules  모듈 id → manifest search_scopes 원본
     * @param  array<string, mixed>  $plugins  플러그인 id → manifest search_scopes 원본
     * @return void 반환값 없음
     */
    private function fakeActiveExtensions(array $modules, array $plugins = []): void
    {
        $extension = fn (string $id, mixed $raw) => new class($id, $raw)
        {
            /**
             * 가짜 확장을 만듭니다.
             *
             * @param  string  $id  식별자
             * @param  mixed  $raw  manifest search_scopes 원본
             */
            public function __construct(private string $id, private mixed $raw) {}

            /**
             * 식별자를 반환합니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return $this->id;
            }

            /**
             * 선언을 정규화해 반환합니다.
             *
             * @return array<string, mixed> 범위 id → 선언
             */
            public function getSearchScopes(): array
            {
                return SearchScopes::normalize($this->raw, $this->id);
            }
        };

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn(array_map($extension, array_keys($modules), $modules));
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn(array_map($extension, array_keys($plugins), $plugins));
        $this->app->instance(PluginManager::class, $pluginManager);
    }

    /**
     * 확장 선언에 식별자 접두어를 붙이고, 이름 규칙·범위를 벗어난 항목은 버리며 설치 경고로 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=scope_declaration_normalized
     *
     * @effects extension_scope_prefixed_with_identifier, invalid_scope_declaration_dropped_with_warning
     */
    public function test_normalize_prefixes_identifier_and_drops_invalid_entries(): void
    {
        $this->fakeWarningLines();
        LifecycleWarnings::current()->clear();

        $normalized = SearchScopes::normalize([
            'products' => ['label' => ['ko' => '상품', 'en' => 'Products'], 'max_count' => 20, 'retention_days' => 30],
            'posts' => ['label' => 'acme-board::messages.scope'],
            'Bad Name' => ['max_count' => 5],
            'core.site' => [],
            'too_many' => ['max_count' => 999],
            'negative' => ['retention_days' => -1],
        ], 'acme-shop', reportWarnings: true);

        $this->assertSame(['acme-shop.products', 'acme-shop.posts', 'acme-shop.too_many', 'acme-shop.negative'], array_keys($normalized));
        $this->assertSame(['label' => ['ko' => '상품', 'en' => 'Products'], 'max_count' => 20, 'retention_days' => 30], $normalized['acme-shop.products']);
        $this->assertSame('acme-board::messages.scope', $normalized['acme-shop.posts']['label']);
        $this->assertNull($normalized['acme-shop.too_many']['max_count'], '상한(50)을 넘는 보관 개수는 버린다');
        $this->assertNull($normalized['acme-shop.negative']['retention_days'], '음수 보관 기한은 버린다');

        $warnings = LifecycleWarnings::current()->all();
        $this->assertCount(1, $warnings);
        $this->assertSame('search_scopes', $warnings[0]['step']);
        $this->assertStringContainsString('Bad Name', $warnings[0]['message']);
        $this->assertStringContainsString('too_many(999)', $warnings[0]['message']);
        $this->assertStringContainsString('negative(-1)', $warnings[0]['message']);
    }

    /**
     * 형식이 틀린 선언 경고는 무엇을 왜 버렸는지 이유별 사람 말로 말하고, 다시 실행할 작업이 아니라 확장 개발자가 고칠
     * 선언이라는 표지(cause=declaration)를 싣는다. 정수가 아닌 보관 개수·기한도 조용히 버리지 않고 같은 이유로 알린다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=scope_declaration_warning_explains_reason
     *
     * @effects invalid_scope_declaration_warning_explains_reason, invalid_scope_declaration_marked_for_developer
     */
    public function test_invalid_declaration_warning_explains_reason_for_developer(): void
    {
        $this->fakeWarningLines();
        LifecycleWarnings::current()->clear();

        $normalized = SearchScopes::normalize([
            'rates' => ['label' => ['ko' => '환율'], 'max_count' => 5],
            'Bad Name!' => ['max_count' => 5],
            'big' => ['max_count' => 999],
            'words' => ['max_count' => 'many', 'retention_days' => 99999],
            'flat' => 'oops',
        ], 'acme-rates', reportWarnings: true);

        $this->assertSame(['acme-rates.rates', 'acme-rates.big', 'acme-rates.words'], array_keys($normalized));
        $this->assertNull($normalized['acme-rates.words']['max_count'], '정수가 아닌 보관 개수는 버린다');

        $warnings = LifecycleWarnings::current()->all();
        $this->assertCount(1, $warnings);
        $this->assertSame('search_scopes', $warnings[0]['step']);
        $this->assertSame('acme-rates', $warnings[0]['target']);
        $this->assertSame(LifecycleWarnings::LEVEL_WARNING, $warnings[0]['level']);
        $this->assertSame(LifecycleWarnings::CAUSE_DECLARATION, $warnings[0]['cause'] ?? null, '확장 개발자가 고칠 선언이라는 표지');
        $this->assertSame(
            'NAME[Bad Name!] / DEF[flat] / COUNT[1-50: big(999), words(many)] / DAYS[0-3650: words(99999)]',
            $warnings[0]['message'],
        );
    }

    /**
     * 선언 자체가 객체가 아니면 전부 무시했다고 말한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=scope_declaration_warning_explains_reason
     *
     * @effects invalid_scope_declaration_warning_explains_reason
     */
    public function test_non_object_declaration_warning_says_all_ignored(): void
    {
        $this->fakeWarningLines();
        LifecycleWarnings::current()->clear();

        $this->assertSame([], SearchScopes::normalize('products', 'acme-rates', reportWarnings: true));

        $this->assertSame('NOT_OBJECT', LifecycleWarnings::current()->all()[0]['message'] ?? null);
    }

    /**
     * 경고 문구 번역을 시험용 표지 글자로 바꿉니다 — 문구가 아니라 이유·자리표시자 구조를 단언한다.
     *
     * @return void 반환값 없음
     */
    private function fakeWarningLines(): void
    {
        app()->setLocale('ko');
        // 묶음 파일을 먼저 읽어 둔다 — 읽기 전에 addLines 하면 같은 묶음의 다른 키가 비어 버린다
        __('extensions.warnings.column_step');
        Lang::addLines([
            'extensions.warnings.search_scopes_not_object' => 'NOT_OBJECT',
            'extensions.warnings.search_scopes_invalid_name' => 'NAME[:names]',
            'extensions.warnings.search_scopes_invalid_definition' => 'DEF[:names]',
            'extensions.warnings.search_scopes_max_count_out_of_range' => 'COUNT[:min-:max: :items]',
            'extensions.warnings.search_scopes_retention_days_out_of_range' => 'DAYS[:min-:max: :items]',
        ], 'ko');
    }

    /**
     * 코어 예약 범위가 먼저 오고, 활성 확장 범위가 소유자와 함께 뒤에 붙는다. 비활성 확장 범위는 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=scope_aggregate_active_only
     *
     * @effects core_scope_always_present, inactive_extension_scope_absent
     */
    public function test_all_lists_core_then_active_extension_scopes(): void
    {
        $this->fakeActiveExtensions(['acme-shop' => ['products' => ['max_count' => 5]]], ['acme-pay' => ['receipts' => []]]);

        $all = SearchScopes::all();

        $this->assertSame(['core.site', 'acme-shop.products', 'acme-pay.receipts'], array_keys($all));
        $this->assertSame('core', $all['core.site']['owner']);
        $this->assertSame('module:acme-shop', $all['acme-shop.products']['owner']);
        $this->assertSame('plugin:acme-pay', $all['acme-pay.receipts']['owner']);
        $this->assertFalse(SearchScopes::isDeclared('acme-board.posts'), '활성 목록에 없는 확장의 범위는 선언되지 않은 것이다');
    }

    /**
     * 적용값은 설정 행 → 선언값 → 기본값 순이고, 설정 행만 남은(끈 확장) 범위의 기한도 파기 정책에 든다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=scope_effective_values
     *
     * @effects effective_value_precedence_settings_declared_default, retention_policy_keeps_inactive_scope_rows
     */
    public function test_effective_values_follow_settings_then_declaration_then_default(): void
    {
        $this->fakeActiveExtensions(['acme-shop' => ['products' => ['max_count' => 5, 'retention_days' => 7]]]);
        Config::set('g7_settings.core.privacy.search_history_scopes', [
            ['scope' => 'core.site', 'max_count' => 30, 'retention_days' => 0],
            ['scope' => 'acme-gone.items', 'max_count' => 3, 'retention_days' => 15],
        ]);

        $this->assertSame(['max_count' => 30, 'retention_days' => 0], SearchScopes::effective('core.site'));
        $this->assertSame(['max_count' => 5, 'retention_days' => 7], SearchScopes::effective('acme-shop.products'));
        $this->assertSame(['max_count' => 10, 'retention_days' => 0], SearchScopes::effective('acme-other.none'));
        $this->assertSame(['acme-shop.products' => 7, 'acme-gone.items' => 15], SearchScopes::retentionPolicies());
    }

    /**
     * 관리자 응답 행은 선언 범위마다 한 행(선언 순서)이고, 저장은 화면에 없던 끈 확장의 행을 보존한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings_rows_present_and_merge
     *
     * @effects settings_rows_one_per_declared_scope, saving_keeps_rows_of_inactive_scopes
     */
    public function test_present_rows_and_merge_for_save(): void
    {
        $this->fakeActiveExtensions(['acme-shop' => ['products' => ['max_count' => 5]]]);

        $rows = SearchScopes::presentRows([
            ['scope' => 'acme-shop.products', 'max_count' => 8, 'retention_days' => 2],
            ['scope' => 'acme-gone.items', 'max_count' => 3, 'retention_days' => 15],
        ]);
        $this->assertSame([
            ['scope' => 'core.site', 'max_count' => 10, 'retention_days' => 0],
            ['scope' => 'acme-shop.products', 'max_count' => 8, 'retention_days' => 2],
        ], $rows);

        $saved = SearchScopes::mergeForSave(
            [['scope' => 'core.site', 'max_count' => 12, 'retention_days' => 0]],
            [['scope' => 'acme-gone.items', 'max_count' => 3, 'retention_days' => 15], ['scope' => 'core.site', 'max_count' => 1, 'retention_days' => 1]],
        );
        $this->assertSame([
            ['scope' => 'core.site', 'max_count' => 12, 'retention_days' => 0],
            ['scope' => 'acme-gone.items', 'max_count' => 3, 'retention_days' => 15],
        ], $saved);
    }

    /**
     * 프론트 인라인은 범위 → 보관 개수이고, 기능을 끄면 빈 객체(엔진이 모든 범위를 미선언으로 본다)다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=frontend_inline
     *
     * @effects frontend_inline_lists_scope_max_count, disabled_feature_inlines_no_scopes
     */
    public function test_for_frontend_inlines_max_count_and_empties_when_disabled(): void
    {
        $this->fakeActiveExtensions(['acme-shop' => ['products' => ['max_count' => 5]]]);

        $this->assertSame([
            'core.site' => ['max_count' => 10],
            'acme-shop.products' => ['max_count' => 5],
        ], SearchScopes::forFrontend());

        Config::set('g7_settings.core.privacy.search_history_enabled', false);

        $this->assertSame([], SearchScopes::forFrontend());
    }
}
