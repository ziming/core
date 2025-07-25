<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Symfony\Bundle\DependencyInjection;

use ApiPlatform\Doctrine\Odm\Extension\AggregationCollectionExtensionInterface;
use ApiPlatform\Doctrine\Odm\Extension\AggregationItemExtensionInterface;
use ApiPlatform\Doctrine\Odm\Filter\AbstractFilter as DoctrineMongoDbOdmAbstractFilter;
use ApiPlatform\Doctrine\Odm\State\LinksHandlerInterface as OdmLinksHandlerInterface;
use ApiPlatform\Doctrine\Orm\Extension\EagerLoadingExtension;
use ApiPlatform\Doctrine\Orm\Extension\FilterEagerLoadingExtension;
use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface as DoctrineQueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter as DoctrineOrmAbstractFilter;
use ApiPlatform\Doctrine\Orm\State\LinksHandlerInterface as OrmLinksHandlerInterface;
use ApiPlatform\Elasticsearch\Extension\RequestBodySearchCollectionExtensionInterface;
use ApiPlatform\GraphQl\Error\ErrorHandlerInterface;
use ApiPlatform\GraphQl\Executor;
use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use ApiPlatform\GraphQl\Resolver\QueryCollectionResolverInterface;
use ApiPlatform\GraphQl\Resolver\QueryItemResolverInterface;
use ApiPlatform\GraphQl\Type\Definition\TypeInterface as GraphQlTypeInterface;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\AsOperationMutator;
use ApiPlatform\Metadata\AsResourceMutator;
use ApiPlatform\Metadata\FilterInterface;
use ApiPlatform\Metadata\OperationMutatorInterface;
use ApiPlatform\Metadata\ResourceMutatorInterface;
use ApiPlatform\Metadata\UriVariableTransformerInterface;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use ApiPlatform\OpenApi\Model\Tag;
use ApiPlatform\RamseyUuid\Serializer\UuidDenormalizer;
use ApiPlatform\State\ApiResource\Error;
use ApiPlatform\State\ParameterProviderInterface;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Symfony\Validator\Metadata\Property\Restriction\PropertySchemaRestrictionMetadataInterface;
use ApiPlatform\Symfony\Validator\ValidationGroupsGeneratorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Doctrine\Persistence\ManagerRegistry;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;

/**
 * The extension of this bundle.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class ApiPlatformExtension extends Extension implements PrependExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (isset($container->getExtensions()['framework'])) {
            $container->prependExtensionConfig('framework', [
                'serializer' => [
                    'enabled' => true,
                ],
            ]);
            $container->prependExtensionConfig('framework', [
                'property_info' => [
                    'enabled' => true,
                ],
            ]);
        }
        if (isset($container->getExtensions()['lexik_jwt_authentication'])) {
            $container->prependExtensionConfig('lexik_jwt_authentication', [
                'api_platform' => [
                    'enabled' => true,
                ],
            ]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $container->setParameter('api_platform.use_symfony_listeners', $config['use_symfony_listeners']);

        $formats = $this->getFormats($config['formats']);
        $patchFormats = $this->getFormats($config['patch_formats']);
        $errorFormats = $this->getFormats($config['error_formats']);
        $docsFormats = $this->getFormats($config['docs_formats']);

        if (!$config['enable_docs']) {
            // JSON-LD documentation format is mandatory, even if documentation is disabled.
            $docsFormats = isset($formats['jsonld']) ? ['jsonld' => ['application/ld+json']] : [];
            // If documentation is disabled, the Hydra documentation for all the resources is hidden by default.
            if (!isset($config['defaults']['hideHydraOperation']) && !isset($config['defaults']['hide_hydra_operation'])) {
                $config['defaults']['hideHydraOperation'] = true;
            }
        }
        $jsonSchemaFormats = $config['jsonschema_formats'];

        if (!$jsonSchemaFormats) {
            foreach (array_merge(array_keys($formats), array_keys($errorFormats)) as $f) {
                // Distinct JSON-based formats must have names that start with 'json'
                if (str_starts_with($f, 'json')) {
                    $jsonSchemaFormats[$f] = true;
                }
            }
        }

        if (!isset($errorFormats['json'])) {
            $errorFormats['json'] = ['application/problem+json', 'application/json'];
        }

        if (!isset($errorFormats['jsonproblem'])) {
            $errorFormats['jsonproblem'] = ['application/problem+json'];
        }

        if (isset($formats['jsonapi']) && !isset($patchFormats['jsonapi'])) {
            $patchFormats['jsonapi'] = ['application/vnd.api+json'];
        }

        $this->registerCommonConfiguration($container, $config, $loader, $formats, $patchFormats, $errorFormats, $docsFormats);
        $this->registerMetadataConfiguration($container, $config, $loader);
        $this->registerOAuthConfiguration($container, $config);
        $this->registerOpenApiConfiguration($container, $config, $loader);
        $this->registerSwaggerConfiguration($container, $config, $loader);
        $this->registerJsonApiConfiguration($formats, $loader, $config);
        $this->registerJsonLdHydraConfiguration($container, $formats, $loader, $config);
        $this->registerJsonHalConfiguration($formats, $loader);
        $this->registerJsonProblemConfiguration($errorFormats, $loader);
        $this->registerGraphQlConfiguration($container, $config, $loader);
        $this->registerCacheConfiguration($container);
        $this->registerDoctrineOrmConfiguration($container, $config, $loader);
        $this->registerDoctrineMongoDbOdmConfiguration($container, $config, $loader);
        $this->registerHttpCacheConfiguration($container, $config, $loader);
        $this->registerValidatorConfiguration($container, $config, $loader);
        $this->registerDataCollectorConfiguration($container, $config, $loader);
        $this->registerMercureConfiguration($container, $config, $loader);
        $this->registerMessengerConfiguration($container, $config, $loader);
        $this->registerElasticsearchConfiguration($container, $config, $loader);
        $this->registerSecurityConfiguration($container, $config, $loader);
        $this->registerMakerConfiguration($container, $config, $loader);
        $this->registerArgumentResolverConfiguration($loader);
        $this->registerLinkSecurityConfiguration($loader, $config);

        if (class_exists(ObjectMapper::class)) {
            $loader->load('state/object_mapper.xml');
        }
        $container->registerForAutoconfiguration(FilterInterface::class)
            ->addTag('api_platform.filter');
        $container->registerForAutoconfiguration(ProviderInterface::class)
            ->addTag('api_platform.state_provider');
        $container->registerForAutoconfiguration(ProcessorInterface::class)
            ->addTag('api_platform.state_processor');
        $container->registerForAutoconfiguration(UriVariableTransformerInterface::class)
            ->addTag('api_platform.uri_variables.transformer');
        $container->registerForAutoconfiguration(ParameterProviderInterface::class)
            ->addTag('api_platform.parameter_provider');
        $container->registerAttributeForAutoconfiguration(ApiResource::class, static function (ChildDefinition $definition): void {
            $definition->setAbstract(true)
                ->addTag('api_platform.resource')
                ->addTag('container.excluded', ['source' => 'by #[ApiResource] attribute']);
        });
        $container->registerAttributeForAutoconfiguration(AsResourceMutator::class,
            static function (ChildDefinition $definition, AsResourceMutator $attribute, \Reflector $reflector): void {
                if (!$reflector instanceof \ReflectionClass) {
                    return;
                }

                if (!is_a($reflector->name, ResourceMutatorInterface::class, true)) {
                    throw new RuntimeException(\sprintf('Resource mutator "%s" should implement %s', $reflector->name, ResourceMutatorInterface::class));
                }

                $definition->addTag('api_platform.resource_mutator', [
                    'resourceClass' => $attribute->resourceClass,
                ]);
            },
        );

        $container->registerAttributeForAutoconfiguration(AsOperationMutator::class,
            static function (ChildDefinition $definition, AsOperationMutator $attribute, \Reflector $reflector): void {
                if (!$reflector instanceof \ReflectionClass) {
                    return;
                }

                if (!is_a($reflector->name, OperationMutatorInterface::class, true)) {
                    throw new RuntimeException(\sprintf('Operation mutator "%s" should implement %s', $reflector->name, OperationMutatorInterface::class));
                }

                $definition->addTag('api_platform.operation_mutator', [
                    'operationName' => $attribute->operationName,
                ]);
            },
        );

        if (!$container->has('api_platform.state.item_provider')) {
            $container->setAlias('api_platform.state.item_provider', 'api_platform.state_provider.object');
        }
    }

    private function registerCommonConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader, array $formats, array $patchFormats, array $errorFormats, array $docsFormats): void
    {
        $loader->load('state/state.xml');
        $loader->load('symfony/symfony.xml');
        $loader->load('api.xml');
        $loader->load('filter.xml');

        if (class_exists(UuidDenormalizer::class) && class_exists(Uuid::class)) {
            $loader->load('ramsey_uuid.xml');
        }

        if (class_exists(AbstractUid::class)) {
            $loader->load('symfony/uid.xml');
        }

        $defaultContext = ['hydra_prefix' => $config['serializer']['hydra_prefix']] + ($container->hasParameter('serializer.default_context') ? $container->getParameter('serializer.default_context') : []);

        $container->setParameter('api_platform.serializer.default_context', $defaultContext);
        if (!$container->hasParameter('serializer.default_context')) {
            $container->setParameter('serializer.default_context', $container->getParameter('api_platform.serializer.default_context'));
        }
        if ($config['use_symfony_listeners']) {
            $loader->load('symfony/events.xml');
        } else {
            $loader->load('symfony/controller.xml');
            $loader->load('state/provider.xml');
            $loader->load('state/processor.xml');
        }
        $loader->load('state/parameter_provider.xml');

        $container->setParameter('api_platform.enable_entrypoint', $config['enable_entrypoint']);
        $container->setParameter('api_platform.enable_docs', $config['enable_docs']);
        $container->setParameter('api_platform.title', $config['title']);
        $container->setParameter('api_platform.description', $config['description']);
        $container->setParameter('api_platform.version', $config['version']);
        $container->setParameter('api_platform.show_webby', $config['show_webby']);
        $container->setParameter('api_platform.url_generation_strategy', $config['defaults']['url_generation_strategy'] ?? UrlGeneratorInterface::ABS_PATH);
        $container->setParameter('api_platform.exception_to_status', $config['exception_to_status']);
        $container->setParameter('api_platform.formats', $formats);
        $container->setParameter('api_platform.patch_formats', $patchFormats);
        $container->setParameter('api_platform.error_formats', $errorFormats);
        $container->setParameter('api_platform.docs_formats', $docsFormats);
        $container->setParameter('api_platform.jsonschema_formats', []);
        $container->setParameter('api_platform.eager_loading.enabled', $this->isConfigEnabled($container, $config['eager_loading']));
        $container->setParameter('api_platform.eager_loading.max_joins', $config['eager_loading']['max_joins']);
        $container->setParameter('api_platform.eager_loading.fetch_partial', $config['eager_loading']['fetch_partial']);
        $container->setParameter('api_platform.eager_loading.force_eager', $config['eager_loading']['force_eager']);
        $container->setParameter('api_platform.collection.exists_parameter_name', $config['collection']['exists_parameter_name']);
        $container->setParameter('api_platform.collection.order', $config['collection']['order']);
        $container->setParameter('api_platform.collection.order_parameter_name', $config['collection']['order_parameter_name']);
        $container->setParameter('api_platform.collection.order_nulls_comparison', $config['collection']['order_nulls_comparison']);
        $container->setParameter('api_platform.collection.pagination.enabled', $config['defaults']['pagination_enabled'] ?? true);
        $container->setParameter('api_platform.collection.pagination.partial', $config['defaults']['pagination_partial'] ?? false);
        $container->setParameter('api_platform.collection.pagination.client_enabled', $config['defaults']['pagination_client_enabled'] ?? false);
        $container->setParameter('api_platform.collection.pagination.client_items_per_page', $config['defaults']['pagination_client_items_per_page'] ?? false);
        $container->setParameter('api_platform.collection.pagination.client_partial', $config['defaults']['pagination_client_partial'] ?? false);
        $container->setParameter('api_platform.collection.pagination.items_per_page', $config['defaults']['pagination_items_per_page'] ?? 30);
        $container->setParameter('api_platform.collection.pagination.maximum_items_per_page', $config['defaults']['pagination_maximum_items_per_page'] ?? null);
        $container->setParameter('api_platform.collection.pagination.page_parameter_name', $config['defaults']['pagination_page_parameter_name'] ?? $config['collection']['pagination']['page_parameter_name']);
        $container->setParameter('api_platform.collection.pagination.enabled_parameter_name', $config['defaults']['pagination_enabled_parameter_name'] ?? $config['collection']['pagination']['enabled_parameter_name']);
        $container->setParameter('api_platform.collection.pagination.items_per_page_parameter_name', $config['defaults']['pagination_items_per_page_parameter_name'] ?? $config['collection']['pagination']['items_per_page_parameter_name']);
        $container->setParameter('api_platform.collection.pagination.partial_parameter_name', $config['defaults']['pagination_partial_parameter_name'] ?? $config['collection']['pagination']['partial_parameter_name']);
        $container->setParameter('api_platform.collection.pagination', $this->getPaginationDefaults($config['defaults'] ?? [], $config['collection']['pagination']));
        $container->setParameter('api_platform.handle_symfony_errors', $config['handle_symfony_errors'] ?? false);
        $container->setParameter('api_platform.http_cache.etag', $config['defaults']['cache_headers']['etag'] ?? true);
        $container->setParameter('api_platform.http_cache.max_age', $config['defaults']['cache_headers']['max_age'] ?? null);
        $container->setParameter('api_platform.http_cache.shared_max_age', $config['defaults']['cache_headers']['shared_max_age'] ?? null);
        $container->setParameter('api_platform.http_cache.vary', $config['defaults']['cache_headers']['vary'] ?? ['Accept']);
        $container->setParameter('api_platform.http_cache.public', $config['defaults']['cache_headers']['public'] ?? $config['http_cache']['public']);
        $container->setParameter('api_platform.http_cache.invalidation.max_header_length', $config['defaults']['cache_headers']['invalidation']['max_header_length'] ?? $config['http_cache']['invalidation']['max_header_length']);
        $container->setParameter('api_platform.http_cache.invalidation.xkey.glue', $config['defaults']['cache_headers']['invalidation']['xkey']['glue'] ?? $config['http_cache']['invalidation']['xkey']['glue']);

        $container->setAlias('api_platform.path_segment_name_generator', $config['path_segment_name_generator']);
        $container->setAlias('api_platform.inflector', $config['inflector']);

        if ($config['name_converter']) {
            $container->setAlias('api_platform.name_converter', $config['name_converter']);
        }
        $container->setParameter('api_platform.asset_package', $config['asset_package']);
        $container->setParameter('api_platform.defaults', $this->normalizeDefaults($config['defaults'] ?? []));

        if ($container->getParameter('kernel.debug')) {
            $container->removeDefinition('api_platform.serializer.mapping.cache_class_metadata_factory');
        }
    }

    /**
     * This method will be removed in 3.0 when "defaults" will be the regular configuration path for the pagination.
     */
    private function getPaginationDefaults(array $defaults, array $collectionPaginationConfiguration): array
    {
        $paginationOptions = [];

        foreach ($defaults as $key => $value) {
            if (!str_starts_with($key, 'pagination_')) {
                continue;
            }

            $paginationOptions[str_replace('pagination_', '', $key)] = $value;
        }

        return array_merge($collectionPaginationConfiguration, $paginationOptions);
    }

    private function normalizeDefaults(array $defaults): array
    {
        $normalizedDefaults = ['extra_properties' => $defaults['extra_properties'] ?? []];
        unset($defaults['extra_properties']);

        $rc = new \ReflectionClass(ApiResource::class);
        $publicProperties = [];
        foreach ($rc->getConstructor()->getParameters() as $param) {
            $publicProperties[$param->getName()] = true;
        }

        $nameConverter = new CamelCaseToSnakeCaseNameConverter();
        foreach ($defaults as $option => $value) {
            if (isset($publicProperties[$nameConverter->denormalize($option)])) {
                $normalizedDefaults[$option] = $value;

                continue;
            }

            $normalizedDefaults['extra_properties'][$option] = $value;
        }

        return $normalizedDefaults;
    }

    private function registerMetadataConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        [$xmlResources, $yamlResources, $phpResources] = $this->getResourcesToWatch($container, $config);

        $container->setParameter('api_platform.class_name_resources', $this->getClassNameResources());

        $loader->load('metadata/resource_name.xml');
        $loader->load('metadata/property_name.xml');

        if (!empty($config['resource_class_directories'])) {
            $container->setParameter('api_platform.resource_class_directories', array_merge(
                $config['resource_class_directories'],
                $container->getParameter('api_platform.resource_class_directories')
            ));
        }

        // V3 metadata
        $loader->load('metadata/php.xml');
        $loader->load('metadata/xml.xml');
        $loader->load('metadata/links.xml');
        $loader->load('metadata/property.xml');
        $loader->load('metadata/resource.xml');
        $loader->load('metadata/operation.xml');
        $loader->load('metadata/mutator.xml');

        $container->getDefinition('api_platform.metadata.resource_extractor.xml')->replaceArgument(0, $xmlResources);
        $container->getDefinition('api_platform.metadata.property_extractor.xml')->replaceArgument(0, $xmlResources);

        if (class_exists(PhpDocParser::class)) {
            $loader->load('metadata/php_doc.xml');
        }

        if (class_exists(Yaml::class)) {
            $loader->load('metadata/yaml.xml');
            $container->getDefinition('api_platform.metadata.resource_extractor.yaml')->replaceArgument(0, $yamlResources);
            $container->getDefinition('api_platform.metadata.property_extractor.yaml')->replaceArgument(0, $yamlResources);
        }

        $container->getDefinition('api_platform.metadata.resource_extractor.php_file')->replaceArgument(0, $phpResources);
    }

    private function getClassNameResources(): array
    {
        return [
            Error::class,
            ValidationException::class,
        ];
    }

    private function getBundlesResourcesPaths(ContainerBuilder $container, array $config): array
    {
        $bundlesResourcesPaths = [];

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundle) {
            $dirname = $bundle['path'];
            $paths = [
                "$dirname/ApiResource",
                "$dirname/src/ApiResource",
            ];
            foreach (['.yaml', '.yml', '.xml', ''] as $extension) {
                $paths[] = "$dirname/Resources/config/api_resources$extension";
                $paths[] = "$dirname/config/api_resources$extension";
            }
            if ($this->isConfigEnabled($container, $config['doctrine'])) {
                $paths[] = "$dirname/Entity";
                $paths[] = "$dirname/src/Entity";
            }
            if ($this->isConfigEnabled($container, $config['doctrine_mongodb_odm'])) {
                $paths[] = "$dirname/Document";
                $paths[] = "$dirname/src/Document";
            }

            foreach ($paths as $path) {
                if ($container->fileExists($path, false)) {
                    $bundlesResourcesPaths[] = $path;
                }
            }
        }

        return $bundlesResourcesPaths;
    }

    private function getResourcesToWatch(ContainerBuilder $container, array $config): array
    {
        $paths = array_unique(array_merge($this->getBundlesResourcesPaths($container, $config), $config['mapping']['paths']));

        if (!$config['mapping']['paths']) {
            $projectDir = $container->getParameter('kernel.project_dir');
            foreach (["$projectDir/config/api_platform", "$projectDir/src/ApiResource"] as $dir) {
                if (is_dir($dir)) {
                    $paths[] = $dir;
                }
            }

            if ($this->isConfigEnabled($container, $config['doctrine']) && is_dir($doctrinePath = "$projectDir/src/Entity")) {
                $paths[] = $doctrinePath;
            }

            if ($this->isConfigEnabled($container, $config['doctrine_mongodb_odm']) && is_dir($documentPath = "$projectDir/src/Document")) {
                $paths[] = $documentPath;
            }
        }

        $resources = ['yml' => [], 'xml' => [], 'php' => [], 'dir' => []];

        foreach ($config['mapping']['imports'] ?? [] as $path) {
            if (is_dir($path)) {
                foreach (Finder::create()->followLinks()->files()->in($path)->name('/\.php$/')->sortByName() as $file) {
                    $resources[$file->getExtension()][] = $file->getRealPath();
                }

                $resources['dir'][] = $path;
                $container->addResource(new DirectoryResource($path, '/\.php$/'));

                continue;
            }

            if ($container->fileExists($path, false)) {
                if (!str_ends_with($path, '.php')) {
                    throw new RuntimeException(\sprintf('Unsupported mapping type in "%s", supported type is PHP.', $path));
                }

                $resources['php'][] = $path;

                continue;
            }

            throw new RuntimeException(\sprintf('Could not open file or directory "%s".', $path));
        }

        foreach ($paths as $path) {
            if (is_dir($path)) {
                foreach (Finder::create()->followLinks()->files()->in($path)->name('/\.(xml|ya?ml)$/')->sortByName() as $file) {
                    $resources['yaml' === ($extension = $file->getExtension()) ? 'yml' : $extension][] = $file->getRealPath();
                }

                $resources['dir'][] = $path;
                $container->addResource(new DirectoryResource($path, '/\.(xml|ya?ml|php)$/'));

                continue;
            }

            if ($container->fileExists($path, false)) {
                if (!preg_match('/\.(xml|ya?ml)$/', (string) $path, $matches)) {
                    throw new RuntimeException(\sprintf('Unsupported mapping type in "%s", supported types are XML & YAML.', $path));
                }

                $resources['yaml' === $matches[1] ? 'yml' : $matches[1]][] = $path;

                continue;
            }

            throw new RuntimeException(\sprintf('Could not open file or directory "%s".', $path));
        }

        $container->setParameter('api_platform.resource_class_directories', $resources['dir']);

        return [$resources['xml'], $resources['yml'], $resources['php']];
    }

    private function registerOAuthConfiguration(ContainerBuilder $container, array $config): void
    {
        if (!$config['oauth']) {
            return;
        }

        $container->setParameter('api_platform.oauth.enabled', $this->isConfigEnabled($container, $config['oauth']));
        $container->setParameter('api_platform.oauth.clientId', $config['oauth']['clientId']);
        $container->setParameter('api_platform.oauth.clientSecret', $config['oauth']['clientSecret']);
        $container->setParameter('api_platform.oauth.type', $config['oauth']['type']);
        $container->setParameter('api_platform.oauth.flow', $config['oauth']['flow']);
        $container->setParameter('api_platform.oauth.tokenUrl', $config['oauth']['tokenUrl']);
        $container->setParameter('api_platform.oauth.authorizationUrl', $config['oauth']['authorizationUrl']);
        $container->setParameter('api_platform.oauth.refreshUrl', $config['oauth']['refreshUrl']);
        $container->setParameter('api_platform.oauth.scopes', $config['oauth']['scopes']);
        $container->setParameter('api_platform.oauth.pkce', $config['oauth']['pkce']);
    }

    /**
     * Registers the Swagger, ReDoc and Swagger UI configuration.
     */
    private function registerSwaggerConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        foreach (array_keys($config['swagger']['api_keys']) as $keyName) {
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $keyName)) {
                throw new RuntimeException(\sprintf('The swagger api_keys key "%s" is not valid, it should match "^[a-zA-Z0-9._-]+$"', $keyName));
            }
        }

        $container->setParameter('api_platform.swagger.versions', $config['swagger']['versions']);

        if (!$config['enable_swagger'] && $config['enable_swagger_ui']) {
            throw new RuntimeException('You can not enable the Swagger UI without enabling Swagger, fix this by enabling swagger via the configuration "enable_swagger: true".');
        }

        if (!$config['enable_swagger']) {
            return;
        }

        $loader->load('openapi.xml');

        if (class_exists(Yaml::class)) {
            $loader->load('openapi/yaml.xml');
        }

        $loader->load('swagger_ui.xml');

        if ($config['use_symfony_listeners']) {
            $loader->load('symfony/swagger_ui.xml');
        }

        if ($config['enable_swagger_ui']) {
            $loader->load('state/swagger_ui.xml');
        }

        if (!$config['enable_swagger_ui'] && !$config['enable_re_doc']) {
            // Remove the listener but keep the controller to allow customizing the path of the UI
            $container->removeDefinition('api_platform.swagger.listener.ui');
        }

        $container->setParameter('api_platform.enable_swagger_ui', $config['enable_swagger_ui']);
        $container->setParameter('api_platform.enable_re_doc', $config['enable_re_doc']);
        $container->setParameter('api_platform.swagger.api_keys', $config['swagger']['api_keys']);
        $container->setParameter('api_platform.swagger.persist_authorization', $config['swagger']['persist_authorization']);
        $container->setParameter('api_platform.swagger.http_auth', $config['swagger']['http_auth']);
        if ($config['openapi']['swagger_ui_extra_configuration'] && $config['swagger']['swagger_ui_extra_configuration']) {
            throw new RuntimeException('You can not set "swagger_ui_extra_configuration" twice - in "openapi" and "swagger" section.');
        }
        $container->setParameter('api_platform.swagger_ui.extra_configuration', $config['openapi']['swagger_ui_extra_configuration'] ?: $config['swagger']['swagger_ui_extra_configuration']);
    }

    private function registerJsonApiConfiguration(array $formats, XmlFileLoader $loader, array $config): void
    {
        if (!isset($formats['jsonapi'])) {
            return;
        }

        $loader->load('jsonapi.xml');
        $loader->load('state/jsonapi.xml');
    }

    private function registerJsonLdHydraConfiguration(ContainerBuilder $container, array $formats, XmlFileLoader $loader, array $config): void
    {
        if (!isset($formats['jsonld'])) {
            return;
        }

        if ($config['use_symfony_listeners']) {
            $loader->load('symfony/jsonld.xml');
        } else {
            $loader->load('state/jsonld.xml');
        }

        $loader->load('state/hydra.xml');
        $loader->load('jsonld.xml');
        $loader->load('hydra.xml');

        if (!$container->has('api_platform.json_schema.schema_factory')) {
            $container->removeDefinition('api_platform.hydra.json_schema.schema_factory');
        }
    }

    private function registerJsonHalConfiguration(array $formats, XmlFileLoader $loader): void
    {
        if (!isset($formats['jsonhal'])) {
            return;
        }

        $loader->load('hal.xml');
    }

    private function registerJsonProblemConfiguration(array $errorFormats, XmlFileLoader $loader): void
    {
        if (!isset($errorFormats['jsonproblem'])) {
            return;
        }

        $loader->load('problem.xml');
    }

    private function registerGraphQlConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        $enabled = $this->isConfigEnabled($container, $config['graphql']);
        $graphqlIntrospectionEnabled = $enabled && $this->isConfigEnabled($container, $config['graphql']['introspection']);
        $graphiqlEnabled = $enabled && $this->isConfigEnabled($container, $config['graphql']['graphiql']);
        $maxQueryDepth = (int) $config['graphql']['max_query_depth'];
        $maxQueryComplexity = (int) $config['graphql']['max_query_complexity'];

        $container->setParameter('api_platform.graphql.enabled', $enabled);
        $container->setParameter('api_platform.graphql.max_query_depth', $maxQueryDepth);
        $container->setParameter('api_platform.graphql.max_query_complexity', $maxQueryComplexity);
        $container->setParameter('api_platform.graphql.introspection.enabled', $graphqlIntrospectionEnabled);
        $container->setParameter('api_platform.graphql.graphiql.enabled', $graphiqlEnabled);
        $container->setParameter('api_platform.graphql.collection.pagination', $config['graphql']['collection']['pagination']);

        if (!$enabled) {
            return;
        }

        if (!class_exists(Executor::class)) {
            throw new \RuntimeException('Graphql is enabled but not installed, run: composer require "api-platform/graphql".');
        }

        $container->setParameter('api_platform.graphql.default_ide', $config['graphql']['default_ide']);
        $container->setParameter('api_platform.graphql.nesting_separator', $config['graphql']['nesting_separator']);

        $loader->load('graphql.xml');

        if (!class_exists(Environment::class) || !isset($container->getParameter('kernel.bundles')['TwigBundle'])) {
            if ($graphiqlEnabled) {
                throw new RuntimeException(\sprintf('GraphiQL interfaces depend on Twig. Please activate TwigBundle for the %s environnement or disable GraphiQL.', $container->getParameter('kernel.environment')));
            }
            $container->removeDefinition('api_platform.graphql.action.graphiql');
        }

        $container->registerForAutoconfiguration(QueryItemResolverInterface::class)
            ->addTag('api_platform.graphql.resolver');
        $container->registerForAutoconfiguration(QueryCollectionResolverInterface::class)
            ->addTag('api_platform.graphql.resolver');
        $container->registerForAutoconfiguration(MutationResolverInterface::class)
            ->addTag('api_platform.graphql.resolver');
        $container->registerForAutoconfiguration(GraphQlTypeInterface::class)
            ->addTag('api_platform.graphql.type');
        $container->registerForAutoconfiguration(ErrorHandlerInterface::class)
            ->addTag('api_platform.graphql.error_handler');
    }

    private function registerCacheConfiguration(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('kernel.debug') || !$container->getParameter('kernel.debug')) {
            $container->removeDefinition('api_platform.cache_warmer.cache_pool_clearer');
        }
    }

    private function registerDoctrineOrmConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        if (!$this->isConfigEnabled($container, $config['doctrine'])) {
            return;
        }

        // For older versions of doctrine bridge this allows autoconfiguration for filters
        if (!$container->has(ManagerRegistry::class)) {
            $container->setAlias(ManagerRegistry::class, 'doctrine');
        }

        $container->registerForAutoconfiguration(QueryItemExtensionInterface::class)
            ->addTag('api_platform.doctrine.orm.query_extension.item');
        $container->registerForAutoconfiguration(DoctrineQueryCollectionExtensionInterface::class)
            ->addTag('api_platform.doctrine.orm.query_extension.collection');
        $container->registerForAutoconfiguration(DoctrineOrmAbstractFilter::class);

        $container->registerForAutoconfiguration(OrmLinksHandlerInterface::class)
            ->addTag('api_platform.doctrine.orm.links_handler');

        $loader->load('doctrine_orm.xml');

        if ($this->isConfigEnabled($container, $config['eager_loading'])) {
            return;
        }

        $container->removeAlias(EagerLoadingExtension::class);
        $container->removeDefinition('api_platform.doctrine.orm.query_extension.eager_loading');
        $container->removeAlias(FilterEagerLoadingExtension::class);
        $container->removeDefinition('api_platform.doctrine.orm.query_extension.filter_eager_loading');
    }

    private function registerDoctrineMongoDbOdmConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        if (!$this->isConfigEnabled($container, $config['doctrine_mongodb_odm'])) {
            return;
        }

        $container->registerForAutoconfiguration(AggregationItemExtensionInterface::class)
            ->addTag('api_platform.doctrine_mongodb.odm.aggregation_extension.item');
        $container->registerForAutoconfiguration(AggregationCollectionExtensionInterface::class)
            ->addTag('api_platform.doctrine_mongodb.odm.aggregation_extension.collection');
        $container->registerForAutoconfiguration(DoctrineMongoDbOdmAbstractFilter::class)
            ->setBindings(['$managerRegistry' => new Reference('doctrine_mongodb')]);
        $container->registerForAutoconfiguration(OdmLinksHandlerInterface::class)
            ->addTag('api_platform.doctrine.odm.links_handler');

        $loader->load('doctrine_mongodb_odm.xml');
    }

    private function registerHttpCacheConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        $loader->load('http_cache.xml');

        if (!$this->isConfigEnabled($container, $config['http_cache']['invalidation'])) {
            return;
        }

        if ($this->isConfigEnabled($container, $config['doctrine'])) {
            $loader->load('doctrine_orm_http_cache_purger.xml');
        }

        $loader->load('state/http_cache_purger.xml');
        $loader->load('http_cache_purger.xml');

        foreach ($config['http_cache']['invalidation']['scoped_clients'] as $client) {
            $definition = $container->getDefinition($client);
            $definition->addTag('api_platform.http_cache.http_client');
        }

        if (!($urls = $config['http_cache']['invalidation']['urls'])) {
            $urls = $config['http_cache']['invalidation']['varnish_urls'];
        }

        foreach ($urls as $key => $url) {
            $definition = new Definition(ScopingHttpClient::class, [new Reference('http_client'), $url, ['base_uri' => $url] + $config['http_cache']['invalidation']['request_options']]);
            $definition->setFactory([ScopingHttpClient::class, 'forBaseUri']);
            $definition->addTag('api_platform.http_cache.http_client');
            $container->setDefinition('api_platform.invalidation_http_client.'.$key, $definition);
        }

        $serviceName = $config['http_cache']['invalidation']['purger'];

        if (!$container->hasDefinition('api_platform.http_cache.purger')) {
            $container->setAlias('api_platform.http_cache.purger', $serviceName);
        }
    }

    /**
     * Normalizes the format from config to the one accepted by Symfony HttpFoundation.
     */
    private function getFormats(array $configFormats): array
    {
        $formats = [];
        foreach ($configFormats as $format => $value) {
            foreach ($value['mime_types'] as $mimeType) {
                $formats[$format][] = $mimeType;
            }
        }

        return $formats;
    }

    private function registerValidatorConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        if (interface_exists(ValidatorInterface::class)) {
            $loader->load('metadata/validator.xml');
            $loader->load('validator/validator.xml');

            if ($this->isConfigEnabled($container, $config['graphql'])) {
                $loader->load('graphql/validator.xml');
            }

            $loader->load($config['use_symfony_listeners'] ? 'validator/events.xml' : 'validator/state.xml');

            $container->registerForAutoconfiguration(ValidationGroupsGeneratorInterface::class)
                ->addTag('api_platform.validation_groups_generator');
            $container->registerForAutoconfiguration(PropertySchemaRestrictionMetadataInterface::class)
                ->addTag('api_platform.metadata.property_schema_restriction');
        }

        if (!$config['validator']) {
            return;
        }

        $container->setParameter('api_platform.validator.serialize_payload_fields', $config['validator']['serialize_payload_fields']);
    }

    private function registerDataCollectorConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        if (!$config['enable_profiler']) {
            return;
        }

        $loader->load('data_collector.xml');

        if ($container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug')) {
            $loader->load('debug.xml');
        }
    }

    private function registerMercureConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        if (!$this->isConfigEnabled($container, $config['mercure'])) {
            return;
        }

        $container->setParameter('api_platform.mercure.include_type', $config['mercure']['include_type']);
        $loader->load('state/mercure.xml');

        if ($this->isConfigEnabled($container, $config['doctrine'])) {
            $loader->load('doctrine_orm_mercure_publisher.xml');
        }
        if ($this->isConfigEnabled($container, $config['doctrine_mongodb_odm'])) {
            $loader->load('doctrine_odm_mercure_publisher.xml');
        }

        if ($this->isConfigEnabled($container, $config['graphql'])) {
            $loader->load('graphql_mercure.xml');
        }
    }

    private function registerMessengerConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        if (!$this->isConfigEnabled($container, $config['messenger'])) {
            return;
        }

        $loader->load('messenger.xml');
    }

    private function registerElasticsearchConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        $enabled = $this->isConfigEnabled($container, $config['elasticsearch']);

        $container->setParameter('api_platform.elasticsearch.enabled', $enabled);

        if (!$enabled) {
            return;
        }

        $clientClass = !class_exists(\Elasticsearch\Client::class)
            // ES v7
            ? \Elastic\Elasticsearch\Client::class
            // ES v8 and up
            : \Elasticsearch\Client::class;

        $clientDefinition = new Definition($clientClass);
        $container->setDefinition('api_platform.elasticsearch.client', $clientDefinition);
        $container->registerForAutoconfiguration(RequestBodySearchCollectionExtensionInterface::class)
            ->addTag('api_platform.elasticsearch.request_body_search_extension.collection');
        $container->setParameter('api_platform.elasticsearch.hosts', $config['elasticsearch']['hosts']);
        $loader->load('elasticsearch.xml');
    }

    private function registerSecurityConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        /** @var string[] $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        if (!isset($bundles['SecurityBundle'])) {
            return;
        }

        $loader->load('security.xml');

        $loader->load('state/security.xml');

        if (interface_exists(ValidatorInterface::class)) {
            $loader->load('state/security_validator.xml');
        }

        if ($this->isConfigEnabled($container, $config['graphql'])) {
            $loader->load('graphql/security.xml');
        }
    }

    private function registerOpenApiConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        $container->setParameter('api_platform.openapi.termsOfService', $config['openapi']['termsOfService']);
        $container->setParameter('api_platform.openapi.contact.name', $config['openapi']['contact']['name']);
        $container->setParameter('api_platform.openapi.contact.url', $config['openapi']['contact']['url']);
        $container->setParameter('api_platform.openapi.contact.email', $config['openapi']['contact']['email']);
        $container->setParameter('api_platform.openapi.license.name', $config['openapi']['license']['name']);
        $container->setParameter('api_platform.openapi.license.url', $config['openapi']['license']['url']);
        $container->setParameter('api_platform.openapi.license.identifier', $config['openapi']['license']['identifier']);
        $container->setParameter('api_platform.openapi.overrideResponses', $config['openapi']['overrideResponses']);

        $tags = [];
        foreach ($config['openapi']['tags'] as $tag) {
            $tags[] = new Tag($tag['name'], $tag['description'] ?? null);
        }

        $container->setParameter('api_platform.openapi.tags', $tags);

        $container->setParameter('api_platform.openapi.errorResourceClass', $config['openapi']['error_resource_class'] ?? null);
        $container->setParameter('api_platform.openapi.validationErrorResourceClass', $config['openapi']['validation_error_resource_class'] ?? null);

        $loader->load('json_schema.xml');
    }

    private function registerMakerConfiguration(ContainerBuilder $container, array $config, XmlFileLoader $loader): void
    {
        if (!$this->isConfigEnabled($container, $config['maker'])) {
            return;
        }

        $loader->load('maker.xml');
    }

    private function registerArgumentResolverConfiguration(XmlFileLoader $loader): void
    {
        $loader->load('argument_resolver.xml');
    }

    private function registerLinkSecurityConfiguration(XmlFileLoader $loader, array $config): void
    {
        if ($config['enable_link_security']) {
            $loader->load('link_security.xml');
        }
    }
}
