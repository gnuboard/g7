<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Enums\UserStatus;
use App\Models\FieldValue;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\BirthdayMemberRepositoryInterface;

/**
 * 생일 회원 조회 Repository 구현체 (#952)
 */
class BirthdayMemberRepository implements BirthdayMemberRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function chunkActiveUserIdsByBirthday(int $fieldDefinitionId, array $monthDays, int $chunk, callable $callback): int
    {
        $monthDays = array_values(array_unique(array_filter($monthDays, fn ($v) => is_string($v) && preg_match('/^\d{2}-\d{2}$/', $v))));
        if ($monthDays === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($monthDays), '?'));
        $ownerIds = FieldValue::query()
            ->select('owner_id')
            ->where('field_definition_id', $fieldDefinitionId)
            ->where('owner_type', FieldValue::OWNER_USER)
            ->whereNotNull('value_date')
            ->whereRaw("DATE_FORMAT(value_date, '%m-%d') IN ({$placeholders})", $monthDays);

        $total = 0;

        User::query()
            ->select('id')
            ->where('status', UserStatus::Active->value)
            ->whereIn('id', $ownerIds)
            ->chunkById(max(1, $chunk), function ($users) use ($callback, &$total) {
                $ids = $users->pluck('id')->map(fn ($id) => (int) $id)->all();
                $total += count($ids);
                $callback($ids);
            });

        return $total;
    }
}
