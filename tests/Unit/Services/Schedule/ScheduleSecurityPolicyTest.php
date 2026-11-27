<?php

namespace Tests\Unit\Services\Schedule;

use App\Services\Schedule\ScheduleSecurityPolicy;
use App\Support\ScheduleCommandValidator;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Queue\QueueProbeJob;
use Tests\TestCase;

/**
 * 스케줄 보안 정책 서버 덮어쓰기 해석기 테스트
 *
 * 덮어쓰기 파일은 테스트마다 임시 경로에 만들고 `schedule_security.override_file` 로 가리킨다 —
 * 개발 서버의 실제 `storage/app/schedule_security.json` 을 건드리지 않는다.
 */
class ScheduleSecurityPolicyTest extends TestCase
{
    /** 이 테스트가 쓰는 임시 덮어쓰기 파일 경로 */
    private string $overridePath;

    /**
     * 임시 덮어쓰기 경로를 정합니다(파일은 아직 없다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->overridePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7_schedule_security_'.uniqid('', true).'.json';
        config(['schedule_security.override_file' => $this->overridePath]);
    }

    /**
     * 임시 덮어쓰기 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (is_file($this->overridePath)) {
            @unlink($this->overridePath);
        }

        parent::tearDown();
    }

    /**
     * 덮어쓰기 파일을 씁니다.
     *
     * @param  array<string, mixed>|string  $content  배열이면 JSON 으로, 문자열이면 그대로 쓴다
     * @return void 반환값 없음
     */
    private function writeOverride(array|string $content): void
    {
        file_put_contents(
            $this->overridePath,
            is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    /**
     * 해석기를 돌려줍니다(컨테이너 singleton — 실행기·검증기와 같은 인스턴스).
     *
     * @return ScheduleSecurityPolicy 해석기
     */
    private function policy(): ScheduleSecurityPolicy
    {
        return app(ScheduleSecurityPolicy::class);
    }

    /**
     * 파일이 없으면 코어 정책 그대로이고 파일 상태는 「없음·정상」이다.
     *
     * @scenario override_state=missing, enforcement_point=policy_unit
     *
     * @effects invalid_override_is_ignored_entirely
     *
     * @return void 반환값 없음
     */
    public function test_missing_file_keeps_core_policy(): void
    {
        $status = $this->policy()->overrideFileStatus();

        $this->assertFalse($status['exists']);
        $this->assertTrue($status['valid']);
        $this->assertNull($status['error']);
        $this->assertArrayHasKey('cache:clear', $this->policy()->artisanAllowlist());
        $this->assertArrayNotHasKey('about', $this->policy()->artisanAllowlist());
        $this->assertSame('not_allowlisted', ScheduleCommandValidator::inspectArtisanCommand('about')['reason']);
    }

    /**
     * 덮어쓰기가 새 Artisan 명령을 옵션·위치 인자 상한과 함께 허용목록에 더한다 — 검증기가 그대로 따른다.
     *
     * @scenario override_state=valid, target=artisan_allowlist, enforcement_point=validator
     *
     * @effects override_adds_artisan_allowlist_entries
     *
     * @return void 반환값 없음
     */
    public function test_override_adds_artisan_allowlist_entry(): void
    {
        $this->writeOverride(['artisan' => ['allowlist' => ['about' => ['options' => ['only'], 'max_arguments' => 0]]]]);

        $this->assertSame(['options' => ['only'], 'max_arguments' => 0], $this->policy()->artisanAllowlist()['about']);
        $this->assertTrue(ScheduleCommandValidator::inspectArtisanCommand('about --only=environment')['allowed']);
        $this->assertSame('option_denied', ScheduleCommandValidator::inspectArtisanCommand('about --json')['reason']);
    }

    /**
     * 코어 허용 항목에 덮어쓰기 옵션을 더하면 합집합이 되고, 코어 옵션은 그대로 남는다.
     *
     * @scenario override_state=valid, target=artisan_allowlist, enforcement_point=validator
     *
     * @effects override_extends_core_allowlist_options
     *
     * @return void 반환값 없음
     */
    public function test_override_extends_core_allowlist_options(): void
    {
        $this->assertSame('option_denied', ScheduleCommandValidator::inspectArtisanCommand('seo:prune-stats --days=3 --chunk=1')['reason']);

        $this->writeOverride(['artisan' => ['allowlist' => ['seo:prune-stats' => ['options' => ['chunk']]]]]);

        $this->assertEqualsCanonicalizing(['days', 'chunk'], $this->policy()->artisanAllowlist()['seo:prune-stats']['options']);
        $this->assertTrue(ScheduleCommandValidator::inspectArtisanCommand('seo:prune-stats --days=3 --chunk=1')['allowed']);
    }

    /**
     * 덮어쓰기가 거부목록·거부 접두사를 더한다 — 코어 허용 명령도 거부된다.
     *
     * @scenario override_state=valid, target=artisan_denylist|denylist_prefixes, enforcement_point=validator
     *
     * @effects override_adds_denylist_and_prefixes
     *
     * @return void 반환값 없음
     */
    public function test_override_adds_denylist_and_prefixes(): void
    {
        $this->assertTrue(ScheduleCommandValidator::inspectArtisanCommand('inspire')['allowed']);
        $this->assertTrue(ScheduleCommandValidator::inspectArtisanCommand('seo:warmup')['allowed']);

        $this->writeOverride(['artisan' => ['denylist' => ['Inspire'], 'denylist_prefixes' => ['seo:']]]);

        $this->assertContains('inspire', $this->policy()->artisanDenylist());
        $this->assertContains('seo:', $this->policy()->artisanDenylistPrefixes());
        $this->assertSame('denied', ScheduleCommandValidator::inspectArtisanCommand('inspire')['reason']);
        $this->assertSame('denied', ScheduleCommandValidator::inspectArtisanCommand('seo:warmup')['reason']);
    }

    /**
     * 덮어쓰기로 코어 거부 항목을 풀 수 없다 — 빈 거부목록을 보내도, 허용목록에 넣어도 거부가 이긴다.
     *
     * @scenario override_state=valid, target=artisan_denylist|artisan_allowlist, enforcement_point=validator
     *
     * @effects override_cannot_remove_core_denylist, denylist_wins_over_override_allowlist
     *
     * @return void 반환값 없음
     */
    public function test_override_cannot_remove_core_denylist_and_deny_wins(): void
    {
        $this->writeOverride([
            'artisan' => [
                'denylist' => [],
                'denylist_prefixes' => [],
                'allowlist' => ['tinker' => ['options' => ['execute']], 'make:model' => []],
            ],
        ]);

        $this->assertTrue($this->policy()->overrideFileStatus()['valid']);
        $this->assertContains('tinker', $this->policy()->artisanDenylist());
        $this->assertContains('make:', $this->policy()->artisanDenylistPrefixes());
        $this->assertSame('denied', ScheduleCommandValidator::inspectArtisanCommand('tinker')['reason']);
        $this->assertSame('denied', ScheduleCommandValidator::inspectArtisanCommand('make:model')['reason']);

        $described = collect($this->policy()->describe()['artisan']['allowlist'])->keyBy('command');
        $this->assertTrue($described['tinker']['denied']);
        $this->assertSame('override', $described['tinker']['source']);
    }

    /**
     * 덮어쓰기가 Job 허용 클래스를 더한다(선행 백슬래시는 떼고 비교). 확장 훅은 그 뒤에 적용된다.
     *
     * @scenario override_state=valid, target=job_classes, enforcement_point=policy_unit
     *
     * @effects override_adds_job_allowed_classes
     *
     * @return void 반환값 없음
     */
    public function test_override_adds_job_allowed_classes(): void
    {
        $this->assertNotContains(QueueProbeJob::class, $this->policy()->filteredJobAllowedClasses());

        $this->writeOverride(['job' => ['allowed_classes' => ['\\'.QueueProbeJob::class]]]);

        $this->assertContains(QueueProbeJob::class, $this->policy()->jobAllowedClasses());
        $this->assertContains(QueueProbeJob::class, $this->policy()->filteredJobAllowedClasses());
    }

    /**
     * 형식이 하나라도 어긋나면 덮어쓰기 전체를 무시하고(정상 항목도 적용하지 않음) 경고 로그를 남긴다.
     *
     * @scenario override_state=invalid_json|invalid_shape|locked_key, enforcement_point=policy_unit
     *
     * @effects invalid_override_is_ignored_entirely, invalid_override_logs_warning, locked_keys_are_not_overridable
     *
     * @param  string  $content  파일 내용
     * @param  string  $errorCode  기대 오류 코드
     * @return void 반환값 없음
     */
    #[DataProvider('invalidOverrideProvider')]
    public function test_invalid_override_is_ignored_entirely(string $content, string $errorCode): void
    {
        Log::spy();
        $this->writeOverride($content);

        $status = $this->policy()->overrideFileStatus();

        $this->assertTrue($status['exists']);
        $this->assertFalse($status['valid']);
        $this->assertSame($errorCode, $status['error_code']);
        $this->assertIsString($status['error']);
        $this->assertNotSame('', $status['error']);
        $this->assertArrayNotHasKey('about', $this->policy()->artisanAllowlist());
        $this->assertNotContains('inspire', $this->policy()->artisanDenylist());
        $this->assertSame('not_allowlisted', ScheduleCommandValidator::inspectArtisanCommand('about')['reason']);
        $this->assertFalse($this->policy()->shellEnabled());

        Log::shouldHaveReceived('warning')
            ->with('Schedule security override ignored', Mockery::on(fn (array $context): bool => $context['error'] === $errorCode))
            ->once();
    }

    /**
     * 형식 오류 파일 사례 — 모두 정상 항목(about 허용·inspire 거부)을 함께 담아 「전체 무시」를 확인한다.
     *
     * @return array<string, array{0: string, 1: string}> 사례 이름 => (파일 내용, 오류 코드)
     */
    public static function invalidOverrideProvider(): array
    {
        $valid = '"artisan": {"allowlist": {"about": {}}, "denylist": ["inspire"]}';

        return [
            'JSON 문법 오류' => ['{'.$valid.',', 'invalid_json'],
            '최상위가 배열' => ['["about"]', 'not_object'],
            '모르는 최상위 키' => ['{'.$valid.', "artisn": {}}', 'unknown_key'],
            '모르는 artisan 키' => ['{"artisan": {"allowlist": {"about": {}}, "deny": ["inspire"]}}', 'unknown_key'],
            '옵션이 문자열' => ['{"artisan": {"allowlist": {"about": {"options": "only"}}, "denylist": ["inspire"]}}', 'invalid_allowlist_entry'],
            '위치 인자 음수' => ['{"artisan": {"allowlist": {"about": {"max_arguments": -1}}, "denylist": ["inspire"]}}', 'invalid_allowlist_entry'],
            '명령명 형태 위반' => ['{"artisan": {"allowlist": {"about": {}, "--evil": {}}, "denylist": ["inspire"]}}', 'invalid_command'],
            '거부목록이 객체' => ['{"artisan": {"allowlist": {"about": {}}, "denylist": {"x": "inspire"}}}', 'invalid_list'],
            'Job 클래스가 숫자' => ['{'.$valid.', "job": {"allowed_classes": [123]}}', 'invalid_list'],
            'shell 키(.env 소유)' => ['{'.$valid.', "shell": {"enabled": true, "allowed_binaries": ["bash"]}}', 'not_overridable'],
            '확장 자동 허용 키' => ['{"artisan": {"allowlist": {"about": {}}, "denylist": ["inspire"], "allow_extension_commands": false}}', 'not_overridable'],
        ];
    }

    /**
     * `_` 로 시작하는 키는 주석으로 보고 건너뛴다.
     *
     * @scenario override_state=valid, enforcement_point=policy_unit
     *
     * @effects override_adds_artisan_allowlist_entries
     *
     * @return void 반환값 없음
     */
    public function test_underscore_keys_are_comments(): void
    {
        $this->writeOverride(['_comment' => '서버 관리자 메모', 'artisan' => ['_note' => 'x', 'allowlist' => ['about' => []]]]);

        $this->assertTrue($this->policy()->overrideFileStatus()['valid']);
        $this->assertArrayHasKey('about', $this->policy()->artisanAllowlist());
    }

    /**
     * 파일을 고치면 같은 프로세스(같은 해석기 인스턴스)에서도 다음 판정부터 반영된다.
     *
     * @scenario override_state=valid, enforcement_point=policy_unit
     *
     * @effects override_file_change_is_picked_up_without_restart
     *
     * @return void 반환값 없음
     */
    public function test_file_change_is_picked_up_without_restart(): void
    {
        $policy = $this->policy();

        $this->writeOverride(['artisan' => ['allowlist' => ['about' => []]]]);
        $this->assertArrayHasKey('about', $policy->artisanAllowlist());

        $this->writeOverride(['artisan' => ['denylist' => ['inspire', 'about']]]);
        $this->assertArrayNotHasKey('about', $policy->artisanAllowlist());
        $this->assertContains('inspire', $policy->artisanDenylist());

        @unlink($this->overridePath);
        $this->assertNotContains('inspire', $policy->artisanDenylist());
        $this->assertFalse($policy->overrideFileStatus()['exists']);
    }

    /**
     * Shell 사용 여부는 .env 게이트와 허용 실행 파일로만 정해진다(둘 다 있어야 true).
     *
     * @scenario target=shell, enforcement_point=policy_unit
     *
     * @effects status_api_exposes_shell_enabled
     *
     * @return void 반환값 없음
     */
    public function test_shell_enabled_requires_gate_and_binaries(): void
    {
        config(['schedule_security.shell.enabled' => false, 'schedule_security.shell.allowed_binaries' => ['backup.sh']]);
        $this->assertFalse($this->policy()->shellEnabled());

        config(['schedule_security.shell.enabled' => true, 'schedule_security.shell.allowed_binaries' => []]);
        $this->assertFalse($this->policy()->shellEnabled());

        config(['schedule_security.shell.enabled' => true, 'schedule_security.shell.allowed_binaries' => ['backup.sh']]);
        $this->assertTrue($this->policy()->shellEnabled());
        $this->assertTrue($this->policy()->describe()['shell']['gate_enabled']);
        $this->assertSame(['backup.sh'], $this->policy()->describe()['shell']['allowed_binaries']);
    }
}
