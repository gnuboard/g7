<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportTypeInterface;
use Modules\Sirsoft\Ecommerce\Models\Export;

/**
 * 내보내기 작업의 범위 판정 한곳
 *
 * 범위는 코어 표준 권한 범위다 — `exports` 권한이 `owner_key = requested_by` 를 선언하므로 역할 관리에서 권한마다
 * 「본인만 / 소유 역할 / 전체」를 고른다(기본: 관리자 전체, 이커머스 관리자 본인만). 목록 쿼리·행 권한·상세 경로·
 * 일괄 삭제·일회용 주소가 모두 이 판정을 쓴다. `{export}` 라우트는 미들웨어도 같은 범위를 보지만, 범위를 두지 않는
 * 「요청」 권한으로 도는 다시 요청과 라우트 모델이 없는 일회용 주소·일괄 삭제는 이 판정만 지킨다.
 */
final class ExportAccess
{
    /**
     * 내보내기 관리 조회 권한 — 목록·상세·다운로드 이력·다시 요청의 범위
     */
    public const READ_PERMISSION = 'sirsoft-ecommerce.exports.read';

    /**
     * 내보내기 파일 다운로드 권한 — 받기·일회용 주소의 범위
     */
    public const DOWNLOAD_PERMISSION = 'sirsoft-ecommerce.exports.download';

    /**
     * 내보내기 삭제 권한 — 삭제·일괄 삭제의 범위
     */
    public const DELETE_PERMISSION = 'sirsoft-ecommerce.exports.delete';

    /**
     * 개인정보 포함 내보내기 권한 — 이미 만든 작업의 개인정보(파일·다시 요청·받은 사람 IP)를 다루는 범위
     */
    public const PERSONAL_PERMISSION = 'sirsoft-ecommerce.exports.personal_data';

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
     * 작업이 그 권한의 범위 안인지 판정합니다 — 권한이 없으면 범위 밖이다.
     *
     * @param  Export  $export  작업
     * @param  User|null  $user  보는 사람
     * @param  string  $permission  판정할 권한 (조회·다운로드·삭제)
     * @return bool 범위 안 여부
     */
    public static function inScope(Export $export, ?User $user, string $permission = self::READ_PERMISSION): bool
    {
        if ($user === null || ! PermissionHelper::check($permission, $user)) {
            return false;
        }

        return PermissionHelper::checkScopeAccess($export, $permission, $user);
    }

    /**
     * 작업의 개인정보를 다룰 수 있는지 판정합니다 — 개인정보가 없는 작업은 언제나 된다.
     *
     * 개인정보 권한도 표준 범위를 따른다: 범위가 「본인만」이면 자기가 요청한 작업의 개인정보만, 「소유 역할」이면 같은
     * 역할 운영자의 작업까지 다룬다. 새로 요청할 때는 판정할 작업이 없으므로 권한 보유만 본다.
     *
     * @param  Export  $export  작업
     * @param  User|null  $user  다루는 사람
     * @return bool 허용 여부
     */
    public static function canHandlePersonal(Export $export, ?User $user): bool
    {
        return ! $export->contains_personal_data || self::inScope($export, $user, self::PERSONAL_PERMISSION);
    }

    /**
     * 목록 쿼리에 조회 범위를 겁니다.
     *
     * @param  Builder  $query  작업 쿼리
     * @param  User|null  $user  보는 사람
     * @return void
     */
    public static function applyScope(Builder $query, ?User $user): void
    {
        if ($user === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        PermissionHelper::applyPermissionScope($query, self::READ_PERMISSION, $user);
    }

    /**
     * 작업의 파일(원본 데이터)을 받을 수 있는지 판정합니다 — 작업 범위({@see inScope()})와 별개로 원본 권한을 본다.
     *
     * - 그 유형의 원본 조회 권한(주문=orders.read …)이 있어야 하고, 개인정보가 든 파일이면 개인정보 권한도 있어야 한다.
     * - 남이 만든 파일이면 원본 조회 범위가 「전체」여야 한다. 파일은 만든 사람의 범위로 뽑혔으므로, 범위가 「본인」·
     *   「소유 역할」인 운영자가 받으면 자기 범위 밖 자료를 보게 된다(내보내기 범위가 「전체」인 것만으로는 부족).
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

        if (! self::canHandlePersonal($export, $user)) {
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
