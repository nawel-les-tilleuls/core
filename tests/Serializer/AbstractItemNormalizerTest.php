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

namespace ApiPlatform\Tests\Serializer;

use ApiPlatform\Api\IriConverterInterface;
use ApiPlatform\Api\ResourceClassResolverInterface;
use ApiPlatform\Exception\InvalidArgumentException;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Property\PropertyNameCollection;
use ApiPlatform\Serializer\AbstractItemNormalizer;
use ApiPlatform\Symfony\Security\ResourceAccessCheckerInterface;
use ApiPlatform\Tests\Fixtures\TestBundle\Entity\Dummy;
use ApiPlatform\Tests\Fixtures\TestBundle\Entity\DummyForAdditionalFields;
use ApiPlatform\Tests\Fixtures\TestBundle\Entity\DummyForAdditionalFieldsInput;
use ApiPlatform\Tests\Fixtures\TestBundle\Entity\RelatedDummy;
use ApiPlatform\Tests\Fixtures\TestBundle\Entity\SecuredDummy;
use ApiPlatform\Tests\ProphecyTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\NameConverter\AdvancedNameConverterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @author Amrouche Hamza <hamza.simperfit@gmail.com>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class AbstractItemNormalizerTest extends TestCase
{
    use ExpectDeprecationTrait;
    use ProphecyTrait;

    public function testSupportNormalizationAndSupportDenormalization()
    {
        $std = new \stdClass();
        $dummy = new Dummy();

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);
        $resourceClassResolverProphecy->isResourceClass(\stdClass::class)->willReturn(false);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);

        $this->assertTrue($normalizer->supportsNormalization($dummy));
        $this->assertFalse($normalizer->supportsNormalization($std));
        $this->assertTrue($normalizer->supportsDenormalization($dummy, Dummy::class));
        $this->assertFalse($normalizer->supportsDenormalization($std, \stdClass::class));
        $this->assertTrue($normalizer->hasCacheableSupportsMethod());
        $this->assertFalse($normalizer->supportsNormalization([]));
    }

    public function testNormalize()
    {
        $relatedDummy = new RelatedDummy();

        $dummy = new Dummy();
        $dummy->setName('foo');
        $dummy->setAlias('ignored');
        $dummy->setRelatedDummy($relatedDummy);
        $dummy->relatedDummies->add(new RelatedDummy());

        $relatedDummies = new ArrayCollection([$relatedDummy]);

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['name', 'alias', 'relatedDummy', 'relatedDummies']));

        $relatedDummyType = new Type(Type::BUILTIN_TYPE_OBJECT, false, RelatedDummy::class);
        $relatedDummiesType = new Type(Type::BUILTIN_TYPE_OBJECT, false, ArrayCollection::class, true, new Type(Type::BUILTIN_TYPE_INT), $relatedDummyType);

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'name', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(true));
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'alias', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(true));
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummy', [])->willReturn((new ApiProperty())->withBuiltinTypes([$relatedDummyType])->withDescription('')->withReadable(true)->withWritable(false)->withReadableLink(false));
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummies', [])->willReturn((new ApiProperty())->withBuiltinTypes([$relatedDummiesType])->withReadable(true)->withWritable(false)->withReadableLink(false));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        $iriConverterProphecy->getIriFromResource($dummy, Argument::cetera())->willReturn('/dummies/1');
        $iriConverterProphecy->getIriFromResource($relatedDummy, Argument::cetera())->willReturn('/dummies/2');

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);
        $propertyAccessorProphecy->getValue($dummy, 'name')->willReturn('foo');
        $propertyAccessorProphecy->getValue($dummy, 'relatedDummy')->willReturn($relatedDummy);
        $propertyAccessorProphecy->getValue($dummy, 'relatedDummies')->willReturn($relatedDummies);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass($dummy, null)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass($relatedDummy, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->getResourceClass($relatedDummies, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);
        $resourceClassResolverProphecy->isResourceClass(RelatedDummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);
        $serializerProphecy->normalize('foo', null, Argument::type('array'))->willReturn('foo');
        $serializerProphecy->normalize(['/dummies/2'], null, Argument::type('array'))->willReturn(['/dummies/2']);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        if (!interface_exists(AdvancedNameConverterInterface::class) && method_exists($normalizer, 'setIgnoredAttributes')) {
            $normalizer->setIgnoredAttributes(['alias']);
        }

        $expected = [
            'name' => 'foo',
            'relatedDummy' => '/dummies/2',
            'relatedDummies' => ['/dummies/2'],
        ];
        $this->assertEquals($expected, $normalizer->normalize($dummy, null, [
            'resources' => [],
            'ignored_attributes' => ['alias'],
        ]));
    }

    public function testNormalizeWithSecuredProperty()
    {
        $dummy = new SecuredDummy();
        $dummy->setTitle('myPublicTitle');
        $dummy->setAdminOnlyProperty('secret');

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(SecuredDummy::class, [])->willReturn(new PropertyNameCollection(['title', 'adminOnlyProperty']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'title', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(true));
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'adminOnlyProperty', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(true)->withSecurity('is_granted(\'ROLE_ADMIN\')'));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        $iriConverterProphecy->getIriFromResource($dummy, Argument::cetera())->willReturn('/secured_dummies/1');

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);
        $propertyAccessorProphecy->getValue($dummy, 'title')->willReturn('myPublicTitle');
        $propertyAccessorProphecy->getValue($dummy, 'adminOnlyProperty')->willReturn('secret');

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass($dummy, null)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, SecuredDummy::class)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->isResourceClass(SecuredDummy::class)->willReturn(true);

        $resourceAccessChecker = $this->prophesize(ResourceAccessCheckerInterface::class);
        $resourceAccessChecker->isGranted(
            SecuredDummy::class,
            'is_granted(\'ROLE_ADMIN\')',
            ['object' => $dummy]
        )->willReturn(false);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);
        $serializerProphecy->normalize('myPublicTitle', null, Argument::type('array'))->willReturn('myPublicTitle');

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            $resourceAccessChecker->reveal(),
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        if (!interface_exists(AdvancedNameConverterInterface::class) && method_exists($normalizer, 'setIgnoredAttributes')) {
            $normalizer->setIgnoredAttributes(['alias']);
        }

        $expected = [
            'title' => 'myPublicTitle',
        ];
        $this->assertEquals($expected, $normalizer->normalize($dummy, null, [
            'resources' => [],
        ]));
    }

    public function testDenormalizeWithSecuredProperty()
    {
        $data = [
            'title' => 'foo',
            'adminOnlyProperty' => 'secret',
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(SecuredDummy::class, [])->willReturn(new PropertyNameCollection(['title', 'adminOnlyProperty']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'title', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'adminOnlyProperty', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(true)->withSecurity('is_granted(\'ROLE_ADMIN\')'));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, SecuredDummy::class)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->isResourceClass(SecuredDummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);

        $resourceAccessChecker = $this->prophesize(ResourceAccessCheckerInterface::class);
        $resourceAccessChecker->isGranted(
            SecuredDummy::class,
            'is_granted(\'ROLE_ADMIN\')',
            ['object' => null]
        )->willReturn(false);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            $resourceAccessChecker->reveal(),
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $actual = $normalizer->denormalize($data, SecuredDummy::class);

        $this->assertInstanceOf(SecuredDummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'title', 'foo')->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'adminOnlyProperty', 'secret')->shouldNotHaveBeenCalled();
    }

    public function testDenormalizeCreateWithDeniedPostDenormalizeSecuredProperty()
    {
        $data = [
            'title' => 'foo',
            'ownerOnlyProperty' => 'should not be set',
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(SecuredDummy::class, [])->willReturn(new PropertyNameCollection(['title', 'ownerOnlyProperty']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'title', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'ownerOnlyProperty', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(true)->withWritable(true)->withSecurityPostDenormalize('false')->withDefault(''));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, SecuredDummy::class)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->isResourceClass(SecuredDummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);

        $resourceAccessChecker = $this->prophesize(ResourceAccessCheckerInterface::class);
        $resourceAccessChecker->isGranted(
            SecuredDummy::class,
            'false',
            Argument::any()
        )->willReturn(false);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            $resourceAccessChecker->reveal(),
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $actual = $normalizer->denormalize($data, SecuredDummy::class);

        $this->assertInstanceOf(SecuredDummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'title', 'foo')->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'ownerOnlyProperty', 'should not be set')->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'ownerOnlyProperty', '')->shouldHaveBeenCalled();
    }

    public function testDenormalizeUpdateWithSecuredProperty()
    {
        $dummy = new SecuredDummy();

        $data = [
            'title' => 'foo',
            'ownerOnlyProperty' => 'secret',
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(SecuredDummy::class, [])->willReturn(new PropertyNameCollection(['title', 'ownerOnlyProperty']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'title', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'ownerOnlyProperty', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(true)->withWritable(true)->withSecurity('true'));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass($dummy, SecuredDummy::class)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, SecuredDummy::class)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->isResourceClass(SecuredDummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);

        $resourceAccessChecker = $this->prophesize(ResourceAccessCheckerInterface::class);
        $resourceAccessChecker->isGranted(
            SecuredDummy::class,
            'true',
            ['object' => null]
        )->willReturn(true);
        $resourceAccessChecker->isGranted(
            SecuredDummy::class,
            'true',
            ['object' => $dummy]
        )->willReturn(true);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            $resourceAccessChecker->reveal(),
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $context = [AbstractItemNormalizer::OBJECT_TO_POPULATE => $dummy];
        $actual = $normalizer->denormalize($data, SecuredDummy::class, null, $context);

        $this->assertInstanceOf(SecuredDummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'title', 'foo')->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'ownerOnlyProperty', 'secret')->shouldHaveBeenCalled();
    }

    public function testDenormalizeUpdateWithDeniedSecuredProperty()
    {
        $dummy = new SecuredDummy();
        $dummy->setOwnerOnlyProperty('secret');

        $data = [
            'title' => 'foo',
            'ownerOnlyProperty' => 'should not be set',
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(SecuredDummy::class, [])->willReturn(new PropertyNameCollection(['title', 'ownerOnlyProperty']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'title', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'ownerOnlyProperty', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(true)->withWritable(true)->withSecurity('false'));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, SecuredDummy::class)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->getResourceClass($dummy, SecuredDummy::class)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->isResourceClass(SecuredDummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);

        $resourceAccessChecker = $this->prophesize(ResourceAccessCheckerInterface::class);
        $resourceAccessChecker->isGranted(
            SecuredDummy::class,
            'false',
            ['object' => null]
        )->willReturn(false);
        $resourceAccessChecker->isGranted(
            SecuredDummy::class,
            'false',
            ['object' => $dummy]
        )->willReturn(false);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            $resourceAccessChecker->reveal(),
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $context = [AbstractItemNormalizer::OBJECT_TO_POPULATE => $dummy];
        $actual = $normalizer->denormalize($data, SecuredDummy::class, null, $context);

        $this->assertInstanceOf(SecuredDummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'title', 'foo')->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'ownerOnlyProperty', 'should not be set')->shouldNotHaveBeenCalled();
    }

    public function testDenormalizeUpdateWithDeniedPostDenormalizeSecuredProperty()
    {
        $dummy = new SecuredDummy();
        $dummy->setOwnerOnlyProperty('secret');

        $data = [
            'title' => 'foo',
            'ownerOnlyProperty' => 'should not be set',
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(SecuredDummy::class, [])->willReturn(new PropertyNameCollection(['title', 'ownerOnlyProperty']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'title', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(SecuredDummy::class, 'ownerOnlyProperty', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(true)->withWritable(true)->withSecurityPostDenormalize('false'));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);
        $propertyAccessorProphecy->getValue($dummy, 'ownerOnlyProperty')->willReturn('secret');

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass($dummy, SecuredDummy::class)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, SecuredDummy::class)->willReturn(SecuredDummy::class);
        $resourceClassResolverProphecy->isResourceClass(SecuredDummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);

        $resourceAccessChecker = $this->prophesize(ResourceAccessCheckerInterface::class);
        $resourceAccessChecker->isGranted(
            SecuredDummy::class,
            'false',
            ['object' => $dummy, 'previous_object' => $dummy]
        )->willReturn(false);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            $resourceAccessChecker->reveal(),
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $context = [AbstractItemNormalizer::OBJECT_TO_POPULATE => $dummy];
        $actual = $normalizer->denormalize($data, SecuredDummy::class, null, $context);

        $this->assertInstanceOf(SecuredDummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'title', 'foo')->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'ownerOnlyProperty', 'should not be set')->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'ownerOnlyProperty', 'secret')->shouldHaveBeenCalled();
    }

    public function testNormalizeReadableLinks()
    {
        $relatedDummy = new RelatedDummy();

        $dummy = new Dummy();
        $dummy->setRelatedDummy($relatedDummy);
        $dummy->relatedDummies->add(new RelatedDummy());

        $relatedDummies = new ArrayCollection([$relatedDummy]);

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['relatedDummy', 'relatedDummies']));

        $relatedDummyType = new Type(Type::BUILTIN_TYPE_OBJECT, false, RelatedDummy::class);
        $relatedDummiesType = new Type(Type::BUILTIN_TYPE_OBJECT, false, ArrayCollection::class, true, new Type(Type::BUILTIN_TYPE_INT), $relatedDummyType);

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummy', [])->willReturn((new ApiProperty())->withBuiltinTypes([$relatedDummyType])->withReadable(true)->withWritable(false)->withReadableLink(true));
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummies', [])->willReturn((new ApiProperty())->withBuiltinTypes([$relatedDummiesType])->withReadable(true)->withWritable(false)->withReadableLink(true));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        $iriConverterProphecy->getIriFromResource($dummy, Argument::cetera())->willReturn('/dummies/1');

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);
        $propertyAccessorProphecy->getValue($dummy, 'relatedDummy')->willReturn($relatedDummy);
        $propertyAccessorProphecy->getValue($dummy, 'relatedDummies')->willReturn($relatedDummies);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass($dummy, null)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass($relatedDummy, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->getResourceClass($relatedDummies, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);
        $resourceClassResolverProphecy->isResourceClass(RelatedDummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);
        $relatedDummyChildContext = Argument::allOf(
            Argument::type('array'),
            Argument::withEntry('resource_class', RelatedDummy::class),
            Argument::not(Argument::withKey('iri'))
        );
        $serializerProphecy->normalize($relatedDummy, null, $relatedDummyChildContext)->willReturn(['foo' => 'hello']);
        $serializerProphecy->normalize(['foo' => 'hello'], null, Argument::type('array'))->willReturn(['foo' => 'hello']);
        $serializerProphecy->normalize([['foo' => 'hello']], null, Argument::type('array'))->willReturn([['foo' => 'hello']]);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $expected = [
            'relatedDummy' => ['foo' => 'hello'],
            'relatedDummies' => [['foo' => 'hello']],
        ];
        $this->assertEquals($expected, $normalizer->normalize($dummy, null, [
            'resources' => [],
        ]));
    }

    public function testDenormalize()
    {
        $data = [
            'name' => 'foo',
            'relatedDummy' => '/dummies/1',
            'relatedDummies' => ['/dummies/2'],
        ];

        $relatedDummy1 = new RelatedDummy();
        $relatedDummy2 = new RelatedDummy();

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['name', 'relatedDummy', 'relatedDummies']));

        $relatedDummyType = new Type(Type::BUILTIN_TYPE_OBJECT, false, RelatedDummy::class);
        $relatedDummiesType = new Type(Type::BUILTIN_TYPE_OBJECT, false, ArrayCollection::class, true, new Type(Type::BUILTIN_TYPE_INT), $relatedDummyType);

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'name', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(false)->withWritable(true));

        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummy', [])->willReturn((new ApiProperty())->withBuiltinTypes([$relatedDummyType])->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(false));
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummies', [])->willReturn((new ApiProperty())->withBuiltinTypes([$relatedDummiesType])->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(false));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        $iriConverterProphecy->getResourceFromIri('/dummies/1', Argument::type('array'))->willReturn($relatedDummy1);
        $iriConverterProphecy->getResourceFromIri('/dummies/2', Argument::type('array'))->willReturn($relatedDummy2);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->isResourceClass(RelatedDummy::class)->willReturn(true);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $actual = $normalizer->denormalize($data, Dummy::class);

        $this->assertInstanceOf(Dummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'name', 'foo')->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'relatedDummy', $relatedDummy1)->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'relatedDummies', [$relatedDummy2])->shouldHaveBeenCalled();
    }

    public function testCanDenormalizeInputClassWithDifferentFieldsThanResourceClass()
    {
        // $data = [
        //     'dummyName' => 'Dummy Name',
        // ];
        //
        // $context = [
        //     'resource_class' => DummyForAdditionalFields::class,
        //     'input' => ['class' => DummyForAdditionalFieldsInput::class],
        //     'output' => ['class' => DummyForAdditionalFields::class],
        // ];
        // $augmentedContext = $context + ['api_denormalize' => true];
        //
        // $preHydratedDummy = new DummyForAdditionalFieldsInput('Name Dummy');
        // $cleanedContext = array_diff_key($augmentedContext, [
        //     'input' => null,
        //     'resource_class' => null,
        // ]);
        // $cleanedContextWithObjectToPopulate = array_merge($cleanedContext, [
        //     AbstractObjectNormalizer::OBJECT_TO_POPULATE => $preHydratedDummy,
        //     AbstractObjectNormalizer::DEEP_OBJECT_TO_POPULATE => true,
        // ]);
        //
        // $dummyInputDto = new DummyForAdditionalFieldsInput('Dummy Name');
        // $dummy = new DummyForAdditionalFields('Dummy Name', 'name-dummy');
        //
        // $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        //
        // $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        //
        // $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        //
        // $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        // $resourceClassResolverProphecy->getResourceClass(null, DummyForAdditionalFields::class)->willReturn(DummyForAdditionalFields::class);
        //
        // $inputDataTransformerProphecy = $this->prophesize(DataTransformerInitializerInterface::class);
        // $inputDataTransformerProphecy->willImplement(DataTransformerInitializerInterface::class);
        // $inputDataTransformerProphecy->initialize(DummyForAdditionalFieldsInput::class, $cleanedContext)->willReturn($preHydratedDummy);
        // $inputDataTransformerProphecy->supportsTransformation($data, DummyForAdditionalFields::class, $augmentedContext)->willReturn(true);
        // $inputDataTransformerProphecy->transform($dummyInputDto, DummyForAdditionalFields::class, $augmentedContext)->willReturn($dummy);
        //
        // $serializerProphecy = $this->prophesize(SerializerInterface::class);
        // $serializerProphecy->willImplement(DenormalizerInterface::class);
        // $serializerProphecy->denormalize($data, DummyForAdditionalFieldsInput::class, 'json', $cleanedContextWithObjectToPopulate)->willReturn($dummyInputDto);
        //
        // $normalizer = new class($propertyNameCollectionFactoryProphecy->reveal(), $propertyMetadataFactoryProphecy->reveal(), $iriConverterProphecy->reveal(), $resourceClassResolverProphecy->reveal(), null, null, null, [], null, null) extends AbstractItemNormalizer {
        // };
        // $normalizer->setSerializer($serializerProphecy->reveal());
        //
        // $actual = $normalizer->denormalize($data, DummyForAdditionalFields::class, 'json', $context);
        //
        // $this->assertInstanceOf(DummyForAdditionalFields::class, $actual);
        // $this->assertEquals('Dummy Name', $actual->getName());
    }

    public function testDenormalizeWritableLinks()
    {
        $data = [
            'name' => 'foo',
            'relatedDummy' => ['foo' => 'bar'],
            'relatedDummies' => [['bar' => 'baz']],
        ];

        $relatedDummy1 = new RelatedDummy();
        $relatedDummy2 = new RelatedDummy();

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['name', 'relatedDummy', 'relatedDummies']));

        $relatedDummyType = new Type(Type::BUILTIN_TYPE_OBJECT, false, RelatedDummy::class);
        $relatedDummiesType = new Type(Type::BUILTIN_TYPE_OBJECT, false, ArrayCollection::class, true, new Type(Type::BUILTIN_TYPE_INT), $relatedDummyType);

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'name', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummy', [])->willReturn((new ApiProperty())->withBuiltinTypes([$relatedDummyType])->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(true));
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummies', [])->willReturn((new ApiProperty())->withBuiltinTypes([$relatedDummiesType])->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(true));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);
        $resourceClassResolverProphecy->isResourceClass(RelatedDummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(DenormalizerInterface::class);
        $serializerProphecy->denormalize(['foo' => 'bar'], RelatedDummy::class, null, Argument::type('array'))->willReturn($relatedDummy1);
        $serializerProphecy->denormalize(['bar' => 'baz'], RelatedDummy::class, null, Argument::type('array'))->willReturn($relatedDummy2);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $actual = $normalizer->denormalize($data, Dummy::class);

        $this->assertInstanceOf(Dummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'name', 'foo')->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'relatedDummy', $relatedDummy1)->shouldHaveBeenCalled();
        $propertyAccessorProphecy->setValue($actual, 'relatedDummies', [$relatedDummy2])->shouldHaveBeenCalled();
    }

    public function testBadRelationType()
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected IRI or nested document for attribute "relatedDummy", "integer" given.');

        $data = [
            'relatedDummy' => 22,
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['relatedDummy']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummy', [])->willReturn(
            (new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_OBJECT, false, RelatedDummy::class)])->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(false)
        );

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);
        $resourceClassResolverProphecy->isResourceClass(RelatedDummy::class)->willReturn(true);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(DenormalizerInterface::class);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $normalizer->denormalize($data, Dummy::class);
    }

    public function testInnerDocumentNotAllowed()
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Nested documents for attribute "relatedDummy" are not allowed. Use IRIs instead.');

        $data = [
            'relatedDummy' => [
                'foo' => 'bar',
            ],
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['relatedDummy']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummy', [])->willReturn(
            (new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_OBJECT, false, RelatedDummy::class)])->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(false)
        );

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->isResourceClass(RelatedDummy::class)->willReturn(true);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(DenormalizerInterface::class);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $normalizer->denormalize($data, Dummy::class);
    }

    public function testBadType()
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The type of the "foo" attribute must be "float", "integer" given.');

        $data = [
            'foo' => 42,
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['foo']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'foo', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_FLOAT)])->withDescription('')->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(false));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(DenormalizerInterface::class);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $normalizer->denormalize($data, Dummy::class);
    }

    public function testTypeChecksCanBeDisabled()
    {
        $data = [
            'foo' => 42,
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['foo']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'foo', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_FLOAT)])->withDescription('')->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(false));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(DenormalizerInterface::class);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $actual = $normalizer->denormalize($data, Dummy::class, null, ['disable_type_enforcement' => true]);

        $this->assertInstanceOf(Dummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'foo', 42)->shouldHaveBeenCalled();
    }

    public function testJsonAllowIntAsFloat()
    {
        $data = [
            'foo' => 42,
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['foo']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'foo', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_FLOAT)])->withDescription('')->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(false));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(DenormalizerInterface::class);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $actual = $normalizer->denormalize($data, Dummy::class, 'jsonfoo');

        $this->assertInstanceOf(Dummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'foo', 42)->shouldHaveBeenCalled();
    }

    public function testDenormalizeBadKeyType()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The type of the key "a" must be "int", "string" given.');

        $data = [
            'name' => 'foo',
            'relatedDummy' => [
                'foo' => 'bar',
            ],
            'relatedDummies' => [
                'a' => [
                    'bar' => 'baz',
                ],
            ],
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['relatedDummies']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'name', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummy', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_OBJECT, false, RelatedDummy::class)])->withDescription('')->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(false));

        $type = new Type(
            Type::BUILTIN_TYPE_OBJECT,
            false,
            ArrayCollection::class,
            true,
            new Type(Type::BUILTIN_TYPE_INT),
            new Type(Type::BUILTIN_TYPE_OBJECT, false, RelatedDummy::class)
        );
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummies', [])->willReturn((new ApiProperty())->withBuiltinTypes([$type])->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(true));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);
        $resourceClassResolverProphecy->isResourceClass(RelatedDummy::class)->willReturn(true);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(DenormalizerInterface::class);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $normalizer->denormalize($data, Dummy::class);
    }

    public function testNullable()
    {
        $data = [
            'name' => null,
        ];

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['name']));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'name', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_STRING, true)])->withDescription('')->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(false));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(DenormalizerInterface::class);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $actual = $normalizer->denormalize($data, Dummy::class);

        $this->assertInstanceOf(Dummy::class, $actual);

        $propertyAccessorProphecy->setValue($actual, 'name', null)->shouldHaveBeenCalled();
    }

    public function testDenormalizeBasicTypePropertiesFromXml()
    {
        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(ObjectWithBasicProperties::class, [])->willReturn(new PropertyNameCollection([
            'boolTrue1',
            'boolFalse1',
            'boolTrue2',
            'boolFalse2',
            'int1',
            'int2',
            'float1',
            'float2',
            'float3',
            'floatNaN',
            'floatInf',
            'floatNegInf',
        ]));

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'boolTrue1', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_BOOL)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'boolFalse1', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_BOOL)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'boolTrue2', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_BOOL)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'boolFalse2', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_BOOL)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'int1', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_INT)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'int2', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_INT)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'float1', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_FLOAT)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'float2', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_FLOAT)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'float3', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_FLOAT)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'floatNaN', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_FLOAT)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'floatInf', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_FLOAT)])->withDescription('')->withReadable(false)->withWritable(true));
        $propertyMetadataFactoryProphecy->create(ObjectWithBasicProperties::class, 'floatNegInf', [])->willReturn((new ApiProperty())->withBuiltinTypes([new Type(Type::BUILTIN_TYPE_FLOAT)])->withDescription('')->withReadable(false)->withWritable(true));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'boolTrue1', true)->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'boolFalse1', false)->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'boolTrue2', true)->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'boolFalse2', false)->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'int1', 4711)->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'int2', -4711)->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'float1', Argument::approximate(123.456, 0))->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'float2', Argument::approximate(-1.2344e56, 1))->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'float3', Argument::approximate(45E-6, 1))->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'floatNaN', Argument::that(static function (float $arg) {
            return is_nan($arg);
        }))->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'floatInf', \INF)->shouldBeCalled();
        $propertyAccessorProphecy->setValue(Argument::type(ObjectWithBasicProperties::class), 'floatNegInf', -\INF)->shouldBeCalled();

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, ObjectWithBasicProperties::class)->willReturn(ObjectWithBasicProperties::class);
        $resourceClassResolverProphecy->isResourceClass(ObjectWithBasicProperties::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(NormalizerInterface::class);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $objectWithBasicProperties = $normalizer->denormalize(
            [
                'boolTrue1' => 'true',
                'boolFalse1' => 'false',
                'boolTrue2' => '1',
                'boolFalse2' => '0',
                'int1' => '4711',
                'int2' => '-4711',
                'float1' => '123.456',
                'float2' => '-1.2344e56',
                'float3' => '45E-6',
                'floatNaN' => 'NaN',
                'floatInf' => 'INF',
                'floatNegInf' => '-INF',
            ],
            ObjectWithBasicProperties::class,
            'xml'
        );

        $this->assertInstanceOf(ObjectWithBasicProperties::class, $objectWithBasicProperties);
    }

    public function testDenormalizeCollectionDecodedFromXmlWithOneChild()
    {
        $data = [
            'relatedDummies' => [
                'name' => 'foo',
            ],
        ];

        $relatedDummy = new RelatedDummy();
        $relatedDummy->setName('foo');

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Dummy::class, [])->willReturn(new PropertyNameCollection(['relatedDummies']));

        $relatedDummyType = new Type(Type::BUILTIN_TYPE_OBJECT, false, RelatedDummy::class);
        $relatedDummiesType = new Type(Type::BUILTIN_TYPE_OBJECT, false, ArrayCollection::class, true, new Type(Type::BUILTIN_TYPE_INT), $relatedDummyType);

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Dummy::class, 'relatedDummies', [])->willReturn((new ApiProperty())->withBuiltinTypes([$relatedDummiesType])->withReadable(false)->withWritable(true)->withReadableLink(false)->withWritableLink(true));

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);
        $propertyAccessorProphecy->setValue(Argument::type(Dummy::class), 'relatedDummies', Argument::type('array'))->shouldBeCalled();

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->getResourceClass(null, Dummy::class)->willReturn(Dummy::class);
        $resourceClassResolverProphecy->getResourceClass(null, RelatedDummy::class)->willReturn(RelatedDummy::class);
        $resourceClassResolverProphecy->isResourceClass(RelatedDummy::class)->willReturn(true);
        $resourceClassResolverProphecy->isResourceClass(Dummy::class)->willReturn(true);

        $serializerProphecy = $this->prophesize(SerializerInterface::class);
        $serializerProphecy->willImplement(DenormalizerInterface::class);
        $serializerProphecy->denormalize(['name' => 'foo'], RelatedDummy::class, 'xml', Argument::type('array'))->willReturn($relatedDummy);

        $normalizer = $this->getMockForAbstractClass(AbstractItemNormalizer::class, [
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $iriConverterProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            null,
            null,
            [],
            null,
            null,
        ]);
        $normalizer->setSerializer($serializerProphecy->reveal());

        $normalizer->denormalize($data, Dummy::class, 'xml');
    }
}

class ObjectWithBasicProperties
{
    /** @var bool */
    public $boolTrue1;

    /** @var bool */
    public $boolFalse1;

    /** @var bool */
    public $boolTrue2;

    /** @var bool */
    public $boolFalse2;

    /** @var int */
    public $int1;

    /** @var int */
    public $int2;

    /** @var float */
    public $float1;

    /** @var float */
    public $float2;

    /** @var float */
    public $float3;

    /** @var float */
    public $floatNaN;

    /** @var float */
    public $floatInf;

    /** @var float */
    public $floatNegInf;
}
