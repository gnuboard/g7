<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories\Contracts;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;

/**
 * 소셜 로그인 제공자 설정 저장소.
 *
 * @since 1.0.0
 */
interface ProviderRepositoryInterface
{
    /**
     * 모든 제공자 행을 표시 순서대로 반환합니다.
     *
     * @return Collection<int, SocialLoginProvider> 제공자 목록
     */
    public function all(): Collection;

    /**
     * 키로 제공자 행을 찾습니다.
     *
     * @param  string  $key  제공자 키
     * @return SocialLoginProvider|null 제공자 행, 없으면 null
     */
    public function findByKey(string $key): ?SocialLoginProvider;

    /**
     * 키가 없으면 만들고, 있으면 비어 있는 기본값만 채웁니다 — 운영자가 바꾼 값은 덮어쓰지 않습니다.
     *
     * @param  string  $key  제공자 키
     * @param  array<string, mixed>  $defaults  새로 만들 때의 값
     * @return SocialLoginProvider 저장된 행
     */
    public function seedIfMissing(string $key, array $defaults): SocialLoginProvider;

    /**
     * 커스텀 제공자 수를 반환합니다.
     *
     * @return int 커스텀 행 수
     */
    public function countCustom(): int;

    /**
     * 마지막 성공·오류 정보를 기록합니다 (진단 화면).
     *
     * @param  string  $key  제공자 키
     * @param  string|null  $errorCode  오류 코드 — null 이면 성공 기록
     * @return void
     */
    public function recordOutcome(string $key, ?string $errorCode): void;
}
