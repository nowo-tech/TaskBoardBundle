<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;
use function parse_url;
use function strcasecmp;

use const PHP_URL_HOST;

/**
 * Since 2.0.0: the help link of the import forms from `import.help_link`, resolved per render: the visibility check and
 * the off-site test depend on the request, so it is not a Twig global.
 *
 * @phpstan-type HelpLinkConfig array{url: string|null, url_resolver?: string|null, route: string|null, route_params: array<string, mixed>, label: string|null, translation_domain: string, anchor_from: string|null, access_attribute: string|null, external: bool|string}
 */
final readonly class ImportHelpLinkResolver
{
    /**
     * @param HelpLinkConfig|null $config
     */
    public function __construct(
        private ?array $config = null,
        private ?UrlGeneratorInterface $urls = null,
        private ?TranslatorInterface $translator = null,
        private ?AuthorizationCheckerInterface $authorization = null,
        private ?RequestStack $requests = null,
        // Since 2.5.0 (TB-17): `import.help_link.url_resolver`.
        private ?ImportHelpLinkUrlResolverInterface $urlResolver = null,
    ) {
    }

    /**
     * The link for `_import_help_link.html.twig`, or null when none is configured or the user may not see it.
     *
     * @return array{url: string, label: string, anchor_from: string|null, external: bool}|null
     */
    public function resolve(): ?array
    {
        $config = $this->config;
        if ($config === null || ($config['url'] === null && $config['route'] === null && !$this->urlResolver instanceof ImportHelpLinkUrlResolverInterface) || $config['label'] === null) {
            return null;
        }
        $attribute = $config['access_attribute'];
        if (is_string($attribute) && $attribute !== '' && $this->authorization?->isGranted($attribute) !== true) {
            return null;
        }
        $url = $config['route'] !== null ? $this->urls?->generate($config['route'], $config['route_params']) : $config['url'];
        if ($this->urlResolver instanceof ImportHelpLinkUrlResolverInterface) {
            $url = $this->urlResolver->resolveHelpLinkUrl(is_string($url) ? $url : null);
        }
        if (!is_string($url) || $url === '') {
            return null;
        }

        return [
            'url'         => $url,
            'label'       => $this->translator?->trans($config['label'], [], $config['translation_domain']) ?? $config['label'],
            'anchor_from' => $config['anchor_from'],
            'external'    => $config['external'] === 'auto' ? $this->isOffSite($url) : (bool) $config['external'],
        ];
    }

    /** An absolute URL to another host than the request's. Without a request, any absolute URL. */
    private function isOffSite(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $current = $this->requests?->getCurrentRequest()?->getHost();

        return $current === null || strcasecmp($host, $current) !== 0;
    }
}
