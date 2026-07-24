<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\SliderItemRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SliderController extends AbstractController
{
    /**
     * Cookie that remembers which kita a display device (TV) should show,
     * so the device is not asked again on every restart.
     */
    private const DISPLAY_COOKIE = 'kitamanager_display_slug';

    /**
     * Entry point for TVs: remembers the selected kita in a cookie and then
     * always forwards to that kita's slider. Without a (valid) cookie a
     * one-time selection dropdown is shown. Append ?change=1 to pick a
     * different kita on a device that already has one stored.
     *
     * NOTE: must be declared before the /slider/{slug?} route so that
     * "display" is not interpreted as a slug.
     */
    #[Route('/slider/display', name: 'app_slider_display')]
    public function display(Request $request, UserRepository $userRepository): Response
    {
        if ($request->isMethod('POST')) {
            $slug = (string) $request->request->get('slug');
            $user = $this->isCsrfTokenValid('select-display', $request->request->get('_token'))
                ? $userRepository->findOneBy(['slug' => $slug])
                : null;

            if ($user) {
                return $this->redirectToSliderWithCookie($user);
            }

            $this->addFlash('danger', 'Die Auswahl konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.');

            return $this->redirectToRoute('app_slider_display');
        }

        $cookieSlug = $request->cookies->get(self::DISPLAY_COOKIE);

        if ($cookieSlug && !$request->query->getBoolean('change')) {
            $user = $userRepository->findOneBy(['slug' => $cookieSlug]);

            if ($user) {
                // Re-setting the cookie keeps it from ever expiring on a TV
                // that regularly opens this route.
                return $this->redirectToSliderWithCookie($user);
            }
            // Stale cookie (kita deleted/renamed): fall through to the dropdown.
        }

        return $this->render('slider/display.html.twig', [
            'kitas' => $userRepository->getUsersByRole('ROLE_USER'),
            'currentSlug' => $cookieSlug,
        ]);
    }

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

    private function redirectToSliderWithCookie(User $user): Response
    {
        $response = $this->redirectToRoute('app_slider', ['slug' => $user->getSlug()]);
        $response->headers->setCookie(
            Cookie::create(self::DISPLAY_COOKIE, $user->getSlug())
                ->withExpires(new \DateTimeImmutable('+1 year'))
                ->withPath('/')
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX)
        );

        return $response;
    }
}
