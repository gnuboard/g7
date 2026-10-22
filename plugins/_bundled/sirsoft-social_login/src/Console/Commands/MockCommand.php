<?php

namespace Plugins\Sirsoft\SocialLogin\Console\Commands;

use Illuminate\Console\Command;
use Plugins\Sirsoft\SocialLogin\Listeners\RegisterMockProvidersListener;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;

/**
 * 모의 제공자 켜기·끄기·상태.
 *
 * 모의 제공자는 이 명령으로만 켠다 — 관리자 화면·API 에는 스위치를 두지 않는다. 운영 서버에서 실수로 켜 두지 않도록
 * 켤 때 시간(최대 240분)을 정하고, 지나면 저절로 꺼진다.
 *
 * @since 1.0.0
 */
class MockCommand extends Command
{
    /** @var string 명령 서명 */
    protected $signature = 'sirsoft-social_login:mock
        {--enable : 모의 제공자를 켭니다}
        {--disable : 모의 제공자를 끕니다}
        {--status : 현재 상태를 봅니다}
        {--minutes=60 : 켜 둘 시간(분, 최대 240)}';

    /** @var string 명령 설명 */
    protected $description = 'Enable, disable or inspect the social login mock providers (development and QA only).';

    /**
     * 명령을 실행합니다.
     *
     * @param  MockGate  $gate  모의 게이트
     * @return int 종료 코드
     */
    public function handle(MockGate $gate): int
    {
        $chosen = array_filter([$this->option('enable'), $this->option('disable'), $this->option('status')]);
        if (count($chosen) !== 1) {
            $this->error(__('sirsoft-social_login::messages.mock_cli.choose_one'));

            return self::INVALID;
        }

        if ($this->option('enable')) {
            $minutes = (int) $this->option('minutes');
            if ($minutes < 1 || $minutes > MockGate::MAX_MINUTES) {
                $this->error(__('sirsoft-social_login::messages.mock_cli.minutes_range', ['max' => MockGate::MAX_MINUTES]));

                return self::INVALID;
            }

            $until = $gate->enable($minutes);
            $this->info(__('sirsoft-social_login::messages.mock_cli.enabled', ['until' => date('Y-m-d H:i:s', $until)]));
            $this->line(implode(', ', array_keys(RegisterMockProvidersListener::configs())));

            return self::SUCCESS;
        }

        if ($this->option('disable')) {
            $gate->disable();
            $this->info(__('sirsoft-social_login::messages.mock_cli.disabled'));

            return self::SUCCESS;
        }

        $until = $gate->until();
        $this->info($until === null
            ? __('sirsoft-social_login::messages.mock_cli.status_off')
            : __('sirsoft-social_login::messages.mock_cli.status_on', ['until' => date('Y-m-d H:i:s', $until)]));

        return self::SUCCESS;
    }
}
