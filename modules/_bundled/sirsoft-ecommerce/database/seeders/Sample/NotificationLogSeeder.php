<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Database\Sample\AbstractNotificationLogSampleSeeder;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 이커머스 모듈 알림 발송 이력 샘플 시더.
 *
 * extension_identifier='sirsoft-ecommerce' 인 활성 알림 정의 **전부**(주문·배송·클레임·환불·문의·마일리지·
 * 관리자 알림)를 최소 한 번씩 쓰고, 제목·본문은 그 정의의 실제 알림 템플릿(ko)에 샘플 값을 넣어 만든다.
 * `*_admin` 알림의 수신자는 관리자만이다. 본인인증 목적 정의(identity.purpose.*)와 운영자가 직접 쓰는
 * 주문 메일(order_custom_email)은 발송 이력 시연 대상이 아니라 뺀다.
 *
 * 수동 실행:
 *   php artisan module:seed sirsoft-ecommerce \
 *     --class="Sample\\NotificationLogSeeder" --sample
 */
class NotificationLogSeeder extends AbstractNotificationLogSampleSeeder
{
    /**
     * 시연에서 빼는 알림 정의 (본인인증 목적 정의·운영자 직접 작성 메일)
     */
    private const EXCLUDED_TYPES = ['order_custom_email'];

    /**
     * 정의별 채널 템플릿 캐시 [type][channel] => NotificationTemplate
     *
     * @var array<string, array<string, NotificationTemplate>>
     */
    private array $templates = [];

    /**
     * 샘플 값에 쓸 실제 주문 (주문번호·금액·상품명을 화면과 맞추기 위함)
     *
     * @var Collection<int, Order>
     */
    private Collection $sampleOrders;

    /**
     * 이커머스 모듈 정의만 필터링 (본인인증 목적 정의·운영자 직접 메일 제외).
     *
     * @param  Builder  $query  NotificationDefinition 쿼리
     * @return Builder 이커머스 영역 쿼리
     */
    protected function applyDefinitionScope(Builder $query): Builder
    {
        return $query->where('extension_identifier', 'sirsoft-ecommerce')
            ->where('type', 'not like', 'identity.purpose.%')
            ->whereNotIn('type', self::EXCLUDED_TYPES);
    }

    /**
     * @return string 카운트 옵션 키
     */
    protected function countKey(): string
    {
        return 'ecommerce_notification_logs';
    }

    /**
     * @return int 기본 건수
     */
    protected function defaultCount(): int
    {
        return 160;
    }

    /**
     * @return string 영역 라벨
     */
    protected function scopeLabel(): string
    {
        return '[이커머스]';
    }

    /**
     * 시더 실행 — 건수가 정의 수 이상이면 정의마다 최소 1건, `*_admin` 은 관리자에게만 보낸 이력을 만든다.
     *
     * 공통 부모(AbstractNotificationLogSampleSeeder)의 흐름과 같되 두 가지만 다르다:
     * 정의를 고르게 한 바퀴 먼저 돌고, 관리자 알림의 수신자를 관리자 중에서 고른다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $count = $this->getSeederCount($this->countKey(), $this->defaultCount());
        $label = $this->scopeLabel();

        $admins = User::query()->whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->get(['id', 'name', 'email']);
        $members = User::query()->whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))->get(['id', 'name', 'email']);
        if ($members->isEmpty() && $admins->isEmpty()) {
            $this->command->warn("사용자 데이터가 없어 {$label} 알림 발송 이력 시더를 건너뜁니다.");

            return;
        }

        $definitions = $this->applyDefinitionScope(
            NotificationDefinition::query()->where('is_active', true)
        )->get(['id', 'type', 'extension_type', 'extension_identifier', 'channels']);

        if ($definitions->isEmpty()) {
            $this->command->warn("{$label} 영역 활성 알림 정의가 없어 시더를 건너뜁니다.");

            return;
        }

        $this->loadTemplates($definitions);
        $this->sampleOrders = Order::query()->with('options')->latest('id')->take(50)->get();

        $this->command->info("{$label} 알림 발송 이력 시딩 시작... ({$count}건, 정의 {$definitions->count()}종)");

        $now = Carbon::now();
        $adminId = $admins->first()?->id;
        $batch = [];

        for ($i = 0; $i < $count; $i++) {
            // 첫 바퀴는 정의를 순서대로 — 모든 알림 종류가 이력 화면에 최소 한 번 보인다
            $definition = $i < $definitions->count() ? $definitions[$i] : $definitions->random();
            $isAdminBound = $this->isAdminBoundType($definition->type);
            // 관리자 알림은 관리자에게 (관리자 계정이 없는 시험 DB 에서만 일반 회원으로 대신)
            $pool = ($isAdminBound && $admins->isNotEmpty()) || $members->isEmpty() ? $admins : $members;

            $channel = $this->pickChannel($definition->channels ?? ['mail']);
            $recipient = $pool->random();
            [$status, $error] = $this->randomStatusAndError();
            $sentAt = $this->randomSentAt($now);
            $values = $this->sampleValues($recipient, $sentAt);

            $batch[] = [
                'channel' => $channel,
                'notification_type' => $definition->type,
                'extension_type' => $definition->extension_type,
                'extension_identifier' => $definition->extension_identifier,
                'recipient_user_id' => $recipient->id,
                'recipient_identifier' => $channel === 'mail' ? $recipient->email : (string) $recipient->id,
                'recipient_name' => $recipient->name,
                'sender_user_id' => $isAdminBound ? null : $adminId,
                'subject' => $this->renderTemplate($definition->type, $channel, 'subject', $values)
                    ?? $this->renderSubject($definition->type),
                'body' => $this->renderTemplate($definition->type, $channel, 'body', $values)
                    ?? $this->renderBody($definition->type, $recipient, $sentAt),
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

        $this->command->info("{$label} 알림 발송 이력 시딩 완료 (".count($batch).'건)');
    }

    /**
     * 정의별 채널 템플릿을 한 번에 읽어 둡니다.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, NotificationDefinition>  $definitions  알림 정의
     * @return void 반환값 없음
     */
    private function loadTemplates($definitions): void
    {
        $typesById = $definitions->pluck('type', 'id');

        NotificationTemplate::query()
            ->whereIn('definition_id', $typesById->keys())
            ->where('is_active', true)
            ->get(['definition_id', 'channel', 'subject', 'body'])
            ->each(function (NotificationTemplate $template) use ($typesById) {
                $this->templates[$typesById[$template->definition_id]][$template->channel] = $template;
            });
    }

    /**
     * 템플릿의 제목·본문(ko)에 샘플 값을 넣어 돌려줍니다. 템플릿이 없으면 null.
     *
     * @param  string  $type  알림 타입
     * @param  string  $channel  채널
     * @param  string  $field  subject|body
     * @param  array<string, string>  $values  치환 값
     * @return string|null 렌더 결과
     */
    private function renderTemplate(string $type, string $channel, string $field, array $values): ?string
    {
        $template = $this->templates[$type][$channel] ?? $this->templates[$type]['mail'] ?? null;
        if ($template === null) {
            return null;
        }

        $raw = $template->{$field};
        $map = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?? [config('app.locale') => $raw]) : []);
        $text = $map[config('app.locale')] ?? $map[config('app.fallback_locale')] ?? (is_array($map) ? (reset($map) ?: null) : null);
        if (! is_string($text) || $text === '') {
            return null;
        }

        // 알 수 없는 변수는 비워 둔다 — 중괄호가 그대로 남은 이력은 발송 실패처럼 보인다
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $values[$m[1]] ?? '', $text);
    }

    /**
     * 템플릿 변수에 넣을 샘플 값을 만듭니다 (가능하면 실제 주문 값).
     *
     * @param  User  $recipient  수신자
     * @param  Carbon  $sentAt  발송 시각
     * @return array<string, string> 변수명 => 값
     */
    private function sampleValues(User $recipient, Carbon $sentAt): array
    {
        $order = $this->sampleOrders->isNotEmpty() ? $this->sampleOrders->random() : null;
        $productName = $order?->options->first()?->product_name;
        $productName = is_array($productName) ? ($productName[config('app.locale')] ?? $productName[config('app.fallback_locale')] ?? reset($productName) ?: '') : (string) $productName;
        $amount = $order ? ecommerce_format_price((float) $order->total_amount) : ecommerce_format_price(39000);
        $siteUrl = rtrim((string) config('app.url'), '/');

        return [
            'name' => $recipient->name,
            'customer_name' => $recipient->name,
            'requester_name' => $recipient->name,
            'depositor_name' => $recipient->name,
            'shipping_recipient_name' => $recipient->name,
            'app_name' => (string) config('app.name'),
            'site_url' => $siteUrl,
            'order_number' => $order?->order_number ?? sprintf('ORD-%s-%05d', $sentAt->format('Ymd'), mt_rand(1, 99999)),
            'order_url' => $siteUrl.'/mypage/orders',
            'claim_number' => sprintf('CLM-%s-%04d', $sentAt->format('Ymd'), mt_rand(1, 9999)),
            'claim_url' => $siteUrl.'/mypage/claims',
            'claim_type_label' => ['교환', '반품', '환불'][array_rand(['교환', '반품', '환불'])],
            'inquiry_url' => $siteUrl.'/mypage/inquiries',
            'mileage_url' => $siteUrl.'/mypage/mileage',
            'product_name' => $productName !== '' ? $productName : '베이직 라운드 티셔츠',
            'total_amount' => $amount,
            'amount' => $amount,
            'paid_amount' => $amount,
            'deposit_amount' => $amount,
            'outstanding_amount' => ecommerce_format_price(0),
            'balance' => ecommerce_format_price(mt_rand(10, 300) * 100),
            'carrier_name' => 'CJ대한통운',
            'tracking_number' => sprintf('%012d', mt_rand(100000000000, 999999999999)),
            'bank_name' => '신한은행',
            'account_number' => '110-123-456789',
            'account_holder' => '샘플상점',
            'deposit_due_at' => $sentAt->copy()->addDays(3)->format('Y-m-d H:i'),
            'expires_date' => $sentAt->copy()->addDays(7)->format('Y-m-d'),
            'collection_due_date' => $sentAt->copy()->addDays(3)->format('Y-m-d'),
            'overdue_days' => (string) mt_rand(1, 5),
            'cancel_reason' => '단순 변심',
            'reject_reason' => '사진상 사용 흔적이 확인되어 반려합니다.',
            'decision_label' => '승인',
            'inquiry_content' => '배송 기간이 얼마나 걸리나요?',
            'total_count' => '3',
            'processed_count' => '2',
            'remaining_count' => '1',
        ];
    }

    /**
     * @return array<string, string> 템플릿이 없는 정의의 대체 제목
     */
    protected function subjectMap(): array
    {
        return [
            'order_confirmed' => '[G7쇼핑] 주문이 확인되었습니다',
            'order_shipped' => '[G7쇼핑] 상품 배송이 시작되었습니다',
            'order_completed' => '[G7쇼핑] 구매가 확정되었습니다',
            'order_cancelled' => '[G7쇼핑] 주문이 취소되었습니다',
            'new_order_admin' => '[G7관리자] 신규 주문이 접수되었습니다',
            'inquiry_received' => '[G7쇼핑] 상품 문의가 접수되었습니다',
            'inquiry_replied' => '[G7쇼핑] 문의에 답변이 등록되었습니다',
        ];
    }

    /**
     * @return array<string, callable(User, Carbon): string> 템플릿이 없는 정의의 대체 본문
     */
    protected function bodyMap(): array
    {
        return [];
    }
}
