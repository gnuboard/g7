<?php

namespace Tests\Feature\Extension;

use App\Extension\Traits\ValidatesPermissionStructure;
use Tests\TestCase;

class ValidatesPermissionStructureTest extends TestCase
{
    private object $traitUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Trait을 사용하는 익명 클래스 생성
        $this->traitUser = new class
        {
            use ValidatesPermissionStructure;

            /**
             * 테스트를 위해 public으로 노출
             */
            public function testValidatePermissionStructure(object $extension, string $type = 'module'): void
            {
                $this->validatePermissionStructure($extension, $type);
            }
        };
    }

    /**
     * 유효한 권한 구조를 가진 모듈 객체 생성
     */
    private function createValidModuleMock(): object
    {
        return new class
        {
            public function getIdentifier(): string
            {
                return 'test-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => [
                        'ko' => '테스트',
                        'en' => 'Test',
                    ],
                    'description' => [
                        'ko' => '테스트 모듈 권한',
                        'en' => 'Test module permissions',
                    ],
                    'categories' => [
                        [
                            'identifier' => 'items',
                            'name' => [
                                'ko' => '항목 관리',
                                'en' => 'Item Management',
                            ],
                            'description' => [
                                'ko' => '항목 관리 권한',
                                'en' => 'Item management permissions',
                            ],
                            'permissions' => [
                                [
                                    'action' => 'view',
                                    'name' => [
                                        'ko' => '항목 조회',
                                        'en' => 'View Items',
                                    ],
                                    'description' => [
                                        'ko' => '항목을 조회할 수 있는 권한',
                                        'en' => 'Permission to view items',
                                    ],
                                    'roles' => ['admin'],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };
    }

    /**
     * 올바른 계층형 권한 구조 검증 통과 테스트
     */
    public function test_valid_hierarchical_permission_structure_passes(): void
    {
        $module = $this->createValidModuleMock();

        // 예외가 발생하지 않아야 함
        $this->traitUser->testValidatePermissionStructure($module);
        $this->assertTrue(true);
    }

    /**
     * 빈 권한 구조 검증 통과 테스트
     */
    public function test_empty_permission_structure_passes(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'empty-module';
            }

            public function getPermissions(): array
            {
                return [];
            }
        };

        // 빈 배열은 검증 통과
        $this->traitUser->testValidatePermissionStructure($module);
        $this->assertTrue(true);
    }

    /**
     * categories 필드 누락 시 예외 발생 테스트
     */
    public function test_missing_categories_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    // categories 누락
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/categories/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * categories가 배열이 아닐 때 예외 발생 테스트
     */
    public function test_categories_not_array_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => 'not-an-array',
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/categories/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * name 필드 누락 시 예외 발생 테스트
     */
    public function test_missing_name_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    // name 누락
                    'categories' => [],
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/name/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * name이 문자열일 때 예외 발생 테스트 (다국어 배열 필수)
     */
    public function test_name_as_string_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => 'Not multilingual',
                    'categories' => [],
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/name/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * 카테고리에 identifier 누락 시 예외 발생 테스트
     */
    public function test_category_missing_identifier_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => [
                        [
                            // identifier 누락
                            'name' => ['ko' => '항목', 'en' => 'Items'],
                            'permissions' => [],
                        ],
                    ],
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/identifier/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * 카테고리에 name 누락 시 예외 발생 테스트
     */
    public function test_category_missing_name_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => [
                        [
                            'identifier' => 'items',
                            // name 누락
                            'permissions' => [],
                        ],
                    ],
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/name/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * 카테고리에 permissions 누락 시 예외 발생 테스트
     */
    public function test_category_missing_permissions_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => [
                        [
                            'identifier' => 'items',
                            'name' => ['ko' => '항목', 'en' => 'Items'],
                            // permissions 누락
                        ],
                    ],
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/permissions/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * 개별 권한에 action 누락 시 예외 발생 테스트
     */
    public function test_permission_missing_action_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => [
                        [
                            'identifier' => 'items',
                            'name' => ['ko' => '항목', 'en' => 'Items'],
                            'permissions' => [
                                [
                                    // action 누락
                                    'name' => ['ko' => '조회', 'en' => 'View'],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/action/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * 개별 권한에 name 누락 시 예외 발생 테스트
     */
    public function test_permission_missing_name_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => [
                        [
                            'identifier' => 'items',
                            'name' => ['ko' => '항목', 'en' => 'Items'],
                            'permissions' => [
                                [
                                    'action' => 'view',
                                    // name 누락
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/name/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * 개별 권한의 name이 문자열일 때 예외 발생 테스트
     */
    public function test_permission_name_as_string_throws_exception(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => [
                        [
                            'identifier' => 'items',
                            'name' => ['ko' => '항목', 'en' => 'Items'],
                            'permissions' => [
                                [
                                    'action' => 'view',
                                    'name' => 'Not multilingual', // 문자열
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/name/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * 여러 카테고리 검증 테스트
     */
    public function test_multiple_categories_validation(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'multi-category-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => [
                        [
                            'identifier' => 'items',
                            'name' => ['ko' => '항목', 'en' => 'Items'],
                            'permissions' => [
                                [
                                    'action' => 'view',
                                    'name' => ['ko' => '조회', 'en' => 'View'],
                                ],
                            ],
                        ],
                        [
                            'identifier' => 'reports',
                            'name' => ['ko' => '보고서', 'en' => 'Reports'],
                            'permissions' => [
                                [
                                    'action' => 'read',
                                    'name' => ['ko' => '읽기', 'en' => 'Read'],
                                ],
                                [
                                    'action' => 'export',
                                    'name' => ['ko' => '내보내기', 'en' => 'Export'],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        // 예외가 발생하지 않아야 함
        $this->traitUser->testValidatePermissionStructure($module);
        $this->assertTrue(true);
    }

    /**
     * 두 번째 카테고리에서 오류 발생 시 정확한 인덱스 표시 테스트
     */
    public function test_error_message_shows_correct_category_index(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => [
                        [
                            'identifier' => 'valid',
                            'name' => ['ko' => '유효', 'en' => 'Valid'],
                            'permissions' => [],
                        ],
                        [
                            // 두 번째 카테고리에 identifier 누락
                            'name' => ['ko' => '무효', 'en' => 'Invalid'],
                            'permissions' => [],
                        ],
                    ],
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/\[1\]/');

        $this->traitUser->testValidatePermissionStructure($module);
    }

    /**
     * 플러그인 타입으로 검증 테스트
     */
    public function test_validation_with_plugin_type(): void
    {
        $plugin = new class
        {
            public function getIdentifier(): string
            {
                return 'invalid-plugin';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    // categories 누락
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/categories/');

        $this->traitUser->testValidatePermissionStructure($plugin, 'plugin');
    }

    /**
     * description 필드는 선택적임을 테스트
     */
    public function test_description_is_optional(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'no-description-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    // description 없음
                    'categories' => [
                        [
                            'identifier' => 'items',
                            'name' => ['ko' => '항목', 'en' => 'Items'],
                            // description 없음
                            'permissions' => [
                                [
                                    'action' => 'view',
                                    'name' => ['ko' => '조회', 'en' => 'View'],
                                    // description 없음
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        // description이 없어도 검증 통과
        $this->traitUser->testValidatePermissionStructure($module);
        $this->assertTrue(true);
    }

    /**
     * roles 필드는 선택적임을 테스트
     */
    public function test_roles_is_optional(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'no-roles-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'categories' => [
                        [
                            'identifier' => 'items',
                            'name' => ['ko' => '항목', 'en' => 'Items'],
                            'permissions' => [
                                [
                                    'action' => 'view',
                                    'name' => ['ko' => '조회', 'en' => 'View'],
                                    // roles 없음
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        // roles가 없어도 검증 통과
        $this->traitUser->testValidatePermissionStructure($module);
        $this->assertTrue(true);
    }

    /**
     * 에러 메시지에 모듈 식별자가 포함되는지 테스트
     */
    public function test_error_message_contains_module_identifier(): void
    {
        $module = new class
        {
            public function getIdentifier(): string
            {
                return 'my-test-module';
            }

            public function getPermissions(): array
            {
                return [
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    // categories 누락
                ];
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/my-test-module/');

        $this->traitUser->testValidatePermissionStructure($module);
    }
}
