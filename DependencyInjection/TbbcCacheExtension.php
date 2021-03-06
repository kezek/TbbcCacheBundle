<?php
/**
 * This file is part of TbbcCacheBundle
 *
 * (c) TheBigBrainsCompany <contact@thebigbrainscompany.com>
 *
 */

namespace Tbbc\CacheBundle\DependencyInjection;

use Tbbc\CacheBundle\DependencyInjection\CacheFactory\CacheFactoryInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * Class TbbcCacheExtension
 *
 * @author Benjamin Dulau <benjamin.dulau@gmail.com>
 */
class TbbcCacheExtension extends Extension
{
    /**
     * @var array|CacheFactoryInterface[]
     */
    protected $cacheFactories;

    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $processor = new Processor();

        $cacheFactories = $this->createCacheFactories();

        // Main configuration
        $configuration = new Configuration($cacheFactories);
        $config = $processor->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('cache.xml');
        $loader->load('doctrine_caches.xml');
        $loader->load('key_generators.xml');
        $loader->load('logger.xml');

        if (true === $container->getParameter('kernel.debug')) {

            $loader->load('data_collectors.xml');
            $dataCollector = $container->getDefinition('tbbc_cache.data_collector.cacheable_operation');
            $dataCollector->replaceArgument(0, new Reference('tbbc_cache.logger.cache_logger'));
        }

        $managerId = $this->createCacheManager($config, $container);

        $container->setParameter($this->getAlias() . '.annotations.enabled', (bool)$config['annotations']['enabled']);

        if (true === (bool) $config['annotations']['enabled']) {
            $bundles = $container->getParameter('kernel.bundles');
            if (!isset($bundles['JMSAopBundle'])) {
                throw new RuntimeException('The TbbcCacheBundle requires the JMSAopBundle for using annotations, please make sure to enable it in your AppKernel.');
            }

            $loader->load('aop.xml');

            if ($config['metadata']['use_cache']) {
                $container->getDefinition("tbbc_cache.metadata.metadata_factory")
                    ->addMethodCall('setCache', array(new Reference('tbbc_cache.metadata.file_cache')))
                ;
            }

            $cacheDir = $container->getParameterBag()->resolveValue($config['metadata']['cache_dir']);
            if (!is_dir($cacheDir)) {
                if (false === @mkdir($cacheDir, 0777, true)) {
                    throw new RuntimeException(sprintf('Could not create cache directory "%s".', $cacheDir));
                }
            }
            $container->setParameter('tbbc_cache.metadata.cache_dir', $cacheDir);

            $interceptor = $container->getDefinition('tbbc_cache.aop.interceptor.cache');
            $interceptor->replaceArgument(1, new Reference($managerId));
            if (isset($config['key_generator'])) {
                if ($container->has('tbbc_cache.key_generator.' . $config['key_generator'])) {
                    $interceptor->replaceArgument(2, new Reference('tbbc_cache.key_generator.' . $config['key_generator']));
                } else {
                    $interceptor->replaceArgument(2, new Reference($config['key_generator']));
                }
            } else {
                $interceptor->replaceArgument(2, new Reference($container->getParameter('tbbc_cache.key_generator.default')));
            }
            // TODO not hardcoded
            $interceptor->replaceArgument(5, new Reference('tbbc_cache.logger.cache_logger'));
        }
    }

    private function createCacheManager(array $config, ContainerBuilder $container)
    {
        $managerId = sprintf('tbbc_cache.%s_manager', strtolower($config['manager']));
        $managerReference = $container->getDefinition($managerId);

        foreach ($config['cache'] as $name => $cache) {
            $cache['name'] = $name;
            $cacheId = $this->createCache($cache, $container);

            $managerReference->addMethodCall('addCache', array(new Reference($cacheId)));
        }

        return $managerId;
    }

    /**
     * Creates a single cache service
     *
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @return string
     */
    private function createCache(array $config, ContainerBuilder $container)
    {
        $type = $config['type'];
        if (array_key_exists($type, $this->cacheFactories)) {
            $id = sprintf('tbbc_cache.%s_cache', $config['name']);
            $this->cacheFactories[$type]->create($container, $id, $config);

            return $id;
        }

        // TODO: throw exception ?
    }

    /**
     * Creates the cache factories
     *
     * @return array|CacheFactoryInterface[]
     */
    private function createCacheFactories()
    {
        if (null !== $this->cacheFactories) {
            return $this->cacheFactories;
        }

        // load bundled cache factories
        $tempContainer = new ContainerBuilder();
        $loader = new Loader\XmlFileLoader($tempContainer, new FileLocator(__DIR__.'/../Resources/config'));

        $loader->load('cache_factories.xml');

        $cacheFactories = array();
        foreach ($tempContainer->findTaggedServiceIds('tbbc_cache.cache_factory') as $id => $factories) {
            foreach ($factories as $factory) {
                if (!isset($factory['cache_type'])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Service "%s" must define a "cache_type" attribute for "tbbc_cache.cache_factory" tag',
                        $id
                    ));
                }

                $factoryService = $tempContainer->get($id);
                $cacheFactories[str_replace('-', '_', strtolower($factory['cache_type']))] = $factoryService;
            }
        }

        return $this->cacheFactories = $cacheFactories;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        $cacheFactories = $this->createCacheFactories();

        return new Configuration($cacheFactories);
    }
}
