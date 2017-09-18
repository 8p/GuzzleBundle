<?php

namespace EightPoints\Bundle\GuzzleBundle\DependencyInjection;

use EightPoints\Bundle\GuzzleBundle\Log\DevNullLogger;
use GuzzleHttp\HandlerStack;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\ExpressionLanguage\Expression;

/**
 * @version   1.0
 * @since     2013-10
 */
class EightPointsGuzzleExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function getConfiguration(array $config, ContainerBuilder $container) : Configuration
    {
        return new Configuration($this->getAlias(), $container->getParameter('kernel.debug'));
    }

    /**
     * Loads the Guzzle configuration.
     *
     * @version 1.0
     * @since   2013-10
     *
     * @param   array            $configs   an array of configuration settings
     * @param   ContainerBuilder $container a ContainerBuilder instance
     *
     * @throws  \InvalidArgumentException
     * @throws  \Symfony\Component\DependencyInjection\Exception\BadMethodCallException
     * @throws  \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     * @throws  \Exception
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configPath = implode(DIRECTORY_SEPARATOR, [__DIR__, '..', 'Resources', 'config']);
        $loader     = new XmlFileLoader($container, new FileLocator($configPath));

        $loader->load('services.xml');

        $configuration = new Configuration($this->getAlias(), $container->getParameter('kernel.debug'));
        $config        = $this->processConfiguration($configuration, $configs);

        $this->createLogger($config, $container);

        foreach ($config['clients'] as $name => $options) {
            $argument = [
                'base_uri' => $options['base_url'],
                'handler'  => $this->createHandler($container, $name, $options)
            ];

            // if present, add default options to the constructor argument for the Guzzle client
            if (isset($options['options']) && is_array($options['options'])) {
                foreach ($options['options'] as $key => $value) {
                    if ($value === null || (is_array($value) && count($value) === 0)) {
                        continue;
                    }

                    $argument[$key] = $value;
                }
            }

            $client = new Definition($options['class']);
            $client->addArgument($argument);

            // set service name based on client name
            $serviceName = sprintf('%s.client.%s', $this->getAlias(), $name);
            $container->setDefinition($serviceName, $client);
        }
    }

    /**
     * @since  2015-07
     *
     * @param  ContainerBuilder $container
     * @param  string           $name
     * @param  array            $config
     *
     * @return Definition
     * @throws \Symfony\Component\DependencyInjection\Exception\BadMethodCallException
     * @throws \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     */
    protected function createHandler(ContainerBuilder $container, string $name, array $config) : Definition
    {
        $logServiceName = sprintf('guzzle_bundle.middleware.log.%s', $name);
        $log = $this->createLogMiddleware();
        $container->setDefinition($logServiceName, $log);

        // Event Dispatching service
        $eventServiceName = sprintf('guzzle_bundle.middleware.event_dispatch.%s', $name);
        $eventService = $this->createEventMiddleware($name);
        $container->setDefinition($eventServiceName, $eventService);

        $logExpression    = new Expression(sprintf("service('%s').log()", $logServiceName));
        // Create the event Dispatch Middleware
        $eventExpression  = new Expression(sprintf("service('%s').dispatchEvent()", $eventServiceName));

        $handler = new Definition(HandlerStack::class);
        $handler->setFactory([HandlerStack::class, 'create']);

        // Plugins
        if (isset($config['plugin'])) {
            // Wsse if required
            if (isset($config['plugin']['wsse'])
                && $config['plugin']['wsse']['username']
                && $config['plugin']['wsse']['password']) {

                $wsseConfig = $config['plugin']['wsse'];
                unset($config['plugin']['wsse']);

                $username = $wsseConfig['username'];
                $password = $wsseConfig['password'];
                $createdAtExpression = null;
                
                if (isset($wsseConfig['created_at']) && $wsseConfig['created_at']) {
                    $createdAtExpression = $wsseConfig['created_at'];
                }

                $wsse = $this->createWsseMiddleware($username, $password, $createdAtExpression);
                $wsseServiceName = sprintf('guzzle_bundle.middleware.wsse.%s', $name);

                $container->setDefinition($wsseServiceName, $wsse);

                $wsseExpression = new Expression(sprintf('service("%s").attach()', $wsseServiceName));

                $handler->addMethodCall('push', [$wsseExpression]);
            }
        }

        $handler->addMethodCall('push', [$logExpression]);
        // goes on the end of the stack.
        $handler->addMethodCall('unshift', [$eventExpression]);

        return $handler;
    }

    /**
     * Create Logger
     *
     * @since  2015-07
     *
     * @param  array            $config
     * @param  ContainerBuilder $container
     *
     * @return Definition
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\BadMethodCallException
     */
    protected function createLogger(array $config, ContainerBuilder $container) : Definition
    {

        if ($config['logging'] === true) {
            $logger = new Definition('%guzzle_bundle.logger.class%');
        } else {
            $logger = new Definition(DevNullLogger::class);
        }

        $container->setDefinition('guzzle_bundle.logger', $logger);

        return $logger;
    }

    /**
     * Create Middleware for Logging
     *
     * @since  2015-07
     *
     * @return Definition
     */
    protected function createLogMiddleware() : Definition
    {
        $log = new Definition('%guzzle_bundle.middleware.log.class%');
        $log->addArgument(new Reference('guzzle_bundle.logger'));
        $log->addArgument(new Reference('guzzle_bundle.formatter'));

        return $log;
    }

    /**
     * Create Middleware For dispatching events
     *
     * @since  2015-09
     *
     * @param  string $name
     *
     * @return Definition
     */
    protected function createEventMiddleware(string $name) : Definition
    {
        $eventMiddleWare = new Definition('%guzzle_bundle.middleware.event_dispatcher.class%');
        $eventMiddleWare->addArgument(new Reference('event_dispatcher'));
        $eventMiddleWare->addArgument($name);

        return $eventMiddleWare;
    }

    /**
     * Create Middleware for WSSE
     *
     * @since  2015-07
     *
     * @param  string  $username
     * @param  string  $password
     * @param  string  $createdAtExpression
     *
     * @return Definition
     */
    protected function createWsseMiddleware($username, $password, $createdAtExpression = null) : Definition
    {
        $wsse = new Definition('%guzzle_bundle.middleware.wsse.class%');
        $wsse->setArguments([$username, $password]);

        if ($createdAtExpression) {
            $wsse->addMethodCall('setCreatedAtTimeExpression', [$createdAtExpression]);
        }

        return $wsse;
    }

    /**
     * Returns alias of extension
     *
     * @version 1.1
     * @since   2013-12
     *
     * @return  string
     */
    public function getAlias() : string
    {
        return 'eight_points_guzzle';
    }
}