<?php

namespace Modules\Zztest\Seedprov\Contracts;

/**
 * 시더가 해석하는 저장소 인터페이스 (시험 픽스처) — 프로바이더 `$repositories` 에만 바인딩된다.
 */
interface ProbeRepositoryInterface
{
    /**
     * 프로브 행을 하나 만듭니다.
     *
     * @param  string  $name  행 이름
     * @return void 반환값 없음
     */
    public function create(string $name): void;
}
