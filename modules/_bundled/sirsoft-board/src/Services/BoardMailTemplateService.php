<?php

namespace Modules\Sirsoft\Board\Services;

use App\Services\Concerns\HandlesMailTemplates;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Database\Seeders\BoardMailTemplateSeeder;
use Modules\Sirsoft\Board\Models\BoardMailTemplate;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardMailTemplateRepositoryInterface;

/**
 * 게시판 메일 템플릿 서비스
 */
class BoardMailTemplateService
{
    use HandlesMailTemplates;

    /**
     * @var string 캐시 키 prefix
     */
    protected string $cachePrefix = 'mail_template:sirsoft-board:';

    /**
     * @var string 모델 클래스
     */
    protected string $modelClass = BoardMailTemplate::class;

    /**
     * BoardMailTemplateService 생성자.
     *
     * @param BoardMailTemplateRepositoryInterface $repository 메일 템플릿 리포지토리
     */
    public function __construct(
        private BoardMailTemplateRepositoryInterface $repository
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

    /**
     * 메일 템플릿을 수정합니다.
     *
     * @param BoardMailTemplate $template 수정 대상
     * @param array $data 수정 데이터
     * @param int|null $userId 수정자 ID
     * @return BoardMailTemplate 수정된 템플릿
     */
    public function updateTemplate(BoardMailTemplate $template, array $data, ?int $userId = null): BoardMailTemplate
    {
        $updateData = [
            'subject' => $data['subject'],
            'body' => $data['body'],
            'is_active' => $data['is_active'] ?? $template->is_active,
            'is_default' => false,
            'updated_by' => $userId,
        ];

        $this->repository->update($template, $updateData);
        $this->invalidateCache($template->type);

        return $template->fresh();
    }

    /**
     * 메일 템플릿의 활성 상태를 토글합니다.
     *
     * @param BoardMailTemplate $template 토글 대상
     * @return BoardMailTemplate 토글된 템플릿
     */
    public function toggleActive(BoardMailTemplate $template): BoardMailTemplate
    {
        $this->repository->update($template, [
            'is_active' => ! $template->is_active,
        ]);

        $this->invalidateCache($template->type);

        return $template->fresh();
    }

    /**
     * 메일 템플릿 목록을 페이지네이션하여 조회합니다.
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지 당 항목 수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function getTemplates(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getPaginated($filters, $perPage);
    }

    /**
     * 미리보기용 렌더링 결과를 반환합니다.
     *
     * @param array $data 미리보기 데이터 (subject, body, variables)
     * @return array{subject: string, body: string} 렌더링 결과
     */
    public function getPreview(array $data): array
    {
        $subject = $data['subject'] ?? '';
        $body = $data['body'] ?? '';
        $sampleVariables = [];

        foreach ($data['variables'] ?? [] as $variable) {
            $key = $variable['key'] ?? '';
            $sampleVariables[$key] = '{' . $key . '}';
        }

        $replacements = [];
        foreach ($sampleVariables as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }

        return [
            'subject' => strtr($subject, $replacements),
            'body' => strtr($body, $replacements),
        ];
    }

    /**
     * 시더에 정의된 기본 템플릿 데이터를 반환합니다.
     *
     * @param string $type 템플릿 유형
     * @return array|null 기본 데이터 또는 null
     */
    public function getDefaultTemplateData(string $type): ?array
    {
        $seeder = app(BoardMailTemplateSeeder::class);

        $defaults = $seeder->getDefaultTemplates();

        foreach ($defaults as $default) {
            if ($default['type'] === $type) {
                return $default;
            }
        }

        return null;
    }
}
