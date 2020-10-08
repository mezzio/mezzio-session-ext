<?php

/**
 * @see       https://github.com/mezzio/mezzio-session-ext for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-session-ext/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-session-ext/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Session\Ext;

use Mezzio\Session\SessionPersistenceInterface;
use Zend\Expressive\Session\Ext\PhpSessionPersistence as LegacyPhpSessionPersistence;
use Zend\Expressive\Session\SessionPersistenceInterface as LegacySessionPersistenceInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    public function getDependencies(): array
    {
        return [
            'aliases'   => [
                SessionPersistenceInterface::class => PhpSessionPersistence::class,

                // Legacy Zend Framework aliases
                LegacySessionPersistenceInterface::class => SessionPersistenceInterface::class,
                LegacyPhpSessionPersistence::class       => PhpSessionPersistence::class,
            ],
            'factories' => [
                PhpSessionPersistence::class => PhpSessionPersistenceFactory::class,
            ],
        ];
    }
}
