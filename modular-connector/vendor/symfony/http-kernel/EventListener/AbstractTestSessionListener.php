<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Modular\ConnectorDependencies\Symfony\Component\HttpKernel\EventListener;

use Modular\ConnectorDependencies\Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Cookie;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Session\Session;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Session\SessionInterface;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Event\RequestEvent;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Event\ResponseEvent;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\KernelEvents;
trigger_deprecation('symfony/http-kernel', '5.4', '"%s" is deprecated use "%s" instead.', AbstractTestSessionListener::class, AbstractSessionListener::class);
/**
 * TestSessionListener.
 *
 * Saves session in test environment.
 *
 * @author Bulat Shakirzyanov <mallluhuct@gmail.com>
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @internal
 *
 * @deprecated since Symfony 5.4, use AbstractSessionListener instead
 */
abstract class AbstractTestSessionListener implements EventSubscriberInterface
{
    private $sessionId;
    private $sessionOptions;
    public function __construct(array $sessionOptions = [])
    {
        $this->sessionOptions = $sessionOptions;
    }
    public function onKernelRequest(RequestEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }
        // bootstrap the session
        if ($event->getRequest()->hasSession()) {
            $session = $event->getRequest()->getSession();
        } elseif (!$session = $this->getSession()) {
            return;
        }
        $cookies = $event->getRequest()->cookies;
        if ($cookies->has($session->getName())) {
            $this->sessionId = $cookies->get($session->getName());
            $session->setId($this->sessionId);
        }
    }
    /**
     * Checks if session was initialized and saves if current request is the main request
     * Runs on 'kernel.response' in test environment.
     */
    public function onKernelResponse(ResponseEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        if ($wasStarted = $session->isStarted()) {
            $session->save();
        }
        if ($session instanceof Session ? !$session->isEmpty() || null !== $this->sessionId && $session->getId() !== $this->sessionId : $wasStarted) {
            $params = session_get_cookie_params() + ['samesite' => null];
            foreach ($this->sessionOptions as $k => $v) {
                if (str_starts_with($k, 'cookie_')) {
                    $params[substr($k, 7)] = $v;
                }
            }
            foreach ($event->getResponse()->headers->getCookies() as $cookie) {
                if ($session->getName() === $cookie->getName() && $params['path'] === $cookie->getPath() && $params['domain'] == $cookie->getDomain()) {
                    return;
                }
            }
            $event->getResponse()->headers->setCookie(new Cookie($session->getName(), $session->getId(), 0 === $params['lifetime'] ? 0 : time() + $params['lifetime'], $params['path'], $params['domain'], $params['secure'], $params['httponly'], \false, $params['samesite'] ?: null));
            $this->sessionId = $session->getId();
        }
    }
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 127],
            // AFTER SessionListener
            KernelEvents::RESPONSE => ['onKernelResponse', -128],
        ];
    }
    /**
     * Gets the session object.
     *
     * @return SessionInterface|null
     */
    abstract protected function getSession();
}
