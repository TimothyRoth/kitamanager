<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ContentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SliderController extends AbstractController
{
    #[Route('/slider/{slug?}', name: 'app_slider')]
    public function index(?User $user, ContentRepository $contentRepository): Response
    {
        if (!$user) {
            /** @var User|null $user */
            $user = $this->getUser();

            if (!$user) {
                return $this->redirectToRoute('app_login');
            }
        }

        $contents = $contentRepository->findAvailableForUser($user);

        return $this->render('slider/slider.html.twig', [
            'user' => $user,
            'contents' => $contents,
        ]);
    }
}
