<?php

namespace Plugins\Sirsoft\Koreaexim\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\SiteUrl;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Plugin;

class UpdateFailedNotificationDataListener implements HookListenerInterface
{
    /**
     * 구독 메서드와 동기 실행 계약을 선언합니다.
     *
     * @return array 처리 결과
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-koreaexim.notification.extract_data' => ['method' => 'extract', 'type' => 'filter', 'priority' => 10],
            'core.notification.filter_default_definitions' => ['method' => 'defaults', 'type' => 'filter', 'priority' => 20],
        ];
    }

    /**
     * 코어 알림 복원 경로에 플러그인이 소유한 기본 정의를 제공합니다.
     *
     * @param  array  $definitions  다른 확장이 제공한 기본 정의
     * @return array 소유 정보와 템플릿을 포함한 기본 정의
     */
    public function defaults(array $definitions): array
    {
        foreach ((new Plugin)->getNotificationDefinitions() as $definition) {
            $definitions[] = $definition + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-koreaexim'];
        }

        return $definitions;
    }

    /**
     * 등록된 처리를 실행합니다.
     *
     * @param  mixed  $args  args 입력값
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 실패 알림 템플릿의 치환 변수를 반환합니다.
     *
     * @param  array  $data  data 입력값
     * @param  string  $type  type 입력값
     * @param  array  $args  args 입력값
     * @return array 처리 결과
     */
    public function extract(array $data, string $type, array $args): array
    {
        $run = $args[0] ?? null;
        if ($type !== 'koreaexim_update_failed' || ! $run instanceof KoreaeximRateRun) {
            return $data;
        }
        $data['data'] = ['name' => '{recipient_name}', 'status_label' => $run->status->label(), 'message' => $run->message,
            'search_date' => $run->search_date->toDateString(), 'settings_url' => SiteUrl::absolute('/admin/plugins/sirsoft-koreaexim/settings')];

        return $data;
    }
}
