<?php

namespace Honeybee\FrameworkBinding\Equip\Configuration\Crate;

use Auryn\Injector;
use Equip\Configuration\ConfigurationInterface;
use Honeybee\Common\Util\StringToolkit;
use Honeybee\FrameworkBinding\Equip\Crate\CrateInterface;
use Honeybee\Infrastructure\DataAccess\Finder\Elasticsearch\ElasticsearchQueryTranslation;
use Honeybee\Infrastructure\DataAccess\Finder\Elasticsearch\Projection\ProjectionFinder;
use Honeybee\Infrastructure\DataAccess\Query\QueryService;
use Honeybee\Infrastructure\DataAccess\Storage\CouchDb\DomainEvent\DomainEventReader;
use Honeybee\Infrastructure\DataAccess\Storage\CouchDb\EventStream\EventStreamAppender;
use Honeybee\Infrastructure\DataAccess\Storage\CouchDb\EventStream\EventStreamReader;
use Honeybee\Infrastructure\DataAccess\Storage\CouchDb\StructureVersionList\StructureVersionListReader;
use Honeybee\Infrastructure\DataAccess\Storage\CouchDb\StructureVersionList\StructureVersionListWriter;
use Honeybee\Infrastructure\DataAccess\Storage\Elasticsearch\Projection\ProjectionReader;
use Honeybee\Infrastructure\DataAccess\Storage\Elasticsearch\Projection\ProjectionWriter;
use Honeybee\Infrastructure\DataAccess\Storage\Elasticsearch\StructureVersionList\StructureVersionListReader as ViewStoreStructureVersionListReader;
use Honeybee\Infrastructure\DataAccess\Storage\Elasticsearch\StructureVersionList\StructureVersionListWriter as ViewStoreStructureVersionListWriter;
use Honeybee\Infrastructure\DataAccess\UnitOfWork\UnitOfWork;
use Honeybee\Projection\ProjectionTypeInterface;

class DataAccessConfiguration implements ConfigurationInterface
{
    protected static $defaultWriters = [
        '%crate_prefix%::version_list::event_source::writer' => [
            'class' => StructureVersionListWriter::class,
            'connection' => '%crate_prefix%.event_source'
        ],
        '%crate_prefix%::version_list::view_store::writer' => [
            'class' => ViewStoreStructureVersionListWriter::class,
            'connection' => 'honeybee.view_store',
            'settings' => [
                'index' => '%vendor%-%package%',
                'type' => 'version_list'
            ]
        ]
    ];

    protected static $defaultReaders = [
        '%crate_prefix%::version_list::event_source::reader' => [
            'class' => StructureVersionListReader::class,
            'connection' => '%crate_prefix%.event_source'
        ],
        '%crate_prefix%::version_list::view_store::reader' => [
            'class' => ViewStoreStructureVersionListReader::class,
            'connection' => 'honeybee.view_store',
            'settings' => [
                'index' => '%vendor%-%package%',
                'type' => 'version_list'
            ]
        ]
    ];

    protected static $defaultResourceWriters = [
        '%res_prefix%::domain_event::event_source::writer' => [
            'class' => EventStreamAppender::class,
            'connection' => '%crate_prefix%.event_source'
        ],
        '%res_prefix%::projection.standard::view_store::writer' => [
            'class' => ProjectionWriter::class,
            'connection' => 'honeybee.view_store',
            'settings' => [
                'index' => '%vendor%-%name%',
                'type' => '%vendor%-%package%-%res_name%-standard',
                'parameters' => [
                    'index' => [ 'refresh' => true ],
                    'delete' => [ 'refresh' => true ]
                ]
            ]
        ]
    ];

    protected static $defaultResourceReaders = [
        '%res_prefix%::event_stream::event_source::reader' => [
            'class' => EventStreamReader::class,
            'connection' => '%crate_prefix%.event_source',
            'settings' => [ 'design_doc' => '%vendor%-%package%-%res_name%' ]
        ],
        '%res_prefix%::domain_event::event_source::reader' => [
            'class' => DomainEventReader::class,
            'connection' => '%crate_prefix%.event_source',
            'settings' => [ 'design_doc' => '%vendor%-%package%-%res_name%' ]
        ],
        '%res_prefix%::projection.standard::view_store::reader' => [
            'class' => ProjectionReader::class,
            'connection' => 'honeybee.view_store',
            'dependencies' => [ 'resource_type' => '%proj_fqcn%' ],
            'settings' => [
                'index' => '%vendor%-%package%',
                'type' => '%vendor%-%package%-%res_name%-standard'
            ]
        ]
    ];

    protected static $defaultResourceUows = [
        '%res_prefix%::domain_event::event_source::unit_of_work' => [
            'class' => UnitOfWork::class,
            'event_reader' => '%res_prefix%::event_stream::event_source::reader',
            'event_writer' => '%res_prefix%::event_stream::event_source::writer',
            'dependencies' => [ 'aggregate_root_type' => '%ar_fqcn%' ]
        ]
    ];

    protected static $defaultResourceFinders = [
        '%res_prefix%::projection.standard::view_store::finder' => [
            'class' => ProjectionFinder::class,
            'connection' => 'honeybee.view_store',
            'dependencies' => [ 'resource_type' => '%proj_fqcn%' ],
            'settings' => [
                'index'=> '%vendor%-%package%',
                'type' => '%vendor%-%package%-%res_name%-standard',
                'log_search_query' => false
            ]
        ]
    ];

    protected static $defaultResourceQueryServices = [
        '%res_prefix%::query_service' => [
            'class' => QueryService::class,
            'settings' => [ 'default_mapping' => '%res_name%.standard' ],
            'finder_mappings' => [
                '%res_name%.standard' => [
                    'finder' => '%res_prefix%::projection.standard::view_store::finder',
                    'query_translation' => [
                        'class' => ElasticsearchQueryTranslation::class,
                        'settings' => [
                            'multi_fields' => [],
                            'query_filters' => [ 'workflow_state' => '!deleted' ]
                        ]
                    ]
                ],
                'domain_event' => [
                    'finder' => 'honeybee::domain_event::view_store::finder',
                    'query_translation' => [ 'class' => ElasticsearchQueryTranslation::class ]
                ]
            ]
        ]
    ];

    protected $crate;

    public function __construct(CrateInterface $crate)
    {
        $this->crate = $crate;
    }

    public function apply(Injector $injector)
    {
        $this
            ->registerStorageReaders($injector)
            ->registerStorageWriters($injector)
            ->registerFinders($injector)
            ->registerUnitOfWorks($injector)
            ->registerQueryServices($injector);
    }

    protected function registerStorageReaders(Injector $injector)
    {
        $injector->execute(function (array $storageReaders = []) use ($injector) {
            $storageReaders = array_merge($storageReaders, $this->replaceTplMarkers(self::$defaultReaders));
            foreach ($this->crate->getProjectionTypes() as $projectionType) {
                $storageReaders = array_merge(
                    $storageReaders,
                    $this->replaceTplMarkers(self::$defaultResourceReaders, $projectionType)
                );
            }
            $injector->defineParam('storageReaders', $storageReaders);
        });

        return $this;
    }

    protected function registerStorageWriters(Injector $injector)
    {
        $injector->execute(function (array $storageWriters = []) use ($injector) {
            $storageWriters = array_merge($storageWriters, $this->replaceTplMarkers(self::$defaultWriters));
            foreach ($this->crate->getProjectionTypes() as $projectionType) {
                $storageWriters = array_merge(
                    $storageWriters,
                    $this->replaceTplMarkers(self::$defaultResourceWriters, $projectionType)
                );
            }
            $injector->defineParam('storageWriters', $storageWriters);
        });

        return $this;
    }

    protected function registerFinders(Injector $injector)
    {
        $injector->execute(function (array $finders = []) use ($injector) {
            foreach ($this->crate->getProjectionTypes() as $projectionType) {
                $finders = array_merge(
                    $finders,
                    $this->replaceTplMarkers(self::$defaultResourceFinders, $projectionType)
                );
            }
            $injector->defineParam('finders', $finders);
        });

        return $this;
    }

    protected function registerQueryServices(Injector $injector)
    {
        $injector->execute(function (array $queryServices = []) use ($injector) {
            foreach ($this->crate->getProjectionTypes() as $projectionType) {
                $queryServices = array_merge(
                    $queryServices,
                    $this->replaceTplMarkers(self::$defaultResourceQueryServices, $projectionType)
                );
            }
            $injector->defineParam('queryServices', $queryServices);
        });

        return $this;
    }

    protected function registerUnitOfWorks(Injector $injector)
    {
        $injector->execute(function (array $unitOfWorks = []) use ($injector) {
            foreach ($this->crate->getProjectionTypes() as $projectionType) {
                $unitOfWorks = array_merge(
                    $unitOfWorks,
                    $this->replaceTplMarkers(self::$defaultResourceUows, $projectionType)
                );
            }
            $injector->defineParam('unitOfWorks', $unitOfWorks);
        });

        return $this;
    }

    protected function replaceTplMarkers(array $config, ProjectionTypeInterface $projectionType = null)
    {
        $searches = [ '%crate_prefix%', '%vendor%', '%package%' ];
        $replacements = [
            $this->crate->getPrefix(),
            StringToolkit::asSnakeCase($this->crate->getVendor()),
            StringToolkit::asSnakeCase($this->crate->getName())
        ];

        if ($projectionType !== null) {
            $aggregateRootTypes = $this->crate->getAggregateRootTypes();
            array_push($searches, '%res_prefix%', '%res_name%', '%proj_fqcn%', '%ar_fqcn%');
            array_push($replacements,
                $projectionType->getPrefix(),
                StringToolkit::asSnakeCase($projectionType->getName()),
                get_class($projectionType),
                get_class($aggregateRootTypes->getItem($projectionType->getPrefix()))
            );
        }

        $newConfig = [];
        foreach ($config as $key => $value) {
            $key = str_replace($searches, $replacements, $key);
            if (is_array($value)) {
                $newConfig[$key] = $this->replaceTplMarkers($value, $projectionType);
            } else if (is_string($value)) {
                $newConfig[$key] = str_replace($searches, $replacements, $value);
            } else {
                $newConfig[$key] = $value;
            }
        }

        return $newConfig;
    }
}
