<?php

namespace Tests\Feature\Repositories;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Repositories\TemplateRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private TemplateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new TemplateRepository;
    }

    /**
     * findByIdentifier 메서드가 identifier로 템플릿을 조회하는지 테스트
     */
    public function test_find_by_identifier_returns_template(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-'.uniqid();
        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // Act
        $result = $this->repository->findByIdentifier($identifier);

        // Assert
        $this->assertNotNull($result);
        $this->assertInstanceOf(Template::class, $result);
        $this->assertEquals($identifier, $result->identifier);
        $this->assertEquals($template->id, $result->id);
    }

    /**
     * findByIdentifier 메서드가 존재하지 않는 identifier일 때 null을 반환하는지 테스트
     */
    public function test_find_by_identifier_returns_null_when_not_found(): void
    {
        // Act
        $result = $this->repository->findByIdentifier('non-existent-template');

        // Assert
        $this->assertNull($result);
    }

    /**
     * findByIdentifier 메서드가 여러 템플릿 중 올바른 템플릿을 조회하는지 테스트
     */
    public function test_find_by_identifier_returns_correct_template_among_many(): void
    {
        // Arrange
        Template::create([
            'identifier' => 'template-1',
            'vendor' => 'vendor-1',
            'name' => 'Template 1',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        $targetTemplate = Template::create([
            'identifier' => 'template-2',
            'vendor' => 'vendor-2',
            'name' => 'Template 2',
            'type' => 'user',
            'version' => '2.0.0',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        Template::create([
            'identifier' => 'template-3',
            'vendor' => 'vendor-3',
            'name' => 'Template 3',
            'type' => 'admin',
            'version' => '3.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // Act
        $result = $this->repository->findByIdentifier('template-2');

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($targetTemplate->id, $result->id);
        $this->assertEquals('template-2', $result->identifier);
        $this->assertEquals('vendor-2', $result->vendor);
        $this->assertEquals('user', $result->type);
    }
}
