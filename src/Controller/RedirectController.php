<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class RedirectController extends AbstractController
{
    public function __construct(
        #[Autowire('%app.display_host%')]
        private readonly string $displayHost,
    ) {
    }

    #[Route('/', name: 'app_home')]
    public function index(Request $request): RedirectResponse
    {
        if ($this->displayHost !== '' && $request->getHost() === $this->displayHost) {
            return $this->redirectToRoute('app_slider_display');
        }

        return $this->redirectToRoute('app_login');
    }
}
