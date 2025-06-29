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

namespace ApiPlatform\Symfony\Bundle\SwaggerUi;

use ApiPlatform\Metadata\Exception\RuntimeException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\OpenApi\OpenApi;
use ApiPlatform\OpenApi\Options;
use ApiPlatform\OpenApi\Serializer\NormalizeOperationNameTrait;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * @internal
 */
final class SwaggerUiProcessor implements ProcessorInterface
{
    use NormalizeOperationNameTrait;

    public function __construct(private readonly ?TwigEnvironment $twig, private readonly UrlGeneratorInterface $urlGenerator, private readonly NormalizerInterface $normalizer, private readonly Options $openApiOptions, private readonly SwaggerUiContext $swaggerUiContext, private readonly array $formats = [], private readonly ?string $oauthClientId = null, private readonly ?string $oauthClientSecret = null, private readonly bool $oauthPkce = false)
    {
        if (null === $this->twig) {
            throw new \RuntimeException('The documentation cannot be displayed since the Twig bundle is not installed. Try running "composer require symfony/twig-bundle".');
        }
    }

    /**
     * @param OpenApi $openApi
     */
    public function process(mixed $openApi, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $request = $context['request'] ?? null;

        $swaggerContext = [
            'formats' => $this->formats,
            'title' => $openApi->getInfo()->getTitle(),
            'description' => $openApi->getInfo()->getDescription(),
            'showWebby' => $this->swaggerUiContext->isWebbyShown(),
            'swaggerUiEnabled' => $this->swaggerUiContext->isSwaggerUiEnabled(),
            'reDocEnabled' => $this->swaggerUiContext->isRedocEnabled(),
            'graphQlEnabled' => $this->swaggerUiContext->isGraphQlEnabled(),
            'graphiQlEnabled' => $this->swaggerUiContext->isGraphiQlEnabled(),
            'assetPackage' => $this->swaggerUiContext->getAssetPackage(),
            'originalRoute' => $request->attributes->get('_api_original_route', $request->attributes->get('_route')),
            'originalRouteParams' => $request->attributes->get('_api_original_route_params', $request->attributes->get('_route_params', [])),
        ];

        $swaggerData = [
            'url' => $this->urlGenerator->generate('api_doc', ['format' => 'json']),
            'spec' => $this->normalizer->normalize($openApi, 'json', []),
            'persistAuthorization' => $this->openApiOptions->hasPersistAuthorization(),
            'oauth' => [
                'enabled' => $this->openApiOptions->getOAuthEnabled(),
                'type' => $this->openApiOptions->getOAuthType(),
                'flow' => $this->openApiOptions->getOAuthFlow(),
                'tokenUrl' => $this->openApiOptions->getOAuthTokenUrl(),
                'authorizationUrl' => $this->openApiOptions->getOAuthAuthorizationUrl(),
                'scopes' => $this->openApiOptions->getOAuthScopes(),
                'clientId' => $this->oauthClientId,
                'clientSecret' => $this->oauthClientSecret,
                'pkce' => $this->oauthPkce,
            ],
            'extraConfiguration' => $this->swaggerUiContext->getExtraConfiguration(),
        ];

        $status = 200;
        $requestedOperation = $request?->attributes->get('_api_requested_operation') ?? null;
        if ($request->isMethodSafe() && $requestedOperation && $requestedOperation->getName()) {
            // TODO: what if the parameter is named something else then `id`?
            $swaggerData['id'] = ($request->attributes->get('_api_original_uri_variables') ?? [])['id'] ?? null;
            $swaggerData['queryParameters'] = $request->query->all();

            $swaggerData['shortName'] = $requestedOperation->getShortName();
            $swaggerData['operationId'] = $this->normalizeOperationName($requestedOperation->getName());

            [$swaggerData['path'], $swaggerData['method']] = $this->getPathAndMethod($swaggerData);
            $status = $requestedOperation->getStatus() ?? $status;
        }

        return new Response($this->twig->render('@ApiPlatform/SwaggerUi/index.html.twig', $swaggerContext + ['swagger_data' => $swaggerData]), $status);
    }

    /**
     * @param array<string, mixed> $swaggerData
     */
    private function getPathAndMethod(array $swaggerData): array
    {
        foreach ($swaggerData['spec']['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (($operation['operationId'] ?? null) === $swaggerData['operationId']) {
                    return [$path, $method];
                }
            }
        }

        throw new RuntimeException(\sprintf('The operation "%s" cannot be found in the Swagger specification.', $swaggerData['operationId']));
    }
}
