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
    public function testFactoryProducesPhpSessionPersistenceServiceWithDefaultsInAbsenceOfConfig(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $factory   = new PhpSessionPersistenceFactory();

        // test php-session-persistence with missing config
        $container
            ->expects($this->once())
            ->method('has')
            ->with('config')
            ->willReturn(false);

        $persistence = $factory($container);
        $this->assertInstanceOf(PhpSessionPersistence::class, $persistence);
        $this->assertFalse($persistence->isNonLocking());
        $this->assertFalse($persistence->isDeleteCookieOnEmptySession());
    }

    public function configProvider(): iterable
    {
        yield 'non_locking disabled' => [
            'config'       => ['non_locking' => false],
            'expected'     => false,
            'methodToTest' => 'isNonLocking',
        ];
        yield 'non_locking enabled' => [
            'config'       => ['non_locking' => true],
            'expected'     => true,
            'methodToTest' => 'isNonLocking',
        ];
        yield 'delete_cookie_on_empty_session disabled' => [
            'config'       => ['delete_cookie_on_empty_session' => false],
            'expected'     => false,
            'methodToTest' => 'isDeleteCookieOnEmptySession',
        ];
        yield 'delete_cookie_on_empty_session enabled' => [
            'config'       => ['delete_cookie_on_empty_session' => true],
            'expected'     => true,
            'methodToTest' => 'isDeleteCookieOnEmptySession',
        ];
    }

    /**
     * @dataProvider configProvider
     */
    public function testFactoryConfigProducesPhpSessionPersistenceInterfaceService(
        array $config,
        bool $expected,
        string $methodToTest
    ): void {
        $container = $this->createMock(ContainerInterface::class);
        $factory   = new PhpSessionPersistenceFactory();

        // test php-session-persistence with missing config
        $container
            ->expects($this->once())
            ->method('has')
            ->with('config')
            ->willReturn(true);
        $container
            ->expects($this->once())
            ->method('get')
            ->with('config')
            ->willReturn([
                'session' => [
                    'persistence' => [
                        'ext' => $config,
                    ],
                ],
            ]);

        $persistence = $factory($container);
        $this->assertSame($expected, $persistence->$methodToTest());
    }
}
