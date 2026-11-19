<?php

namespace App\Contracts\Repositories;

use App\Enums\DeferredCleanupKind;
use App\Models\ExtensionDeferredCleanup;
use Illuminate\Support\Collection;

/**
 * 미뤄 둔 선언 정리 대장 Repository 인터페이스.
 *
 * @since 7.2.0
 */
interface ExtensionDeferredCleanupRepositoryInterface
{
    /**
     * 정리 필요를 적습니다 — 같은 (범위·식별자·종류·대상) 은 한 줄만 두고 기록 시각만 갱신한다.
     *
     * 코어 판은 더 높은 쪽을 남긴다 — 검수 서버를 여러 번 올리면 마지막 판 기준으로 운영 서버 코어 판을 확인한다.
     *
     * @param  string  $scope  범위 (module|plugin|core)
     * @param  string  $identifier  식별자 (코어는 빈 문자열)
     * @param  DeferredCleanupKind  $kind  종류
     * @param  string  $target  seeder 종류의 시더 클래스 (그 밖은 빈 문자열)
     * @param  string|null  $recordedVersion  코어 정리의 코어 판 (그 밖은 null)
     * @param  string|null  $codeFingerprint  적는 서버의 코드 내용 지문 — 늘 마지막 기록으로 바꾼다(마지막 검수 빌드) — 같은 범위·식별자의 다른 행(설치 때 적힌 시드 정리 등)도 함께
     * @return void 반환값 없음
     */
    public function record(string $scope, string $identifier, DeferredCleanupKind $kind, string $target = '', ?string $recordedVersion = null, ?string $codeFingerprint = null): void;

    /**
     * 대장 전체(기록 순).
     *
     * @return Collection<int, ExtensionDeferredCleanup> 행
     */
    public function all(): Collection;

    /**
     * 한 건을 지웁니다 — 읽은 뒤 다시 적힌 행(검수 서버 재배포)은 지우지 않는다.
     *
     * @param  ExtensionDeferredCleanup  $row  실행 전에 읽은 행
     * @return bool 지웠으면 true, 그 사이 다시 적혀 남겼으면 false
     */
    public function delete(ExtensionDeferredCleanup $row): bool;
}
