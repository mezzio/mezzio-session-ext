<?php

declare(strict_types=1);

namespace Mezzio\Session\Ext;

use Mezzio\Session\SessionPersistenceInterface;

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
                'Zend\Expressive\Session\SessionPersistenceInterface' => SessionPersistenceInterface::class,
                'Zend\Expressive\Session\Ext\PhpSessionPersistence'   => PhpSessionPersistence::class,
            ],
            'factories' => [
                PhpSessionPersistence::class => PhpSessionPersistenceFactory::class,
            ],
        ];
    }
}
