<?php

declare(strict_types=1);

namespace App\Sync;

use App\Controller\DemoClickUpOAuthController;
use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\OAuthConnectionStartInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TenantCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\CredentialScheme;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TenantRef;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Demo credential store: one fake ClickUp connection (the demo provider is in memory, nothing leaves the container).
 * A real host keeps encrypted tokens per board or project and never shows them again.
 *
 * Since 3.1.0 it also offers *Connect with ClickUp* (OAuth): {@see DemoClickUpOAuthController} plays the host's
 * start and callback routes without calling ClickUp, and the "connected" OAuth connection lives in the session.
 *
 * Since 3.2.0 it answers per tenant too (the demo has none: one connection for the whole site), for the guided import.
 */
final readonly class DemoCredentialResolver implements ProviderCredentialSourceInterface, OAuthConnectionStartInterface, TenantCredentialSourceInterface
{
    public const SECRET = 'pk_demo_not_a_real_token';

    public const OAUTH_SECRET = 'demo_oauth_not_a_real_token';

    public const OAUTH_REF = 'demo-oauth';

    public function __construct(
        private RequestStack $requests,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function connectionsFor(TaskBoardSnapshot $board, object $user, string $provider): array
    {
        $choices = [new ConnectionChoice('demo', 'Demo workspace', substr(self::SECRET, -4))];
        if ($this->oauthConnected()) {
            $choices[] = new ConnectionChoice(self::OAUTH_REF, 'Demo workspace', substr(self::OAUTH_SECRET, -4), CredentialScheme::OAuth, ['9001']);
        }

        return $choices;
    }

    public function credentialFor(TaskBoardSnapshot $board, string $connectionRef): ?ProviderCredential
    {
        return match (true) {
            $connectionRef === 'demo'                                     => new ProviderCredential(self::SECRET, $connectionRef),
            $connectionRef === self::OAUTH_REF && $this->oauthConnected() => new ProviderCredential(self::OAUTH_SECRET, $connectionRef, [CredentialScheme::OPTION => CredentialScheme::OAuth->value]),
            default                                                       => null,
        };
    }

    public function credentialFormUrl(TaskBoardSnapshot $board, string $provider): ?string
    {
        return null;
    }

    public function connectUrl(TaskBoardSnapshot $board, string $provider, string $returnTo): ?string
    {
        return $provider === 'clickup' ? $this->urls->generate('app_demo_clickup_oauth_start', ['returnTo' => $returnTo]) : null;
    }

    public function tenantConnectionsFor(TenantRef $tenant, object $user, string $provider): array
    {
        return $this->connectionsFor($this->site(), $user, $provider);
    }

    public function tenantCredentialFor(TenantRef $tenant, string $connectionRef): ?ProviderCredential
    {
        return $this->credentialFor($this->site(), $connectionRef);
    }

    public function tenantCredentialFormUrl(TenantRef $tenant, string $provider): ?string
    {
        return null;
    }

    public function tenantConnectUrl(TenantRef $tenant, string $provider, string $returnTo): ?string
    {
        return $provider === 'clickup' ? $this->urls->generate('app_demo_clickup_oauth_start', ['returnTo' => $returnTo]) : null;
    }

    /** The demo's connections do not depend on the board: any snapshot will do. */
    private function site(): TaskBoardSnapshot
    {
        return new TaskBoardSnapshot('', '', '', null, null, null, false, new DateTimeImmutable('@0'));
    }

    private function oauthConnected(): bool
    {
        $session = $this->requests->getCurrentRequest()?->hasSession() === true ? $this->requests->getSession() : null;

        return $session?->get(DemoClickUpOAuthController::CONNECTED) === true;
    }
}
