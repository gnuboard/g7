<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\MailTemplateRepositoryInterface;
use App\Models\MailTemplate;

class MailTemplateUserOverridesListener implements HookListenerInterface
{
    /**
     * @param MailTemplateRepositoryInterface $repository 메일 템플릿 저장소
     */
    public function __construct(
        private readonly MailTemplateRepositoryInterface $repository,
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 이름 → 설정 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.mail_template.before_update' => ['method' => 'handleBeforeUpdate', 'priority' => 10],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param mixed ...$args 훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 메일 템플릿 수정 전 변경된 필드를 user_overrides에 기록합니다.
     *
     * 추적 대상 필드: subject, body, is_active
     *
     * @param MailTemplate $template 기존 메일 템플릿
     * @param array $data 수정할 데이터
     * @return void
     */
    public function handleBeforeUpdate(MailTemplate $template, array $data): void
    {
        $userOverrides = $template->user_overrides ?? [];
        $changed = false;
        $trackableFields = ['subject', 'body', 'is_active'];

        foreach ($trackableFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $template->{$field}) {
                if (! in_array($field, $userOverrides, true)) {
                    $userOverrides[] = $field;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $this->repository->update($template, ['user_overrides' => $userOverrides]);
        }
    }
}
