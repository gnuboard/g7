<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Helpers\TimezoneHelper;
use App\Support\SiteUrl;
use Modules\Sirsoft\Ecommerce\Exports\ExportTypeRegistry;
use Modules\Sirsoft\Ecommerce\Models\Export;

/**
 * 내보내기 알림(완료·실패) 데이터 리스너
 *
 * 받는 사람은 요청자 한 명이다(`trigger_user`). 다른 운영자에게 「누가 무엇을 내보냈는지」를 알리지 않는다.
 */
class ExportNotificationDataListener implements HookListenerInterface
{
    /**
     * 이 리스너가 처리하는 알림 유형
     */
    private const TYPES = ['export_completed', 'export_failed'];

    /**
     * @param  UserRepositoryInterface  $users  회원 Repository
     * @param  ExportTypeRegistry  $types  내보내기 유형 레지스트리
     */
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ExportTypeRegistry $types,
    ) {}

    /**
     * 구독할 훅 목록
     *
     * @return array<string, array<string, mixed>> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.notification.extract_data' => [
                'method' => 'extractData',
                'priority' => 25,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트 처리 (필터 매핑을 사용하므로 미사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 내보내기 알림이면 데이터와 수신 컨텍스트를 만들어 돌려줍니다.
     *
     * @param  array  $default  앞선 필터까지의 추출 결과
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅 인수 [$export, ($code)]
     * @return array{notifiable: mixed, notifiables: mixed, data: array, context: array} 추출 결과
     */
    public function extractData(array $default, string $type, array $args): array
    {
        if (! in_array($type, self::TYPES, true)) {
            return $default;
        }

        $export = $args[0] ?? null;
        if (! $export instanceof Export || ! $export->requested_by) {
            return $default;
        }

        $requester = $this->users->findById((int) $export->requested_by);
        if ($requester === null) {
            return $default;
        }

        $baseUrl = SiteUrl::base();
        $exportsPath = '/admin/ecommerce/exports';
        $exportType = $this->types->find($export->type);
        $code = $export->error_code;

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                // 받는 사람 이름 — 코어가 받는 사람과 채널마다 그 채널의 표시 이름으로 바꾼다
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'type_label' => $exportType ? __($exportType->labelKey()) : $export->type,
                'file_name' => (string) ($export->file_name ?? ''),
                'row_count' => number_format((int) ($export->processed_rows ?? 0)),
                'expires_at' => $export->expires_at ? $export->expires_at->copy()->setTimezone(TimezoneHelper::getUserTimezone())->format('Y-m-d H:i') : '',
                'error_message' => $code ? __('sirsoft-ecommerce::exceptions.export.'.$code) : '',
                'exports_url' => $baseUrl.$exportsPath,
                // 알림 클릭 주소 — 알림 센터는 같은 탭 이동을 사이트 내 경로로만 허용한다
                'exports_path' => $exportsPath,
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $requester->id,
                'trigger_user' => $requester,
            ],
        ];
    }
}
