<?php

namespace Robo;

use Composer\Autoload\ClassLoader;
use League\Container\Container;
use Psr\Container\ContainerInterface;
use Robo\Common\ProcessExecutor;
use Consolidation\Config\ConfigInterface;
use Consolidation\Config\Loader\ConfigProcessor;
use Consolidation\Config\Loader\YamlConfigLoader;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Process\Process;
use Robo\Config\Config;
use Robo\Application;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Robo\Common\OutputAdapter;
use Robo\Log\RoboLogStyle;
use Robo\Log\RoboLogger;
use Symfony\Component\Console\Helper\ProgressBar;
use Robo\Common\ProgressIndicator;
use Robo\Log\ResultPrinter;
use Robo\Task\Simulator;
use Robo\GlobalOptionsEventListener;
use Consolidation\Config\Inject\ConfigForCommand;
use Robo\Collection\CollectionProcessHook;
use Consolidation\AnnotatedCommand\Options\AlterOptionsCommandEvent;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Consolidation\OutputFormatters\FormatterManager;
use Consolidation\AnnotatedCommand\Options\PrepareTerminalWidthOption;
use Robo\Symfony\SymfonyStyleInjector;
use Robo\Symfony\ConsoleIOInjector;
use Consolidation\AnnotatedCommand\ParameterInjection;
use Consolidation\AnnotatedCommand\CommandProcessor;
use Consolidation\AnnotatedCommand\Input\StdinHandler;
use Consolidation\AnnotatedCommand\AnnotatedCommandFactory;
use Robo\ClassDiscovery\RelativeNamespaceDiscovery;
use Robo\Collection\Collection;
use Robo\Collection\CollectionBuilder;
use Robo\Contract\ConfigAwareInterface;
use Psr\Log\LoggerAwareInterface;
use League\Container\ContainerAwareInterface;
use Symfony\Component\Console\Input\InputAwareInterface;
use Robo\Contract\ProgressIndicatorAwareInterface;
use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use Robo\Contract\VerbosityThresholdInterface;
use Consolidation\AnnotatedCommand\Input\StdinAwareInterface;
use Consolidation\Log\Logger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manages the container reference and other static data.  Favor
 * using dependency injection wherever possible.  Avoid using
 * this class directly, unless setting up a custom DI container.
 */
class Robo
{
    const APPLICATION_NAME = 'Robo';
    const VERSION = '3.0.4-dev';

    /**
     * The currently active container object, or NULL if not initialized yet.
     *
     * @var \Psr\Container\ContainerInterface|null
     */
    protected static $container;

    /**
     * Entrypoint for standalone Robo-based tools.  See docs/framework.md.
     *
     * @param string[] $argv
     * @param string $commandClasses
     * @param null|string $appName
     * @param null|string $appVersion
     * @param null|string $repository
     *
     * @return int
     */
    public static function run($argv, $commandClasses, $appName = null, $appVersion = null, ?OutputInterface $output = null, $repository = null): int
    {
        $runner = new Runner($commandClasses);

        $runner->setSelfUpdateRepository($repository);
        $statusCode = $runner->execute($argv, $appName, $appVersion, $output);

        return $statusCode;
    }

    /**
     * Sets a new global container.
     *
     * @param \Psr\Container\ContainerInterface $container
     *   A new container instance to replace the current.
     */
    public static function setContainer(ContainerInterface $container)
    {
        static::$container = $container;
    }

    /**
     * Unset the global container.
     */
    public static function unsetContainer()
    {
        static::$container = null;
    }

    /**
     * Returns the currently active global container.
     *
     * @throws \RuntimeException
     */
    public static function getContainer(): ContainerInterface
    {
        if (static::$container === null) {
            throw new \RuntimeException('container is not initialized yet. \Robo\Robo::setContainer() must be called with a real container.');
        }

        return static::$container;
    }

    /**
     * Returns TRUE if the container has been initialized, FALSE otherwise.
     */
    public static function hasContainer(): bool
    {
        return static::$container !== null;
    }

    /**
     * Create a config object and load it from the provided paths.
     *
     * @param string[] $paths
     */
    public static function createConfiguration(array $paths): ConfigInterface
    {
        $config = new Config();

        static::loadConfiguration($paths, $config);

        return $config;
    }

    /**
     * Use a simple config loader to load configuration values from specified paths
     *
     * @param string[] $paths
     */
    public static function loadConfiguration(array $paths, ?ConfigInterface $config = null)
    {
        if ($config == null) {
            $config = static::config();
        }
        $loader = new YamlConfigLoader();
        $processor = new ConfigProcessor();
        $processor->add($config->export());
        foreach ($paths as $path) {
            $processor->extend($loader->load($path));
        }
        $config->import($processor->export());
    }

    /**
     * Create a container for Robo application.
     *
     * After calling this method you may add any additional items you wish
     * to manage in your application. After you do that, you must call
     * Robo::finalizeContainer($container) to complete container initialization.
     *
     * @param null|\Robo\Application $app
     * @param null|\Consolidation\Config\ConfigInterface $config
     * @param null|\Composer\Autoload\ClassLoader $classLoader
     */
    public static function createContainer(
        ?Application $app = null,
        ?ConfigInterface $config = null,
        ?ClassLoader $classLoader = null
    ): ContainerInterface {
        // Do not allow this function to be called more than once.
        if (static::hasContainer()) {
            return static::getContainer();
        }

        if (!$app) {
            $app = static::createDefaultApplication();
        }

        if (!$config) {
            $config = new Config();
        }

        // $input and $output will not be stored in the container at all in the future.
        $unusedInput = new StringInput('');
        $unusedOutput = new NullOutput();

        // Set up our dependency injection container.
        $container = new Container();

        return static::configureContainer($container, $app, $config, $unusedInput, $unusedOutput, $classLoader);
    }

    /**
     * Create a container and initiailze it.  If you wish to *change*
     * anything defined in the container, then you should call
     * Robo::createContainer() and Robo::finalizeContainer() instead of this function.
     *
     * @param null|\Symfony\Component\Console\Input\InputInterface $input
     * @param null|\Symfony\Component\Console\Output\OutputInterface $output
     * @param null|\Robo\Application $app
     * @param null|\Consolidation\Config\ConfigInterface $config
     * @param null|\Composer\Autoload\ClassLoader $classLoader
     *
     * @deprecated Use createContainer instead
     *
     * @return \Psr\Container\ContainerInterface
     */
    public static function createDefaultContainer(
        ?InputInterface $input = null,
        ?OutputInterface $output = null,
        ?Application $app = null,
        ?ConfigInterface $config = null,
        ?ClassLoader $classLoader = null
    ): ContainerInterface {
        // Do not allow this function to be called more than once.
        if (static::hasContainer()) {
            return static::getContainer();
        }

        if (!$app) {
            $app = static::createDefaultApplication();
        }

        if (!$config) {
            $config = new Config();
        }

        // Set up our dependency injection container.
        $container = new Container();
        static::configureContainer($container, $app, $config, $input, $output, $classLoader);
        static::finalizeContainer($container);

        return $container;
    }

    /**
     * Do final initialization to the provided container. Make any necessary
     * modifications to the container before calling this method.
     */
    public static function finalizeContainer(ContainerInterface $container)
    {
        $app = $container->get('application');

        // Set the application dispatcher
        $app->setDispatcher($container->get('eventDispatcher'));
    }

    /**
     * Initialize a container with all of the default Robo services.
     * IMPORTANT:  after calling this method, clients MUST call:
     *
     * Robo::finalizeContainer($container);
     *
     * Any modification to the container should be done prior to fetching
     * objects from it.
     *
     * It is recommended to use Robo::createContainer() instead.
     *
     * @param \Psr\Container\ContainerInterface $container
     * @param \Symfony\Component\Console\Application $app
     * @param \Consolidation\Config\ConfigInterface $config
     * @param null|\Symfony\Component\Console\Input\InputInterface $input
     * @param null|\Symfony\Component\Console\Output\OutputInterface $output
     * @param null|\Composer\Autoload\ClassLoader $classLoader
     */
    public static function configureContainer(ContainerInterface $container, SymfonyApplication $app, ConfigInterface $config, $input = null, $output = null, $classLoader = null): ContainerInterface
    {
        // Self-referential container refernce for the inflector
        $container->add('container', $container);
        static::setContainer($container);

        // Create default input and output objects if they were not provided.
        // TODO: We would like to remove $input and $output from the container
        // (or always register StringInput('') and NullOutput()). There are
        // currently three shortcomings preventing this:
        //  1. The logger cannot be used (we could remove the logger from Robo)
        //  2. Commands that abort with an exception do not print a message (bug)
        //  3. The runner tests do not initialize taskIO correctly for all tests
        if (!$input) {
            $input = new StringInput('');
        }
        if (!$output) {
            $output = new ConsoleOutput();
        }
        if (!$classLoader) {
            $classLoader = new ClassLoader();
        }
        $config->set(Config::DECORATED, $output->isDecorated());
        $config->set(Config::INTERACTIVE, $input->isInteractive());

        $container->share('application', $app);
        $container->share('config', $config);
        $container->share('input', $input);
        $container->share('output', $output);
        $container->share('outputAdapter', OutputAdapter::class);
        $container->share('classLoader', $classLoader);

        // Register logging and related services.
        $container->share('logStyler', RoboLogStyle::class);
        $container->share('logger', RoboLogger::class)
            ->addArgument('output')
            ->addMethodCall('setLogOutputStyler', ['logStyler']);
        $container->add('progressBar', ProgressBar::class)
            ->addArgument('output');
        $container->share('progressIndicator', ProgressIndicator::class)
            ->addArgument('progressBar')
            ->addArgument('output');
        $container->share('resultPrinter', ResultPrinter::class);
        $container->add('simulator', Simulator::class);
        $container->share('globalOptionsEventListener', GlobalOptionsEventListener::class)
            ->addMethodCall('setApplication', ['application']);
        $container->share('injectConfigEventListener', ConfigForCommand::class)
            ->addArgument('config')
            ->addMethodCall('setApplication', ['application']);
        $container->share('collectionProcessHook', CollectionProcessHook::class);
        $container->share('alterOptionsCommandEvent', AlterOptionsCommandEvent::class)
            ->addArgument('application');
        $container->share('hookManager', HookManager::class)
            ->addMethodCall('addCommandEvent', ['alterOptionsCommandEvent'])
            ->addMethodCall('addCommandEvent', ['injectConfigEventListener'])
            ->addMethodCall('addCommandEvent', ['globalOptionsEventListener'])
            ->addMethodCall('addResultProcessor', ['collectionProcessHook', '*']);
        $container->share('eventDispatcher', EventDispatcher::class)
            ->addMethodCall('addSubscriber', ['hookManager']);
        $container->share('formatterManager', FormatterManager::class)
            ->addMethodCall('addDefaultFormatters', [])
            ->addMethodCall('addDefaultSimplifiers', []);
        $container->share('prepareTerminalWidthOption', PrepareTerminalWidthOption::class)
            ->addMethodCall('setApplication', ['application']);
        $container->share('symfonyStyleInjector', SymfonyStyleInjector::class);
        $container->share('consoleIOInjector', ConsoleIOInjector::class);
        $container->share('parameterInjection', ParameterInjection::class)
            ->addMethodCall('register', ['Symfony\Component\Console\Style\SymfonyStyle', 'symfonyStyleInjector'])
            ->addMethodCall('register', ['Robo\Symfony\ConsoleIO', 'consoleIOInjector']);
        $container->share('commandProcessor', CommandProcessor::class)
            ->addArgument('hookManager')
            ->addMethodCall('setFormatterManager', ['formatterManager'])
            ->addMethodCall('addPrepareFormatter', ['prepareTerminalWidthOption'])
            ->addMethodCall('setParameterInjection', ['parameterInjection'])
            ->addMethodCall(
                'setDisplayErrorFunction',
                [
                    function ($output, $message) use ($container) {
                        $logger = $container->get('logger');
                        $logger->error($message);
                    }
                ]
            );
        $container->share('stdinHandler', StdinHandler::class);
        $container->share('commandFactory', AnnotatedCommandFactory::class)
            ->addMethodCall('setCommandProcessor', ['commandProcessor'])
            // Public methods from the class Robo\Commo\IO that should not be
            // added as available commands.
            ->addMethodCall('addIgnoredCommandsRegexp', ['/^currentState$|^restoreState$/']);
        $container->share('relativeNamespaceDiscovery', RelativeNamespaceDiscovery::class)
            ->addArgument('classLoader');

        // Deprecated: favor using collection builders to direct use of collections.
        $container->add('collection', Collection::class);
        // Deprecated: use CollectionBuilder::create() instead -- or, better
        // yet, BuilderAwareInterface::collectionBuilder() if available.
        $container->add('collectionBuilder', CollectionBuilder::class);

        static::addInflectors($container);

        // Make sure the application is appropriately initialized.
        $app->setAutoExit(false);

        return $container;
    }

    public static function createDefaultApplication(?string $appName = null, ?string $appVersion = null): Application
    {
        $appName = $appName ?: self::APPLICATION_NAME;
        $appVersion = $appVersion ?: self::VERSION;

        $app = new Application($appName, $appVersion);
        $app->setAutoExit(false);

        return $app;
    }

    /**
     * Add the Robo League\Container inflectors to the container
     */
    public static function addInflectors(ContainerInterface $container)
    {
        // Register our various inflectors.
        $container->inflector(ConfigAwareInterface::class)
            ->invokeMethod('setConfig', ['config']);
        $container->inflector(LoggerAwareInterface::class)
            ->invokeMethod('setLogger', ['logger']);
        $container->inflector(ContainerAwareInterface::class)
            ->invokeMethod('setContainer', ['container']);
        $container->inflector(InputAwareInterface::class)
            ->invokeMethod('setInput', ['input']);
        $container->inflector(\Robo\Contract\OutputAwareInterface::class)
            ->invokeMethod('setOutput', ['output']);
        $container->inflector(ProgressIndicatorAwareInterface::class)
            ->invokeMethod('setProgressIndicator', ['progressIndicator']);
        $container->inflector(CustomEventAwareInterface::class)
            ->invokeMethod('setHookManager', ['hookManager']);
        $container->inflector(VerbosityThresholdInterface::class)
            ->invokeMethod('setOutputAdapter', ['outputAdapter']);
        $container->inflector(StdinAwareInterface::class)
            ->invokeMethod('setStdinHandler', ['stdinHandler']);
    }

    /**
     * Retrieves a service from the container.
     *
     * Use this method if the desired service is not one of those with a dedicated
     * accessor method below. If it is listed below, those methods are preferred
     * as they can return useful type hints.
     *
     * @param string $id
     *   The ID of the service to retrieve.
     *
     * @return mixed
     *   The specified service.
     */
    public static function service(string $id)
    {
        return static::getContainer()->get($id);
    }

    /**
     * Indicates if a service is defined in the container.
     *
     * @param string $id
     *   The ID of the service to check.
     *
     * @return bool
     *   TRUE if the specified service exists, FALSE otherwise.
     */
    public static function hasService(string $id): bool
    {
        // Check hasContainer() first in order to always return a Boolean.
        return static::hasContainer() && static::getContainer()->has($id);
    }

    /**
     * Return the result printer object.
     *
     * @deprecated
     */
    public static function resultPrinter(): ResultPrinter
    {
        return static::service('resultPrinter');
    }

    public static function config(): ConfigInterface
    {
        return static::service('config');
    }

    public static function logger(): Logger
    {
        return static::service('logger');
    }

    public static function application(): Application
    {
        return static::service('application');
    }

    /**
     * Return the output object.
     */
    public static function output(): OutputInterface
    {
        return static::service('output');
    }

    /**
     * Return the input object.
     */
    public static function input(): InputInterface
    {
        return static::service('input');
    }

    public static function process(Process $process): ProcessExecutor
    {
        return ProcessExecutor::create(static::getContainer(), $process);
    }
}
