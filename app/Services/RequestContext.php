<?php

declare(strict_types=1);

namespace App\Services;

final class RequestContext
{
    private ?array $company = null;
    private ?string $apiKey = null;

    public function setCompany(array $company): void
    {
        $this->company = $company;
    }
    public function getCompany(): ?array
    {
        return $this->company;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }
    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }
}
