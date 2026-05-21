<?php

namespace Modules\Sirsoft\Inquiry\Providers;

use App\Extension\BaseModuleServiceProvider;

class InquiryServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleIdentifier = 'sirsoft-inquiry';

    /**
     * Repository 인터페이스 → 구현체 매핑.
     * Task 16-19 에서 채워짐.
     */
    protected array $repositories = [];

    protected array $cacheServices = [];

    protected array $storageServices = [];
}
