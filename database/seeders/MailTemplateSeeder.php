<?php

namespace Database\Seeders;

use App\Models\MailTemplate;
use Illuminate\Database\Seeder;

class MailTemplateSeeder extends Seeder
{
    /**
     * 코어 메일 템플릿을 시딩합니다.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('코어 메일 템플릿 시딩 시작...');

        $templates = $this->getDefaultTemplates();

        foreach ($templates as $data) {
            MailTemplate::updateOrCreate(
                ['type' => $data['type']],
                [
                    'subject' => $data['subject'],
                    'body' => $data['body'],
                    'variables' => $data['variables'],
                    'is_active' => true,
                    'is_default' => true,
                ]
            );

            $this->command->info("  - {$data['type']} 템플릿 등록 완료");
        }

        $this->command->info('코어 메일 템플릿 시딩 완료 ('.count($templates).'종)');
    }

    /**
     * 기본 템플릿 데이터를 반환합니다.
     * 정의는 config/core.php의 mail_templates에서 읽습니다.
     *
     * 리셋 기능에서도 사용하기 위해 public으로 제공합니다.
     *
     * @return array<int, array{type: string, subject: array, body: array, variables: array}> 기본 템플릿 배열
     */
    public function getDefaultTemplates(): array
    {
        return config('core.mail_templates', []);
    }
}
