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

namespace ApiPlatform\Metadata\Tests\Extractor;

use ApiPlatform\JsonSchema\Metadata\Property\Factory\SchemaPropertyMetadataFactory;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Extractor\XmlPropertyExtractor;
use ApiPlatform\Metadata\Extractor\YamlPropertyExtractor;
use ApiPlatform\Metadata\Property\Factory\ExtractorPropertyMetadataFactory;
use ApiPlatform\Metadata\Tests\Extractor\Adapter\PropertyAdapterInterface;
use ApiPlatform\Metadata\Tests\Extractor\Adapter\XmlPropertyAdapter;
use ApiPlatform\Metadata\Tests\Extractor\Adapter\YamlPropertyAdapter;
use ApiPlatform\Metadata\Tests\Fixtures\ApiResource\Comment;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\TypeInfo\Type;

/**
 * Ensures XML and YAML mappings are fully compatible with ApiPlatform\Metadata\ApiProperty.
 *
 * @author Vincent Chalamon <vincentchalamon@gmail.com>
 */
final class PropertyMetadataCompatibilityTest extends TestCase
{
    private const RESOURCE_CLASS = Comment::class;
    private const PROPERTY = 'comment';
    private const FIXTURES = [
        'description' => 'Comment message',
        'readable' => true,
        'writable' => true,
        'readableLink' => true,
        'writableLink' => true,
        'default' => 'Plop',
        'deprecationReason' => 'Foo',
        'schema' => ['https://schema.org/Thing'],
        'required' => true,
        'identifier' => false,
        'example' => 'Lorem ipsum dolor sit amet',
        'fetchable' => true,
        'fetchEager' => true,
        'jsonldContext' => [
            'bar' => [
                'foo' => [
                    'bar' => 'baz',
                ],
            ],
        ],
        'openapiContext' => [
            'foo' => 'bar',
        ],
        'jsonSchemaContext' => [
            'lorem' => 'ipsum',
        ],
        'push' => true,
        'security' => 'is_granted(\'IS_AUTHENTICATED_ANONYMOUSLY\')',
        'securityPostDenormalize' => 'is_granted(\'ROLE_CUSTOM_ADMIN\')',
        'types' => ['someirischema', 'anotheririschema'],
        'initializable' => true,
        'extraProperties' => [
            'custom_property' => 'Lorem ipsum dolor sit amet',
            SchemaPropertyMetadataFactory::JSON_SCHEMA_USER_DEFINED => true,
        ],
        'iris' => ['https://schema.org/totalPrice'],
        'genId' => true,
        'uriTemplate' => '/sub-resource-get-collection',
        'property' => 'test',
        'hydra' => false,
        'nativeType' => 'string',
    ];

    #[\PHPUnit\Framework\Attributes\DataProvider('getExtractors')]
    public function testValidMetadata(string $extractorClass, PropertyAdapterInterface $adapter): void
    {
        $reflClass = new \ReflectionClass(ApiProperty::class);
        $parameters = $reflClass->getConstructor()->getParameters();

        try {
            $extractor = new $extractorClass($adapter(self::RESOURCE_CLASS, self::PROPERTY, $parameters, self::FIXTURES));
            $factory = new ExtractorPropertyMetadataFactory($extractor);
            $property = $factory->create(self::RESOURCE_CLASS, self::PROPERTY);
        } catch (\Exception $exception) {
            throw new AssertionFailedError('Failed asserting that the schema is valid according to '.ApiProperty::class, 0, $exception);
        }

        $this->assertEquals($this->buildApiProperty(), $property);
    }

    public static function getExtractors(): array
    {
        return [
            [XmlPropertyExtractor::class, new XmlPropertyAdapter()],
            [YamlPropertyExtractor::class, new YamlPropertyAdapter()],
        ];
    }

    private function buildApiProperty(): ApiProperty
    {
        $property = new ApiProperty();

        foreach (self::FIXTURES as $parameter => $value) {
            if (method_exists($this, 'with'.ucfirst($parameter))) {
                $value = $this->{'with'.ucfirst($parameter)}($value, self::FIXTURES);
            }

            if (method_exists($property, 'with'.ucfirst($parameter))) {
                $property = $property->{'with'.ucfirst($parameter)}($value, self::FIXTURES);
                continue;
            }

            throw new \RuntimeException(\sprintf('Unknown ApiProperty parameter "%s".', $parameter));
        }

        return $property;
    }

    private function withNativeType(string $value): Type
    {
        return Type::builtin($value);
    }
}
