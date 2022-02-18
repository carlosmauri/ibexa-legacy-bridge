<?php

/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace eZ\Publish\Core\MVC\Legacy\Kernel;

use eZ\Bundle\EzPublishLegacyBundle\Rest\ResponseWriter;
use eZ\Publish\Core\MVC\Legacy\Event\PostBuildKernelEvent;
use eZ\Publish\Core\MVC\Legacy\Event\PreResetLegacyKernelEvent;
use eZ\Publish\Core\MVC\Legacy\Kernel as LegacyKernel;
use eZ\Publish\Core\MVC\Legacy\LegacyEvents;
use eZ\Publish\Core\MVC\Legacy\Event\PreBuildKernelWebHandlerEvent;
use eZ\Publish\Core\MVC\Legacy\Event\PreBuildKernelEvent;
use eZ\Publish\Core\MVC\Symfony\SiteAccess;
use ezpKernelHandler;
use ezpKernelRest;
use ezpKernelTreeMenu;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Legacy kernel loader.
 */
class Loader
{
    use ContainerAwareTrait;

    /**
     * @var string Absolute path to the legacy root directory (eZPublish 4 install dir)
     */
    protected $legacyRootDir;

    /**
     * @var string Absolute path to the new webroot directory (public/)
     */
    protected $webrootDir;

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var URIHelper
     */
    protected $uriHelper;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var bool
     */
    private $buildEventsEnabled = true;

    /** @var ezpKernelHandler */
    private $webHandler;

    /** @var ezpKernelHandler */
    private $cliHandler;

    /** @var ezpKernelHandler */
    private $restHandler;

    /**
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    private $requestStack;

    /**
     * @var \eZ\Publish\Core\MVC\Symfony\SiteAccess
     */
    private $siteAccess;

    public function __construct($legacyRootDir, $webrootDir, EventDispatcherInterface $eventDispatcher, URIHelper $uriHelper, SiteAccess $siteAccess, LoggerInterface $logger = null)
    {
        $this->legacyRootDir = $legacyRootDir;
        $this->webrootDir = $webrootDir;
        $this->eventDispatcher = $eventDispatcher;
        $this->uriHelper = $uriHelper;
        $this->siteAccess = $siteAccess;
        $this->logger = $logger;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
     */
    public function setRequestStack(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    /**
     * @param bool $enabled
     */
    public function setBuildEventsEnabled($enabled = true)
    {
        $this->buildEventsEnabled = (bool)$enabled;
    }

    /**
     * @return bool
     */
    public function getBuildEventsEnabled()
    {
        return $this->buildEventsEnabled;
    }

    /**
     * Builds up the legacy kernel and encapsulates it inside a closure, allowing lazy loading.
     *
     * @param \ezpKernelHandler|\Closure A kernel handler instance or a closure returning a kernel handler instance
     *
     * @return \Closure
     */
    public function buildLegacyKernel($legacyKernelHandler)
    {
        $legacyRootDir = $this->legacyRootDir;
        $webrootDir = $this->webrootDir;
        $eventDispatcher = $this->eventDispatcher;
        $logger = $this->logger;
        $that = $this;

        return static function ($handler = null) use ($legacyKernelHandler, $legacyRootDir, $webrootDir, $eventDispatcher, $logger, $that) {
            if ($handler === null && LegacyKernel::hasInstance()) {
                return LegacyKernel::instance();
            }

            if ($handler !== null) {
                $legacyKernelHandler = $handler;
            }

            if ($legacyKernelHandler instanceof \Closure) {
                $legacyKernelHandler = $legacyKernelHandler();
            }

            $legacyKernel = new LegacyKernel($legacyKernelHandler, $legacyRootDir, $webrootDir, $logger);

            if ($that->getBuildEventsEnabled()) {
                $eventDispatcher->dispatch(
                    new PostBuildKernelEvent($legacyKernel, $legacyKernelHandler),
                    LegacyEvents::POST_BUILD_LEGACY_KERNEL
                );
            }

            return $legacyKernel;
        };
    }

    /**
     * Builds up the legacy kernel web handler and encapsulates it inside a closure, allowing lazy loading.
     *
     * @param string $webHandlerClass The legacy kernel handler class to use
     * @param array $defaultLegacyOptions Hash of options to pass to the legacy kernel handler
     *
     * @return \Closure
     */
    public function buildLegacyKernelHandlerWeb($webHandlerClass, array $defaultLegacyOptions = [])
    {
        $legacyRootDir = $this->legacyRootDir;
        $webrootDir = $this->webrootDir;
        $uriHelper = $this->uriHelper;
        $eventDispatcher = $this->eventDispatcher;
        $container = $this->container;
        $that = $this;

        return function () use ($legacyRootDir, $webrootDir, $container, $defaultLegacyOptions, $webHandlerClass, $uriHelper, $eventDispatcher, $that) {
            if (!$that->getWebHandler()) {
                chdir($legacyRootDir);

                $legacyParameters = new ParameterBag($defaultLegacyOptions);
                $legacyParameters->set('service-container', $container);
                // $request = $this->requestStack->getCurrentRequest() ?? Request::create('');
                $request = $this->requestStack->getCurrentRequest();

                if ($that->getBuildEventsEnabled()) {
                    // PRE_BUILD_LEGACY_KERNEL for non request related stuff
                    $eventDispatcher->dispatch(new PreBuildKernelEvent($legacyParameters), LegacyEvents::PRE_BUILD_LEGACY_KERNEL);

                    // Pure web stuff
                    $eventDispatcher->dispatch(
                        new PreBuildKernelWebHandlerEvent($legacyParameters, $request),
                        LegacyEvents::PRE_BUILD_LEGACY_KERNEL_WEB
                    );
                }

                $interfaces = class_implements($webHandlerClass);
                if (!isset($interfaces['ezpKernelHandler'])) {
                    throw new \InvalidArgumentException('A legacy kernel handler must be an instance of ezpKernelHandler.');
                }

                $that->setWebHandler(new $webHandlerClass($legacyParameters->all()));
                // Fix up legacy URI for global use cases (i.e. using runCallback()).
                $uriHelper->updateLegacyURI($request);
                chdir($webrootDir);
            }

            return $that->getWebHandler();
        };
    }

    /**
     * @param $handler
     */
    public function setWebHandler(ezpKernelHandler $handler)
    {
        $this->webHandler = $handler;
    }

    /**
     * @return ezpKernelHandler
     */
    public function getWebHandler()
    {
        return $this->webHandler;
    }

    /**
     * Builds legacy kernel handler CLI.
     *
     * @return CLIHandler
     */
    public function buildLegacyKernelHandlerCLI()
    {
        $legacyRootDir = $this->legacyRootDir;
        $eventDispatcher = $this->eventDispatcher;
        $container = $this->container;
        $siteAccess = $this->siteAccess;
        $that = $this;

        return static function () use ($legacyRootDir, $container, $eventDispatcher, $siteAccess, $that) {
            if (!$that->getCLIHandler()) {
                $currentDir = getcwd();
                chdir($legacyRootDir);

                $legacyParameters = new ParameterBag($container->getParameter('ezpublish_legacy.kernel_handler.cli.options'));
                if ($that->getBuildEventsEnabled()) {
                    $eventDispatcher->dispatch(new PreBuildKernelEvent($legacyParameters), LegacyEvents::PRE_BUILD_LEGACY_KERNEL);
                }

                $that->setCLIHandler(
                    new CLIHandler($legacyParameters->all(), $siteAccess, $container)
                );

                chdir($currentDir);
            }

            return $that->getCLIHandler();
        };
    }

    /**
     * @return ezpKernelhandler
     */
    public function getCLIHandler()
    {
        return $this->cliHandler;
    }

    public function setCLIHandler(ezpKernelHandler $kernelHandler)
    {
        $this->cliHandler = $kernelHandler;
    }

    /**
     * Builds the legacy kernel handler for the tree menu in admin interface.
     *
     * @return \Closure a closure returning an \ezpKernelTreeMenu instance
     */
    public function buildLegacyKernelHandlerTreeMenu()
    {
        return $this->buildLegacyKernelHandlerWeb(
            ezpKernelTreeMenu::class,
            [
                'use-cache-headers' => false,
                'use-exceptions' => true,
            ]
        );
    }

    /**
     * Builds the legacy kernel handler for the tree menu in admin interface.
     *
     * @return \Closure a closure returning an \ezpKernelTreeMenu instance
     */
    public function buildLegacyKernelHandlerRest($mvcConfiguration)
    {
        $legacyRootDir = $this->legacyRootDir;
        $webrootDir = $this->webrootDir;
        $uriHelper = $this->uriHelper;
        $eventDispatcher = $this->eventDispatcher;
        $container = $this->container;
        $that = $this;

        return function () use ($legacyRootDir, $webrootDir, $container, $uriHelper, $eventDispatcher, $that) {
            if (!$that->getRestHandler()) {
                chdir($legacyRootDir);

                $legacyParameters = new ParameterBag();
                $request = $this->requestStack->getCurrentRequest();

                if ($that->getBuildEventsEnabled()) {
                    // PRE_BUILD_LEGACY_KERNEL for non request related stuff
                    $eventDispatcher->dispatch(new PreBuildKernelEvent($legacyParameters), LegacyEvents::PRE_BUILD_LEGACY_KERNEL);

                    // Pure web stuff
                    $eventDispatcher->dispatch(
                        new PreBuildKernelWebHandlerEvent($legacyParameters, $request),
                        LegacyEvents::PRE_BUILD_LEGACY_KERNEL_WEB
                    );
                }

                $that->setRestHandler(new ezpKernelRest($legacyParameters->all(), ResponseWriter::class));
                chdir($webrootDir);
            }

            return $that->getRestHandler();
        };
    }

    /**
     * @return ezpKernelhandler
     */
    public function getRestHandler()
    {
        return $this->restHandler;
    }

    public function setRestHandler(ezpKernelHandler $handler)
    {
        $this->restHandler = $handler;
    }

    /**
     * Resets the legacy kernel instances from the container.
     */
    public function resetKernel()
    {
        // Reset the kernel only if it has been initialized.
        if (LegacyKernel::hasInstance()) {
            /** @var \Closure $kernelClosure */
            $kernelClosure = $this->container->get('ezpublish_legacy.kernel');
            $this->eventDispatcher->dispatch(
                new PreResetLegacyKernelEvent($kernelClosure()),
                LegacyEvents::PRE_RESET_LEGACY_KERNEL
            );
        }

        LegacyKernel::resetInstance();
        $this->webHandler = null;
        $this->cliHandler = null;
        $this->restHandler = null;

        // $this->container->set('ezpublish_legacy.kernel', null);
        // $this->container->set('ezpublish_legacy.kernel_handler.web', null);
        // $this->container->set('ezpublish_legacy.kernel_handler.cli', null);
    }
}
