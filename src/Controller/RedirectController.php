<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class RedirectController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('app_login');
    }
}
