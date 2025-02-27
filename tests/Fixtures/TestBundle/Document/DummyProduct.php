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

namespace ApiPlatform\Tests\Fixtures\TestBundle\Document;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * Dummy Product.
 *
 * https://github.com/api-platform/core/issues/1107.
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
#[ApiResource]
#[ApiResource(uriTemplate: '/dummy_products/{id}/related_products.{_format}', uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])], status: 200, operations: [new GetCollection()])]
#[ODM\Document]
class DummyProduct
{
    /**
     * @var int The id
     */
    #[ODM\Id(strategy: 'INCREMENT', type: 'int')]
    private ?int $id = null;
    #[ODM\ReferenceMany(targetDocument: DummyAggregateOffer::class, mappedBy: 'product', cascade: ['persist'])]
    private \Doctrine\Common\Collections\Collection $offers;
    /**
     * @var string The tour name
     */
    #[ODM\Field]
    private $name;
    #[ODM\ReferenceMany(targetDocument: self::class, mappedBy: 'parent')]
    private \Doctrine\Common\Collections\Collection $relatedProducts;
    #[ODM\ReferenceOne(targetDocument: self::class, inversedBy: 'relatedProducts')]
    private $parent;

    public function __construct()
    {
        $this->offers = new ArrayCollection();
        $this->relatedProducts = new ArrayCollection();
    }

    public function getOffers(): Collection
    {
        return $this->offers;
    }

    public function setOffers($offers)
    {
        $this->offers = $offers;
    }

    public function addOffer(DummyAggregateOffer $offer)
    {
        $this->offers->add($offer);
        $offer->setProduct($this);
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getRelatedProducts(): Collection
    {
        return $this->relatedProducts;
    }

    public function setRelatedProducts(iterable $relatedProducts): void
    {
        $this->relatedProducts = $relatedProducts;
    }

    public function addRelatedProduct(self $relatedProduct)
    {
        $this->relatedProducts->add($relatedProduct);
        $relatedProduct->setParent($this);
    }

    public function getParent()
    {
        return $this->parent;
    }

    public function setParent(self $product)
    {
        $this->parent = $product;
    }
}
