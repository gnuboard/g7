<?php

namespace App\Repositories;

use App\Contracts\Repositories\ExtensionDeferredCleanupRepositoryInterface;
use App\Enums\DeferredCleanupKind;
use App\Models\ExtensionDeferredCleanup;
use Illuminate\Support\Collection;

/**
 * 미뤄 둔 선언 정리 대장 Repository.
 *
 * @since 7.2.0
 */
class ExtensionDeferredCleanupRepository implements ExtensionDeferredCleanupRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function record(string $scope, string $identifier, DeferredCleanupKind $kind, string $target = '', ?string $recordedVersion = null, ?string $codeFingerprint = null): void
    {
        $keys = ['scope' => $scope, 'identifier' => $identifier, 'kind' => $kind->value, 'target' => $target];
        $now = now();

        // 유니크 키로 한 줄만 둔다 — 동시에 두 실행이 적어도 중복 키 예외가 나지 않게 먼저 무시 삽입하고 갱신한다
        ExtensionDeferredCleanup::query()->insertOrIgnore($keys + ['recorded_version' => $recordedVersion, 'code_fingerprint' => $codeFingerprint, 'created_at' => $now, 'updated_at' => $now]);

        $row = ExtensionDeferredCleanup::query()->where($keys)->first();
        if ($row === null) {
            return;
        }

        $updates = ['updated_at' => $now, 'code_fingerprint' => $codeFingerprint];
        if ($recordedVersion !== null
            && ($row->recorded_version === null || version_compare($recordedVersion, (string) $row->recorded_version, '>'))) {
            $updates['recorded_version'] = $recordedVersion;
        }
        ExtensionDeferredCleanup::query()->whereKey($row->id)->update($updates);

        // 지문은 행이 아니라 그 확장(코어)의 마지막 검수 빌드다 — 설치 때만 적히는 시드 정리 행이 옛 빌드 지문에 머물면
        // 마지막 빌드를 받은 운영 서버에서 영영 기다린다. 다시 적힘 판정(updated_at)은 그 행 것이라 건드리지 않는다
        ExtensionDeferredCleanup::query()
            ->where('scope', $scope)
            ->where('identifier', $identifier)
            ->whereKeyNot($row->id)
            ->toBase()
            ->update(['code_fingerprint' => $codeFingerprint]);
    }

    /**
     * {@inheritDoc}
     */
    public function all(): Collection
    {
        return ExtensionDeferredCleanup::query()->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(ExtensionDeferredCleanup $row): bool
    {
        // 읽은 뒤 다시 적혔으면(검수 서버 재배포) 지우지 않는다 — 다음 실행이 새 기록으로 다시 계산한다
        return ExtensionDeferredCleanup::query()
            ->whereKey($row->id)
            ->where('updated_at', '<=', $row->updated_at)
            ->delete() > 0;
    }
}
