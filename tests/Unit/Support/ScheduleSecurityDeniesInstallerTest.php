<?php

namespace Tests\Unit\Support;

use App\Support\ScheduleCommandValidator;
use Tests\TestCase;

/**
 * 예약 작업이 터미널 설치·운영 명령을 실행하지 못하는지 검증한다.
 *
 * 스케줄 권한만 위임받은 관리자가 예약 작업으로 설치된 사이트를 다시 설치하거나(g7-install·core:install),
 * 비밀번호·설정을 바꾸는(user:reset-password·settings:set) 경로를 막는다. Artisan 은 허용목록 방식이라 기본 거부이고,
 * Shell 은 인터프리터 뒤 스크립트 이름 `g7-install` 을 거부한다.
 */
class ScheduleSecurityDeniesInstallerTest extends TestCase
{
    /**
     * Shell 타입에서 php 인터프리터로 g7-install 을 부르면 거부된다 (php 를 허용해도).
     *
     * @effects schedule_shell_rejects_g7_install_script
     *
     * @return void 반환값 없음
     */
    public function test_shell_rejects_g7_install_script_even_when_php_is_allowed(): void
    {
        config([
            'schedule_security.shell.enabled' => true,
            'schedule_security.shell.allowed_binaries' => ['php'],
        ]);

        $this->assertFalse(ScheduleCommandValidator::isShellCommandAllowed('php /var/www/g7/g7-install --reinstall --drop-existing-tables'));
        $this->assertFalse(ScheduleCommandValidator::isShellCommandAllowed('php C:\\www\\g7\\g7-install -n'));
        $this->assertTrue(ScheduleCommandValidator::isShellCommandAllowed('php /var/www/g7/scripts/backup.php'), '다른 스크립트는 계속 허용');
    }

    /**
     * Artisan 타입의 신규 명령은 허용목록에 없어 거부된다.
     *
     * @effects schedule_artisan_rejects_installer_and_ops_commands
     *
     * @return void 반환값 없음
     */
    public function test_artisan_rejects_new_install_and_ops_commands(): void
    {
        foreach (['core:install -n', 'user:reset-password admin@example.com --generate', 'settings:set mail.host smtp.test', 'settings:get mail'] as $command) {
            $this->assertFalse(ScheduleCommandValidator::isArtisanCommandAllowed($command), $command);
        }
    }
}
