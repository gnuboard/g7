<?php

namespace Modules\Zztest\Seedprov\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Zztest\Seedprov\Contracts\ProbeRepositoryInterface;

/**
 * 프로브 저장소 구현 (시험 픽스처).
 */
class ProbeRepository implements ProbeRepositoryInterface
{
    /**
     * 프로브 행을 하나 만듭니다.
     *
     * @param  string  $name  행 이름
     * @return void 반환값 없음
     */
    public function create(string $name): void
    {
        DB::table('zztest_seedprov_probes')->insert(['name' => $name]);
    }
}
