<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Builds absolute URLs for the display host when host separation is enabled.
 * Falls back to normal relative paths in single-host mode.
 */
final class HostUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
        #[Autowire('%app.display_host%')]
        private readonly string $displayHost,
        #[Autowire('%app.management_host%')]
        private readonly string $managementHost,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_url', [$this, 'displayUrl']),
            new TwigFunction('host_separation_enabled', [$this, 'isEnabled']),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function displayUrl(string $route, array $parameters = []): string
    {
        $path = $this->urlGenerator->generate($route, $parameters);

        if ($this->displayHost === '') {
            return $path;
        }

        $request = $this->requestStack->getCurrentRequest();
        $scheme = $request?->getScheme() ?? 'https';

        return $scheme.'://'.$this->displayHost.$path;
    }

    public function isEnabled(): bool
    {
        return $this->displayHost !== '' && $this->managementHost !== '';
    }
}
