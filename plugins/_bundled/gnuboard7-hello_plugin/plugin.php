<?php

namespace Plugins\Gnuboard7\HelloPlugin;

use App\Extension\AbstractPlugin;
use Plugins\Gnuboard7\HelloPlugin\Listeners\FilterMemoTitleListener;
use Plugins\Gnuboard7\HelloPlugin\Listeners\LogMemoCreatedListener;

/**
 * Hello 학습용 샘플 플러그인
 *
 * `gnuboard7-hello_module` 이 발행하는 훅을 구독해 동작하는 최소 샘플입니다.
 * manifest `hidden: true` 로 관리자 UI 에서 숨겨집니다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 훅 리스너 목록 반환
     *
     * @return array<class-string>
     */
    public function getHookListeners(): array
    {
        return [
            LogMemoCreatedListener::class,
            FilterMemoTitleListener::class,
        ];
    }

    /**
     * 플러그인이 제공하는 훅 정보 반환
     *
     * 이 플러그인이 로그 기록 시 발행하는 데모 훅을 선언합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'gnuboard7-hello_plugin.log.written',
                'type' => 'action',
                'description' => [
                    'ko' => 'Hello 플러그인이 로그 파일에 기록을 남긴 직후 실행되는 액션 훅',
                    'en' => 'Action hook executed right after Hello Plugin writes a log entry',
                ],
                'parameters' => [
                    'message' => 'string - 기록된 메시지',
                    'context' => 'array - 기록 시점의 부가 컨텍스트',
                ],
            ],
        ];
    }

    /**
     * 플러그인 설정 스키마 반환
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSettingsSchema(): array
    {
        return [
            'log_enabled' => [
                'type' => 'boolean',
                'default' => true,
                'label' => [
                    'ko' => '로그 기록 사용',
                    'en' => 'Enable log writing',
                ],
                'hint' => [
                    'ko' => '활성화하면 메모 생성 시 hello-plugin.log 파일에 기록합니다.',
                    'en' => 'When enabled, writes to hello-plugin.log on memo creation.',
                ],
                'required' => false,
            ],
        ];
    }

    /**
     * 플러그인 설정 기본값 반환
     *
     * @return array<string, mixed>
     */
    public function getConfigValues(): array
    {
        return [
            'log_enabled' => true,
        ];
    }
}
