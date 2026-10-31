<?php

namespace Tests\Unit\Lang;

use App\Extension\HookManager;
use App\Models\ActivityLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 괄호 없이 늘 복수로 적혀 있던 코어 서버 영어 문구의 단·복수 테스트.
 *
 * 「(1 items)」·「1 layouts deleted」 처럼 수 자리표시자 뒤 명사를 늘 복수로 적은 문구를, `(s)` 표기와 같은 방식
 * (영어 값 `단수|복수` + `trans_choice`/`trans_count`)으로 1 이면 단수로 고른다. 한국어 값은 그대로다.
 * 콘솔 출력·검증 규칙처럼 번역을 직접 부르는 호출부는 {@see PluralKeyDirectTranslationScanTest} 가 `__()` 로
 * 남지 않았음을 지킨다.
 */
class AlwaysPluralMessagesTest extends TestCase
{
    /**
     * 코어 사전 키별 [다국어 키, 수 외 치환값, 수 자리표시자, 1일 때 영어, 2일 때 영어].
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string, 4: string}> 데이터 이름 => [키, 치환값, 수 자리표시자, 1일 때, 2일 때]
     */
    public static function coreKeys(): array
    {
        $rows = [
            ['activity_log.description.user_bulk_update_status', [], 'count', 'Users status bulk updated (1 item)', 'Users status bulk updated (2 items)'],
            ['activity_log.description.member_notification_bulk_delete', [], 'count', 'Member notifications bulk deleted (1 item)', 'Member notifications bulk deleted (2 items)'],
            ['activity_log.description.auth_account_locked', ['minutes' => 5], 'attempts', 'Account locked (1 attempt, 5 min lockout)', 'Account locked (2 attempts, 5 min lockout)'],
            ['activity_log.description.auth_account_locked_permanently', [], 'attempts',
                'Account locked permanently (1 attempt, administrator unlock or password reset required)',
                'Account locked permanently (2 attempts, administrator unlock or password reset required)'],
            ['activity_log.description.auth_password_expiry_logout', ['user_id' => 'u1'], 'count', 'Signed out on password expiry (ID: u1, 1 device)', 'Signed out on password expiry (ID: u1, 2 devices)'],
            ['activity_log.description.privacy_manual_run_completed', ['target' => 'T'], 'count', 'Privacy action manual run completed (T, 1 item)', 'Privacy action manual run completed (T, 2 items)'],
            ['activity_log.description.schedule_bulk_update_status', [], 'count', 'Schedule status bulk updated (1 item)', 'Schedule status bulk updated (2 items)'],
            ['activity_log.description.schedule_bulk_delete', [], 'count', 'Schedules bulk deleted (1 item)', 'Schedules bulk deleted (2 items)'],
            ['activity_log.description.activity_log_bulk_delete', [], 'count', 'Activity logs bulk deleted (1 entry)', 'Activity logs bulk deleted (2 entries)'],
            ['modules.commands.list.summary', ['installed' => 1, 'active' => 0], 'total', 'Total 1 module (Installed: 1, Active: 0)', 'Total 2 modules (Installed: 1, Active: 0)'],
            ['plugins.commands.list.summary', ['installed' => 1, 'active' => 0], 'total', 'Total 1 plugin (Installed: 1, Active: 0)', 'Total 2 plugins (Installed: 1, Active: 0)'],
            ['modules.commands.install.menus_created', [], 'count', '1 menu created', '2 menus created'],
            ['modules.commands.uninstall.menus_deleted', [], 'count', '1 menu deleted', '2 menus deleted'],
            ['modules.commands.uninstall.confirm_details.menus', [], 'count', '- 1 menu will be deleted.', '- 2 menus will be deleted.'],
            ['modules.commands.cache_clear.success_all', [], 'count', 'Module cache cleared (1 item)', 'Module cache cleared (2 items)'],
            ['modules.commands.cache_clear.success_single', ['module' => 'm'], 'count', 'Module "m" cache cleared (1 item)', 'Module "m" cache cleared (2 items)'],
            ['plugins.commands.cache_clear.success_all', [], 'count', 'Plugin cache cleared (1 item)', 'Plugin cache cleared (2 items)'],
            ['plugins.commands.cache_clear.success_single', ['plugin' => 'p'], 'count', 'Plugin "p" cache cleared (1 item)', 'Plugin "p" cache cleared (2 items)'],
            ['search.results_found', [], 'count', '1 result found.', '2 results found.'],
            // 결과 상한(설정값, 1 도 허용)에 닿았을 때만 나온다 — 상한이 1 이면 「More than 1 result found.」
            ['search.results_found_at_least', [], 'count', 'More than 1 result found.', 'More than 2 results found.'],
            ['search.index.fulltext.self_match', ['found' => 1], 'probed', '1/1 row found itself', '1/2 rows found themselves'],
            ['seo.cache_cleared_layout', ['layout' => 'home'], 'count', '1 SEO cache entry for layout home has been cleared.', '2 SEO cache entries for layout home have been cleared.'],
            ['seo.stats_period', [], 'days', 'SEO cache statistics for the last 1 day', 'SEO cache statistics for the last 2 days'],
            ['validation.password_policy.reused', [], 'count',
                'This password was used recently. Your last 1 password cannot be reused.',
                'This password was used recently. Your last 2 passwords cannot be reused.'],
        ];

        // 모듈·플러그인 콘솔의 역할·권한·레이아웃 문구 (두 도메인 같은 문장)
        $console = [
            'commands.install.roles_created' => ['1 role created', '2 roles created'],
            'commands.install.permissions_created' => ['1 permission created', '2 permissions created'],
            'commands.activate.layouts_registered' => ['1 layout registered', '2 layouts registered'],
            'commands.deactivate.layouts_deleted' => ['1 layout deleted', '2 layouts deleted'],
            'commands.uninstall.roles_deleted' => ['1 role deleted', '2 roles deleted'],
            'commands.uninstall.permissions_deleted' => ['1 permission deleted', '2 permissions deleted'],
            'commands.uninstall.layouts_deleted' => ['1 layout deleted', '2 layouts deleted'],
            'commands.uninstall.confirm_details.roles' => ['- 1 role will be deleted.', '- 2 roles will be deleted.'],
            'commands.uninstall.confirm_details.permissions' => ['- 1 permission will be deleted.', '- 2 permissions will be deleted.'],
            'commands.uninstall.confirm_details.layouts' => ['- 1 layout will be deleted.', '- 2 layouts will be deleted.'],
        ];
        foreach (['modules', 'plugins'] as $domain) {
            foreach ($console as $key => [$one, $two]) {
                $rows[] = ["{$domain}.{$key}", [], 'count', $one, $two];
            }
        }

        $cases = [];
        foreach ($rows as $row) {
            $cases[$row[0]] = $row;
        }

        return $cases;
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
        $this->assertSame(
            preg_replace('/(?<![\w.])2(?![\w.])/', '0', $two),
            trans_choice($key, 0, $params + [$numberParam => 0]),
            "{$key}: 0 은 복수"
        );
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
     * 활동 로그 화면 문구는 저장된 `count` 로 고르고, 수를 다른 이름으로만 저장한 옛 행(로그인 잠금의 `attempts`)도
     * `|` 가 이어져 나오지 않고 그 수로 고른다.
     *
     * @scenario surface=activity_log
     *
     * @effects activity_log_description_follows_stored_number
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function activity_log_description_follows_stored_number(): void
    {
        $this->app->setLocale('en');

        $log = fn (string $key, array $params) => (new ActivityLog([
            'description_key' => $key,
            'description_params' => $params,
        ]))->localized_description;

        $this->assertSame('Users status bulk updated (1 item)', $log('activity_log.description.user_bulk_update_status', ['count' => '1']));
        $this->assertSame('Users status bulk updated (3 items)', $log('activity_log.description.user_bulk_update_status', ['count' => '3']));

        // 새 행: 리스너가 `count` 를 함께 저장한다 / 옛 행: `attempts` 만 있다
        $this->assertSame('Account locked (1 attempt, 5 min lockout)', $log('activity_log.description.auth_account_locked', ['attempts' => 1, 'minutes' => 5, 'count' => 1]));
        $this->assertSame('Account locked (1 attempt, 5 min lockout)', $log('activity_log.description.auth_account_locked', ['attempts' => 1, 'minutes' => 5]));
        $this->assertSame('Account locked (5 attempts, 30 min lockout)', $log('activity_log.description.auth_account_locked', ['attempts' => 5, 'minutes' => 30]));

        $this->app->setLocale('ko');
        $this->assertSame(
            __('activity_log.description.auth_account_locked', ['attempts' => 1, 'minutes' => 5]),
            $log('activity_log.description.auth_account_locked', ['attempts' => 1, 'minutes' => 5])
        );
    }

    /**
     * 통합 검색 응답 메시지는 결과가 1 건이면 「1 result found.」 이고, 천 단위 구분 기호를 단 수도 복수로 고른다.
     *
     * @scenario surface=search_response
     *
     * @effects search_results_message_follows_total
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function search_results_message_follows_total(): void
    {
        foreach ([1 => '1 result found.', 1234 => '1,234 results found.'] as $total => $expected) {
            HookManager::clearFilter('core.search.results');
            HookManager::addFilter('core.search.results', function (array $results) use ($total): array {
                $results['probe'] = ['total' => $total, 'total_is_exact' => true, 'items' => []];

                return $results;
            }, 5);

            $response = $this->withHeader('Accept-Language', 'en')->getJson('/api/search?q=probe');

            $response->assertOk();
            $this->assertSame($expected, $response->json('message'));
        }

        HookManager::clearFilter('core.search.results');
    }
}
