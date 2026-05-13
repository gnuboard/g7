<?php

namespace Database\Seeders\Sample;

use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * 기본 템플릿 정보
     */
    private array $defaultTemplate = [
        'identifier' => 'sirsoft-admin_basic',
        'vendor' => 'sirsoft',
        'name' => [
            'ko' => 'Admin Basic',
            'en' => 'Admin Basic',
        ],
        'version' => '0.1.1',
        'type' => 'admin',
        'description' => [
            'ko' => '그누보드7 기본 관리자 템플릿',
            'en' => 'Gnuboard7 default admin template',
        ],
        'status' => 'inactive',
    ];

    /**
     * 기본 레이아웃 정보
     */
    private array $defaultLayout = [
        'name' => '_admin_base',
        'content' => [
            'version' => '0.1.1',
            'layout_name' => '_admin_base',
            'meta' => [
                'title' => '$t:common.admin',
                'description' => 'Admin base layout',
            ],
            'components' => [
                [
                    'id' => 'root',
                    'type' => 'layout',
                    'name' => 'Container',
                    'props' => [
                        'layout' => 'flex',
                        'direction' => 'column',
                    ],
                    'children' => [
                        [
                            'id' => 'header',
                            'type' => 'composite',
                            'name' => 'AdminHeader',
                            'props' => [],
                        ],
                        [
                            'id' => 'content',
                            'type' => 'layout',
                            'name' => 'Slot',
                            'props' => [
                                'name' => 'content',
                            ],
                        ],
                        [
                            'id' => 'footer',
                            'type' => 'composite',
                            'name' => 'AdminFooter',
                            'props' => [],
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('템플릿 시더 생성을 시작합니다.');

        // 기존 데이터 삭제
        $this->deleteExistingTemplates();

        // 기본 템플릿 생성
        $template = $this->createDefaultTemplate();

        // 기본 레이아웃 생성
        $this->createTemplateLayouts($template);

        $this->command->info('템플릿 시더가 성공적으로 생성되었습니다.');
    }

    /**
     * 기존 템플릿 삭제
     */
    private function deleteExistingTemplates(): void
    {
        $deletedCount = Template::where('identifier', $this->defaultTemplate['identifier'])->forceDelete();

        if ($deletedCount > 0) {
            $this->command->warn("기존 템플릿 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 기본 템플릿 생성
     */
    private function createDefaultTemplate(): Template
    {
        // JSON 구조 검증
        if (! is_array($this->defaultTemplate['name']) || ! is_array($this->defaultTemplate['description'])) {
            throw new \Exception('Template name and description must be arrays');
        }

        $template = Template::create($this->defaultTemplate);

        $this->command->info("템플릿이 생성되었습니다: {$template->identifier}");

        return $template;
    }

    /**
     * 기본 레이아웃 생성
     */
    private function createTemplateLayouts(Template $template): void
    {
        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => $this->defaultLayout['name'],
            'content' => $this->defaultLayout['content'],
        ]);

        $this->command->info("레이아웃이 생성되었습니다: {$layout->name}");
    }
}
