<?php

namespace Modules\Sirsoft\Inquiry;

use App\Extension\AbstractModule;

class Module extends AbstractModule
{
    // Minimal scaffold for inquiry module.
    // Permissions, event listeners, and seeders will be added in subsequent tasks.

    public function getRoutes(): array
    {
        return [
            'api' => $this->getModulePath() . '/src/routes/api.php',
        ];
    }
}
