<?php

namespace Tests\Unit\Support;

use App\Support\EnvPriority;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * `.env` 키 단위 우선 판정(EnvPriority) 단위 테스트.
 *
 * @effects switch_off_behaves_identically, explicit_env_skips_injection, empty_string_env_not_explicit, false_env_is_explicit, locked_plain_shows_effective_value, locked_sensitive_hides_effective_value
 */
class EnvPriorityTest extends TestCase
{
    /**
     * 스위치를 켜고 지정한 env 변수만 명시 상태로 만듭니다.
     *
     * @param  array<int, string>  $explicitVars  명시 상태로 만들 env 변수 목록
     */
    private function enableWith(array $explicitVars): void
    {
        Config::set('env-priority.enabled', true);
        Config::set('env-priority.explicit', array_fill_keys($explicitVars, true));
    }

    /**
     * 스위치가 꺼져 있으면 어떤 키도 잠기지 않습니다 (현행 동작 보존).
     *
     * @scenario switch=off, key_state=unlocked, surface=inject
     */
    public function test_switch_off_locks_nothing(): void
    {
        Config::set('env-priority.enabled', false);
        Config::set('env-priority.explicit', ['APP_NAME' => true, 'MAIL_PORT' => true]);

        $this->assertFalse(EnvPriority::enabled());
        $this->assertFalse(EnvPriority::isLocked('general.site_name'));
        $this->assertSame([], EnvPriority::lockedKeys());
        $this->assertSame(
            ['site_name' => 'X'],
            EnvPriority::filterLocked('general', ['site_name' => 'X'])
        );
    }

    /**
     * 스위치가 켜지고 env 가 명시된 키만 잠깁니다.
     *
     * @scenario switch=on, key_state=locked_plain, surface=get_meta
     */
    public function test_explicit_env_locks_only_that_key(): void
    {
        $this->enableWith(['MAIL_PORT']);

        $this->assertTrue(EnvPriority::isLocked('mail.port'));
        $this->assertFalse(EnvPriority::isLocked('mail.host'));
        $this->assertSame(['mail.port' => true], EnvPriority::lockedKeys());
    }

    /**
     * 매핑에 없는 키는 잠기지 않습니다 (면제 목록 등재 키 포함).
     *
     * @scenario switch=on, key_state=unlocked, surface=get_meta
     */
    public function test_unmapped_key_is_never_locked(): void
    {
        $this->enableWith(['MAIL_PORT']);

        $this->assertFalse(EnvPriority::isLocked('mail.encryption'));
        $this->assertFalse(EnvPriority::isLocked('general.site_description'));
        $this->assertFalse(EnvPriority::isLocked('nonexistent.key'));
    }

    /**
     * env 두 개가 한 설정을 나누는 키는 하나만 명시돼도 잠깁니다 (any-of).
     *
     * @scenario switch=on, key_state=locked_plain, surface=inject
     */
    public function test_any_of_env_vars_locks_the_setting(): void
    {
        $this->enableWith(['REDIS_CACHE_DB']);
        $this->assertTrue(EnvPriority::isLocked('drivers.redis_database'));

        $this->enableWith(['REDIS_DB']);
        $this->assertTrue(EnvPriority::isLocked('drivers.redis_database'));

        $this->enableWith(['REDIS_HOST']);
        $this->assertFalse(EnvPriority::isLocked('drivers.redis_database'));
    }

    /**
     * filterLocked 는 잠긴 키만 제거하고 나머지는 그대로 둡니다.
     *
     * @scenario switch=on, key_state=unlocked, surface=inject
     */
    public function test_filter_locked_removes_only_locked_keys(): void
    {
        $this->enableWith(['MAIL_PORT', 'MAIL_PASSWORD']);

        $filtered = EnvPriority::filterLocked('mail', [
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 587,
            'password' => 'stored',
        ]);

        $this->assertSame(['mailer' => 'smtp', 'host' => 'smtp.example.com'], $filtered);
    }

    /**
     * 저장 게이트는 잠긴 키를 조용히 제거합니다 (화면 disabled 우회 경로 차단).
     *
     * @scenario switch=on, key_state=locked_plain, surface=post_filter
     */
    public function test_reject_locked_for_save_strips_locked_keys(): void
    {
        $this->enableWith(['APP_NAME']);

        $accepted = EnvPriority::rejectLockedForSave('general', [
            'site_name' => '공격자가_보낸_값',
            'site_description' => '허용',
        ]);

        $this->assertSame(['site_description' => '허용'], $accepted);
    }

    /**
     * 저장 게이트도 스위치가 꺼져 있으면 입력을 그대로 통과시킵니다.
     *
     * @scenario switch=off, key_state=unlocked, surface=post_filter
     */
    public function test_reject_locked_for_save_is_noop_when_switch_off(): void
    {
        Config::set('env-priority.enabled', false);
        Config::set('env-priority.explicit', ['APP_NAME' => true]);

        $input = ['site_name' => 'X', 'site_description' => 'Y'];

        $this->assertSame($input, EnvPriority::rejectLockedForSave('general', $input));
    }

    /**
     * 민감 키 규약: password/secret/token/license 류만 sensitive 입니다.
     *
     * @scenario switch=on, key_state=locked_sensitive, surface=get_meta
     */
    public function test_sensitive_contract(): void
    {
        $this->assertTrue(EnvPriority::isSensitive('mail.password'));
        $this->assertTrue(EnvPriority::isSensitive('mail.ses_secret'));
        $this->assertTrue(EnvPriority::isSensitive('drivers.redis_password'));
        $this->assertTrue(EnvPriority::isSensitive('drivers.s3_secret_key'));
        $this->assertTrue(EnvPriority::isSensitive('drivers.websocket_app_secret'));
        $this->assertTrue(EnvPriority::isSensitive('core_update.github_token'));
        $this->assertTrue(EnvPriority::isSensitive('geoip.license_key'));

        $this->assertFalse(EnvPriority::isSensitive('mail.host'));
        $this->assertFalse(EnvPriority::isSensitive('drivers.websocket_app_key'));
        $this->assertFalse(EnvPriority::isSensitive('general.site_name'));
    }

    /**
     * 매핑에 등장하는 모든 sensitive 키는 실제로 자격증명 계열 이름을 갖습니다.
     *
     * 반대 방향(자격증명 이름인데 sensitive 표시가 빠진 키) 검출이 목적입니다 —
     * 표시가 빠지면 `.env` 의 비밀값이 관리자 화면 응답에 실려 나갑니다.
     */
    /** @scenario switch=on, key_state=locked_sensitive, surface=get_meta */
    public function test_credential_named_keys_are_all_marked_sensitive(): void
    {
        $missing = [];

        foreach (EnvPriority::MAP as $key => $entry) {
            $isCredentialName = (bool) preg_match('/(password|secret|token|license|_access_key|ses_key)/', $key);

            if ($isCredentialName && ($entry['sensitive'] ?? false) !== true) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'sensitive 표시가 빠진 자격증명 키: '.implode(', ', $missing));
    }

    /**
     * 유효값은 런타임 config 값이며, 표시 변환 지시자가 있으면 화면 단위로 환산됩니다.
     */
    /** @scenario switch=on, key_state=locked_plain, surface=get_effective_value */
    public function test_effective_value_reads_runtime_config(): void
    {
        Config::set('app.name', '운영중인이름');

        $this->assertSame('운영중인이름', EnvPriority::effectiveValue('general.site_name'));
    }

    /**
     * 첨부 최대 크기는 KB(config) → MB(화면) 로 환산됩니다.
     */
    /** @scenario switch=on, key_state=locked_plain, surface=get_effective_value */
    public function test_effective_value_converts_kb_to_mb(): void
    {
        Config::set('attachment.max_file_size', 20480);

        $this->assertSame(20, EnvPriority::effectiveValue('upload.max_file_size'));
    }

    /**
     * 웹소켓 마스터 토글은 broadcasting 드라이버가 reverb 인지로 표현됩니다.
     *
     * @scenario switch=on, key_state=locked_falsy, surface=get_meta
     */
    public function test_effective_value_maps_broadcast_driver_to_toggle(): void
    {
        Config::set('broadcasting.default', 'reverb');
        $this->assertTrue(EnvPriority::effectiveValue('drivers.websocket_enabled'));

        Config::set('broadcasting.default', 'null');
        $this->assertFalse(EnvPriority::effectiveValue('drivers.websocket_enabled'));
    }

    /**
     * 로그 드라이버는 stack 채널 목록의 첫 항목으로 표현됩니다.
     */
    /** @scenario switch=on, key_state=locked_plain, surface=get_effective_value */
    public function test_effective_value_takes_first_stack_channel(): void
    {
        Config::set('logging.channels.stack.channels', ['daily', 'stderr']);

        $this->assertSame('daily', EnvPriority::effectiveValue('drivers.log_driver'));
    }

    /**
     * 매핑에 없는 키의 유효값은 null 입니다.
     */
    /** @scenario switch=on, key_state=unlocked, surface=get_effective_value */
    public function test_effective_value_is_null_for_unmapped_key(): void
    {
        $this->assertNull(EnvPriority::effectiveValue('mail.encryption'));
    }

    /**
     * envVars() 는 매핑의 env 변수를 중복 없이 반환합니다.
     */
    public function test_env_vars_are_deduplicated(): void
    {
        $vars = EnvPriority::envVars();

        $this->assertSame(array_values(array_unique($vars)), $vars);
        // AWS_ACCESS_KEY_ID 는 mail.ses_key 와 drivers.s3_access_key 두 곳에 등장한다.
        $this->assertContains('AWS_ACCESS_KEY_ID', $vars);
        $this->assertContains('REDIS_CACHE_DB', $vars);
    }

    /**
     * 명시 판별은 strict 입니다 — 빈 값·`null` 문자열·미설정만 미명시입니다.
     *
     * 이 규약은 `config/env-priority.php` 의 캡처식이 소유하므로, 그 파일을 실제로
     * 평가해 판정합니다. `?:` 로 바꾸면 `APP_DEBUG=false`·`REDIS_DB=0` 같은 falsy
     * 명시가 미명시로 오판되는데, 그 회귀를 여기서 잠급니다.
     *
     * @scenario switch=on, key_state=locked_falsy, surface=inject
     */
    public function test_explicit_capture_is_strict_about_presence(): void
    {
        $cases = [
            'MAILGUN_ENDPOINT' => ['value' => 'api.example.com', 'explicit' => true],
            'GEOIP_LICENSE_KEY' => ['value' => '', 'explicit' => false],
            'GEOIP_AUTO_UPDATE_ENABLED' => ['value' => 'false', 'explicit' => true],
            'LOG_DAILY_DAYS' => ['value' => '0', 'explicit' => true],
            'AWS_BUCKET' => ['value' => 'null', 'explicit' => false],
        ];

        $originalEnv = [];
        $originalServer = [];

        foreach ($cases as $var => $case) {
            $originalEnv[$var] = $_ENV[$var] ?? null;
            $originalServer[$var] = $_SERVER[$var] ?? null;
            $_ENV[$var] = $case['value'];
            $_SERVER[$var] = $case['value'];
        }

        // REVERB_APP_ID 는 어떤 케이스에도 없다 — 미설정 축의 대조군.
        $unsetVar = 'REVERB_APP_ID';
        $originalEnv[$unsetVar] = $_ENV[$unsetVar] ?? null;
        $originalServer[$unsetVar] = $_SERVER[$unsetVar] ?? null;
        unset($_ENV[$unsetVar], $_SERVER[$unsetVar]);

        try {
            $captured = require config_path('env-priority.php');
            $explicit = $captured['explicit'];

            foreach ($cases as $var => $case) {
                $this->assertSame(
                    $case['explicit'],
                    ($explicit[$var] ?? false) === true,
                    "{$var}={$case['value']} 의 명시 판정이 규약과 다릅니다"
                );
            }

            $this->assertFalse(($explicit[$unsetVar] ?? false) === true, '미설정 env 는 미명시여야 합니다');
        } finally {
            foreach ($originalEnv as $var => $value) {
                if ($value === null) {
                    unset($_ENV[$var]);
                } else {
                    $_ENV[$var] = $value;
                }
            }

            foreach ($originalServer as $var => $value) {
                if ($value === null) {
                    unset($_SERVER[$var]);
                } else {
                    $_SERVER[$var] = $value;
                }
            }
        }
    }
}
