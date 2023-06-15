<?php

declare(strict_types=1);

namespace Zing\LaravelScout\OpenSearch\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Mockery as m;
use OpenSearch\Client;
use Zing\LaravelScout\OpenSearch\Engines\OpenSearchEngine;
use Zing\LaravelScout\OpenSearch\Tests\Fixtures\CustomKeySearchableModel;
use Zing\LaravelScout\OpenSearch\Tests\Fixtures\EmptySearchableModel;
use Zing\LaravelScout\OpenSearch\Tests\Fixtures\SearchableModel;
use Zing\LaravelScout\OpenSearch\Tests\Fixtures\SoftDeletedEmptySearchableModel;

/**
 * @internal
 */
final class OpenSearchEngineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);
    }

    public function testUpdateAddsObjectsToIndex(): void
    {
        $client = m::mock(Client::class);
        $model = new SearchableModel([
            'id' => 1,
        ]);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'index' => [
                            '_index' => 'table',
                            '_id' => 1,
                        ],
                    ],
                    array_merge([
                        'id' => 1,
                    ], [
                        $model->getScoutKeyName() => $model->getScoutKey(),
                    ]),
                ],
            ]);

        $engine = new OpenSearchEngine($client);
        $engine->update(Collection::make([$model]));
    }

    public function testDeleteRemovesObjectsToIndex(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'delete' => [
                            '_index' => 'table',
                            '_id' => 1,
                        ],
                    ],
                ],
            ]);

        $engine = new OpenSearchEngine($client);
        $engine->delete(Collection::make([
            new SearchableModel([
                'id' => 1,
            ]),
        ]));
    }

    public function testDeleteRemovesObjectsToIndexWithACustomSearchKey(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'delete' => [
                            '_index' => 'table',
                            '_id' => 'my-opensearch-key.5',
                        ],
                    ],
                ],
            ]);

        $engine = new OpenSearchEngine($client);
        $engine->delete(Collection::make([
            new CustomKeySearchableModel([
                'id' => 5,
            ]),
        ]));
    }

    public function testDeleteWithRemoveableScoutCollectionUsingCustomSearchKey(): void
    {
        if (! class_exists(RemoveFromSearch::class)) {
            self::markTestSkipped('Support for RemoveFromSearch available since 9.0.');
        }
        $job = new RemoveFromSearch(Collection::make([
            new CustomKeySearchableModel([
                'id' => 5,
            ]),
        ]));

        $job = unserialize(serialize($job));

        $client = m::mock(Client::class);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'delete' => [
                            '_index' => 'table',
                            '_id' => 'my-opensearch-key.5',
                        ],
                    ],
                ],
            ]);
        $engine = new OpenSearchEngine($client);
        $engine->delete($job->models);
    }

    public function testRemoveFromSearchJobUsesCustomSearchKey(): void
    {
        if (! class_exists(RemoveFromSearch::class)) {
            self::markTestSkipped('Support for RemoveFromSearch available since 9.0.');
        }
        $job = new RemoveFromSearch(Collection::make([
            new CustomKeySearchableModel([
                'id' => 5,
            ]),
        ]));

        $job = unserialize(serialize($job));

        Container::getInstance()->bind(EngineManager::class, function () {
            $engine = m::mock(OpenSearchEngine::class);

            $engine->shouldReceive('delete')
                ->once()
                ->with(m::on(function ($collection) {
                    $keyName = ($model = $collection->first())
                        ->getScoutKeyName();

                    return $model->getAttributes()[$keyName] === 'my-opensearch-key.5';
                }));

            $manager = m::mock(EngineManager::class);

            $manager->shouldReceive('engine')
                ->once()
                ->andReturn($engine);

            return $manager;
        });

        $job->handle();
    }

    public function testSearchSendsCorrectParametersToAlgolia(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('search')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    'query' => [
                        'bool' => [
                            'filter' => [
                                [
                                    'term' => [
                                        'foo' => 1,
                                    ],
                                ],
                            ],
                            'must' => [
                                [
                                    'query_string' => [
                                        'query' => 'zonda',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'sort' => [
                        [
                            'id' => [
                                'order' => 'desc',
                            ],
                        ],
                    ],
                ],
            ]);

        $engine = new OpenSearchEngine($client);
        $builder = new Builder(new SearchableModel(), 'zonda');
        $builder->where('foo', 1)
            ->orderBy('id', 'desc');
        $engine->search($builder);
    }

    public function testSearchSendsCorrectParametersToAlgoliaForWhereInSearch(): void
    {
        if (! method_exists(Builder::class, 'whereIn')) {
            self::markTestSkipped('Support for whereIn available since 9.0.');
        }
        $client = m::mock(Client::class);
        $client->shouldReceive('search')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    'query' => [
                        'bool' => [
                            'filter' => [
                                [
                                    'term' => [
                                        'foo' => 1,
                                    ],
                                ],
                                [
                                    'terms' => [
                                        'bar' => [1, 2],
                                    ],
                                ],
                            ],
                            'must' => [
                                [
                                    'query_string' => [
                                        'query' => 'zonda',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'sort' => [
                        [
                            'id' => [
                                'order' => 'desc',
                            ],
                        ],
                    ],
                ],
            ]);

        $engine = new OpenSearchEngine($client);
        $builder = new Builder(new SearchableModel(), 'zonda');
        $builder->where('foo', 1)
            ->whereIn('bar', [1, 2])
            ->orderBy('id', 'desc');
        $engine->search($builder);
    }

    public function testSearchSendsCorrectParametersToAlgoliaForEmptyWhereInSearch(): void
    {
        if (! method_exists(Builder::class, 'whereIn')) {
            self::markTestSkipped('Support for whereIn available since 9.0.');
        }
        $client = m::mock(Client::class);
        $client->shouldReceive('search')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    'query' => [
                        'bool' => [
                            'filter' => [
                                [
                                    'term' => [
                                        'foo' => 1,
                                    ],
                                ],
                                [
                                    'terms' => [
                                        'bar' => [],
                                    ],
                                ],
                            ],
                            'must' => [
                                [
                                    'query_string' => [
                                        'query' => 'zonda',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'sort' => [
                        [
                            'id' => [
                                'order' => 'desc',
                            ],
                        ],
                    ],
                ],
            ]);

        $engine = new OpenSearchEngine($client);
        $builder = new Builder(new SearchableModel(), 'zonda');
        $builder->where('foo', 1)
            ->whereIn('bar', [])
            ->orderBy('id', 'desc');
        $engine->search($builder);
    }

    public function testMapCorrectlyMapsResultsToModels(): void
    {
        $client = m::mock(Client::class);
        $engine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('getScoutModelsByIds')
            ->andReturn($models = Collection::make([
                new SearchableModel([
                    'id' => 1,
                ]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'nbHits' => 1,
            'hits' => [
                [
                    '_id' => 1,
                    'id' => 1,
                ],
            ],
        ], $model);

        self::assertCount(1, $results);
    }

    public function testMapMethodRespectsOrder(): void
    {
        $client = m::mock(Client::class);
        $engine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('getScoutModelsByIds')
            ->andReturn($models = Collection::make([
                new SearchableModel([
                    'id' => 1,
                ]),
                new SearchableModel([
                    'id' => 2,
                ]),
                new SearchableModel([
                    'id' => 3,
                ]),
                new SearchableModel([
                    'id' => 4,
                ]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'nbHits' => 4,
            'hits' => [
                [
                    '_id' => 1,
                    'id' => 1,
                ],
                [
                    '_id' => 2,
                    'id' => 2,
                ],
                [
                    '_id' => 4,
                    'id' => 4,
                ],
                [
                    '_id' => 3,
                    'id' => 3,
                ],
            ],
        ], $model);

        self::assertCount(4, $results);

        // It's important we assert with array keys to ensure
        // they have been reset after sorting.
        self::assertSame([
            0 => [
                'id' => 1,
            ],
            1 => [
                'id' => 2,
            ],
            2 => [
                'id' => 4,
            ],
            3 => [
                'id' => 3,
            ],
        ], $results->toArray());
    }

    public function testLazyMapCorrectlyMapsResultsToModels(): void
    {
        if (! method_exists(Builder::class, 'cursor')) {
            self::markTestSkipped('Support for cursor available since 9.0.');
        }
        $client = m::mock(Client::class);
        $engine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('queryScoutModelsByIds->cursor')
            ->andReturn($models = LazyCollection::make([
                new SearchableModel([
                    'id' => 1,
                ]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, [
            'nbHits' => 1,
            'hits' => [
                [
                    '_id' => 1,
                    'id' => 1,
                ],
            ],
        ], $model);

        self::assertCount(1, $results);
    }

    public function testLazyMapMethodRespectsOrder(): void
    {
        if (! method_exists(Builder::class, 'cursor')) {
            self::markTestSkipped('Support for cursor available since 9.0.');
        }
        $client = m::mock(Client::class);
        $engine = new OpenSearchEngine($client);

        $model = m::mock(SearchableModel::class);
        $model->shouldReceive('queryScoutModelsByIds->cursor')
            ->andReturn($models = LazyCollection::make([
                new SearchableModel([
                    'id' => 1,
                ]),
                new SearchableModel([
                    'id' => 2,
                ]),
                new SearchableModel([
                    'id' => 3,
                ]),
                new SearchableModel([
                    'id' => 4,
                ]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, [
            'hits' => [
                [
                    '_id' => 1,
                    'id' => 1,
                ],
                [
                    '_id' => 2,
                    'id' => 2,
                ],
                [
                    '_id' => 4,
                    'id' => 4,
                ],
                [
                    '_id' => 3,
                    'id' => 3,
                ],
            ],
        ], $model);

        self::assertCount(4, $results);

        // It's important we assert with array keys to ensure
        // they have been reset after sorting.
        self::assertSame([
            0 => [
                'id' => 1,
            ],
            1 => [
                'id' => 2,
            ],
            2 => [
                'id' => 4,
            ],
            3 => [
                'id' => 3,
            ],
        ], $results->toArray());
    }

    public function testAModelIsIndexedWithACustomAlgoliaKey(): void
    {
        $client = m::mock(Client::class);
        $model = new CustomKeySearchableModel([
            'id' => 1,
        ]);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'index' => [
                            '_index' => 'table',
                            '_id' => 'my-opensearch-key.1',
                        ],
                    ],
                    array_merge([
                        'id' => 1,
                    ], [
                        $model->getScoutKeyName() => $model->getScoutKey(),
                    ]),
                ],
            ]);

        $engine = new OpenSearchEngine($client);
        $engine->update(Collection::make([$model]));
    }

    public function testAModelIsRemovedWithACustomAlgoliaKey(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('bulk')
            ->once()
            ->with([
                'index' => 'table',
                'body' => [
                    [
                        'delete' => [
                            '_index' => 'table',
                            '_id' => 'my-opensearch-key.1',
                        ],
                    ],
                ],
            ]);

        $engine = new OpenSearchEngine($client);
        $engine->delete(Collection::make([
            new CustomKeySearchableModel([
                'id' => 1,
            ]),
        ]));
    }

    public function testFlushAModelWithACustomAlgoliaKey(): void
    {
        $model = m::mock(CustomKeySearchableModel::class);
        $model->shouldReceive('searchableAs')
            ->once()
            ->withNoArgs()
            ->andReturn('table');
        $client = m::mock(Client::class);
        $client->shouldReceive('deleteByQuery')
            ->with([
                'index' => 'table',
                'body' => [
                    'query' => [
                        'match_all' => new \stdClass(),
                    ],
                ],
            ])
            ->andReturn('table');
        $engine = new OpenSearchEngine($client);
        $engine->flush($model);
    }

    public function testUpdateEmptySearchableArrayDoesNotAddObjectsToIndex(): void
    {
        $client = m::mock(Client::class);

        $client->shouldNotReceive('bulk');
        $engine = new OpenSearchEngine($client);
        $engine->update(Collection::make([new EmptySearchableModel()]));
    }

    public function testUpdateEmptySearchableArrayFromSoftDeletedModelDoesNotAddObjectsToIndex(): void
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('bulk');
        $engine = new OpenSearchEngine($client, true);
        $engine->update(Collection::make([new SoftDeletedEmptySearchableModel()]));
    }
}
