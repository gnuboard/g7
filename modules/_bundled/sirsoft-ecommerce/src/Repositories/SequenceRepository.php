<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Models\SequenceCode;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SequenceRepositoryInterface;

/**
 * 시퀀스 Repository 구현체
 */
class SequenceRepository implements SequenceRepositoryInterface
{
    public function __construct(
        protected Sequence $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findByType(SequenceType $type): ?Sequence
    {
        return $this->model
            ->where('type', $type->value)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByTypeForUpdate(SequenceType $type): ?Sequence
    {
        return $this->model
            ->where('type', $type->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Sequence
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function updateCurrentValue(Sequence $sequence, int $newValue): bool
    {
        return $sequence->update(['current_value' => $newValue]);
    }

    /**
     * {@inheritDoc}
     */
    public function updateLastResetDate(Sequence $sequence, Carbon $date): bool
    {
        return $sequence->update(['last_reset_date' => $date]);
    }

    /**
     * {@inheritDoc}
     */
    public function codeExists(SequenceType $type, string $code): bool
    {
        return SequenceCode::where('type', $type->value)
            ->where('code', $code)
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function insertCode(SequenceType $type, string $code): void
    {
        SequenceCode::create([
            'type' => $type,
            'code' => $code,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function countCodes(SequenceType $type): int
    {
        return SequenceCode::where('type', $type->value)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteOldCodes(SequenceType $type, int $keepCount): int
    {
        $keepIds = SequenceCode::where('type', $type->value)
            ->orderByDesc('id')
            ->limit($keepCount)
            ->pluck('id');

        return SequenceCode::where('type', $type->value)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
