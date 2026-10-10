<?php

namespace Tests\Unit\Lang;

use App\Exceptions\Auth\AccountLockedException;
use App\Extension\Layout\LayoutSyncSummary;
use App\Extension\Traits\ValidatesLayoutFiles;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\BaseApiController;
use App\Http\Controllers\Concerns\BuildsAuthFailureResponses;
use App\Services\Analytics\ReportService;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 코어 백엔드 영어 메시지의 단·복수 테스트.
 *
 * 종전 영어 문구는 수와 상관없이 `:count item(s)` 처럼 적었다. 프런트 사전이 `count` 로 CLDR 범주를 고르는 것과
 * 같게(1 → 단수, 0·2 이상 → 복수), 서버 메시지도 `trans_choice` 형(`단수|복수`)으로 고른다. 한국어 문구는
 * 복수 구분이 없어 그대로다.
 */
class BackendPluralMessagesTest extends TestCase
{
    /**
     * 코어 사전 키별 [치환값, 수 자리표시자 이름, 1일 때 영어, 2일 때 영어].
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string, 4: string}> 데이터 이름 => [다국어 키, 수 외 치환값, 수 자리표시자, 1일 때 영어, 2일 때 영어]
     */
    public static function coreKeys(): array
    {
        return [
            'analytics.bucket.pages_one' => ['analytics.bucket.pages_one', [], 'n', '1 page', '2 pages'],
            'auth.account_locked' => ['auth.account_locked', [], 'minutes',
                'Too many failed login attempts. Your account is locked for 1 minute. Resetting your password unlocks it right away.',
                'Too many failed login attempts. Your account is locked for 2 minutes. Resetting your password unlocks it right away.'],
            'auth.too_many_attempts' => ['auth.too_many_attempts', [], 'seconds',
                'Too many requests. Please try again in 1 second.',
                'Too many requests. Please try again in 2 seconds.'],
            'extensions.layout_sync.no_base' => ['extensions.layout_sync.no_base', [], 'count',
                '(1 screen without a comparison base was kept as is)',
                '(2 screens without a comparison base were kept as is)'],
            'layouts.errors.delete_blocked_by_reference' => ['layouts.errors.delete_blocked_by_reference', [], 'count',
                '1 layout references this file, so it cannot be deleted. Clear that reference first.',
                '2 layouts reference this file, so it cannot be deleted. Clear those references first.'],
            'layouts.errors.rename_blocked_by_reference' => ['layouts.errors.rename_blocked_by_reference', [], 'count',
                '1 layout references this file, so it cannot be renamed. Clear that reference first.',
                '2 layouts reference this file, so it cannot be renamed. Clear those references first.'],
            'member_notification.bulk_deleted' => ['member_notification.bulk_deleted', [], 'count',
                '1 notification deleted.', '2 notifications deleted.'],
            'menu.bulk_roles_success' => ['menu.bulk_roles_success', [], 'count',
                'Updated the roles of 1 menu.', 'Updated the roles of 2 menus.'],
            'modules.commands.update.modified_layouts_warning' => ['modules.commands.update.modified_layouts_warning', [], 'count',
                '1 operator-modified layout/extension fragment will be overwritten:',
                '2 operator-modified layouts/extension fragments will be overwritten:'],
            'plugins.commands.update.modified_layouts_warning' => ['plugins.commands.update.modified_layouts_warning', [], 'count',
                '1 operator-modified layout/extension fragment will be overwritten:',
                '2 operator-modified layouts/extension fragments will be overwritten:'],
            'templates.commands.update.modified_layouts_warning' => ['templates.commands.update.modified_layouts_warning', [], 'count',
                '1 operator-modified layout/extension fragment will be overwritten:',
                '2 operator-modified layouts/extension fragments will be overwritten:'],
            'plugins.errors.layout_validation_validation_failed' => ['plugins.errors.layout_validation_validation_failed', ['identifier' => 'probe'], 'count',
                'Layout validation failed for plugin probe. 1 error found.',
                'Layout validation failed for plugin probe. 2 errors found.'],
            'modules.errors.layout_validation_validation_failed' => ['modules.errors.layout_validation_validation_failed', ['identifier' => 'probe'], 'count',
                'Layout validation failed for module probe. 1 error found.',
                'Layout validation failed for module probe. 2 errors found.'],
            'templates.errors.layout_validation_validation_failed' => ['templates.errors.layout_validation_validation_failed', ['identifier' => 'probe'], 'count',
                'Layout validation failed for probe template. (1 error)',
                'Layout validation failed for probe template. (2 errors)'],
            'search.index.rebuild_targets' => ['search.index.rebuild_targets', [], 'count',
                '1 index to rebuild', '2 indexes to rebuild'],
            'search.index.stale_after_update' => ['search.index.stale_after_update', [], 'count',
                '1 search index holds no indexed content, so that search returns nothing.',
                '2 search indexes hold no indexed content, so those searches return nothing.'],
            'templates.commands.install.layouts_created' => ['templates.commands.install.layouts_created', [], 'count',
                '1 layout created', '2 layouts created'],
            'templates.commands.uninstall.confirm_details.layouts' => ['templates.commands.uninstall.confirm_details.layouts', [], 'count',
                '- 1 layout will be deleted', '- 2 layouts will be deleted'],
            'templates.commands.uninstall.layouts_deleted' => ['templates.commands.uninstall.layouts_deleted', [], 'count',
                '1 layout deleted', '2 layouts deleted'],
            'templates.commands.list.summary' => ['templates.commands.list.summary', ['installed' => 1, 'active' => 0], 'total',
                'Total 1 template (Installed: 1, Active: 0)', 'Total 2 templates (Installed: 1, Active: 0)'],
            'templates.commands.cache_clear.success_single' => ['templates.commands.cache_clear.success_single', ['template' => 'probe'], 'count',
                'Cleared 1 cache for template "probe"', 'Cleared 2 caches for template "probe"'],
            'templates.commands.cache_clear.success_all' => ['templates.commands.cache_clear.success_all', [], 'count',
                'Cleared 1 template cache total', 'Cleared 2 template caches total'],
            'user.bulk_status_updated' => ['user.bulk_status_updated', [], 'count',
                'Status of 1 user updated successfully.', 'Status of 2 users updated successfully.'],
            'user.bulk_roles_updated' => ['user.bulk_roles_updated', ['skipped' => 0], 'count',
                'Changed roles of 1 user. (0 skipped)', 'Changed roles of 2 users. (0 skipped)'],
            'user.bulk_roles_updated_with_unchanged' => ['user.bulk_roles_updated_with_unchanged', ['skipped' => 0, 'unchanged' => 1], 'count',
                'Changed roles of 1 user. (0 skipped, 1 already up to date)', 'Changed roles of 2 users. (0 skipped, 1 already up to date)'],
        ];
    }

    /**
     * 코어·번들 확장의 영어 백엔드 사전에 `(s)`·`(es)`·`(ies)` 표기가 새로 생기지 않는다.
     *
     * 남은 키는 서버 호출부가 없는(프런트 사전이 대신 쓰는) 키뿐이다.
     *
     * @scenario surface=dictionary_scan
     *
     * @effects no_parenthesised_plural_in_english_backend_dictionaries
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function english_backend_dictionaries_have_no_parenthesised_plural(): void
    {
        $unused = [
            'lang/en/templates.php:commands.uninstall.versions_deleted',
            'modules/_bundled/sirsoft-board/src/lang/en/messages.php:reports.blinded_will_be_public',
            'modules/_bundled/sirsoft-ecommerce/src/lang/en/messages.php:logs.images_added',
            'modules/_bundled/sirsoft-ecommerce/src/lang/en/messages.php:logs.images_removed',
            'plugins/_bundled/sirsoft-gdpr/lang/en/messages.php:mypage.privacy.renew_all_success',
        ];

        $files = array_merge(
            glob(base_path('lang/en/*.php')),
            glob(base_path('modules/_bundled/*/src/lang/en/*.php')),
            glob(base_path('plugins/_bundled/*/lang/en/*.php')),
        );

        $offenders = [];
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
            $walk = function (array $lines, string $prefix) use (&$walk, &$offenders, $relative, $unused): void {
                foreach ($lines as $key => $value) {
                    $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                    if (is_array($value)) {
                        $walk($value, $path);
                    } elseif (is_string($value) && preg_match('/\((s|es|ies)\)/', $value) && ! in_array("{$relative}:{$path}", $unused, true)) {
                        $offenders[] = "{$relative}:{$path} => {$value}";
                    }
                }
            };
            $walk(require $file, '');
        }

        $this->assertSame([], $offenders, "영어 백엔드 문구에 (s) 표기가 있다 — `단수|복수` 로 적고 trans_choice/trans_count 로 부른다:\n".implode("\n", $offenders));
    }

    /**
     * 수 하나로 단·복수를 고르는 헬퍼는 영어에서 1 만 단수, 0·2·소수는 복수이고, 수가 없으면 `__()` 와 같다.
     *
     * @scenario surface=helper
     *
     * @effects trans_count_picks_singular_only_for_one
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function trans_count_picks_the_singular_only_for_one(): void
    {
        $this->app->setLocale('en');

        $this->assertSame('1 notification deleted.', trans_count('member_notification.bulk_deleted', ['count' => 1]));
        $this->assertSame('1 notification deleted.', trans_count('member_notification.bulk_deleted', ['count' => '1']));
        $this->assertSame('0 notifications deleted.', trans_count('member_notification.bulk_deleted', ['count' => 0]));
        $this->assertSame('2 notifications deleted.', trans_count('member_notification.bulk_deleted', ['count' => 2]));
        $this->assertSame('1.5 notifications deleted.', trans_count('member_notification.bulk_deleted', ['count' => 1.5]));

        // 수가 아니면 복수 고르기를 하지 않는다 — `__()` 결과와 같다.
        $this->assertSame(__('common.success'), trans_count('common.success'));
        $this->assertSame(__('common.success', ['count' => 'abc']), trans_count('common.success', ['count' => 'abc']));
    }

    /**
     * 각 영어 메시지는 1 이면 단수, 0·2 이면 복수로 나온다.
     *
     * @scenario surface=dictionary
     *
     * @effects english_message_singular_for_one_plural_otherwise
     *
     * @param  string  $key  다국어 키
     * @param  array<string, mixed>  $params  수 외 치환값
     * @param  string  $numberParam  수 자리표시자 이름
     * @param  string  $one  1 일 때 기대 문구
     * @param  string  $two  2 일 때 기대 문구
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('coreKeys')]
    public function english_message_is_singular_for_one_and_plural_otherwise(string $key, array $params, string $numberParam, string $one, string $two): void
    {
        $this->app->setLocale('en');

        $this->assertSame($one, trans_choice($key, 1, $params + [$numberParam => 1]));
        $this->assertSame($two, trans_choice($key, 2, $params + [$numberParam => 2]));
        $this->assertSame(str_replace('2', '0', $two), trans_choice($key, 0, $params + [$numberParam => 0]), "{$key}: 0 은 복수");
    }

    /**
     * 한국어 문구는 복수 구분 기호가 없고, 복수 고르기 호출로 바꿔도 결과가 `__()` 와 같다.
     *
     * @scenario surface=dictionary
     *
     * @effects korean_message_unchanged
     *
     * @param  string  $key  다국어 키
     * @param  array<string, mixed>  $params  수 외 치환값
     * @param  string  $numberParam  수 자리표시자 이름
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('coreKeys')]
    public function korean_message_is_unchanged(string $key, array $params, string $numberParam): void
    {
        $this->app->setLocale('ko');

        foreach ([1, 2] as $n) {
            $replace = $params + [$numberParam => $n];
            $legacy = __($key, $replace);

            $this->assertStringNotContainsString('|', $legacy, "{$key}: 한국어 값에 복수 구분 기호가 있다");
            $this->assertSame($legacy, trans_choice($key, $n, $replace));
        }
    }

    /**
     * API 응답 메시지(ResponseHelper)는 치환값의 `count` 로 단·복수를 고른다.
     *
     * @scenario surface=response_message
     *
     * @effects response_message_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function response_helper_message_follows_count(): void
    {
        $this->app->setLocale('en');

        $one = ResponseHelper::success('menu.bulk_roles_success', null, 200, ['count' => 1])->getData(true);
        $two = ResponseHelper::success('menu.bulk_roles_success', null, 200, ['count' => 2])->getData(true);

        $this->assertSame('Updated the roles of 1 menu.', $one['message']);
        $this->assertSame('Updated the roles of 2 menus.', $two['message']);

        $this->app->setLocale('ko');
        $ko = ResponseHelper::success('menu.bulk_roles_success', null, 200, ['count' => 1])->getData(true);
        $this->assertSame(__('menu.bulk_roles_success', ['count' => 1]), $ko['message']);
    }

    /**
     * 캐시 헤더를 붙이는 성공 응답(`successWithCache`)도 ResponseHelper 와 같게 `count` 로 단·복수를 고른다.
     *
     * 이 응답만 메시지를 따로 번역해 왔다 — `__()` 로 번역하면 `단수|복수` 두 문장이 이어져 나온다.
     *
     * @scenario surface=response_message
     *
     * @effects response_message_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function cached_success_response_message_follows_count(): void
    {
        $this->app->setLocale('en');

        $controller = new class extends BaseApiController
        {
            /**
             * 캐시 헤더가 붙은 성공 응답을 만듭니다.
             *
             * @param  int  $count  메시지에 넣을 수
             * @return JsonResponse 성공 응답
             */
            public function cached(int $count): JsonResponse
            {
                return $this->successWithCache('menu.bulk_roles_success', null, 60, ['count' => $count]);
            }
        };

        $this->assertSame('Updated the roles of 1 menu.', $controller->cached(1)->getData(true)['message']);
        $this->assertSame('Updated the roles of 2 menus.', $controller->cached(2)->getData(true)['message']);
    }

    /**
     * 계정 잠김 응답(423)은 남은 분이 1 이면 「1 minute」 다.
     *
     * @scenario surface=auth_lock
     *
     * @effects account_locked_minute_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function account_locked_response_uses_singular_minute(): void
    {
        $this->app->setLocale('en');

        $controller = new class extends BaseApiController
        {
            use BuildsAuthFailureResponses;

            /**
             * 잠김 응답을 만듭니다.
             *
             * @param  AccountLockedException  $e  잠금 예외
             * @return JsonResponse 423 응답
             */
            public function locked(AccountLockedException $e): JsonResponse
            {
                return $this->lockedResponse($e);
            }
        };

        $one = $controller->locked(new AccountLockedException(now()->addMinute(), 1))->getData(true);
        $two = $controller->locked(new AccountLockedException(now()->addMinutes(2), 2))->getData(true);

        $this->assertStringContainsString('locked for 1 minute.', $one['message']);
        $this->assertStringContainsString('locked for 2 minutes.', $two['message']);
    }

    /**
     * 확장 업데이트의 레이아웃 처리 요약은 두 수를 각각 단·복수로 읽는다. 한국어는 종전 문구 그대로다.
     *
     * @scenario surface=layout_sync
     *
     * @effects layout_sync_summary_pluralises_each_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function layout_sync_summary_pluralises_each_count(): void
    {
        $report = static fn (int $a, int $b) => [
            'modified_count' => 1,
            'applied_count' => $a,
            'conflict_count' => $b,
            'kept_no_base_count' => $a,
            'kept_deleted_by_operator_count' => $a,
            'kept_name_collision_count' => $b,
        ];

        $this->app->setLocale('en');
        $this->assertSame(
            'Layouts: applied 1 new-version change automatically; kept the operator version at 2 places that overlapped operator edits.'
            .' (1 screen without a comparison base was kept as is)'
            .' (skipped 1 screen deleted by the operator and 2 screens with a colliding name)',
            LayoutSyncSummary::text($report(1, 2))
        );
        $this->assertSame(
            'Layouts: applied 2 new-version changes automatically; kept the operator version at 1 place that overlapped operator edits.'
            .' (2 screens without a comparison base were kept as is)'
            .' (skipped 2 screens deleted by the operator and 1 screen with a colliding name)',
            LayoutSyncSummary::text($report(2, 1))
        );

        $this->app->setLocale('ko');
        $this->assertSame(
            '레이아웃: 새 버전 변경 1곳 자동 반영, 운영자 수정과 겹친 2곳은 운영자 것 유지.'
            .' (비교 기준이 없는 1개 화면은 통째로 유지)'
            .' (운영자가 지운 화면 1개·이름이 겹친 화면 2개는 건너뜀)',
            LayoutSyncSummary::text($report(1, 2))
        );
    }

    /**
     * 레이아웃 검증 실패 요약은 오류가 1 건이면 「1 error」 다 (모듈·플러그인·템플릿 공통).
     *
     * @scenario surface=layout_validation
     *
     * @effects layout_validation_error_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function layout_validation_failure_summary_uses_singular_error(): void
    {
        $harness = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 검증 실패 문구를 만듭니다.
             *
             * @param  string  $type  소스 타입
             * @param  int  $count  오류 수
             * @return string 문구
             */
            public function format(string $type, int $count): string
            {
                return $this->formatLayoutValidationError($type, 'validation_failed', ['identifier' => 'probe', 'count' => $count]);
            }
        };

        $this->app->setLocale('en');

        $this->assertSame('Layout validation failed for plugin probe. 1 error found.', $harness->format('plugin', 1));
        $this->assertSame('Layout validation failed for module probe. 1 error found.', $harness->format('module', 1));
        $this->assertSame('Layout validation failed for probe template. (1 error)', $harness->format('template', 1));
        $this->assertSame('Layout validation failed for plugin probe. 3 errors found.', $harness->format('plugin', 3));
    }

    /**
     * 방문당 페이지 구간 라벨은 1 페이지면 「1 page」 다.
     *
     * @scenario surface=analytics_label
     *
     * @effects pages_bucket_label_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function pages_bucket_label_uses_singular_page(): void
    {
        $service = $this->app->make(ReportService::class);
        $method = new \ReflectionMethod($service, 'pagesBucketLabel');

        $this->assertSame('1 page', $method->invoke($service, '1', 'en'));
        $this->assertSame('3 pages', $method->invoke($service, '3', 'en'));
        $this->assertSame(__('analytics.bucket.pages_one', ['n' => 1], 'ko'), $method->invoke($service, '1', 'ko'));
    }
}
