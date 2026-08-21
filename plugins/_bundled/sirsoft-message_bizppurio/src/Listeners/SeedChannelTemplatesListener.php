<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 회원 대상 알림 정의에 sms·alimtalk 채널 template 을 증강하는 시딩 필터 리스너
 * (계획서 §6-2 결정 D, Phase 6 재설계).
 *
 * 코어/모듈은 알림 정의를 DB 로 시딩하기 직전에 "정의 배열"을 필터 훅으로 한 번 통과시킨다
 * (언어팩 다국어 병합용). 이 리스너가 그 훅에 편승해, 각 정의 배열에 sms·alimtalk 채널을
 * channels 목록과 templates 에 끼워넣는다. 그러면 코어/모듈이 그것을 자기 선언처럼 저장하고
 * (NotificationSyncHelper::syncTemplate), cleanupStaleTemplates 의 definedChannels 에 우리
 * 채널이 포함되므로 재시딩·업데이트에도 삭제되지 않는다. 코어·모듈 파일은 수정하지 않는다.
 *
 * 구독 훅(3영역, 정의 배열을 넘기는 필터):
 * - seed.notifications.translations                     (코어 — config/core.php)
 * - seed.sirsoft-board.notifications.translations       (게시판)
 * - seed.sirsoft-ecommerce.notifications.translations   (이커머스)
 *
 * 배열 형태 차이:
 * - 코어: 연관 배열 `['welcome' => [...], ...]` (키가 type)
 * - 모듈: 순차 배열 `[['type' => 'order_confirmed', ...], ...]`
 * 둘 다 각 정의 값에 대해 동일하게 채널을 증강하고 키/순서는 보존한다.
 *
 * 증강 대상 = 회원(사용자) 대상 알림(결정 E). 관리자 전용(수신자가 role:admin 뿐인) 알림은
 * 문자/알림톡 대상이 아니므로 건너뛴다. 기본 body 는 그 알림의 database 채널 body(짧은 평문)를
 * 재활용하고, 없으면 mail body 의 HTML 을 제거해 만든다.
 *
 * #597 이후에도 이 시딩은 존치한다. 발송 본문의 SSoT 는 bizppurio_templates 로 옮겨졌지만
 * (SmsChannelDriver 는 sms_body, AlimtalkChannelDriver 는 approved_content 를 읽는다),
 * 코어·게시판·이커머스의 알림 설정 화면이 여전히 이 template 행을 그리기 때문이다
 * (채널 서브탭의 행 제목·수신자 표시, 행이 없으면 "no_template_for_channel" 문구).
 * 즉 이 행들은 화면 표시 전용이며 발송 경로는 소비하지 않는다 — 발송이 안 쓴다는 이유로
 * 시딩을 지우면 알림 설정 화면의 비즈뿌리오 탭에서 행 정보가 사라진다.
 */
class SeedChannelTemplatesListener implements HookListenerInterface
{
    /** 증강할 채널 id 목록 */
    private const CHANNELS = ['sms', 'alimtalk'];

    /**
     * 구독할 훅 목록 반환.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        $hooks = [];
        foreach ([
            'seed.notifications.translations',
            'seed.sirsoft-board.notifications.translations',
            'seed.sirsoft-ecommerce.notifications.translations',
        ] as $hook) {
            $hooks[$hook] = [
                'method' => 'augment',
                'priority' => 50,
                'type' => 'filter',
            ];
        }

        return $hooks;
    }

    /**
     * 기본 핸들러 (미사용 — 필터 메서드로 처리).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 정의 배열의 각 회원 대상 알림에 sms·alimtalk 채널 template 을 증강합니다.
     *
     * 키(코어=type, 모듈=정수)와 순서를 보존한 채 각 정의 값만 변형한다. 정의 형태가 배열이
     * 아니거나 회원 대상이 아니면 원본을 그대로 둔다.
     *
     * @param  array<int|string, mixed>  $definitions  시딩 직전 알림 정의 배열
     * @return array<int|string, mixed> 채널이 증강된 정의 배열
     */
    public function augment(array $definitions): array
    {
        foreach ($definitions as $key => $definition) {
            if (! is_array($definition) || ! $this->isMemberFacing($definition)) {
                continue;
            }

            $definitions[$key] = $this->augmentDefinition($definition);
        }

        return $definitions;
    }

    /**
     * 단일 알림 정의에 sms·alimtalk 채널을 channels·templates 에 추가합니다.
     *
     * 이미 해당 채널이 선언돼 있으면(다른 경로로 이미 존재) 중복 추가하지 않는다.
     *
     * @param  array<string, mixed>  $definition  단일 알림 정의
     * @return array<string, mixed> 증강된 정의
     */
    private function augmentDefinition(array $definition): array
    {
        $channels = $definition['channels'] ?? ['mail'];
        $templates = $definition['templates'] ?? [];

        $existingChannels = array_column($templates, 'channel');
        $baseBody = $this->resolveBaseBody($templates);
        $baseSubject = $this->resolveBaseSubject($templates);
        $recipients = $this->resolveRecipients($templates);

        foreach (self::CHANNELS as $channel) {
            if (in_array($channel, $existingChannels, true)) {
                continue;
            }

            if (! in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }

            $templates[] = [
                'channel' => $channel,
                'recipients' => $recipients,
                'subject' => $baseSubject,
                'body' => $baseBody,
            ];
        }

        $definition['channels'] = $channels;
        $definition['templates'] = $templates;

        return $definition;
    }

    /**
     * 알림이 회원(사용자) 대상인지 판정합니다 (결정 E).
     *
     * 정의의 어떤 template recipients 든 관리자 전용(type=role, value=admin)이 아닌 수신자가
     * 하나라도 있으면 회원 대상으로 본다. recipients 가 전혀 없으면(수신자 미지정) 회원 발송
     * 기본값으로 포함한다. channels 컬럼이 아니라 recipients 로 판정한다(결정 E).
     *
     * @param  array<string, mixed>  $definition  단일 알림 정의
     * @return bool 회원 대상이면 true
     */
    private function isMemberFacing(array $definition): bool
    {
        $templates = $definition['templates'] ?? [];
        $hasAnyRecipient = false;

        foreach ($templates as $template) {
            foreach ($template['recipients'] ?? [] as $recipient) {
                $hasAnyRecipient = true;

                $type = $recipient['type'] ?? null;
                $value = $recipient['value'] ?? null;

                if (! ($type === 'role' && $value === 'admin')) {
                    return true;
                }
            }
        }

        return ! $hasAnyRecipient;
    }

    /**
     * 증강할 채널의 기본 body(다국어 배열)를 결정합니다.
     *
     * 우선순위: database 채널 body(짧은 평문) → mail body 의 HTML 제거본 → 빈 배열.
     * 문자/알림톡은 평문이므로 HTML 을 담지 않는다.
     *
     * @param  array<int, array<string, mixed>>  $templates  기존 template 목록
     * @return array<string, string> locale → 평문 body
     */
    private function resolveBaseBody(array $templates): array
    {
        $database = $this->findTemplate($templates, 'database');
        if ($database !== null && is_array($database['body'] ?? null)) {
            return $database['body'];
        }

        $mail = $this->findTemplate($templates, 'mail');
        if ($mail !== null && is_array($mail['body'] ?? null)) {
            return array_map(fn ($body) => $this->stripHtml((string) $body), $mail['body']);
        }

        return [];
    }

    /**
     * 증강할 채널의 기본 subject(다국어 배열)를 결정합니다.
     *
     * database → mail subject 순으로 재활용한다. LMS 전환 시 제목으로 쓰인다.
     *
     * @param  array<int, array<string, mixed>>  $templates  기존 template 목록
     * @return array<string, string> locale → subject
     */
    private function resolveBaseSubject(array $templates): array
    {
        foreach (['database', 'mail'] as $channel) {
            $template = $this->findTemplate($templates, $channel);
            if ($template !== null && is_array($template['subject'] ?? null)) {
                return $template['subject'];
            }
        }

        return [];
    }

    /**
     * 증강할 채널의 recipients 를 결정합니다.
     *
     * 기존 template(database → mail)의 recipients 를 그대로 재활용해 수신자 규칙을 일치시킨다.
     * 없으면 발생 회원 본인(trigger_user) 기본값을 쓴다.
     *
     * @param  array<int, array<string, mixed>>  $templates  기존 template 목록
     * @return array<int, array<string, mixed>> recipients 규칙
     */
    private function resolveRecipients(array $templates): array
    {
        foreach (['database', 'mail'] as $channel) {
            $template = $this->findTemplate($templates, $channel);
            if ($template !== null && ! empty($template['recipients']) && is_array($template['recipients'])) {
                return $template['recipients'];
            }
        }

        return [['type' => 'trigger_user']];
    }

    /**
     * template 목록에서 특정 채널의 template 을 찾습니다.
     *
     * @param  array<int, array<string, mixed>>  $templates  template 목록
     * @param  string  $channel  찾을 채널
     * @return array<string, mixed>|null 매칭 template 또는 null
     */
    private function findTemplate(array $templates, string $channel): ?array
    {
        foreach ($templates as $template) {
            if (($template['channel'] ?? null) === $channel) {
                return $template;
            }
        }

        return null;
    }

    /**
     * HTML 문자열을 문자/알림톡용 평문으로 변환합니다.
     *
     * 태그 제거 + 엔티티 디코드 + 연속 공백 정리. 변수 표기({name} 등)는 보존된다.
     *
     * @param  string  $html  원본 HTML
     * @return string 평문
     */
    private function stripHtml(string $html): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $html) ?? '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
