<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PreviewBundle\Preview\Renderer;

use Sulu\Bundle\PreviewBundle\Preview\Events;
use Sulu\Bundle\PreviewBundle\Preview\Events\PreRenderEvent;
use Sulu\Bundle\PreviewBundle\Preview\Exception\RouteDefaultsProviderNotFoundException;
use Sulu\Bundle\PreviewBundle\Preview\Exception\TemplateNotFoundException;
use Sulu\Bundle\PreviewBundle\Preview\Exception\TwigException;
use Sulu\Bundle\PreviewBundle\Preview\Exception\UnexpectedException;
use Sulu\Bundle\PreviewBundle\Preview\Exception\WebspaceLocalizationNotFoundException;
use Sulu\Bundle\PreviewBundle\Preview\Exception\WebspaceNotFoundException;
use Sulu\Bundle\RouteBundle\Routing\Defaults\RouteDefaultsProviderInterface;
use Sulu\Component\Webspace\Analyzer\Attributes\RequestAttributes;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzer;
use Sulu\Component\Webspace\Environment;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Portal;
use Sulu\Component\Webspace\PortalInformation;
use Sulu\Component\Webspace\Url;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Renders preview responses.
 */
class PreviewRenderer implements PreviewRendererInterface
{
    /**
     * @var RouteDefaultsProviderInterface
     */
    private $routeDefaultsProvider;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var KernelFactoryInterface
     */
    private $kernelFactory;

    /**
     * @var WebspaceManagerInterface
     */
    private $webspaceManager;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var array
     */
    private $previewDefaults;

    /**
     * @var string
     */
    private $environment;

    /**
     * @var string
     */
    private $targetGroupHeader;

    /**
     * @param RouteDefaultsProviderInterface $routeDefaultsProvider
     * @param RequestStack $requestStack
     * @param KernelFactoryInterface $kernelFactory
     * @param WebspaceManagerInterface $webspaceManager
     * @param EventDispatcherInterface $eventDispatcher
     * @param array $previewDefaults
     * @param string $environment
     * @param string $targetGroupHeader
     */
    public function __construct(
        RouteDefaultsProviderInterface $routeDefaultsProvider,
        RequestStack $requestStack,
        KernelFactoryInterface $kernelFactory,
        WebspaceManagerInterface $webspaceManager,
        EventDispatcherInterface $eventDispatcher,
        array $previewDefaults,
        $environment,
        $targetGroupHeader = null
    ) {
        $this->routeDefaultsProvider = $routeDefaultsProvider;
        $this->requestStack = $requestStack;
        $this->kernelFactory = $kernelFactory;
        $this->webspaceManager = $webspaceManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->previewDefaults = $previewDefaults;
        $this->environment = $environment;
        $this->targetGroupHeader = $targetGroupHeader;
    }

    /**
     * {@inheritdoc}
     */
    public function render($object, $id, $webspaceKey, $locale, $partial = false, $targetGroupId = null)
    {
        if (!$this->routeDefaultsProvider->supports(get_class($object))) {
            throw new RouteDefaultsProviderNotFoundException($object, $id, $webspaceKey, $locale);
        }

        $portalInformations = $this->webspaceManager->findPortalInformationsByWebspaceKeyAndLocale(
            $webspaceKey,
            $locale,
            $this->environment
        );

        /** @var PortalInformation $portalInformation */
        $portalInformation = reset($portalInformations);

        if (!$portalInformation) {
            $portalInformation = $this->createPortalInformation($object, $id, $webspaceKey, $locale);
        }

        $webspace = $portalInformation->getWebspace();
        $localization = $webspace->getLocalization($locale);

        $query = [];
        $request = [];
        $currentRequest = $this->requestStack->getCurrentRequest();
        if ($currentRequest !== null) {
            $query = $currentRequest->query->all();
            $request = $currentRequest->request->all();
        }

        $attributes = new RequestAttributes(
            [
                'webspace' => $webspace,
                'locale' => $locale,
                'localization' => $localization,
                'portal' => $portalInformation->getPortal(),
                'portalUrl' => $portalInformation->getUrl(),
                'resourceLocatorPrefix' => $portalInformation->getPrefix(),
                'getParameters' => $query,
                'postParameters' => $request,
                'analyticsKey' => $this->previewDefaults['analyticsKey'],
                'portalInformation' => $portalInformation,
            ]
        );

        $defaults = $this->routeDefaultsProvider->getByEntity(get_class($object), $id, $locale, $object);

        // Controller arguments
        $defaults['object'] = $object;
        $defaults['preview'] = true;
        $defaults['partial'] = $partial;
        $defaults['_sulu'] = $attributes;

        $request = new Request($query, $request, $defaults);
        $request->setLocale($locale);

        if ($this->targetGroupHeader && $targetGroupId) {
            $request->headers->set($this->targetGroupHeader, $targetGroupId);
        }

        $this->eventDispatcher->dispatch(Events::PRE_RENDER, new PreRenderEvent($attributes));

        try {
            $response = $this->handle($request);
        } catch (\Twig_Error $e) {
            throw new TwigException($e, $object, $id, $webspace, $locale);
        } catch (\InvalidArgumentException $e) {
            throw new TemplateNotFoundException($e, $object, $id, $webspace, $locale);
        } catch (\Exception $e) {
            throw new UnexpectedException($e, $object, $id, $webspace, $locale);
        }

        return $response->getContent();
    }

    /**
     * Handles given request and returns response.
     *
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Exception
     */
    private function handle(Request $request)
    {
        $kernel = $this->kernelFactory->create($this->environment);

        try {
            return $kernel->handle($request, HttpKernelInterface::MASTER_REQUEST, false);
        } catch (HttpException $e) {
            if ($e->getPrevious()) {
                throw $e->getPrevious();
            }

            throw $e;
        }
    }

    /**
     * This creates a new portal information based on the given information. This is necessary because it is possible
     * that a webspace defines a language, which is not used in any portal. For this case we have to define our own
     * fake PortalInformation object.
     *
     * @param object $object
     * @param int $id
     * @param string $webspaceKey
     * @param string $locale
     *
     * @return PortalInformation
     *
     * @throws WebspaceLocalizationNotFoundException
     * @throws WebspaceNotFoundException
     */
    private function createPortalInformation($object, $id, $webspaceKey, $locale)
    {
        $webspace = $this->webspaceManager->findWebspaceByKey($webspaceKey);
        $domain = $this->requestStack->getCurrentRequest()->getHost();

        if (!$webspace) {
            throw new WebspaceNotFoundException($object, $id, $webspaceKey, $locale);
        }

        $webspace = clone $webspace;
        $localization = $webspace->getLocalization($locale);

        if (!$localization) {
            throw new WebspaceLocalizationNotFoundException($object, $id, $webspaceKey, $locale);
        }

        $localization = clone $localization;
        $localization->setXDefault(true);
        $portal = new Portal();
        $portal->setName($webspace->getName());
        $portal->setKey($webspace->getKey());
        $portal->setWebspace($webspace);
        $portal->setXDefaultLocalization($localization);
        $portal->setLocalizations([$localization]);
        $portal->setDefaultLocalization($localization);
        $environment = new Environment();
        $url = new Url($domain, $this->environment);
        $environment->setUrls([$url]);
        $portal->setEnvironments([$environment]);
        $webspace->setPortals([$portal]);

        return new PortalInformation(RequestAnalyzer::MATCH_TYPE_FULL, $webspace, $portal, $localization, $domain);
    }
}
