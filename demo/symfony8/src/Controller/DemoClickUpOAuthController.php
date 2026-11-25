<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\TaskBoardBundle\Sync\OAuth\OAuthReturnPath;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function is_array;
use function is_string;

/**
 * Since 3.1.0 (CU-1): the host's two OAuth routes of docs/sync.md ("Connecting ClickUp with OAuth"), as a stub with
 * no network: the start route skips ClickUp's consent page and comes straight back with a placeholder code, and the
 * callback does not call `ClickUpOAuthClient::exchange()`. A real host does both and stores the token encrypted.
 *
 * DEMO ONLY, NOT A TEMPLATE (audit ZBT-I5): it checks no board permission, binds no tenant and "connects" on a plain
 * GET with a placeholder token, so a cross-site link could flip the demo session's flag. Copy the recipe in
 * docs/sync.md ("Host recipe: start and callback routes") instead, which authorizes both routes.
 */
final class DemoClickUpOAuthController extends AbstractController
{
    public const CONNECTED = 'demo_clickup_oauth_connected';

    private const PENDING = 'demo_clickup_oauth_pending';

    private const TTL = 600;

    #[Route('/demo/clickup/oauth/start', name: 'app_demo_clickup_oauth_start', methods: ['GET'])]
    public function start(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');
        $returnTo = (string) $request->query->get('returnTo', '');
        if (!OAuthReturnPath::isSafe($returnTo)) {
            throw new BadRequestHttpException('Unsafe return path.');
        }
        $state = OAuthState::create();
        $request->getSession()->set(self::PENDING, ['state' => $state->value, 'returnTo' => $returnTo, 'expires' => time() + self::TTL]);

        // A real host redirects to $oauthClient->authorizationUrl($clientId, $callbackUrl, $state).
        return $this->redirectToRoute('app_demo_clickup_oauth_callback', ['code' => 'demo_code_placeholder', 'state' => $state->value]);
    }

    #[Route('/demo/clickup/oauth/callback', name: 'app_demo_clickup_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');
        $session = $request->getSession();
        $pending = $session->get(self::PENDING);
        $session->remove(self::PENDING); // single use, whatever happens next
        if (!is_array($pending) || !is_string($pending['state'] ?? null) || !OAuthState::isValid($pending['state'])
            || ($pending['expires'] ?? 0) < time() || !OAuthState::fromString($pending['state'])->matches($request->query->get('state'))) {
            throw new BadRequestHttpException('The connection request expired or was not started here.');
        }

        // A real host: $grant = $oauthClient->exchange($clientId, $clientSecret, $code); $identity =
        // $oauthClient->describe($grant->toCredential($ref)); then it encrypts and stores the token.
        $session->set(self::CONNECTED, true);

        return $this->redirect((string) $pending['returnTo']);
    }
}
