<?php

/**
 * @see       https://github.com/mezzio/mezzio-session-ext for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-session-ext/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-session-ext/blob/master/LICENSE.md New BSD License
 */

namespace MezzioTest\Session\Ext;

use Mezzio\Session\Ext\ConfigProvider;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    public function setUp()
    {
        $this->provider = new ConfigProvider();
    }

    public function testInvocationReturnsArray()
    {
        $config = ($this->provider)();
        $this->assertInternalType('array', $config);
        return $config;
    }

    /**
     * @depends testInvocationReturnsArray
     */
    public function testReturnedArrayContainsDependencies(array $config)
    {
        $this->assertArrayHasKey('dependencies', $config);
        $this->assertInternalType('array', $config['dependencies']);
    }
}
