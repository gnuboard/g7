<?php

namespace Tests\Unit\Extension;

use App\Extension\Traits\ResolvesLanguageFragments;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ResolvesLanguageFragments trait 테스트
 *
 * 다국어 파일의 $partial 디렉티브 해석 기능을 테스트합니다.
 */
class ResolvesLanguageFragmentsTest extends TestCase
{
    /**
     * trait을 사용하는 테스트용 클래스
     */
    private object $traitUser;

    /**
     * 테스트용 임시 디렉토리
     */
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        // trait을 사용하는 익명 클래스 생성
        $this->traitUser = new class
        {
            use ResolvesLanguageFragments;

            // protected 메서드를 public으로 노출
            public function callResolveLanguageFragments(array $data, string $basePath, int $depth = 0): array
            {
                return $this->resolveLanguageFragments($data, $basePath, $depth);
            }

            public function callResetFragmentStack(): void
            {
                $this->resetFragmentStack();
            }
        };

        // 테스트용 임시 디렉토리 생성
        $this->tempDir = storage_path('app/testing/lang_fragments_' . uniqid());
        File::makeDirectory($this->tempDir, 0755, true, true);
    }

    protected function tearDown(): void
    {
        // 테스트용 임시 디렉토리 삭제
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * $partial 디렉티브 없는 데이터는 그대로 반환
     */
    public function test_resolve_data_without_partial(): void
    {
        $data = [
            'common' => [
                'save' => '저장',
                'cancel' => '취소',
            ],
            'title' => '제목',
        ];

        $result = $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);

        $this->assertEquals($data, $result);
    }

    /**
     * 단일 $partial 디렉티브 해석 테스트
     */
    public function test_resolve_single_partial(): void
    {
        // fragment 파일 생성
        $fragmentContent = [
            'save' => '저장',
            'cancel' => '취소',
        ];
        File::put($this->tempDir . '/common.json', json_encode($fragmentContent, JSON_UNESCAPED_UNICODE));

        $data = [
            'common' => [
                '$partial' => 'common.json',
            ],
            'title' => '제목',
        ];

        $this->traitUser->callResetFragmentStack();
        $result = $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);

        $expected = [
            'common' => [
                'save' => '저장',
                'cancel' => '취소',
            ],
            'title' => '제목',
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * 중첩 $partial 디렉티브 해석 테스트
     */
    public function test_resolve_nested_partials(): void
    {
        // 하위 디렉토리 생성
        File::makeDirectory($this->tempDir . '/admin', 0755, true, true);

        // 중첩된 fragment 파일 생성
        $buttonsContent = [
            'save' => '저장',
            'cancel' => '취소',
        ];
        File::put($this->tempDir . '/admin/buttons.json', json_encode($buttonsContent, JSON_UNESCAPED_UNICODE));

        $adminContent = [
            'dashboard' => '대시보드',
            'buttons' => [
                '$partial' => 'admin/buttons.json',
            ],
        ];
        File::put($this->tempDir . '/admin.json', json_encode($adminContent, JSON_UNESCAPED_UNICODE));

        $data = [
            'admin' => [
                '$partial' => 'admin.json',
            ],
        ];

        $this->traitUser->callResetFragmentStack();
        $result = $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);

        $expected = [
            'admin' => [
                'dashboard' => '대시보드',
                'buttons' => [
                    'save' => '저장',
                    'cancel' => '취소',
                ],
            ],
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * 다중 $partial 디렉티브 해석 테스트
     */
    public function test_resolve_multiple_partials(): void
    {
        // fragment 파일들 생성
        File::put($this->tempDir . '/common.json', json_encode([
            'save' => '저장',
            'cancel' => '취소',
        ], JSON_UNESCAPED_UNICODE));

        File::put($this->tempDir . '/auth.json', json_encode([
            'login' => '로그인',
            'logout' => '로그아웃',
        ], JSON_UNESCAPED_UNICODE));

        $data = [
            'common' => [
                '$partial' => 'common.json',
            ],
            'auth' => [
                '$partial' => 'auth.json',
            ],
            'title' => '앱 제목',
        ];

        $this->traitUser->callResetFragmentStack();
        $result = $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);

        $expected = [
            'common' => [
                'save' => '저장',
                'cancel' => '취소',
            ],
            'auth' => [
                'login' => '로그인',
                'logout' => '로그아웃',
            ],
            'title' => '앱 제목',
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * 순환 참조 감지 테스트
     */
    public function test_detect_circular_reference(): void
    {
        // 순환 참조 파일 생성
        File::put($this->tempDir . '/a.json', json_encode([
            'nested' => [
                '$partial' => 'b.json',
            ],
        ], JSON_UNESCAPED_UNICODE));

        File::put($this->tempDir . '/b.json', json_encode([
            'nested' => [
                '$partial' => 'a.json',
            ],
        ], JSON_UNESCAPED_UNICODE));

        $data = [
            'section' => [
                '$partial' => 'a.json',
            ],
        ];

        $this->traitUser->callResetFragmentStack();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/순환 참조 감지/');

        $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);
    }

    /**
     * 최대 깊이 초과 테스트
     */
    public function test_max_depth_exceeded(): void
    {
        // 11단계 중첩 파일 생성 (MAX_DEPTH = 10)
        for ($i = 1; $i <= 11; $i++) {
            if ($i < 11) {
                $content = [
                    'level' => $i,
                    'nested' => [
                        '$partial' => "level{$i}.json",
                    ],
                ];
            } else {
                $content = ['level' => $i];
            }

            $fileName = $i === 1 ? 'start.json' : 'level' . ($i - 1) . '.json';
            File::put($this->tempDir . '/' . $fileName, json_encode($content, JSON_UNESCAPED_UNICODE));
        }

        $data = [
            'deep' => [
                '$partial' => 'start.json',
            ],
        ];

        $this->traitUser->callResetFragmentStack();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/최대 깊이.*초과/');

        $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);
    }

    /**
     * 존재하지 않는 fragment 파일 오류 테스트
     */
    public function test_fragment_file_not_found(): void
    {
        $data = [
            'missing' => [
                '$partial' => 'nonexistent.json',
            ],
        ];

        $this->traitUser->callResetFragmentStack();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/찾을 수 없습니다/');

        $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);
    }

    /**
     * 잘못된 JSON 파일 오류 테스트
     */
    public function test_invalid_json_fragment(): void
    {
        // 잘못된 JSON 파일 생성
        File::put($this->tempDir . '/invalid.json', '{ invalid json }');

        $data = [
            'section' => [
                '$partial' => 'invalid.json',
            ],
        ];

        $this->traitUser->callResetFragmentStack();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/JSON 파싱 오류/');

        $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);
    }

    /**
     * 빈 fragment 파일 처리 테스트
     */
    public function test_empty_fragment(): void
    {
        // 빈 객체 JSON 파일 생성
        File::put($this->tempDir . '/empty.json', '{}');

        $data = [
            'empty_section' => [
                '$partial' => 'empty.json',
            ],
            'title' => '제목',
        ];

        $this->traitUser->callResetFragmentStack();
        $result = $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);

        $expected = [
            'empty_section' => [],
            'title' => '제목',
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * 하위 디렉토리 경로 fragment 테스트
     */
    public function test_fragment_with_subdirectory_path(): void
    {
        // 하위 디렉토리 생성
        File::makeDirectory($this->tempDir . '/admin/products', 0755, true, true);

        // fragment 파일 생성
        File::put($this->tempDir . '/admin/products/list.json', json_encode([
            'title' => '상품 목록',
            'add' => '상품 추가',
        ], JSON_UNESCAPED_UNICODE));

        $data = [
            'products' => [
                '$partial' => 'admin/products/list.json',
            ],
        ];

        $this->traitUser->callResetFragmentStack();
        $result = $this->traitUser->callResolveLanguageFragments($data, $this->tempDir);

        $expected = [
            'products' => [
                'title' => '상품 목록',
                'add' => '상품 추가',
            ],
        ];

        $this->assertEquals($expected, $result);
    }
}
