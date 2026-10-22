<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Types;

use App\Extension\HookManager;
use App\Models\User;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Enums\ExportTypeEnum;
use Modules\Sirsoft\Ecommerce\Exports\ExportFieldType;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\MileageTransactionListRequest;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;

/**
 * 마일리지 내역 내보내기 — 원장 거래 1건 = 1행
 *
 * 합계는 통화별 **거래액 합**이다(잔액이 아니다). 잔액 캐시가 아니라 원장 거래를 그대로 내보낸다.
 */
class MileageExportType extends AbstractExportType
{
    public const UNIT_TRANSACTION = 'transaction';

    /**
     * 한 번에 읽는 거래 수
     */
    private const CHUNK = 1000;

    /**
     * @param  MileageTransactionRepositoryInterface  $transactions  마일리지 거래 저장소 — 내보내기 원천 쿼리(목록과 같은 조건·요청자 범위)
     */
    public function __construct(
        private readonly MileageTransactionRepositoryInterface $transactions,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return ExportTypeEnum::MILEAGE->value;
    }

    /**
     * {@inheritDoc}
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.export_type.mileage';
    }

    /**
     * {@inheritDoc}
     */
    public function readPermission(): string
    {
        return 'sirsoft-ecommerce.mileage.read';
    }

    /**
     * {@inheritDoc}
     */
    public function rowUnits(): array
    {
        return [self::UNIT_TRANSACTION => 'sirsoft-ecommerce::export.row_units.transaction'];
    }

    /**
     * {@inheritDoc}
     */
    public function dateTypes(): array
    {
        return ['created_at' => 'sirsoft-ecommerce::export.date_types.mileage.created_at'];
    }

    /**
     * {@inheritDoc}
     */
    public function periodRequired(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function listRequestClass(): string
    {
        return MileageTransactionListRequest::class;
    }

    /**
     * {@inheritDoc}
     */
    public function screenColumns(): array
    {
        $label = static fn (string $k) => 'sirsoft-ecommerce::export.screen.mileage.'.$k;

        return [
            'created_at' => ['label_key' => $label('created_at'), 'fields' => ['mileage.created_at']],
            'member' => ['label_key' => $label('member'), 'fields' => ['mileage.member_name', 'mileage.member_uuid']],
            'type' => ['label_key' => $label('type'), 'fields' => ['mileage.type']],
            'amount' => ['label_key' => $label('amount'), 'fields' => ['mileage.currency', 'mileage.amount']],
            'remaining' => ['label_key' => $label('remaining'), 'fields' => ['mileage.remaining_amount', 'mileage.balance_after']],
            'expiry' => ['label_key' => $label('expiry'), 'fields' => ['mileage.expires_at', 'mileage.expired_at']],
            'order' => ['label_key' => $label('order'), 'fields' => ['mileage.order_number']],
            'granted_by' => ['label_key' => $label('granted_by'), 'fields' => ['mileage.granted_by_name']],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function excludedScreenColumns(): array
    {
        return ['number' => '화면 행 번호 — 파일에는 의미 없음(쪽마다 다시 센다)'];
    }

    /**
     * {@inheritDoc}
     */
    protected function extendFields(array $fields, string $unit): mixed
    {
        return HookManager::applyFilters('sirsoft-ecommerce.export.mileage.fields', $fields, $unit);
    }

    /**
     * {@inheritDoc}
     */
    protected function buildFields(string $unit): array
    {
        $groups = [];
        foreach ($this->screenColumns() as $screenKey => $def) {
            foreach ($def['fields'] as $fieldKey) {
                $groups[substr($fieldKey, strlen('mileage.'))] = $screenKey;
            }
        }

        $fields = $this->declaredFields(MileageTransaction::class, 'mileage', static fn (object $row) => $row, $groups);

        $fields[] = $this->computed('mileage.member_name', ExportFieldType::STRING, true,
            static fn (MileageTransaction $t) => $t->user?->name, 'member');
        $fields[] = $this->computed('mileage.member_uuid', ExportFieldType::STRING, false,
            static fn (MileageTransaction $t) => $t->user?->uuid, 'member');
        $fields[] = $this->computed('mileage.order_number', ExportFieldType::STRING, false,
            static fn (MileageTransaction $t) => $t->order?->order_number, 'order');
        $fields[] = $this->computed('mileage.granted_by_name', ExportFieldType::STRING, false,
            static fn (MileageTransaction $t) => $t->granted_by
                ? ($t->grantedByUser?->name ?? (string) $t->granted_by)
                : __('sirsoft-ecommerce::export.values.system'), 'granted_by');

        return $fields;
    }

    /**
     * {@inheritDoc}
     */
    public function query(array $filters, ?array $ids, User $requester, ?CarbonInterface $cutoff): Builder
    {
        $query = $this->transactions->newExportQuery($filters, $ids, $requester);

        if ($cutoff !== null) {
            $query->where('created_at', '<', $cutoff);
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function countRows(Builder $query, string $unit, ?CarbonInterface $cutoff = null): int
    {
        return (clone $query)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function rows(Builder $query, string $unit, ?CarbonInterface $cutoff = null): Generator
    {
        $relations = ['user:id,uuid,name', 'grantedByUser:id,name', 'order:id,order_number'];

        foreach ((clone $query)->with($relations)->lazyByIdDesc(self::CHUNK) as $transaction) {
            yield [$transaction, true, $transaction->currency];
        }
    }
}
