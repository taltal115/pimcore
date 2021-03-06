<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\CoreBundle\EventListener\Frontend;

use Pimcore\Bundle\CoreBundle\EventListener\Traits\PimcoreContextAwareTrait;
use Pimcore\Http\Request\Resolver\DocumentResolver;
use Pimcore\Http\Request\Resolver\PimcoreContextResolver;
use Pimcore\Http\Request\Resolver\SiteResolver;
use Pimcore\Model\Document;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * If no document was found on the active request (not set by router or by initiator of a sub-request), try to find and
 * set a fallback document:
 *
 *  - if request is a sub-request, try to read document from master request
 *  - if all fails, try to find the nearest document by path
 */
class DocumentFallbackListener implements EventSubscriberInterface
{
    use PimcoreContextAwareTrait;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var DocumentResolver
     */
    protected $documentResolver;

    /**
     * @var SiteResolver
     */
    protected $siteResolver;

    /**
     * @var Document\Service
     */
    protected $documentService;

    /**
     * @var array
     */
    protected $options;

    public function __construct(
        RequestStack $requestStack,
        DocumentResolver $documentResolver,
        SiteResolver $siteResolver,
        Document\Service $documentService,
        array $options = []
    ) {
        $this->requestStack     = $requestStack;
        $this->documentResolver = $documentResolver;
        $this->siteResolver     = $siteResolver;
        $this->documentService  = $documentService;

        $optionsResolver = new OptionsResolver();
        $this->configureOptions($optionsResolver);

        $this->options = $optionsResolver->resolve($options);
    }

    protected function configureOptions(OptionsResolver $optionsResolver)
    {
        $optionsResolver->setDefaults([
            'nearestDocumentTypes' => ['page', 'snippet', 'hardlink']
        ]);

        $optionsResolver->setAllowedTypes('nearestDocumentTypes', 'array');
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            // priority must be before
            // -> Symfony\Component\HttpKernel\EventListener\LocaleListener::onKernelRequest()
            // -> Pimcore\Bundle\CoreBundle\EventListener\Frontend\EditmodeListener::onKernelRequest()
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    /**
     * Finds the nearest document for the current request if the routing/document router didn't find one (e.g. static routes)
     *
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        if (!$this->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_DEFAULT)) {
            return;
        }

        if ($this->documentResolver->getDocument($request)) {
            // we already have a document (e.g. set through the document router)
            return;
        } else {
            // if we're in a sub request and no explicit document is set - try to load document from
            // parent and/or master request and set it on our sub-request
            if (!$event->isMasterRequest()) {
                $parentRequest = $this->requestStack->getParentRequest();
                $masterRequest = $this->requestStack->getMasterRequest();

                $eligibleRequests = [];

                if (null !== $parentRequest) {
                    $eligibleRequests[] = $parentRequest;
                }

                if ($masterRequest !== $parentRequest) {
                    $eligibleRequests[] = $masterRequest;
                }

                foreach ($eligibleRequests as $eligibleRequest) {
                    if ($document = $this->documentResolver->getDocument($eligibleRequest)) {
                        $this->documentResolver->setDocument($request, $document);

                        return;
                    }
                }
            }
        }

        // no document found yet - try to find the nearest document by request path
        // this is only done on the master request as a sub-request's pathInfo is _fragment when
        // rendered via actions helper
        if ($event->isMasterRequest()) {
            $path = null;
            if ($this->siteResolver->isSiteRequest($request)) {
                $path = $this->siteResolver->getSitePath($request);
            } else {
                $path = urldecode($request->getPathInfo());
            }

            $document = $this->documentService->getNearestDocumentByPath($path, false, $this->options['nearestDocumentTypes']);
            if ($document) {
                $this->documentResolver->setDocument($request, $document);
            }
        }
    }
}
