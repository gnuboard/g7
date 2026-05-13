<?php

namespace App\Database\Sample;

use App\Enums\NotificationLogStatus;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 알림 발송 이력 샘플 시더 추상 베이스.
 *
 * 코어/모듈/플러그인이 각자 영역의 발송 이력을 채울 수 있도록 공통 골격을 제공한다.
 * - 등록된 NotificationDefinition 중 자기 영역 정의만 추려서 사용
 * - 수신자/발송자 = 실제 등록된 G7 사용자
 * - 상태 분포 = 운영 트래픽 비율 (sent 80%, failed 13%, skipped 7%)
 * - sent_at = 최근 60일 분포
 *
 * 서브클래스는 영역 필터(applyDefinitionScope) + 카운트 키/기본값 + 라벨 + 본문/제목 맵을 정의한다.
 */
abstract class AbstractNotificationLogSampleSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 발송 실패 시 사용할 실제 SMTP/메일 게이트웨이 에러 메시지.
     *
     * @var array<int, string>
     */
    protected array $errorMessages = [
        'SMTP connection refused: smtp.gmail.com:587',
        'Connection timed out after 10s',
        'Mailbox unavailable: 550 5.1.1 user unknown',
        'TLS handshake failed',
        'Rate limit exceeded (provider quota)',
        'Recipient address rejected: domain not found',
        'Authentication failed: invalid credentials',
        'Greylisted, retry later (450 4.2.0)',
    ];

    /**
     * 발송 건너뜀 사유.
     *
     * @var array<int, string>
     */
    protected array $skipReasons = [
        'Template inactive',
        'User opted out',
        'Channel disabled by user preference',
        'Quiet hours policy applied',
        'Duplicate suppression window',
    ];

    /**
     * 알림 정의 쿼리에 영역 필터를 적용한다 (예: extension_type='core' 또는 extension_identifier='sirsoft-board').
     *
     * @param  Builder  $query  NotificationDefinition 쿼리
     * @return Builder 영역 필터가 적용된 쿼리
     */
    abstract protected function applyDefinitionScope(Builder $query): Builder;

    /**
     * 카운트 옵션 키 (예: 'core_notification_logs', 'ecommerce_notification_logs').
     *
     * @return string 카운트 옵션 키
     */
    abstract protected function countKey(): string;

    /**
     * 기본 생성 건수.
     *
     * @return int 기본 건수
     */
    abstract protected function defaultCount(): int;

    /**
     * 콘솔 메시지에 사용할 영역 라벨 (예: '코어', '이커머스 모듈').
     *
     * @return string 영역 라벨
     */
    abstract protected function scopeLabel(): string;

    /**
     * 알림 타입별 한국어 제목 맵.
     *
     * @return array<string, string> [type => subject]
     */
    abstract protected function subjectMap(): array;

    /**
     * 알림 타입별 한국어 본문 빌더 맵.
     *
     * @return array<string, callable(User, Carbon): string> [type => fn($recipient, $sentAt) => string]
     */
    abstract protected function bodyMap(): array;

    /**
     * 시더 실행.
     */
    public function run(): void
    {
        $count = $this->getSeederCount($this->countKey(), $this->defaultCount());
        $label = $this->scopeLabel();

        $users = User::query()->get(['id', 'name', 'email']);
        if ($users->isEmpty()) {
            $this->command->warn("사용자 데이터가 없어 {$label} 알림 발송 이력 시더를 건너뜁니다.");

            return;
        }

        $definitions = $this->applyDefinitionScope(
            NotificationDefinition::query()->where('is_active', true)
        )->get(['type', 'extension_type', 'extension_identifier', 'channels']);

        if ($definitions->isEmpty()) {
            $this->command->warn("{$label} 영역 활성 알림 정의가 없어 시더를 건너뜁니다.");

            return;
        }

        $admin = User::query()
            ->whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))
            ->first();
        $adminId = $admin?->id;

        $this->command->info("{$label} 알림 발송 이력 시딩 시작... ({$count}건)");

        $now = Carbon::now();
        $batch = [];

        for ($i = 0; $i < $count; $i++) {
            $definition = $definitions->random();
            $channel = $this->pickChannel($definition->channels ?? ['mail']);
            $recipient = $users->random();
            [$status, $error] = $this->randomStatusAndError();
            $sentAt = $this->randomSentAt($now);

            $batch[] = [
                'channel' => $channel,
                'notification_type' => $definition->type,
                'extension_type' => $definition->extension_type,
                'extension_identifier' => $definition->extension_identifier,
                'recipient_user_id' => $recipient->id,
                'recipient_identifier' => $channel === 'mail'
                    ? $recipient->email
                    : (string) $recipient->id,
                'recipient_name' => $recipient->name,
                'sender_user_id' => $this->isAdminBoundType($definition->type) ? null : $adminId,
                'subject' => $this->renderSubject($definition->type),
                'body' => $this->renderBody($definition->type, $recipient, $sentAt),
                'status' => $status->value,
                'error_message' => $error,
                'source' => 'notification',
                'sent_at' => $sentAt,
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ];
        }

        foreach (array_chunk($batch, 100) as $chunk) {
            NotificationLog::insert($chunk);
        }

        $this->command->info("{$label} 알림 발송 이력 시딩 완료 ({$count}건)");
    }

    /**
     * 알림 정의에 등록된 채널 중 하나를 선택한다.
     *
     * @param  array<int, string>  $channels  활성 채널 배열
     * @return string 선택된 채널
     */
    protected function pickChannel(array $channels): string
    {
        if (empty($channels)) {
            return 'mail';
        }

        if (in_array('mail', $channels, true) && in_array('database', $channels, true)) {
            return mt_rand(1, 100) <= 60 ? 'mail' : 'database';
        }

        return $channels[array_rand($channels)];
    }

    /**
     * 가중치 기반 상태/에러 메시지 페어를 반환한다.
     *
     * @return array{0: NotificationLogStatus, 1: string|null}
     */
    protected function randomStatusAndError(): array
    {
        $rand = mt_rand(1, 100);

        if ($rand <= 80) {
            return [NotificationLogStatus::Sent, null];
        }

        if ($rand <= 93) {
            return [
                NotificationLogStatus::Failed,
                $this->errorMessages[array_rand($this->errorMessages)],
            ];
        }

        return [
            NotificationLogStatus::Skipped,
            $this->skipReasons[array_rand($this->skipReasons)],
        ];
    }

    /**
     * 최근 60일 내 임의 발송 시각을 반환한다.
     *
     * @param  Carbon  $now  기준 시각
     * @return Carbon 발송 시각
     */
    protected function randomSentAt(Carbon $now): Carbon
    {
        return (clone $now)
            ->subDays(mt_rand(0, 60))
            ->subHours(mt_rand(0, 23))
            ->subMinutes(mt_rand(0, 59))
            ->subSeconds(mt_rand(0, 59));
    }

    /**
     * 알림 타입이 관리자 수신용인지 (sender_user_id null 처리).
     *
     * @param  string  $type  알림 타입
     * @return bool 관리자 수신용이면 true
     */
    protected function isAdminBoundType(string $type): bool
    {
        return str_ends_with($type, '_admin');
    }

    /**
     * 알림 타입별 제목 (서브클래스 subjectMap 우선, fallback 은 generic).
     *
     * @param  string  $type  알림 타입
     * @return string 제목
     */
    protected function renderSubject(string $type): string
    {
        return $this->subjectMap()[$type] ?? "[G7] {$type} 알림";
    }

    /**
     * 알림 타입별 본문 (서브클래스 bodyMap 우선, fallback 은 generic).
     *
     * @param  string  $type  알림 타입
     * @param  User  $recipient  수신자
     * @param  Carbon  $sentAt  발송 시각
     * @return string 본문
     */
    protected function renderBody(string $type, User $recipient, Carbon $sentAt): string
    {
        $builder = $this->bodyMap()[$type] ?? null;

        if ($builder === null) {
            return "안녕하세요 {$recipient->name}님,\n\n{$type} 알림이 도착했습니다.";
        }

        return $builder($recipient, $sentAt);
    }
}
