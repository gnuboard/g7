<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Benchmark;

// ModuleTestCase 수동 로드 (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Tests\Support\Concerns\AssertsListIndexCoverage;

/**
 * 게시판 모듈 목록 프로파일의 색인 커버리지 검사
 *
 * 모듈 테이블은 이 스위트에서만 마이그레이션되므로, 코어 스위트에 몰면 테이블이 없어
 * 건너뛰고 초록인 채 미검사가 된다. 모듈 프로파일은 반드시 자기 스위트에서 검사한다.
 *
 * @scenario case=list_index_coverage
 *
 * @effects list_profiles_declare_matching_index
 */
class ListIndexCoverageTest extends ModuleTestCase
{
    use AssertsListIndexCoverage;

    public function test_module_list_profiles_have_matching_indexes(): void
    {
        $this->assertListIndexCoverageForExtension(new Module);
    }
}
