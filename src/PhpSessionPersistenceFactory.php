<?php

declare(strict_types=1);

namespace Mezzio\Session\Ext;

use ArrayAccess;
use Psr\Container\ContainerInterface;

use function assert;
use function is_array;

/**
 * Create and return an instance of PhpSessionPersistence.
 *
 * In order to use non-locking sessions please provide a configuration entry
 * like the following:
 *
 * <code>
 * //...
 * 'session' => [
 *     'persistence' => [
 *         'ext' => [
 *             'non_locking' => true, // bool
 *             'delete_cookie_on_empty_session' => false, // bool
 *         ],
 *     ],
 * ],
 * //...
 * <code>
 *
 * @final
 */
class PhpSessionPersistenceFactory
{
    public function __invoke(ContainerInterface $container): PhpSessionPersistence
    {
        $config = $container->has('config') ? $container->get('config') : [];
        assert(is_array($config) || $config instanceof ArrayAccess);
        $session = isset($config['session']) && is_array($config['session']) ? $config['session'] : [];

        return new PhpSessionPersistence($session);
    }
}
