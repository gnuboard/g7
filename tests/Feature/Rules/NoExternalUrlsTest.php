<?php

namespace Tests\Feature\Rules;

use App\Rules\NoExternalUrls;
use Tests\TestCase;

class NoExternalUrlsTest extends TestCase
{
    private NoExternalUrls $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new NoExternalUrls;
    }

    /**
     * 정상적인 레이아웃 JSON 통과 테스트
     */
    public function test_passes_with_valid_layout_json(): void
    {
        $validLayout = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Button',
                    'props' => [
                        'label' => '저장',
                        'icon' => '/images/save.png',
                    ],
                    'actions' => [
                        [
                            'type' => 'api',
                            'endpoint' => '/api/admin/save',
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $validLayout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Valid layout should pass validation');
    }

    /**
     * HTTP URL 차단 테스트
     */
    public function test_fails_with_http_url_in_props(): void
    {
        $layoutWithHttp = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Image',
                    'props' => [
                        'src' => 'http://evil.com/image.jpg',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithHttp, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'HTTP URL should fail validation');
        $this->assertStringContainsString('HTTP', $errorMessage);
    }

    /**
     * HTTPS URL 차단 테스트
     */
    public function test_fails_with_https_url_in_props(): void
    {
        $layoutWithHttps = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Link',
                    'props' => [
                        'href' => 'https://attacker.com/malicious',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithHttps, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'HTTPS URL should fail validation');
        $this->assertStringContainsString('HTTPS', $errorMessage);
    }

    /**
     * actions에서 외부 URL 차단 테스트
     */
    public function test_fails_with_external_url_in_actions(): void
    {
        $layoutWithExternalAction = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Button',
                    'props' => [
                        'label' => 'Click',
                    ],
                    'actions' => [
                        [
                            'type' => 'navigate',
                            'target' => 'https://evil.com/redirect',
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithExternalAction, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'External URL in actions should fail validation');
    }

    /**
     * Data URI 차단 테스트
     */
    public function test_fails_with_data_uri(): void
    {
        $layoutWithDataUri = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Image',
                    'props' => [
                        'src' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithDataUri, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'Data URI should fail validation');
        $this->assertStringContainsString('Data URI', $errorMessage);
    }

    /**
     * JavaScript URI 차단 테스트
     */
    public function test_fails_with_javascript_uri(): void
    {
        $layoutWithJsUri = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Link',
                    'props' => [
                        'href' => 'javascript:alert(1)',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithJsUri, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'JavaScript URI should fail validation');
        $this->assertStringContainsString('JavaScript URI', $errorMessage);
    }

    /**
     * 중첩된 children에서 외부 URL 차단 테스트
     */
    public function test_fails_with_external_url_in_nested_children(): void
    {
        $layoutWithNestedUrl = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Container',
                    'props' => [],
                    'children' => [
                        [
                            'component' => 'Section',
                            'props' => [],
                            'children' => [
                                [
                                    'component' => 'Image',
                                    'props' => [
                                        'src' => 'https://evil.com/nested.jpg',
                                    ],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithNestedUrl, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'External URL in nested children should fail validation');
    }

    /**
     * 프로토콜 상대 URL 차단 테스트
     */
    public function test_fails_with_protocol_relative_url(): void
    {
        $layoutWithProtocolRelative = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Image',
                    'props' => [
                        'src' => '//evil.com/image.jpg',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithProtocolRelative, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Protocol-relative URL should fail validation');
    }

    /**
     * FTP 프로토콜 차단 테스트
     */
    public function test_fails_with_ftp_url(): void
    {
        $layoutWithFtp = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Link',
                    'props' => [
                        'href' => 'ftp://files.example.com/download',
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $errorMessage = '';
        $this->rule->validate('layout', $layoutWithFtp, function ($message) use (&$failed, &$errorMessage) {
            $failed = true;
            $errorMessage = $message;
        });

        $this->assertTrue($failed, 'FTP URL should fail validation');
        $this->assertStringContainsString('ftp', strtolower($errorMessage));
    }

    /**
     * 상대 경로 허용 테스트
     */
    public function test_passes_with_relative_paths(): void
    {
        $layoutWithRelativePaths = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Image',
                    'props' => [
                        'src' => '/images/logo.png',
                        'fallback' => '../assets/default.jpg',
                    ],
                    'children' => [],
                ],
                [
                    'component' => 'Link',
                    'props' => [
                        'href' => '/about',
                    ],
                    'actions' => [
                        [
                            'type' => 'api',
                            'endpoint' => '/api/admin/data',
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithRelativePaths, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Relative paths should pass validation');
    }

    /**
     * 복잡한 중첩 구조에서 다중 외부 URL 차단 테스트
     */
    public function test_fails_with_multiple_external_urls_in_complex_structure(): void
    {
        $complexLayout = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'component' => 'Container',
                    'props' => [
                        'background' => 'https://cdn.evil.com/bg.jpg',
                    ],
                    'children' => [
                        [
                            'component' => 'Header',
                            'props' => [],
                            'actions' => [
                                [
                                    'type' => 'fetch',
                                    'endpoint' => 'http://api.attacker.com/steal',
                                ],
                            ],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $complexLayout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Multiple external URLs should fail validation');
    }
}
