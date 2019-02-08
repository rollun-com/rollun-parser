<?php
/**
 * Created by PhpStorm.
 * User: victorsecuring
 * Date: 27.03.18
 * Time: 1:39 PM
 */

namespace rollun\logger\Writer\Factory;


use Interop\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use rollun\logger\Writer\Http;
use Zend\Http\Client;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Factory\FactoryInterface;

class HttpFactory implements FactoryInterface
{
    const KEY = HttpFactory::class;

    const KEY_CLIENT = "client";

    const KEY_URI = "uri";

    const KEY_OPTIONS = "options";

    /**
     * Create an object
     *
     * @param  ContainerInterface $container
     * @param  string $requestedName
     * @param  null|array $options
     * @return object
     * @throws NotFoundExceptionInterface if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     *     creating a service.
     * @throws ContainerExceptionInterface if any other error occurs
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = (array)$options;
        $client = isset($config[static::KEY_CLIENT]) ? $container->get($config[static::KEY_CLIENT]) : new Client();
        $uri = isset($config[static::KEY_URI]) ? $config[static::KEY_URI] : null;
        $options = isset($config[static::KEY_OPTIONS]) ? $config[static::KEY_OPTIONS] :  [];
        return new Http($client, $uri, $options);
    }

}