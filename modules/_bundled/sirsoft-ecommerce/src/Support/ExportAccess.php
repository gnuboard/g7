<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportTypeInterface;
use Modules\Sirsoft\Ecommerce\Models\Export;

/**
 * 내보내기 작업의 「본인/전체」 범위 판정 한곳
 *
 * 범위를 별도 권한 `sirsoft-ecommerce.exports.all` 로 나눈다. 이 권한이 없으면 본인이 요청한
 * 작업만 보고·받고·지우고·다시 요청한다. 목록 쿼리·행 권한·상세 경로가 모두 이 판정을 쓴다.
 */
final class ExportAccess
{
    /**
     * 다른 운영자의 작업까지 다루는 권한
     */
    public const ALL_PERMISSION = 'sirsoft-ecommerce.exports.all';

    /**
     * 원본 조회 범위 — 전체
     */
    public const SCOPE_ALL = 'all';

    /**
     * 원본 조회 범위의 넓이 (클수록 넓다)
     *
     * @var array<string, int>
     */
    private const SCOPE_RANK = ['self' => 0, 'role' => 1, self::SCOPE_ALL => 2];

    /**
     * 다른 운영자의 작업까지 다룰 수 있는지 판정합니다.
     *
     * @param  User|null  $user  보는 사람
     * @return bool 전체 범위 여부
     */
    public static function seesAll(?User $user): bool
    {
        return $user !== null && PermissionHelper::check(self::ALL_PERMISSION, $user);
    }

    /**
     * 작업이 보는 사람의 범위 안인지 판정합니다.
     *
     * @param  Export  $export  작업
     * @param  User|null  $user  보는 사람
     * @return bool 범위 안 여부
     */
    public static function inScope(Export $export, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return self::seesAll($user) || (int) $export->requested_by === (int) $user->id;
    }

    /**
     * 목록 쿼리에 범위를 겁니다.
     *
     * @param  Builder  $query  작업 쿼리
     * @param  User|null  $user  보는 사람
     * @return void
     */
    public static function applyScope(Builder $query, ?User $user): void
    {
        if (! self::seesAll($user)) {
            $query->where('requested_by', $user?->id ?? 0);
        }
    }

    /**
     * 작업의 파일(원본 데이터)을 받을 수 있는지 판정합니다 — 작업 범위({@see inScope()})와 별개로 원본 권한을 본다.
     *
     * - 그 유형의 원본 조회 권한(주문=orders.read …)이 있어야 하고, 개인정보가 든 파일이면 개인정보 권한도 있어야 한다.
     * - 남이 만든 파일이면 원본 조회 범위가 「전체」여야 한다. 파일은 만든 사람의 범위로 뽑혔으므로, 범위가 「본인」·
     *   「소유 역할」인 운영자가 받으면 자기 범위 밖 자료를 보게 된다(「다른 운영자의 내보내기 관리」 권한만으로는 부족).
     *   범위 개념이 없는 권한(소유자 열 없음)은 이 조건을 보지 않는다.
     * - 자기 파일도 만들 때 기록한 범위(`options.source_scope`)보다 지금 범위가 좁으면 받지 못한다 — 범위를 줄인 뒤에도
     *   예전에 넓은 범위로 뽑은 파일을 받으면 줄인 의미가 없다.
     *
     * @param  Export  $export  작업
     * @param  User|null  $user  받는 사람
     * @param  ExportTypeInterface|null  $type  작업 유형 (없으면 받을 수 없다)
     * @return bool 받을 수 있으면 true
     */
    public static function canReadSource(Export $export, ?User $user, ?ExportTypeInterface $type): bool
    {
        if ($user === null || $type === null || ! PermissionHelper::check($type->readPermission(), $user)) {
            return false;
        }

        if ($export->contains_personal_data && ! PermissionHelper::check('sirsoft-ecommerce.exports.personal_data', $user)) {
            return false;
        }

        if (PermissionHelper::getOwnerKey($type->readPermission()) === null) {
            return true;
        }

        $current = self::currentSourceScope($type, $user);
        if ((int) $export->requested_by !== (int) $user->id) {
            return $current === self::SCOPE_ALL;
        }

        // 자기 파일도 만들 때보다 범위가 좁아졌으면 막는다 — 기록이 없는 옛 파일은 「전체」로 뽑힌 것으로 본다
        $recorded = (string) (((array) $export->options)['source_scope'] ?? self::SCOPE_ALL);

        return (self::SCOPE_RANK[$current] ?? 0) >= (self::SCOPE_RANK[$recorded] ?? self::SCOPE_RANK[self::SCOPE_ALL]);
    }

    /**
     * 그 유형의 원본 조회 범위를 반환합니다 — 파일에 기록하고 받을 때 비교하는 값.
     *
     * @param  ExportTypeInterface  $type  작업 유형
     * @param  User  $user  운영자
     * @return string `all`(전체) · `role`(소유 역할) · `self`(본인)
     */
    public static function currentSourceScope(ExportTypeInterface $type, User $user): string
    {
        return PermissionHelper::getEffectiveScope($type->readPermission(), $user) ?? self::SCOPE_ALL;
    }
}
