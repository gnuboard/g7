<?php

namespace Modules\Sirsoft\Ecommerce\Privacy;

use App\Extension\HookManager;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use App\Privacy\DTO\PrivacyRetentionTarget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderPrivacyRetentionService;
use Throwable;

/**
 * 종결 주문 개인정보 보유기간 핸들러 (이슈 #636)
 *
 * 종결된 주문 가운데 종결 후 N년(기본 5년 — 전자상거래법 §6 보존기간)이 지난 것의 주문자·
 * 수령인 개인정보를 운영자가 고른 방식으로 처리합니다. 한 주문 안에서 무엇을 지우는지는
 * {@see OrderPrivacyRetentionService} 가 알고, 이 핸들러는 대상 선정·순회·건수 보고만 합니다.
 *
 * ## 「종결」의 정의
 *
 * 주문 상태가 **구매확정** 또는 **취소**이고, **진행 중인 환불·교환·반품이 없는** 주문입니다. 종결
 * 시각은 그 상태에 도달한 시각(`confirmed_at` · `cancelled_at`)과 **완료된 환불·교환·반품의 완료 시각**
 * 중 가장 늦은 것입니다. 「환불완료」는 주문 상태가 아니라 환불 레코드의 상태라서(부수의무 #41) 환불
 * 테이블을 함께 봅니다 — 주문 상태 두 갈래만 보면 전체취소 뒤 무통장 환불이 아직 송금 전(승인 대기)인
 * 주문까지 파기되어, 환불 계좌 정보가 사라진 채 송금할 수 없게 됩니다.
 *
 * 교환·반품(#599)도 주문 상태를 바꾸지 않고 주문 곁에서 진행되므로 같은 이유로 봅니다 — 구매확정 뒤에도
 * 신청·직권 접수가 가능해, 처리 중인 신청서의 환불 계좌·재배송지를 지우면 회수·재발송·환불을 끝낼 수
 * 없습니다. 거절·철회로 끝난 신청서(환불 포함)는 진행 중이 아니고 종결 시각도 늦추지 않습니다.
 *
 * 종결 시각 컬럼이 비어 있는 레거시 주문(`cancelled_at` 도입 전 취소분 등)은 마지막 수정 시각으로
 * 대신합니다. 그 값은 실제 종결 시각보다 늦거나 같으므로 **더 오래 보존하는 쪽**으로만 틀립니다.
 *
 * 배송 중·배송 완료(구매확정 전) 등 나머지 상태는 어떤 경우에도 대상이 아닙니다. 삭제된(휴지통)
 * 주문도 행에 개인정보가 남으므로 대상입니다.
 *
 * ## 멱등
 *
 * 익명화는 `order_meta` 의 익명화 표지가 없는 주문만, 삭제는 행이 사라지므로 표지 없이 두 번째 실행이
 * 0건입니다. 익명화했던 주문을 나중에 삭제 모드로 바꾸면 그 주문은 다시 대상이 됩니다.
 */
class OrderRetentionHandler implements PrivacyRetentionHandlerInterface
{
    /** 익명화 모드 */
    public const MODE_ANONYMIZE = 'anonymize';

    /** 삭제 모드 */
    public const MODE_DELETE = 'delete';

    /** 전자상거래법 보존기간 하한(년) — 판독부 바닥값 */
    public const MIN_RETENTION_YEARS = 5;

    /** 한 번에 읽어 처리할 주문 수 */
    private const CHUNK_SIZE = 100;

    /**
     * @param  OrderPrivacyRetentionService  $service  주문 한 건의 익명화·삭제 서비스
     */
    public function __construct(private OrderPrivacyRetentionService $service) {}

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'sirsoft-ecommerce.order';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::messages.privacy_retention.handler_label');
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return (bool) module_setting('sirsoft-ecommerce', 'privacy_retention.enabled', false);
    }

    /**
     * 보유기간(년)을 설정에서 읽습니다.
     *
     * 바닥값 5 — 저장 검증(`min:5`)을 거치지 않은 쓰기(설정 파일 직접 편집 등)가 더 짧은 값을
     * 남겨도 전자상거래법 보존기간보다 먼저 파기되지 않게 판독부에서 막는다.
     *
     * @return int 보유기간(년)
     */
    public function retentionYears(): int
    {
        return max(self::MIN_RETENTION_YEARS, (int) module_setting('sirsoft-ecommerce', 'privacy_retention.retention_years', self::MIN_RETENTION_YEARS));
    }

    /**
     * 처리 방식을 읽습니다. 알 수 없는 값은 되돌릴 수 있는 쪽(익명화)으로 떨어집니다.
     *
     * @return string anonymize|delete
     */
    public function mode(): string
    {
        $mode = module_setting('sirsoft-ecommerce', 'privacy_retention.mode', self::MODE_ANONYMIZE);

        return $mode === self::MODE_DELETE ? self::MODE_DELETE : self::MODE_ANONYMIZE;
    }

    /**
     * {@inheritDoc}
     */
    public function preview(): int
    {
        return $this->targetQuery()->count();
    }

    /**
     * {@inheritDoc}
     *
     * 행: 주문번호 · 주문자(주문자명, 익명화 뒤에는 `***`) · 주문 상태 · 종결일. 종결일은 상태의
     * 종결 시각(구매확정·취소)과 완료된 환불·교환·반품의 완료 시각 중 가장 늦은 것이다 — 대상 판정과 같은 기준.
     * 휴지통 주문은 대상에 들지만 관리자 주문 상세가 열리지 않으므로 상세 링크를 두지 않는다.
     */
    public function targets(int $page, int $perPage): LengthAwarePaginator
    {
        return $this->targetQuery()
            ->select(['id', 'order_number', 'order_status', 'confirmed_at', 'cancelled_at', 'updated_at', 'deleted_at'])
            ->with(['addresses' => fn ($q) => $q->select(['id', 'order_id', 'orderer_name', 'address_type'])])
            ->withMax(['refunds as last_refunded_at' => fn ($q) => $q->where('refund_status', RefundStatusEnum::COMPLETED->value)], 'refunded_at')
            ->withMax(['exchanges as last_exchange_completed_at' => fn ($q) => $q->where('exchange_status', ExchangeStatusEnum::COMPLETED->value)], 'completed_at')
            ->withMax(['returns as last_return_completed_at' => fn ($q) => $q->where('return_status', ReturnStatusEnum::COMPLETED->value)], 'completed_at')
            ->orderByRaw('COALESCE(confirmed_at, cancelled_at, updated_at)')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(function (Order $order) {
                $status = $order->order_status instanceof OrderStatusEnum
                    ? $order->order_status
                    : OrderStatusEnum::tryFrom((string) $order->order_status);
                // 전량 반품으로 끝난 주문은 마지막 반품 완료 시각이 종결 시각이다(대상 판정과 같은 기준) — 수정 시각을
                // 바탕에 두면 반품 완료 뒤 운영자 메모 같은 수정이 종결일을 늦춰 보인다. 완료 반품이 없는 옛 주문만 수정 시각.
                $returnedWithCompletion = $status === OrderStatusEnum::RETURNED && $order->last_return_completed_at !== null;
                $closedAt = $order->confirmed_at ?? $order->cancelled_at ?? ($returnedWithCompletion ? null : $order->updated_at);

                foreach (['last_refunded_at', 'last_exchange_completed_at', 'last_return_completed_at'] as $column) {
                    $completedAt = $order->{$column} ? Carbon::parse($order->{$column}) : null;

                    if ($completedAt !== null && ($closedAt === null || $completedAt->greaterThan($closedAt))) {
                        $closedAt = $completedAt;
                    }
                }

                return new PrivacyRetentionTarget(
                    id: $order->id,
                    title: (string) $order->order_number,
                    subtitle: $order->addresses->firstWhere('address_type', 'shipping')?->orderer_name
                        ?? $order->addresses->first()?->orderer_name,
                    context: $status?->label(),
                    date: $closedAt,
                    url: $order->trashed() ? null : '/admin/ecommerce/orders/'.$order->order_number,
                );
            });
    }

    /**
     * {@inheritDoc}
     */
    public function targetColumns(): array
    {
        return [
            'title' => __('sirsoft-ecommerce::messages.privacy_retention.target_columns.order_number'),
            'subtitle' => __('sirsoft-ecommerce::messages.privacy_retention.target_columns.orderer'),
            'context' => __('sirsoft-ecommerce::messages.privacy_retention.target_columns.status'),
            'date' => __('sirsoft-ecommerce::messages.privacy_retention.target_columns.closed_at'),
        ];
    }

    /**
     * {@inheritDoc}
     *
     * 설정 읽기 + 주문 읽기 — 명단에 주문자 이름이 그대로 나오므로 주문을 볼 수 없는 운영자가
     * 설정 화면으로 우회해 주문자 명단을 보는 길을 막는다.
     */
    public function targetPermissions(): array
    {
        return ['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.orders.read'];
    }

    /**
     * {@inheritDoc}
     *
     * 설정 수정 + 주문 수정. 삭제 방식이면 주문 삭제 권한을 더한다 — 주문을 지울 수 없는 운영자가
     * 설정 화면의 직권 실행으로 종결 주문을 통째로 지우는 우회 경로를 막는다.
     */
    public function runPermissions(): array
    {
        $permissions = ['sirsoft-ecommerce.settings.update', 'sirsoft-ecommerce.orders.update'];

        if ($this->mode() === self::MODE_DELETE) {
            $permissions[] = 'sirsoft-ecommerce.orders.delete';
        }

        return $permissions;
    }

    /**
     * {@inheritDoc}
     */
    public function process(bool $dryRun, bool $force = false): array
    {
        // 토글을 스스로 다시 본다 — 수집기가 걸러 주지만 되돌릴 수 없는 파기라 호출자를 믿지 않는다.
        // 관리자 직권 실행(`$force`)만 이 가드를 넘는다 — 권한은 코어 직권 실행 API 가 검사했다.
        if (! $force && ! $this->isEnabled()) {
            return ['count' => 0, 'mode' => 'disabled'];
        }

        $mode = $this->mode();

        if ($dryRun) {
            return ['count' => $this->preview(), 'mode' => $mode];
        }

        // 보존일 인자 — prune 훅 규약(7.0.7)은 보존기간을 일 단위로 넘긴다.
        $days = $this->retentionDays();

        // 훅 이름을 리터럴로 적는다 — 조립하면 문서 생성기와 이름 검색이 찾지 못한다.
        HookManager::doAction('sirsoft-ecommerce.order.before_prune', $days);

        $processed = 0;

        $this->targetQuery()->chunkById(self::CHUNK_SIZE, function ($orders) use ($mode, &$processed) {
            foreach ($orders as $order) {
                try {
                    $mode === self::MODE_DELETE
                        ? $this->service->purge($order)
                        : $this->service->anonymize($order);

                    $processed++;
                } catch (Throwable $e) {
                    // 건별 격리 — 한 주문의 실패가 나머지 파기를 멈추지 않는다. 처리하지 못한 주문은
                    // 표지가 찍히지 않았으므로(트랜잭션 롤백) 다음 실행이 다시 대상으로 잡는다.
                    Log::warning('종결 주문 개인정보 보유기간 처리 실패 — 건너뜀', [
                        'order_id' => $order->id,
                        'mode' => $mode,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        HookManager::doAction('sirsoft-ecommerce.order.after_prune', $days, $processed);

        if ($processed > 0) {
            Log::info('종결 주문 개인정보 보유기간 처리 완료', [
                'years' => $this->retentionYears(),
                'mode' => $mode,
                'count' => $processed,
            ]);
        }

        return ['count' => $processed, 'mode' => $mode];
    }

    /**
     * 보유기간을 일 단위로 환산합니다 (prune 훅 인자).
     *
     * @return int 보존일
     */
    public function retentionDays(): int
    {
        return (int) abs($this->cutoff()->diffInDays(now()));
    }

    /**
     * 보유기간이 지난 미처리 종결 주문 쿼리 — 미리보기와 실행이 같은 이 쿼리를 쓴다.
     *
     * @return Builder<Order> 대상 쿼리
     */
    public function targetQuery(): Builder
    {
        $cutoff = $this->cutoff();

        $query = Order::withTrashed()
            ->where(function (Builder $q) use ($cutoff) {
                $q->where(function (Builder $confirmed) use ($cutoff) {
                    $confirmed->where('order_status', OrderStatusEnum::CONFIRMED->value)
                        ->where(fn (Builder $at) => $this->closedBefore($at, 'confirmed_at', $cutoff));
                })->orWhere(function (Builder $cancelled) use ($cutoff) {
                    $cancelled->where('order_status', OrderStatusEnum::CANCELLED->value)
                        ->where(fn (Builder $at) => $this->closedBefore($at, 'cancelled_at', $cutoff));
                })->orWhere(function (Builder $returned) use ($cutoff) {
                    // 전량 반품으로 끝난 주문 — 종결 시각은 마지막 반품 완료 시각이다(아래 「기준일 뒤 완료된 반품 없음」이
                    // 그 시각을 잰다). 반품 신청서 없이 행만 반품완료가 된 옛 주문은 마지막 수정 시각으로 대신한다.
                    $returned->where('order_status', OrderStatusEnum::RETURNED->value)
                        ->where(fn (Builder $at) => $at
                            ->whereHas('returns', fn (Builder $return) => $return->where('return_status', ReturnStatusEnum::COMPLETED->value))
                            ->orWhere('updated_at', '<=', $cutoff));
                });
            })
            // 진행 중인 환불이 있으면 종결이 아니다 — 송금 전 환불 계좌 정보를 지우면 환불할 수 없다.
            // 끝난 상태는 enum 이 정한다(완료·거절·철회) — 철회된 환불신청서를 진행 중으로 읽으면 그 주문은
            // 영영 대상이 되지 못한다(#599 가 철회를 들였다).
            ->whereDoesntHave('refunds', function (Builder $refund) {
                $refund->whereNotIn('refund_status', self::values(RefundStatusEnum::finalStatuses()));
            })
            // 환불이 기준일 뒤에 완료됐으면 종결 시각이 그만큼 늦다.
            ->whereDoesntHave('refunds', function (Builder $refund) use ($cutoff) {
                $refund->where('refund_status', RefundStatusEnum::COMPLETED->value)
                    ->where('refunded_at', '>', $cutoff);
            })
            // 교환·반품(#599)도 같다 — 진행 중이면 종결이 아니고, 기준일 뒤에 완료됐으면 종결이 그만큼 늦다.
            ->whereDoesntHave('exchanges', function (Builder $exchange) {
                $exchange->whereNotIn('exchange_status', self::values(ExchangeStatusEnum::finalStatuses()));
            })
            ->whereDoesntHave('exchanges', function (Builder $exchange) use ($cutoff) {
                $exchange->where('exchange_status', ExchangeStatusEnum::COMPLETED->value)
                    ->where('completed_at', '>', $cutoff);
            })
            ->whereDoesntHave('returns', function (Builder $return) {
                $return->whereNotIn('return_status', self::values(ReturnStatusEnum::finalStatuses()));
            })
            ->whereDoesntHave('returns', function (Builder $return) use ($cutoff) {
                $return->where('return_status', ReturnStatusEnum::COMPLETED->value)
                    ->where('completed_at', '>', $cutoff);
            });

        if ($this->mode() === self::MODE_ANONYMIZE) {
            // 이미 익명화된 주문 제외 — `order_meta` 는 JSON 문자열 컬럼이라 키 이름으로 가른다.
            $query->where(function (Builder $q) {
                $q->whereNull('order_meta')
                    ->orWhere('order_meta', 'not like', '%"'.OrderPrivacyRetentionService::ANONYMIZED_MARKER.'"%');
            });
        }

        return $query;
    }

    /**
     * enum 목록을 값 목록으로 바꿉니다.
     *
     * @param  array<int, \BackedEnum>  $cases  enum 목록
     * @return array<int, string> 값 목록
     */
    private static function values(array $cases): array
    {
        return array_map(fn (\BackedEnum $case) => (string) $case->value, $cases);
    }

    /**
     * 종결 시각 컬럼이 기준 시각 이전인지 판정합니다 (비어 있으면 마지막 수정 시각으로 대신).
     *
     * @param  Builder  $query  조건을 더할 쿼리
     * @param  string  $column  종결 시각 컬럼
     * @param  Carbon  $cutoff  기준 시각
     * @return void 반환값 없음
     */
    private function closedBefore(Builder $query, string $column, Carbon $cutoff): void
    {
        $query->where($column, '<=', $cutoff)
            ->orWhere(fn (Builder $legacy) => $legacy->whereNull($column)->where('updated_at', '<=', $cutoff));
    }

    /**
     * 보유기간 기준 시각을 계산합니다.
     *
     * @return Carbon 이 시각 이전에 종결된 주문이 대상이다
     */
    private function cutoff(): Carbon
    {
        return now()->subYears($this->retentionYears());
    }
}
