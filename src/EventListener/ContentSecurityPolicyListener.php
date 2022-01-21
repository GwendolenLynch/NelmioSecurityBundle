<?php

declare(strict_types=1);

/*
 * This file is part of the Nelmio SecurityBundle.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\SecurityBundle\EventListener;

use Nelmio\SecurityBundle\ContentSecurityPolicy\DirectiveSet;
use Nelmio\SecurityBundle\ContentSecurityPolicy\NonceGenerator;
use Nelmio\SecurityBundle\ContentSecurityPolicy\ShaComputer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @final
 */
class ContentSecurityPolicyListener extends AbstractContentTypeRestrictableListener
{
    protected $report;
    protected $enforce;
    protected $compatHeaders;
    protected $hosts;
    protected $_nonce;
    protected $scriptNonce;
    protected $styleNonce;
    protected $sha;
    protected $nonceGenerator;
    protected $shaComputer;

    public function __construct(DirectiveSet $report, DirectiveSet $enforce, NonceGenerator $nonceGenerator, ShaComputer $shaComputer, $compatHeaders = true, array $hosts = [], array $contentTypes = [])
    {
        parent::__construct($contentTypes);
        $this->report = $report;
        $this->enforce = $enforce;
        $this->compatHeaders = $compatHeaders;
        $this->hosts = $hosts;
        $this->nonceGenerator = $nonceGenerator;
        $this->shaComputer = $shaComputer;
    }

    public function onKernelRequest(RequestEvent $e)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $e->getRequestType()) {
            return;
        }

        $this->sha = [];
    }

    public function addSha($directive, $sha)
    {
        if (null === $this->sha) {
            // We're not in a request context, probably in a worker
            // let's disable it to avoid memory leak
            return;
        }

        $this->sha[$directive][] = $sha;
    }

    public function addScript($html)
    {
        if (null === $this->sha) {
            // We're not in a request context, probably in a worker
            // let's disable it to avoid memory leak
            return;
        }

        $this->sha['script-src'][] = $this->shaComputer->computeForScript($html);
    }

    public function addStyle($html)
    {
        if (null === $this->sha) {
            // We're not in a request context, probably in a worker
            // let's disable it to avoid memory leak
            return;
        }

        $this->sha['style-src'][] = $this->shaComputer->computeForStyle($html);
    }

    public function getReport()
    {
        return $this->report;
    }

    public function getEnforcement()
    {
        return $this->enforce;
    }

    public function getNonce(string $usage)
    {
        $nonce = $this->doGetNonce();

        if ('script' === $usage) {
            $this->scriptNonce = $nonce;
        } elseif ('style' === $usage) {
            $this->styleNonce = $nonce;
        } else {
            throw new \InvalidArgumentException('Invalid usage provided');
        }

        return $nonce;
    }

    private function doGetNonce()
    {
        if (null === $this->_nonce) {
            $this->_nonce = $this->nonceGenerator->generate();
        }

        return $this->_nonce;
    }

    public function onKernelResponse(ResponseEvent $e)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $e->getRequestType()) {
            return;
        }

        $request = $e->getRequest();
        $response = $e->getResponse();

        if ($response->isRedirection()) {
            $this->_nonce = null;
            $this->styleNonce = null;
            $this->scriptNonce = null;
            $this->sha = null;

            return;
        }

        if ((empty($this->hosts) || in_array($e->getRequest()->getHost(), $this->hosts, true)) && $this->isContentTypeValid($response)) {
            $signatures = $this->sha;
            if ($this->scriptNonce) {
                $signatures['script-src'][] = 'nonce-'.$this->scriptNonce;
            }
            if ($this->styleNonce) {
                $signatures['style-src'][] = 'nonce-'.$this->styleNonce;
            }

            $response->headers->add($this->buildHeaders($request, $this->report, true, $this->compatHeaders, $signatures));
            $response->headers->add($this->buildHeaders($request, $this->enforce, false, $this->compatHeaders, $signatures));
        }

        $this->_nonce = null;
        $this->styleNonce = null;
        $this->scriptNonce = null;
        $this->sha = null;
    }

    private function buildHeaders(Request $request, DirectiveSet $directiveSet, $reportOnly, $compatHeaders, array $signatures = null)
    {
        // $signatures might be null if no KernelEvents::REQUEST has been triggered.
        // for instance if a security.authentication.failure has been dispatched
        $headerValue = $directiveSet->buildHeaderValue($request, $signatures);

        if (!$headerValue) {
            return [];
        }

        $hn = function ($name) use ($reportOnly) {
            return $name.($reportOnly ? '-Report-Only' : '');
        };

        $headers = [
            $hn('Content-Security-Policy') => $headerValue,
        ];

        if ($compatHeaders) {
            $headers[$hn('X-Content-Security-Policy')] = $headerValue;
        }

        return $headers;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 512],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }
}