<?php

/*
 * This file is part of SILARHI.
 * (c) 2019 - present Guillaume Sainthillier <guillaume@silarhi.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace App\Security;

use App\Controller\SecurityController;
use App\EventSubscriber\DoubleAuthentificationSubscriber;
use Override;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class TwoFactorsAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    final public const string LOGIN_ROUTE = 'app_security_setup_fa';

    public function __construct(private readonly TokenStorageInterface $tokenStorage, private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    #[Override]
    public function supports(Request $request): bool
    {
        return parent::supports($request)
            && $request->getSession()->has(SecurityController::QR_CODE_KEY);
    }

    #[Override]
    public function authenticate(Request $request): Passport
    {
        // Get user from login form
        $existingToken = $this->tokenStorage->getToken();
        if (!$existingToken instanceof TokenInterface || $existingToken instanceof NullToken) {
            throw new UserNotFoundException();
        }

        /** @var UserInterface $user */
        $user = $existingToken->getUser();
        /** @var string $qrCode */
        $qrCode = $request->request->get('qrCode', '');
        /** @var string $secretKey */
        $secretKey = $request->getSession()->get(SecurityController::QR_CODE_KEY);

        $google2fa = new Google2FA();
        $google2fa->setSecret($secretKey);

        if (true !== $google2fa->verifyKey($google2fa->getSecret(), $qrCode)) {
            throw new CustomUserMessageAuthenticationException('This code is not valid');
        }

        $email = $user->getUserIdentifier();

        return new SelfValidatingPassport(
            new UserBadge($email)
        );
    }

    #[Override]
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $currentToken = parent::createToken($passport, $firewallName);

        $roles = array_merge($currentToken->getRoleNames(), [DoubleAuthentificationSubscriber::ROLE_2FA_SUCCEED]);

        /** @var UserInterface $user */
        $user = $currentToken->getUser();

        return new PostAuthenticationToken(
            $user,
            $firewallName,
            $roles
        );
    }

    #[Override]
    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    #[Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            $this->removeTargetPath($request->getSession(), $firewallName);

            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_security_authentification_protected'));
    }
}
