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

namespace ApiPlatform\Serializer;

use ApiPlatform\Api\ResourceClassResolverInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\Pagination\PartialPaginatorInterface;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Base collection normalizer.
 *
 * @author Baptiste Meyer <baptiste.meyer@gmail.com>
 */
abstract class AbstractCollectionNormalizer implements NormalizerInterface, NormalizerAwareInterface, CacheableSupportsMethodInterface
{
    use ContextTrait {
        initContext as protected;
    }
    use NormalizerAwareTrait;

    /**
     * This constant must be overridden in the child class.
     */
    public const FORMAT = 'to-override';

    protected $resourceClassResolver;
    protected $pageParameterName;

    protected $resourceMetadataFactory;

    public function __construct(ResourceClassResolverInterface $resourceClassResolver, string $pageParameterName, ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory = null)
    {
        $this->resourceClassResolver = $resourceClassResolver;
        $this->pageParameterName = $pageParameterName;
        $this->resourceMetadataFactory = $resourceMetadataFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null, array $context = []): bool
    {
        return static::FORMAT === $format && is_iterable($data);
    }

    /**
     * {@inheritdoc}
     */
    public function hasCacheableSupportsMethod(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @param iterable $object
     *
     * @return array|string|int|float|bool|\ArrayObject|null
     */
    public function normalize($object, $format = null, array $context = [])
    {
        if (!isset($context['resource_class']) || isset($context['api_sub_level'])) {
            return $this->normalizeRawCollection($object, $format, $context);
        }

        $resourceClass = $this->resourceClassResolver->getResourceClass($object, $context['resource_class']);
        $context = $this->initContext($resourceClass, $context);
        $data = [];

        return array_merge_recursive(
            $data,
            $this->getPaginationData($object, $context),
            $this->getItemsData($object, $format, $context)
        );
    }

    /**
     * Normalizes a raw collection (not API resources).
     *
     * @param string|null $format
     * @param mixed       $object
     */
    protected function normalizeRawCollection($object, $format = null, array $context = []): array
    {
        $data = [];
        foreach ($object as $index => $obj) {
            $data[$index] = $this->normalizer->normalize($obj, $format, $context);
        }

        return $data;
    }

    /**
     * Gets the pagination configuration.
     *
     * @param iterable $object
     */
    protected function getPaginationConfig($object, array $context = []): array
    {
        $currentPage = $lastPage = $itemsPerPage = $pageTotalItems = $totalItems = null;
        $paginated = $paginator = false;

        if ($object instanceof PartialPaginatorInterface) {
            $paginated = $paginator = true;
            if ($object instanceof PaginatorInterface) {
                $paginated = 1. !== $lastPage = $object->getLastPage();
                $totalItems = $object->getTotalItems();
            } else {
                $pageTotalItems = (float) \count($object);
            }

            $currentPage = $object->getCurrentPage();
            $itemsPerPage = $object->getItemsPerPage();
        } elseif (\is_array($object) || $object instanceof \Countable) {
            $totalItems = \count($object);
        }

        return [$paginator, $paginated, $currentPage, $itemsPerPage, $lastPage, $pageTotalItems, $totalItems];
    }

    /**
     * Gets the pagination data.
     *
     * @param iterable $object
     */
    abstract protected function getPaginationData($object, array $context = []): array;

    /**
     * Gets items data.
     *
     * @param iterable $object
     */
    abstract protected function getItemsData($object, string $format = null, array $context = []): array;
}
