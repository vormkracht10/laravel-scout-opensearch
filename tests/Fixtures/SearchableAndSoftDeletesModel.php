<?php

declare(strict_types=1);

namespace Vormkracht10\LaravelScout\OpenSearch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class SearchableAndSoftDeletesModel extends Model
{
    use Searchable;
    use SoftDeletes;

    protected $fillable = ['id'];

    public function searchableAs(): string
    {
        return 'table';
    }
}
