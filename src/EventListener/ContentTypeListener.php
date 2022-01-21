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

use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @final
 */
class ContentTypeListener
{
    protected $nosniff;

    public function __construct($nosniff)
    {
        $this->nosniff = $nosniff;
    }

    public function onKernelResponse(ResponseEvent $e)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $e->getRequestType()) {
            return;
        }

        if (!$this->nosniff) {
            return;
        }

        $response = $e->getResponse();

        if ($response->isRedirection()) {
            return;
        }

        $response->headers->add(['X-Content-Type-Options' => 'nosniff']);
    }
}