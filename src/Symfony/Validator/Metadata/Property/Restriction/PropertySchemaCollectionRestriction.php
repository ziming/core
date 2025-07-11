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

namespace ApiPlatform\Symfony\Validator\Metadata\Property\Restriction;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Required;

/**
 * @author Tomas Norkūnas <norkunas.tom@gmail.com>
 */
final class PropertySchemaCollectionRestriction implements PropertySchemaRestrictionMetadataInterface
{
    /**
     * @param iterable<PropertySchemaRestrictionMetadataInterface> $restrictionsMetadata
     */
    public function __construct(private readonly iterable $restrictionsMetadata = [])
    {
    }

    /**
     * {@inheritdoc}
     *
     * @param Collection $constraint
     */
    public function create(Constraint $constraint, ApiProperty $propertyMetadata): array
    {
        $restriction = [
            'type' => 'object',
            'properties' => new \ArrayObject(),
            'additionalProperties' => $constraint->allowExtraFields,
        ];
        $required = [];

        foreach ($constraint->fields as $field => $baseConstraint) {
            /** @var Required|Optional $baseConstraint */
            if ($baseConstraint instanceof Required && !$constraint->allowMissingFields) {
                $required[] = $field;
            }

            $restriction['properties'][$field] = $this->mergeConstraintRestrictions($baseConstraint, $propertyMetadata);
        }

        if ($required) {
            $restriction['required'] = $required;
        }

        return $restriction;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Constraint $constraint, ApiProperty $propertyMetadata): bool
    {
        return $constraint instanceof Collection;
    }

    private function mergeConstraintRestrictions(Required|Optional $constraint, ApiProperty $propertyMetadata): array|\ArrayObject
    {
        $propertyRestrictions = [];
        $nestedConstraints = $constraint->constraints;

        foreach ($nestedConstraints as $nestedConstraint) {
            foreach ($this->restrictionsMetadata as $restrictionMetadata) {
                if ($restrictionMetadata->supports($nestedConstraint, $propertyMetadata->withExtraProperties($propertyMetadata->getExtraProperties() + ['nested_schema' => true])) && !empty($nestedConstraintRestriction = $restrictionMetadata->create($nestedConstraint, $propertyMetadata))) {
                    $propertyRestrictions[] = $nestedConstraintRestriction;
                }
            }
        }

        return array_merge([], ...$propertyRestrictions) ?: new \ArrayObject();
    }
}
