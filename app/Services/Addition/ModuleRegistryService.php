<?php

namespace App\Services\Addition;

class ModuleRegistryService
{
    /**
     * Registry for additional frontend app capabilities.
     */
    public function listEnabledModules(): array
    {
        return [
            'addition_api' => true,
        ];
    }
}
