<?php
/**
 * This file is part of TbbcCacheBundle
 *
 * (c) TheBigBrainsCompany <contact@thebigbrainscompany.com>
 *
 */

namespace Tbbc\CacheBundle\DependencyInjection\CacheFactory;

use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Factory for Filesystem
 *
 * @author Andrei Mocanu <kezek.ma@gmail.com>
 */
class FilesystemCacheFactory implements CacheFactoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function create(ContainerBuilder $container, $id, array $config)
    {

        $doctrineArrayId = sprintf('tbbc_cache.doctrine_cache.%s_file_instance', $config['name']);
        $cacheDir = $container->getParameter('kernel.cache_dir');
        $container
            ->setDefinition($doctrineArrayId, new DefinitionDecorator('tbbc_cache.doctrine_cache.file'))
            ->addArgument($cacheDir . DIRECTORY_SEPARATOR . $config['path'])
            ->setPublic(false)
        ;

        $container
            ->setDefinition($id, new DefinitionDecorator('tbbc_cache.cache.doctrine_proxy'))
            ->addArgument($config['name'])
            ->addArgument(new Reference($doctrineArrayId))
        ;
    }

    /**
     * {@inheritDoc}
     */
    public function getKey()
    {
        return 'filesystem';
    }

    /**
     * {@inheritDoc}
     */
    public function addConfiguration(NodeBuilder $node)
    {
        $node
            ->scalarNode('ttl')->defaultNull()->end()
            ->scalarNode('path')->isRequired()->cannotBeEmpty()->end()
            ->end();
    }
}
