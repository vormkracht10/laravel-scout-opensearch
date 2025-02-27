<?php

declare(strict_types=1);

namespace Vormkracht10\LaravelScout\OpenSearch\Tests\Fixtures;

class CustomKeySearchableModel extends SearchableModel
{
    public function getScoutKey(): string
    {
        return 'my-opensearch-key.' . $this->getKey();
    }
}
