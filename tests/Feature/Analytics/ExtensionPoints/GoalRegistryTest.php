<?php

namespace Tests\Feature\Analytics\ExtensionPoints;

use App\Analytics\AnalyticsConfig;
use App\Analytics\DTO\GoalDefinition;
use App\Contracts\Analytics\DimensionRegistryInterface;
use App\Contracts\Analytics\GoalRegistryInterface;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\HookManager;
use App\Services\Analytics\DiagnosticsService;
use App\Services\Analytics\DimensionRegistry;
use App\Services\Analytics\GoalRegistry;
use App\Support\ExtensionDoc\DeclarativeSurfaceCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\FakesAnalyticsExtensions;
use Tests\TestCase;

/**
 * 전환(목표) 등록부 — 코어·확장 선언 수집과 정규화.
 *
 * 등록부가 조용히 선언을 버리면 그 확장의 전환은 「unknown_goal」로 전부 거부된다. 확장
 * 개발자는 자기 목표가 왜 안 쌓이는지 알 길이 없으므로, 버린 선언은 사유와 함께 드러나야 한다.
 */
class GoalRegistryTest extends TestCase
{
    use FakesAnalyticsExtensions;
    use RefreshDatabase;

    /**
     * 코어 목표 두 건이 `core.` 로 등록된다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects core_goals_are_registered_under_core_prefix
     *
     * @return void 없음
     */
    public function test_core_goals_are_registered(): void
    {
        $this->activateAnalyticsExtensions([]);

        $goals = app(GoalRegistryInterface::class)->all();

        $this->assertArrayHasKey('core.user_registered', $goals);
        $this->assertArrayHasKey('core.user_logged_in', $goals);
        $this->assertFalse($goals['core.user_registered']->isExtension());
        $this->assertSame('signup', $goals['core.user_registered']->category);
    }

    /**
     * 활성 모듈·플러그인의 선언이 식별자 접두로 모인다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects extension_goals_are_qualified_with_identifier, goal_owner_type_is_recorded
     *
     * @return void 없음
     */
    public function test_extension_goals_are_collected_with_owner(): void
    {
        $this->activateAnalyticsExtensions(
            [$this->fakeAnalyticsExtension('vendor-shop', [['key' => 'order_completed', 'label' => '주문 완료']])],
            [$this->fakeAnalyticsExtension('vendor-form', [['key' => 'submitted', 'label' => ['ko' => '제출', 'en' => 'Submitted']]])],
        );

        $goals = app(GoalRegistryInterface::class)->all();

        $this->assertSame('module', $goals['vendor-shop.order_completed']->ownerType);
        $this->assertSame('plugin', $goals['vendor-form.submitted']->ownerType);
        $this->assertSame('every_time', $goals['vendor-shop.order_completed']->dedupe, '기본 중복 규칙');
    }

    /**
     * 비활성 확장의 목표는 등록부에서 사라진다.
     *
     * 선언을 DB 에 쌓지 않는 이유가 이것이다 — 확장이 꺼지면 목표도 함께 꺼져야 한다.
     *
     * @scenario point=goal, extension_state=inactive, input=registered
     *
     * @effects inactive_extension_goals_disappear
     *
     * @return void 없음
     */
    public function test_inactive_extension_goals_disappear(): void
    {
        $shop = $this->fakeAnalyticsExtension('vendor-shop', [['key' => 'order_completed', 'label' => '주문 완료']]);

        $this->activateAnalyticsExtensions([$shop]);
        $this->assertNotNull(app(GoalRegistryInterface::class)->find('vendor-shop.order_completed'));

        $this->activateAnalyticsExtensions([]);
        $this->assertNull(app(GoalRegistryInterface::class)->find('vendor-shop.order_completed'));
    }

    /**
     * 잘못된 선언은 그 항목만 버리고 사유를 남긴다.
     *
     * @scenario point=goal, extension_state=active, input=unregistered
     *
     * @effects malformed_goal_declaration_is_rejected_with_reason, valid_goals_survive_a_malformed_sibling
     *
     * @return void 없음
     */
    public function test_malformed_declarations_are_rejected_with_reason(): void
    {
        $this->activateAnalyticsExtensions([$this->fakeAnalyticsExtension('vendor-shop', [
            ['key' => 'Bad Key', 'label' => 'x'],
            ['key' => 'no_label'],
            ['key' => 'bad_category', 'label' => 'x', 'category' => 'lottery'],
            ['key' => 'bad_dedupe', 'label' => 'x', 'dedupe' => 'sometimes'],
            ['key' => 'bad_attr', 'label' => 'x', 'attributes' => ['amount' => ['type' => 'money']]],
            ['key' => 'enum_without_values', 'label' => 'x', 'attributes' => ['tier' => ['type' => 'enum']]],
            ['key' => 'valid', 'label' => 'ok'],
            ['key' => 'valid', 'label' => 'duplicate'],
        ])]);

        $registry = app(GoalRegistryInterface::class);
        $warnings = implode("\n", $registry->warnings());

        $this->assertSame(['vendor-shop.valid'], array_values(array_filter(
            array_keys($registry->all()),
            fn (string $key): bool => str_starts_with($key, 'vendor-shop.'),
        )));

        foreach (['Bad Key', 'no_label', 'lottery', 'sometimes', 'money', 'enum', '두 번'] as $needle) {
            $this->assertStringContainsString($needle, $warnings, "경고에 {$needle} 사유가 없다");
        }
    }

    /**
     * 선언 필드에 문자열·숫자 대신 배열·객체가 와도 두 등록부가 멈추지 않는다.
     *
     * 문자열로 바꾸는 자리(`category`·`scope`·enum `values` 등)는 배열·객체를 받으면 PHP 가
     * 던진다. 그 한 항목이 등록부 전체를 멈추면 차원 쪽은 그날 집계·수집 진단·차원 선택란이,
     * 목표 쪽은 목표 비콘과 코어 가입·로그인 전환이 함께 멈춘다. 모집단은 두 등록부의 정규화
     * 소스가 읽는 선언 필드 전부다 — 필드가 늘어도 케이스 없이 따라온다.
     *
     * @scenario point=goal, extension_state=active, input=unregistered
     *
     * @effects malformed_field_types_never_stop_the_registries
     *
     * @return void 없음
     */
    public function test_malformed_field_types_never_stop_the_registries(): void
    {
        $fieldsOf = function (string $class, string $variable): array {
            $source = (string) file_get_contents((new \ReflectionClass($class))->getFileName());
            preg_match_all('/\$'.$variable.'\[\'([a-z_]+)\'\]/', $source, $matches);

            return array_values(array_unique($matches[1]));
        };

        $populations = [
            'goal item' => $fieldsOf(GoalRegistry::class, 'item'),
            'goal attribute rule' => $fieldsOf(GoalRegistry::class, 'rule'),
            'dimension item' => $fieldsOf(DimensionRegistry::class, 'item'),
        ];
        foreach ($populations as $name => $fields) {
            $this->assertNotEmpty($fields, "{$name} 필드를 소스에서 찾지 못했다 — 가드 모집단이 비었다");
        }
        $this->assertContains('values', $populations['dimension item'], 'enum 값 목록 자리가 모집단에 없다');

        $bad = ['array' => ['x'], 'object' => new \stdClass];
        $cases = 0;

        foreach ($bad as $badName => $badValue) {
            foreach ($populations['goal item'] as $field) {
                $item = ['key' => 'probe', 'label' => ['ko' => 'P'], $field => $badValue];
                $this->assertGoalRegistrySurvives($item, "목표 {$field}={$badName}");
                $cases++;
            }

            foreach ($populations['goal attribute rule'] as $field) {
                $rule = array_merge(['type' => $field === 'values' ? 'enum' : 'string'], [$field => $badValue]);
                $this->assertGoalRegistrySurvives(
                    ['key' => 'probe', 'label' => ['ko' => 'P'], 'attributes' => ['amount' => $rule]],
                    "목표 속성 {$field}={$badName}",
                );
                $cases++;
            }

            foreach ($populations['dimension item'] as $field) {
                $item = array_merge(
                    ['key' => 'probe', 'label' => ['ko' => 'P']],
                    $field === 'values' ? ['value_type' => 'enum'] : [],
                    [$field => $badValue],
                );
                $this->activateAnalyticsExtensions([$this->fakeAnalyticsExtension('vendor-probe', [], [
                    $item,
                    ['key' => 'ok', 'label' => ['ko' => '정상']],
                ])]);

                try {
                    $registry = app(DimensionRegistryInterface::class);
                    $dimensions = $registry->all();
                    $registry->warnings();
                } catch (\Throwable $e) {
                    $this->fail("차원 {$field}={$badName} 선언 하나가 차원 등록부를 멈췄다 — ".$e->getMessage());
                }
                $this->assertArrayHasKey('vendor-probe:ok', $dimensions, "차원 {$field}={$badName}: 옆 선언이 함께 사라졌다");
                $cases++;
            }
        }

        $this->assertGreaterThanOrEqual(20, $cases, '잰 경우의 수가 너무 적다 — 모집단 파생을 확인하라');
    }

    /**
     * 목표 선언 하나를 넣고 등록부가 멈추지 않으며 옆 선언·코어 목표가 남는지 봅니다.
     *
     * @param  array<string, mixed>  $item  시험할 목표 선언
     * @param  string  $case  실패 메시지에 쓸 경우 이름
     * @return void 없음
     */
    private function assertGoalRegistrySurvives(array $item, string $case): void
    {
        $this->activateAnalyticsExtensions([$this->fakeAnalyticsExtension('vendor-probe', [
            $item,
            ['key' => 'ok', 'label' => ['ko' => '정상']],
        ])]);

        try {
            $registry = app(GoalRegistryInterface::class);
            $goals = $registry->all();
            $registry->warnings();
        } catch (\Throwable $e) {
            $this->fail("{$case} 선언 하나가 목표 등록부를 멈췄다 — ".$e->getMessage());
        }

        $this->assertArrayHasKey('vendor-probe.ok', $goals, "{$case}: 옆 선언이 함께 사라졌다");
        $this->assertArrayHasKey('core.user_registered', $goals, "{$case}: 코어 목표가 함께 사라졌다");
    }

    /**
     * 버린 선언의 사유가 운영자가 보는 진단 응답에 실린다(목표·차원 모두).
     *
     * `warnings()` 만 있고 읽는 곳이 없으면 선언을 잘못한 확장 개발자도, 그 확장을 켠 운영자도
     * 왜 전환·차원이 안 쌓이는지 볼 수 없다 — 나중에 `unknown_goal` 로만 간접적으로 드러난다.
     *
     * @scenario point=goal, extension_state=active, input=unregistered
     *
     * @effects declaration_warnings_are_shown_in_diagnostics
     *
     * @return void 없음
     */
    public function test_declaration_warnings_reach_the_diagnostics_response(): void
    {
        $this->activateAnalyticsExtensions([$this->fakeAnalyticsExtension('vendor-shop', [
            ['key' => 'Bad Key', 'label' => 'x'],
            ['key' => 'valid', 'label' => 'ok'],
        ], [
            ['key' => 'no_label_dim'],
        ])]);

        $warnings = app(DiagnosticsService::class)->diagnostics(AnalyticsConfig::fromSettings())['declaration_warnings'] ?? null;

        $this->assertIsArray($warnings);
        $joined = implode("\n", $warnings);
        $this->assertStringContainsString('Bad Key', $joined, '목표 선언 경고가 진단에 없다');
        $this->assertStringContainsString('no_label_dim', $joined, '차원 선언 경고가 진단에 없다');
    }

    /**
     * getter 가 던져도 다른 확장의 선언과 코어 목표는 살아남는다.
     *
     * @scenario point=goal, extension_state=active, input=unregistered
     *
     * @effects goal_getter_failure_is_isolated
     *
     * @return void 없음
     */
    public function test_getter_failure_is_isolated(): void
    {
        $this->activateAnalyticsExtensions([
            $this->fakeAnalyticsExtension('vendor-broken', fn () => throw new \RuntimeException('boom')),
            $this->fakeAnalyticsExtension('vendor-shop', [['key' => 'order_completed', 'label' => '주문 완료']]),
        ]);

        $registry = app(GoalRegistryInterface::class);

        $this->assertNotNull($registry->find('vendor-shop.order_completed'));
        $this->assertNotNull($registry->find('core.user_registered'));
        $this->assertStringContainsString('vendor-broken', implode("\n", $registry->warnings()));
    }

    /**
     * 필터로 목표를 숨길 수 있다(운영자가 끈 기능의 목표).
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects registered_goals_filter_can_hide_goals
     *
     * @return void 없음
     */
    public function test_registered_goals_filter_can_hide_goals(): void
    {
        $this->activateAnalyticsExtensions([]);

        HookManager::addFilter('core.analytics.registered_goals', function (array $goals): array {
            unset($goals['core.user_logged_in']);
            $goals['broken'] = 'not a definition';

            return $goals;
        });

        $registry = app(GoalRegistryInterface::class);

        $this->assertNull($registry->find('core.user_logged_in'));
        $this->assertNotNull($registry->find('core.user_registered'));
        $this->assertStringContainsString('broken', implode("\n", $registry->warnings()));
    }

    /**
     * 선언된 속성 스키마로 전환 속성을 검증한다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects goal_attributes_are_validated_against_the_declared_schema
     *
     * @return void 없음
     */
    public function test_attribute_schema_validation(): void
    {
        $goal = new GoalDefinition('order', 'module', 'vendor-shop', ['default' => '주문'], [], 'purchase', 'every_time', [
            'revenue' => ['type' => 'decimal', 'required' => true, 'min' => 0],
            'currency' => ['type' => 'enum', 'required' => true, 'values' => ['KRW', 'USD']],
            'qty' => ['type' => 'int', 'max' => 10],
            'gift' => ['type' => 'bool'],
        ], 30, []);

        [$clean, $error] = $goal->validateAttributes(['revenue' => '100.5', 'currency' => 'KRW', 'qty' => '3', 'gift' => 'true', 'extra' => 'dropped']);
        $this->assertNull($error);
        $this->assertSame(['revenue' => 100.5, 'currency' => 'KRW', 'qty' => 3, 'gift' => true], $clean, '선언 밖 속성은 버린다');

        $this->assertStringContainsString('revenue', (string) $goal->validateAttributes(['currency' => 'KRW'])[1], '필수 누락');
        $this->assertStringContainsString('revenue', (string) $goal->validateAttributes(['revenue' => -1, 'currency' => 'KRW'])[1], '하한');
        $this->assertStringContainsString('currency', (string) $goal->validateAttributes(['revenue' => 1, 'currency' => 'EUR'])[1], '허용값');
        $this->assertStringContainsString('qty', (string) $goal->validateAttributes(['revenue' => 1, 'currency' => 'KRW', 'qty' => '1.5'])[1], '정수');
        $this->assertStringContainsString('qty', (string) $goal->validateAttributes(['revenue' => 1, 'currency' => 'KRW', 'qty' => 11])[1], '상한');
    }

    /**
     * 확장 문서 생성기가 두 선언 getter 를 수집 대상으로 안다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects declaration_getters_are_listed_in_extension_docs, abstract_extensions_default_to_empty_declarations
     *
     * @return void 없음
     */
    public function test_getters_are_part_of_the_declarative_surface(): void
    {
        $this->assertArrayHasKey('getAnalyticsGoals', DeclarativeSurfaceCollector::GETTERS);
        $this->assertArrayHasKey('getAnalyticsDimensions', DeclarativeSurfaceCollector::GETTERS);

        foreach ([AbstractModule::class, AbstractPlugin::class] as $class) {
            foreach (['getAnalyticsGoals', 'getAnalyticsDimensions'] as $method) {
                $reflection = new \ReflectionMethod($class, $method);
                $this->assertTrue($reflection->isPublic(), "{$class}::{$method}");
                $this->assertSame('array', (string) $reflection->getReturnType());
            }
        }
    }
}
