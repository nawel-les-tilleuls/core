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

namespace ApiPlatform\Tests\Symfony\Validator\Exception;

use ApiPlatform\Exception\RuntimeException;
use ApiPlatform\Symfony\Validator\Exception\ValidationException;
use ApiPlatform\Validator\Exception\ValidationException as MainValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class ValidationExceptionTest extends TestCase
{
    public function testToString()
    {
        $e = new ValidationException(new ConstraintViolationList([
            new ConstraintViolation('message 1', '', [], '', '', 'invalid'),
            new ConstraintViolation('message 2', '', [], '', 'foo', 'invalid'),
        ]));
        $this->assertInstanceOf(MainValidationException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);

        $this->assertEquals(str_replace(\PHP_EOL, "\n", <<<TXT
message 1
foo: message 2
TXT
        ), $e->__toString());
    }
}
