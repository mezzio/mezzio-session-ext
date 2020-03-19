<?php

/**
 * @see       https://github.com/mezzio/mezzio-session-ext for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-session-ext/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-session-ext/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Session\Ext;

use Mezzio\Session\Ext\PhpSessionPersistence;
use Mezzio\Session\Ext\PhpSessionPersistenceFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class PhpSessionPersistenceFactoryTest extends TestCase
{
    public function testFactoryConfigProducesPhpSessionPersistenceInterfaceService() : void
    {
        $container = $this->prophesize(ContainerInterface::class);
        $factory = new PhpSessionPersistenceFactory();

        // test php-session-persistence with missing config
        $container->has('config')->willReturn(false);
        $persistence = $factory($container->reveal());
        $this->assertInstanceOf(PhpSessionPersistence::class, $persistence);
        $this->assertFalse($persistence->isNonLocking());

        // test php-session-persistence with non-locking config set to false and true
        foreach ([false, true] as $nonLocking) {
            $container->has('config')->willReturn(true);
            $container->get('config')->willReturn([
                'session' => [
                    'persistence' => [
                        'ext' => [
                            'non_locking' => $nonLocking,
                        ],
                    ],
                ],
            ]);
            $persistence = $factory($container->reveal());
            $this->assertSame($nonLocking, $persistence->isNonLocking());
        }
    }
}
