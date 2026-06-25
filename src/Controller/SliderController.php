<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\SliderItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SliderController extends AbstractController
{
    #[Route('/slider/{slug?}', name: 'app_slider')]
    public function index(?User $user, SliderItemRepository $sliderItemRepository): Response
    {
        if (!$user) {
            /** @var User|null $user */
            $user = $this->getUser();

            if (!$user) {
                return $this->redirectToRoute('app_login');
            }
        }

        $items = $sliderItemRepository->findEnabledForConsumer($user);
        $slides = $this->renderView('slider/_slides.html.twig', ['items' => $items]);

        return $this->render('slider/slider.html.twig', [
            'user' => $user,
            'slides' => $slides,
            'signature' => md5($slides),
        ]);
    }

    /**
     * Lightweight endpoint polled by the slider to refresh its content in the
     * background. Returns a signature so the client can swap slides only when
     * something actually changed (new/edited/removed/reordered content or a
     * changed slide duration).
     */
    #[Route('/slider/{slug}/content', name: 'app_slider_content', methods: ['GET'])]
    public function content(User $user, SliderItemRepository $sliderItemRepository): JsonResponse
    {
        $items = $sliderItemRepository->findEnabledForConsumer($user);
        $slides = $this->renderView('slider/_slides.html.twig', ['items' => $items]);

        return new JsonResponse([
            'signature' => md5($slides),
            'duration' => $user->getDurationBetweenSlides() * 1000,
            'html' => $slides,
        ]);
    }
}
