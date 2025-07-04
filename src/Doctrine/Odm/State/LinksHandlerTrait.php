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

namespace ApiPlatform\Doctrine\Odm\State;

use ApiPlatform\Doctrine\Common\State\LinksHandlerTrait as CommonLinksHandlerTrait;
use ApiPlatform\Metadata\Exception\RuntimeException;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Util\StateOptionsTrait;
use Doctrine\ODM\MongoDB\Aggregation\Builder;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @internal
 */
trait LinksHandlerTrait
{
    use CommonLinksHandlerTrait;
    use StateOptionsTrait;

    private ManagerRegistry $managerRegistry;

    private function handleLinks(Builder $aggregationBuilder, array $identifiers, array $context, string $resourceClass, ?Operation $operation = null): void
    {
        if (!$identifiers) {
            return;
        }

        $links = $this->getLinks($resourceClass, $operation, $context);

        if (!$links) {
            return;
        }

        foreach ($links as $i => $link) {
            if (null !== $link->getExpandedValue()) {
                unset($links[$i]);
            }
        }

        $executeOptions = $operation->getExtraProperties()['doctrine_mongodb']['execute_options'] ?? [];

        $this->buildAggregation($resourceClass, array_reverse($links), array_reverse($identifiers), $context, $executeOptions, $resourceClass, $aggregationBuilder, $operation);
    }

    /**
     * @throws RuntimeException
     */
    private function buildAggregation(string $toClass, array $links, array $identifiers, array $context, array $executeOptions, string $previousAggregationClass, Builder $previousAggregationBuilder, Operation $operation): Builder
    {
        if (\count($links) <= 0) {
            return $previousAggregationBuilder;
        }

        /** @var Link $link */
        $link = array_shift($links);

        $fromClass = $link->getFromClass();
        $fromProperty = $link->getFromProperty();
        $toProperty = $link->getToProperty();
        $identifierProperties = $link->getIdentifiers();
        $hasCompositeIdentifiers = 1 < \count($identifierProperties);

        $aggregationClass = $fromClass;
        if ($toProperty) {
            $aggregationClass = $toClass;
        }

        $lookupProperty = $toProperty ?? $fromProperty;
        $lookupPropertyAlias = $lookupProperty ? "{$lookupProperty}_lkup" : null;

        $manager = $this->managerRegistry->getManagerForClass($aggregationClass);
        if (!$manager instanceof DocumentManager) {
            $aggregationClass = $this->getLinkFromClass($link, $operation);
            $manager = $this->managerRegistry->getManagerForClass($aggregationClass);

            if (!$manager instanceof DocumentManager) {
                throw new RuntimeException(\sprintf('The manager for "%s" must be an instance of "%s".', $aggregationClass, DocumentManager::class));
            }
        }

        $classMetadata = $manager->getClassMetadata($aggregationClass);

        $aggregation = $previousAggregationBuilder;
        if ($aggregationClass !== $previousAggregationClass) {
            $aggregation = $manager->createAggregationBuilder($aggregationClass);
        }

        if ($lookupProperty && $classMetadata->hasAssociation($lookupProperty)) {
            $aggregation->lookup($lookupProperty)->alias($lookupPropertyAlias);
        }

        if ($toProperty) {
            foreach ($identifierProperties as $identifierProperty) {
                $aggregation->match()->field(\sprintf('%s.%s', $lookupPropertyAlias, 'id' === $identifierProperty ? '_id' : $identifierProperty))->equals($this->getIdentifierValue($identifiers, $hasCompositeIdentifiers ? $identifierProperty : null));
            }
        } else {
            foreach ($identifierProperties as $identifierProperty) {
                $aggregation->match()->field($identifierProperty)->equals($this->getIdentifierValue($identifiers, $hasCompositeIdentifiers ? $identifierProperty : null));
            }
        }

        // Recurse aggregations
        $aggregation = $this->buildAggregation($fromClass, $links, $identifiers, $context, $executeOptions, $aggregationClass, $aggregation, $operation);

        if (null === $fromProperty || null !== $toProperty) {
            return $aggregation;
        }

        $results = $aggregation->getAggregation($executeOptions)->getIterator()->toArray();
        $in = [];
        foreach ($results as $result) {
            foreach ($result[$lookupPropertyAlias] ?? [] as $lookupResult) {
                $in[] = $lookupResult['_id'];
            }
        }
        $previousAggregationBuilder->match()->field('_id')->in($in);

        return $previousAggregationBuilder;
    }

    private function getLinkFromClass(Link $link, Operation $operation): string
    {
        $documentClass = $this->getStateOptionsClass($operation, $operation->getClass(), Options::class);
        $fromClass = $link->getFromClass();
        if ($fromClass === $operation->getClass() && $documentClass) {
            return $documentClass;
        }

        $operation = $this->resourceMetadataCollectionFactory->create($fromClass)->getOperation();

        if ($documentClass = $this->getStateOptionsClass($operation, null, Options::class)) {
            return $documentClass;
        }

        throw new \Exception('Can not found a doctrine class for this link.');
    }
}
