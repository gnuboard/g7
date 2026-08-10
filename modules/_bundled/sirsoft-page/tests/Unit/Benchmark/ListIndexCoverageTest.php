<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Benchmark;

use Modules\Sirsoft\Page\Module;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use Tests\Support\Concerns\AssertsListIndexCoverage;

/**
 * 페이지 모듈 목록 프로파일의 색인 커버리지 검사
 *
 * 페이지 목록은 정의성 데이터라 색인을 면제했다. 면제는 사유와 함께 선언된 경우에만
 * 인정되므로, 사유가 지워지면 이 테스트가 색인 부재를 다시 보고한다.
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
