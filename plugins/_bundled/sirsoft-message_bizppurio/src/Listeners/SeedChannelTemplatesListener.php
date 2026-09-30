<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 회원 대상 알림 정의에 sms·alimtalk 채널 template 을 증강하는 시딩 필터 리스너
 * (계획서 §6-2 결정 D, Phase 6 재설계).
 *
 * 코어는 코어 시더와 모든 모듈·플러그인의 알림 정의 동기화가 DB 에 쓰기 직전 "정의 목록"을
 * 공통 시딩 필터 훅 하나(core.notification.filter_seed_definitions, 코어 7.1.0)에 통과시킨다.
 * 이 리스너가 그 훅에 답해, 각 정의에 sms·alimtalk 채널을 channels 목록과 templates 에
 * 끼워넣는다. 그러면 시딩 주체가 그것을 자기 선언처럼 저장하고(NotificationSyncHelper::syncTemplate),
 * cleanupStaleTemplates 의 definedChannels 에 우리 채널이 포함되므로 재시딩·업데이트에도 삭제되지
 * 않는다. 코어·모듈 파일은 수정하지 않는다.
 *
 * 구독 훅(정의 목록을 넘기는 코어 필터 2종) — 도메인 이름을 모른다:
 * - core.notification.filter_seed_definitions    (시딩 — 코어·모든 모듈·플러그인 공통)
 * - core.notification.filter_default_definitions (코어 [기본값 복원] 경로 —
 *   NotificationTemplateService::getDefaultTemplateData). 시딩과 같은 증강을 적용해야
 *   우리가 시드한 sms·alimtalk 행의 [기본값 복원]이 "기본 템플릿 데이터를 찾을 수 없습니다" 로
 *   끝나지 않는다 — 시드 출처와 복원 출처가 같은 함수여야 한다(#597 §18.7 C2 실측 결함).
 *   도메인이 자기 정의를 이 필터에 보태는 priority 20 뒤(50)에 돌아야 도메인 정의도 증강된다.
 *
 * 종전에는 도메인별 언어팩 시딩 훅(seed.{도메인}.notifications.translations)을 이름으로 골라
 * 구독해, 목록에 없는 새 도메인의 알림에는 문자·알림톡 template 이 생기지 않았다. 그 훅들은
 * 언어팩 다국어 병합 전용이라 이제 구독하지 않는다.
 *
 * 두 훅 모두 type 을 담은 순차 배열 `[['type' => 'order_confirmed', ...], ...]` 을 넘긴다.
 * 형태가 다른 배열(키가 type 인 연관 배열)이 와도 각 정의 값에 동일하게 증강하고 키/순서는 보존한다.
 *
 * 증강 대상 = 회원(사용자) 대상 알림(결정 E). 관리자 전용(수신자가 role:admin 뿐인) 알림은
 * 문자/알림톡 대상이 아니므로 건너뛴다. 기본 body 는 그 알림의 database 채널 body(짧은 평문)를
 * 재활용하고, 없으면 mail body 의 HTML 을 제거해 만든다.
 *
 * #597 이후에도 이 시딩은 존치한다. 발송 본문의 SSoT 는 bizppurio_templates 로 옮겨졌지만
 * (SmsChannelDriver 는 sms_body, AlimtalkChannelDriver 는 approved_content 를 읽는다),
 * 알림 설정 화면이 여전히 이 template 행을 그리기 때문이다
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
     * 도메인 이름을 담은 훅은 구독하지 않는다 — 시딩·복원 모두 코어 훅 하나씩에 답한다.
     * - core.notification.filter_seed_definitions: 코어·모든 모듈·플러그인 알림 정의 시딩 공통 (코어 7.1.0)
     * - core.notification.filter_default_definitions: 코어 [기본값 복원] 경로
     *
     * @return array<string, array<string, mixed>> 훅 이름 → 구독 설정(method·priority·type)
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.notification.filter_seed_definitions' => [
                'method' => 'augment',
                'priority' => 50,
                'type' => 'filter',
            ],
            'core.notification.filter_default_definitions' => [
                'method' => 'augment',
                'priority' => 50,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 필터 메서드로 처리).
     *
     * @param  mixed  ...$args  훅 인자 (사용하지 않음)
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 정의 배열의 각 회원 대상 알림에 sms·alimtalk 채널 template 을 증강합니다.
     *
     * 정의 소유자(코어·어느 모듈·플러그인이든)와 무관하게 같은 규칙을 적용한다. 키와 순서를
     * 보존한 채 각 정의 값만 변형한다. 정의 형태가 배열이 아니거나 회원 대상이 아니면 원본을
     * 그대로 둔다.
     *
     * @param  array<int|string, mixed>  $definitions  시딩(또는 기본값 복원) 직전 알림 정의 배열
     * @param  array<string, mixed>  $context  훅 컨텍스트 — 시딩 훅은 소유자(extension_type·extension_identifier),
     *                                         복원 훅은 대상(type·channel). 증강 규칙은 컨텍스트에 의존하지 않는다
     * @return array<int|string, mixed> 채널이 증강된 정의 배열
     */
    public function augment(array $definitions, array $context = []): array
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
