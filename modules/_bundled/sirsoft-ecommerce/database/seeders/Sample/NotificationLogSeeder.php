<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Database\Sample\AbstractNotificationLogSampleSeeder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * 이커머스 모듈 알림 발송 이력 샘플 시더.
 *
 * extension_identifier='sirsoft-ecommerce' 인 알림 정의(주문/배송/문의 7종)만 사용하여
 * 이커머스 영역 발송 이력을 채운다.
 *
 * 수동 실행:
 *   php artisan module:seed sirsoft-ecommerce \
 *     --class="Sample\\NotificationLogSeeder" --sample
 */
class NotificationLogSeeder extends AbstractNotificationLogSampleSeeder
{
    /**
     * 이커머스 모듈 정의만 필터링.
     *
     * @param  Builder  $query  NotificationDefinition 쿼리
     * @return Builder 이커머스 영역 쿼리
     */
    protected function applyDefinitionScope(Builder $query): Builder
    {
        return $query->where('extension_identifier', 'sirsoft-ecommerce');
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
        return 100;
    }

    /**
     * @return string 영역 라벨
     */
    protected function scopeLabel(): string
    {
        return '[이커머스]';
    }

    /**
     * @return array<string, string>
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
     * @return array<string, callable(User, Carbon): string>
     */
    protected function bodyMap(): array
    {
        $orderNo = fn (Carbon $t) => sprintf('ORD-%s-%05d', $t->format('Ymd'), mt_rand(1, 99999));
        $tracking = fn () => sprintf('%012d', mt_rand(100000000000, 999999999999));

        return [
            'order_confirmed' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n주문번호 {$orderNo($t)} 주문이 정상 접수되었습니다.\n결제가 완료되는 즉시 상품 준비를 시작합니다.\n\n주문 내역은 마이페이지에서 확인하실 수 있습니다.",
            'order_shipped' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n주문번호 {$orderNo($t)} 상품이 출고되었습니다.\n택배사: CJ대한통운\n송장번호: {$tracking()}\n\n평균 1~2일 내 도착 예정입니다.",
            'order_completed' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n주문번호 {$orderNo($t)} 구매가 확정되었습니다.\n상품 만족도가 어떠셨는지 후기를 남겨 주시면 다른 구매자에게 큰 도움이 됩니다.",
            'order_cancelled' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n주문번호 {$orderNo($t)} 주문이 취소되었습니다.\n결제하신 금액은 영업일 기준 3~5일 내 환불 처리됩니다.",
            'new_order_admin' => fn (User $u, Carbon $t) => "관리자님,\n\n신규 주문이 접수되었습니다.\n주문번호: {$orderNo($t)}\n주문자: {$u->name}\n\n관리자 페이지에서 확인해 주세요.",
            'inquiry_received' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n상품 문의가 정상 접수되었습니다.\n빠른 시일 내 답변 드리겠습니다.",
            'inquiry_replied' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n등록하신 상품 문의에 답변이 등록되었습니다.\n마이페이지 > 1:1 문의에서 확인해 주세요.",
        ];
    }
}
