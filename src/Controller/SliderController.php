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
     * Cookie holding the PIN a TV was linked with. Browsers cap cookie
     * lifetimes (~400 days), so it is re-issued on every visit, which makes
     * it effectively permanent on a regularly running device.
     */
    private const PIN_COOKIE = 'kitamanager_display_pin';

    /**
     * Entry point for TVs: each user defines a unique 4-digit PIN in their
     * panel; the TV enters it once here and is linked via a cookie. When the
     * PIN no longer resolves (user changed or removed it), the device is
     * asked to enter the current PIN again.
     *
     * NOTE: must be declared before the /slider/{slug?} route so that
     * "display" is not interpreted as a slug.
     */
    #[Route('/slider/display', name: 'app_slider_display', methods: ['GET', 'POST'])]
    public function display(Request $request, UserRepository $userRepository): Response
    {
        if ($request->isMethod('POST')) {
            $pin = trim((string) $request->request->get('pin'));
            $user = $this->isCsrfTokenValid('display-pin', $request->request->get('_token'))
                ? $userRepository->findOneBy(['devicePin' => $pin])
                : null;

            if ($user) {
                return $this->redirectToSliderWithPinCookie($user, $pin);
            }

            // 422 so Turbo renders the error response of the form submission.
            return $this->render('slider/display.html.twig', [
                'error' => 'Diese PIN ist keiner Kita zugeordnet. Bitte prüfen Sie die Eingabe und versuchen Sie es erneut.',
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $cookiePin = $request->cookies->get(self::PIN_COOKIE);

        if ($cookiePin) {
            $user = $userRepository->findOneBy(['devicePin' => $cookiePin]);

            if ($user) {
                return $this->redirectToSliderWithPinCookie($user, $cookiePin);
            }

            return $this->render('slider/display.html.twig', [
                'error' => 'Ihrer PIN konnte kein Slider zugewiesen werden. Bitte geben Sie die aktuelle PIN erneut ein.',
            ]);
        }

        return $this->render('slider/display.html.twig', [
            'error' => null,
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

    private function redirectToSliderWithPinCookie(User $user, string $pin): Response
    {
        $response = $this->redirectToRoute('app_slider', ['slug' => $user->getSlug()]);
        $response->headers->setCookie(
            Cookie::create(self::PIN_COOKIE, $pin)
                ->withExpires(new \DateTimeImmutable('+400 days'))
                ->withPath('/')
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX)
        );

        return $response;
    }
}
