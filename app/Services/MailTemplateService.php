<?php

namespace App\Services;

use App\Contracts\Repositories\MailTemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Models\MailTemplate;
use App\Services\Concerns\HandlesMailTemplates;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * 메일 템플릿 서비스
 *
 * 메일 템플릿의 조회, 수정, 미리보기, 초기화 등 비즈니스 로직을 처리합니다.
 */
class MailTemplateService
{
    use HandlesMailTemplates;

    /**
     * @var string 캐시 키 prefix
     */
    protected string $cachePrefix = 'mail_template:core:';

    /**
     * @var string 모델 클래스
     */
    protected string $modelClass = MailTemplate::class;

    /**
     * MailTemplateService 생성자.
     *
     * @param  MailTemplateRepositoryInterface  $repository  메일 템플릿 리포지토리
     */
    public function __construct(
        private MailTemplateRepositoryInterface $repository
    ) {}

    /**
     * 환경설정 탭용 전체 템플릿 목록을 반환합니다.
     *
     * @return Collection 메일 템플릿 컬렉션
     */
    public function getTemplatesForSettings(): Collection
    {
        return $this->repository->getAllTemplates();
    }

    public function updateTemplate(MailTemplate $template, array $data, ?int $userId = null): MailTemplate
    {
        // Before 훅
        HookManager::doAction('core.mail_template.before_update', $template, $data);

        // 스냅샷 캡처 (ChangeDetector용)
        $snapshot = $template->toArray();

        // 필터 훅 - 수정 데이터 변형
        $data = HookManager::applyFilters('core.mail_template.filter_update_data', $data, $template);

        $updateData = [
            'subject' => $data['subject'],
            'body' => $data['body'],
            'is_active' => $data['is_active'] ?? $template->is_active,
            'is_default' => false,
            'updated_by' => $userId,
        ];

        $this->repository->update($template, $updateData);
        $this->invalidateCache($template->type);

        $updatedTemplate = $template->fresh();

        // After 훅 (스냅샷 전달)
        HookManager::doAction('core.mail_template.after_update', $updatedTemplate, $snapshot);

        return $updatedTemplate;
    }

    public function toggleActive(MailTemplate $template): MailTemplate
    {
        // Before 훅
        HookManager::doAction('core.mail_template.before_toggle_active', $template);

        $this->repository->update($template, [
            'is_active' => ! $template->is_active,
        ]);

        $this->invalidateCache($template->type);

        $toggledTemplate = $template->fresh();

        // After 훅
        HookManager::doAction('core.mail_template.after_toggle_active', $toggledTemplate);

        return $toggledTemplate;
    }

    /**
     * 시더에 정의된 기본 템플릿 데이터를 반환합니다.
     *
     * @param  string  $type  템플릿 유형
     * @return array|null 기본 데이터 또는 null
     */
    public function getDefaultTemplateData(string $type): ?array
    {
        $seeder = app(\Database\Seeders\MailTemplateSeeder::class);

        $defaults = $seeder->getDefaultTemplates();

        foreach ($defaults as $default) {
            if ($default['type'] === $type) {
                return $default;
            }
        }

        return null;
    }

    /**
     * 메일 템플릿 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지 당 항목 수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function getTemplates(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getPaginated($filters, $perPage);
    }

    /**
     * 미리보기용 렌더링 결과를 반환합니다.
     *
     * @param  array  $data  미리보기 데이터 (subject, body, variables)
     * @return array{subject: string, body: string} 렌더링 결과
     */
    public function getPreview(array $data): array
    {
        $subject = $data['subject'] ?? '';
        $body = $data['body'] ?? '';
        $sampleVariables = [];

        foreach ($data['variables'] ?? [] as $variable) {
            $key = $variable['key'] ?? '';
            $sampleVariables[$key] = '{'.$key.'}';
        }

        $replacements = [];
        foreach ($sampleVariables as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
        }

        return [
            'subject' => strtr($subject, $replacements),
            'body' => strtr($body, $replacements),
        ];
    }
}
