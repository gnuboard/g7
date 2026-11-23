<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\NotificationDefinition;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;

/**
 * 알림 종류가 지워지면 그 종류의 문자·알림톡 템플릿 행을 함께 지우는 리스너
 *
 * 템플릿 행(`bizppurio_templates`)은 알림 종류(`notification_type`)마다 한 줄이다. 런타임에 만들어지고 지워지는 알림 종류
 * (예: 이커머스 쿠폰별 발급 알림 `coupon_issued.{쿠폰ID}`)가 사라진 뒤 행이 남으면, 같은 종류 이름을 다시 쓸 때 옛 승인
 * 문구가 되살아난다. 코어 표준 삭제 훅(`core.notification_definition.after_delete`)에 답해 정리한다.
 */
class PurgeTemplateOnDefinitionDeleteListener implements HookListenerInterface
{
    /**
     * @param  BizppurioTemplateRepositoryInterface  $templates  비즈뿌리오 템플릿 저장소
     */
    public function __construct(
        private readonly BizppurioTemplateRepositoryInterface $templates,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array<string, mixed>> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.notification_definition.after_delete' => [
                'method' => 'purge',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 지워진 알림 종류의 템플릿 행을 지웁니다.
     *
     * @param  mixed  $definition  지워진 알림 정의
     * @return void 반환값 없음
     */
    public function purge(mixed $definition = null): void
    {
        $type = $definition instanceof NotificationDefinition ? (string) $definition->type : '';
        if ($type === '') {
            return;
        }

        try {
            $template = $this->templates->findByType($type);
            if ($template !== null) {
                $this->templates->delete($template);
            }
        } catch (\Throwable $e) {
            Log::warning('비즈뿌리오 템플릿 정리 실패', ['notification_type' => $type, 'message' => $e->getMessage()]);
        }
    }
}
