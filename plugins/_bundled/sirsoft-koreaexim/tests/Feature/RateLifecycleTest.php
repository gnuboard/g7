<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Feature;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RunTrigger;
use Plugins\Sirsoft\Koreaexim\Http\Requests\Admin\IndexRateRunRequest;
use Plugins\Sirsoft\Koreaexim\Http\Resources\RateRunResource;
use Plugins\Sirsoft\Koreaexim\Listeners\ReapplyRatesOnCurrencyChangeListener;
use Plugins\Sirsoft\Koreaexim\Listeners\RegisterExchangeRateProviderListener;
use Plugins\Sirsoft\Koreaexim\Listeners\UpdateFailedNotificationDataListener;
use Plugins\Sirsoft\Koreaexim\Listeners\ValidatePluginSettingsListener;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Plugin;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;
use Plugins\Sirsoft\Koreaexim\Services\ExchangeRateUpdateService;
use Plugins\Sirsoft\Koreaexim\Services\ManagedCurrencyResolver;
use Plugins\Sirsoft\Koreaexim\Services\RateRunService;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class RateLifecycleTest extends PluginTestCase
{
    /**
     * 실패 종류와 알림 설정 및 재시도 소진 조합별 발송 여부를 검증합니다.
     *
     * @effects failure_notification_policy
     *
     * @return void 반환값 없음
     */
    public function test_failure_notification_matrix(): void
    {
        $body = [];
        $network = false;
        Http::fake(function () use (&$body, &$network) {
            return Http::response($body, $network ? 503 : 200);
        });
        $notifications = [];
        HookManager::addAction('sirsoft-koreaexim.update.failed', function ($run) use (&$notifications) {
            $notifications[] = $run->id;
        });
        $cases = require dirname(__DIR__).'/fixtures/NotificationCases.php';
        foreach ($cases as $index => [$status, $enabled, $notified, $exhausted]) {
            $this->travelTo(Carbon::create(2026, 9, 14, 11, 30, 0, 'Asia/Seoul')->addDays($index));
            $this->configure(['notify_on_failure' => $enabled === 'on', 'retry_max' => $exhausted === 'yes' ? 0 : 3, 'run_days' => [1, 2, 3, 4, 5, 6, 7], 'lookback_days' => 0]);
            $body = match ($status) {
                'auth_error' => [['result' => 3]], 'quota_exceeded' => [['result' => 4]],
                'no_data' => [], 'bad_response' => '<html>invalid</html>', default => $this->quotes(),
            };
            $network = $status === 'network_error';
            if ($notified === 'yes') {
                app(CacheInterface::class)->put('koreaexim:notified:'.$status.':'.now('Asia/Seoul')->format('Ymd'), true, 86400);
            }
            $before = count($notifications);
            $run = app(ExchangeRateUpdateService::class)->execute(RunTrigger::Scheduled);
            $expected = $enabled === 'on' && $notified === 'no' && (in_array($status, ['auth_error', 'quota_exceeded', 'bad_response'], true) || ($network && $exhausted === 'yes'));
            $this->assertSame($expected ? 1 : 0, count($notifications) - $before, implode('/', $cases[$index]));
            $this->assertNotNull($run);
        }
    }

    /**
     * DB 큐 환경에서도 환율 재적용 훅이 동기 실행되고 다른 설정은 무시하는지 검증합니다.
     *
     * @effects reapply_hook_runs_synchronously_with_database_queue_and_ignores_other_categories
     *
     * @return void 반환값 없음
     */
    public function test_reapply_hook_runs_synchronously_with_database_queue_and_ignores_other_categories(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $service = \Mockery::mock(ExchangeRateUpdateService::class);
        $service->shouldReceive('reapply')->once();
        $this->app->instance(ExchangeRateUpdateService::class, $service);
        app(HookListenerRegistrar::class)->register(ReapplyRatesOnCurrencyChangeListener::class);
        HookManager::doAction('sirsoft-ecommerce.settings.after_save', ['general']);
        HookManager::doAction('sirsoft-ecommerce.settings.after_save', ['language_currency']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /**
     * 실패 알림 정의와 치환 변수가 플러그인 소유 계약을 따르는지 검증합니다.
     *
     * @effects failure_definition_and_extracted_template_variables_belong_to_plugin
     *
     * @return void 반환값 없음
     */
    public function test_failure_definition_and_extracted_template_variables_belong_to_plugin(): void
    {
        $definition = (new Plugin)->getNotificationDefinitions()[0];
        $this->assertSame('sirsoft-koreaexim', $definition['hook_prefix']);
        $this->assertSame(['mail', 'database'], $definition['channels']);
        foreach ($definition['templates'] as $template) {
            $this->assertSame([['type' => 'role', 'value' => 'admin']], $template['recipients']);
        }
        $run = new KoreaeximRateRun(['status' => RunStatus::AuthError, 'message' => '인증 오류', 'search_date' => '2026-09-14']);
        $listener = new UpdateFailedNotificationDataListener;
        $data = $listener->extract([], 'koreaexim_update_failed', [$run]);
        $this->assertSame('2026-09-14', $data['data']['search_date']);
        $this->assertSame('{recipient_name}', $data['data']['name']);
        $this->assertStringEndsWith('/admin/plugins/sirsoft-koreaexim/settings', $data['data']['settings_url']);
        $this->assertSame(['preserved' => true], $listener->extract(['preserved' => true], 'other', [$run]));
    }

    /**
     * 각 테스트의 격리된 설정과 의존 객체를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Seoul')->setDate(2026, 9, 14)->setTime(11, 30));
        $settings = app(EcommerceSettingsService::class);
        $currency = $settings->getSettings('language_currency');
        $currency['rate_provider'] = 'koreaexim';
        $settings->saveSettings(['language_currency' => $currency]);
    }

    /**
     * 재적용이 외부 요청 없이 스냅샷을 사용하고 반복해도 결과가 같은지 검증합니다.
     *
     * @effects reapply_uses_snapshot_without_http_and_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_reapply_uses_snapshot_without_http_and_is_idempotent(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $service = app(ExchangeRateUpdateService::class);
        $source = $service->execute(RunTrigger::Manual);
        $settings = app(EcommerceSettingsService::class);
        $currency = $settings->getSettings('language_currency');
        foreach ($currency['currencies'] as &$row) {
            if ($row['code'] === 'USD') {
                $row['exchange_rate'] = 123;
            }
        }
        unset($row);
        $settings->saveSettings(['language_currency' => $currency]);
        $service->reapply();
        $repo = app(RateRunRepositoryInterface::class);
        $latest = $repo->latestSuccessful();
        $this->assertSame(RunTrigger::CurrencyChanged, $latest->trigger);
        $this->assertSame($source->id, $latest->source_run_id);
        $this->assertSame(0, $latest->request_count);
        $service->reapply();
        $this->assertSame($latest->id, $repo->latestSuccessful()->id);
        Http::assertSentCount(1);
    }

    /**
     * 기준 단위 변경 시 마지막 성공 응답에서 환율을 다시 계산하는지 검증합니다.
     *
     * @effects base_unit_change_reapplies_without_http
     *
     * @return void 반환값 없음
     */
    public function test_base_unit_change_recalculates_rates_from_last_success(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $updates = app(ExchangeRateUpdateService::class);
        $updates->execute(RunTrigger::Manual);
        $settings = app(EcommerceSettingsService::class);
        $currency = $settings->getSettings('language_currency');
        $before = collect($currency['currencies'])->firstWhere('code', 'USD')['exchange_rate'];
        foreach ($currency['currencies'] as &$row) {
            if ($row['code'] === $currency['default_currency']) {
                $row['base_unit'] *= 2;
            }
        }
        unset($row);
        $settings->saveSettings(['language_currency' => $currency]);
        $updates->reapply();
        $after = collect($settings->getSettings('language_currency')['currencies'])->firstWhere('code', 'USD')['exchange_rate'];
        $this->assertEqualsWithDelta($before * 2, $after, 0.000001);
        $latest = app(RateRunRepositoryInterface::class)->latestSuccessful();
        $this->assertSame(RunTrigger::CurrencyChanged, $latest->trigger);
        $this->assertSame(0, $latest->request_count);
        Http::assertSentCount(1);
    }

    /**
     * 관리 대상 판정에 인증키와 자동 관리 및 지원 기본통화가 필요한지 검증합니다.
     *
     * @effects management_requires_key_and_auto_manage_and_supported_base
     *
     * @return void 반환값 없음
     */
    public function test_management_requires_key_and_auto_manage_and_supported_base(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        app(ExchangeRateUpdateService::class)->execute(RunTrigger::Manual);
        $resolver = app(ManagedCurrencyResolver::class);
        $this->assertContains('USD', $resolver->resolve()['managed_codes']);
        $this->configure(['auth_key' => '']);
        $this->assertSame('auth_key_required', $resolver->resolve()['notice_key']);
        $this->assertSame([], $resolver->resolve()['managed_codes']);
        $this->configure(['auth_key' => 'fixture-key', 'auto_manage' => false]);
        $this->assertSame([], $resolver->resolve()['managed_codes']);
        $this->assertNull(app(RateRunService::class)->status()['next_run_at_kst']);
        $this->configure(['auto_manage' => true]);
        $settings = app(EcommerceSettingsService::class);
        $currency = $settings->getSettings('language_currency');
        $currency['default_currency'] = 'ZZZ';
        $currency['currencies'][] = ['code' => 'ZZZ', 'base_unit' => 1, 'exchange_rate' => 1];
        $settings->saveSettings(['language_currency' => $currency]);
        $this->assertSame('unsupported_base', $resolver->resolve()['notice_key']);
        $this->assertSame([], $resolver->resolve()['managed_codes']);
        Http::assertSentCount(1);
    }

    /**
     * 정리가 최신 성공을 보존하고 오래된 실행의 항목부터 삭제하는지 검증합니다.
     *
     * @effects pruning_preserves_latest_success_and_deletes_children_of_old_runs
     *
     * @return void 반환값 없음
     */
    public function test_pruning_preserves_latest_success_and_deletes_children_of_old_runs(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $this->travel(-400)->days();
        $service = app(ExchangeRateUpdateService::class);
        $old = $service->execute(RunTrigger::Manual);
        $keep = $service->execute(RunTrigger::Manual);
        $this->travel(400)->days();
        $repo = app(RateRunRepositoryInterface::class);
        $this->assertSame(1, $repo->prune(365));
        $this->assertDatabaseMissing('koreaexim_rate_runs', ['id' => $old->id]);
        $this->assertDatabaseMissing('koreaexim_rate_run_items', ['run_id' => $old->id]);
        $this->assertSame($keep->id, $repo->latestSuccessful()->id);
        $this->assertGreaterThan(0, $keep->items()->count());
        $this->assertSame(0, $repo->prune(365));
    }

    /**
     * 같은 실패 알림을 중복 발송하지 않고 연결 테스트는 알리지 않는지 검증합니다.
     *
     * @effects failure_notifications_are_deduplicated_and_dry_run_is_silent
     *
     * @return void 반환값 없음
     */
    public function test_failure_notifications_are_deduplicated_and_dry_run_is_silent(): void
    {
        $this->configure(['notify_on_failure' => true]);
        $notifications = [];
        HookManager::addAction('sirsoft-koreaexim.update.failed', function ($run) use (&$notifications) {
            $notifications[] = $run->status;
        });
        Http::fake(['*' => Http::response([['result' => 3]])]);
        $service = app(ExchangeRateUpdateService::class);
        $service->execute(RunTrigger::Manual, dryRun: true);
        $this->assertSame([], $notifications);
        $service->execute(RunTrigger::Manual);
        $service->execute(RunTrigger::Manual);
        $this->assertSame([RunStatus::AuthError], $notifications);
    }

    /**
     * 이력 필터와 응답이 동작하며 원본 API 응답을 노출하지 않는지 검증합니다.
     *
     * @effects history_filters_and_resources_do_not_expose_raw_responses
     *
     * @return void 반환값 없음
     */
    public function test_history_filters_and_resources_do_not_expose_raw_responses(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $run = app(ExchangeRateUpdateService::class)->execute(RunTrigger::Manual);
        $repo = app(RateRunRepositoryInterface::class);
        $this->assertCount(1, $repo->paginate(['status' => $run->status->value, 'date_to' => '2026-09-14']));
        $this->assertCount(0, $repo->paginate(['status' => 'auth_error']));
        $resource = (new RateRunResource($repo->find($run->id)))->resolve(request());
        $this->assertArrayNotHasKey('raw_response', $resource);
        $this->assertNotEmpty($resource['items']);
        $this->assertNull($repo->latest()->raw_response);
        $request = new IndexRateRunRequest(['date_to' => '2026-09-14']);
        $this->assertFalse(Validator::make($request->all(), $request->rules())->fails());
        $request = new IndexRateRunRequest(['date_from' => '2026-09-15', 'date_to' => '2026-09-14']);
        $this->assertTrue(Validator::make($request->all(), $request->rules())->fails());
    }

    /**
     * 수동 실행 쿨다운이 두 번째 외부 요청을 차단하는지 검증합니다.
     *
     * @effects manual_cooldown_rejects_second_request_without_http
     *
     * @return void 반환값 없음
     */
    public function test_manual_cooldown_rejects_second_request_without_http(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $service = app(RateRunService::class);
        $service->manual(true, null);
        try {
            $service->manual(true, null);
            $this->fail('Second manual call must be rate limited');
        } catch (TooManyRequestsHttpException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
        }
        Http::assertSentCount(1);
    }

    /**
     * 인증키와 실행 시각 및 요일을 검증하고 다른 플러그인 설정을 건드리지 않는지 검증합니다.
     *
     * @effects settings_validate_key_time_weekdays_and_leave_other_plugins_untouched
     *
     * @return void 반환값 없음
     */
    public function test_settings_validate_key_time_weekdays_and_leave_other_plugins_untouched(): void
    {
        $listener = new ValidatePluginSettingsListener;
        $this->assertSame(['existing' => ['required']], $listener->rules(['existing' => ['required']], 'other'));
        foreach ([['auto_manage' => true, 'auth_key' => ''], ['update_time' => '24:00'], ['run_days' => [1, 1]], ['run_days' => [8]]] as $input) {
            $this->assertTrue(Validator::make($input, $listener->rules([], 'sirsoft-koreaexim', $input))->fails());
        }
        $input = ['auto_manage' => true, 'auth_key' => '••••••••', 'update_time' => '23:59', 'run_days' => []];
        $this->assertFalse(Validator::make($input, $listener->rules([], 'sirsoft-koreaexim', $input))->fails());
    }

    /**
     * 제공자 등록 훅 이름과 모듈이 읽는 페이로드 계약을 검증합니다.
     *
     * @effects management_requires_key_and_auto_manage_and_supported_base
     *
     * @return void 반환값 없음
     */
    public function test_provider_registration_payload_matches_module_contract(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $settings = app(EcommerceSettingsService::class);
        $currency = $settings->getSettings('language_currency');
        // API 가 주지 않는 통화는 잠그지 않고 수동 관리로 남겨야 합니다.
        $currency['currencies'][] = ['code' => 'VND', 'base_unit' => 1, 'exchange_rate' => 18000];
        $settings->saveSettings(['language_currency' => $currency]);
        app(ExchangeRateUpdateService::class)->execute(RunTrigger::Manual);
        app(HookListenerRegistrar::class)->register(RegisterExchangeRateProviderListener::class);
        // 훅 이름이 어긋나면 모듈은 제공자를 영원히 보지 못합니다.
        $providers = HookManager::applyFilters('sirsoft-ecommerce.currency.registered_rate_providers', []);
        $this->assertCount(1, $providers);
        $provider = $providers[0];
        // 제공자 id 는 플러그인 식별자가 아니라 짧은 이름이며, 모듈 설정값과 같아야 잠금이 걸립니다.
        $this->assertSame('koreaexim', $provider['id']);
        $this->assertSame('/admin/plugins/sirsoft-koreaexim/settings', $provider['settings_url']);
        $this->assertContains('USD', $provider['managed_codes']);
        $this->assertContains('VND', $provider['unsupported_codes']);
        $this->assertNotContains('VND', $provider['managed_codes']);
        $this->assertNotNull($provider['last_updated_at']);
        $this->assertArrayHasKey('notice_key', $provider);
        $this->assertNotEmpty($provider['name']);
    }

    /**
     * 성공 이력이 없으면 재적용이 아무 것도 하지 않고, 기본통화가 바뀌면 전 통화를 다시 계산하는지 검증합니다.
     *
     * @effects reapply_uses_snapshot_without_http_and_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_reapply_is_inert_without_success_run_and_recalculates_every_currency_on_base_change(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $updates = app(ExchangeRateUpdateService::class);
        // 성공 런이 없으면 재적용의 원천이 없으므로 정기·수동 실행이 처리하도록 물러나야 합니다.
        $updates->reapply();
        $this->assertNull(app(RateRunRepositoryInterface::class)->latestSuccessful());
        $this->assertSame(0, KoreaeximRateRun::count());
        Http::assertNothingSent();

        $updates->execute(RunTrigger::Manual);
        $settings = app(EcommerceSettingsService::class);
        $currency = $settings->getSettings('language_currency');
        $before = collect($currency['currencies'])->pluck('exchange_rate', 'code')->all();
        $currency['default_currency'] = 'USD';
        $settings->saveSettings(['language_currency' => $currency]);
        $updates->reapply();
        $after = collect($settings->getSettings('language_currency')['currencies'])->pluck('exchange_rate', 'code')->all();
        // 기본통화 행은 비고, 나머지는 전부 새 기준으로 다시 계산돼야 합니다.
        $this->assertNull($after['USD']);
        $recalculated = 0;
        foreach ($after as $code => $rate) {
            if ($code === 'USD' || $rate === null || ($before[$code] ?? null) === null) {
                continue;
            }
            $this->assertNotEqualsWithDelta($before[$code], $rate, 0.000001, $code);
            $recalculated++;
        }
        $this->assertGreaterThan(0, $recalculated, '재계산 대상이 비면 이 검사는 아무것도 재지 않습니다.');
        $latest = app(RateRunRepositoryInterface::class)->latestSuccessful();
        $this->assertSame(RunTrigger::CurrencyChanged, $latest->trigger);
        $this->assertSame(0, $latest->request_count);
        Http::assertSentCount(1);
    }
}
