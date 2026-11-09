<?php

namespace App\Controller;

use App\Entity\SliderItem;
use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\UserDurationType;
use App\Repository\ContentRepository;
use App\Repository\SliderItemRepository;
use App\Service\SliderOrder;
use App\Service\UserPasswordUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/management')]
final class KitaDashboardController extends ManagementController
{
    #[Route('/user', name: 'app_management_user')]
    public function userAction(Request $request, EntityManagerInterface $entityManager, UserPasswordUpdater $passwordUpdater, ContentRepository $contentRepository, SliderItemRepository $sliderItemRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $durationForm = $this->createForm(UserDurationType::class, $user);
        $durationForm->handleRequest($request);

        if ($durationForm->isSubmitted() && $durationForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Die Anzeigedauer wurde erfolgreich aktualisiert.');

            return $this->redirectToRoute('app_management_user');
        }

        $passwordForm = $this->createForm(ChangePasswordType::class);
        $passwordForm->handleRequest($request);

        if ($passwordChanged = $this->changeOwnPassword($passwordForm, $passwordUpdater, $entityManager)) {
            return $passwordChanged;
        }

        return $this->render('management/user.html.twig', [
            'contents' => $contentRepository->findByCreator($user),
            'sliderItems' => $sliderItemRepository->findAllForConsumer($user),
            'durationForm' => $durationForm->createView(),
            'passwordForm' => $passwordForm->createView(),
        ], new Response(null, $this->statusFor($durationForm, $passwordForm)));
    }

    #[Route('/user/manual', name: 'app_management_manual')]
    public function manual(#[Autowire('%kernel.project_dir%/docs/benutzerhandbuch-kita.pdf')] string $manualPath): BinaryFileResponse
    {
        return $this->file($manualPath, 'Kita-Manager-Anleitung.pdf');
    }

    #[Route('/slider-item/reorder', name: 'app_management_slider_reorder', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function reorderSlider(Request $request, SliderOrder $sliderOrder, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('reorder-slider', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $consumer */
        $consumer = $this->getUser();
        $sliderOrder->arrange($consumer, array_map('intval', $request->request->all('ids')));
        $entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/slider-item/{id}/toggle-status', name: 'app_management_slider_toggle_status', methods: ['POST'])]
    public function toggleSliderItemStatus(Request $request, SliderItem $item, EntityManagerInterface $entityManager): Response
    {
        // Only the creator may activate/deactivate; assigned foreign content stays fixed.
        $this->denyAccessUnlessGranted('TOGGLE', $item);

        if ($this->isCsrfTokenValid('toggle-status' . $item->getId(), $request->request->get('_token'))) {
            $item->setIsEnabled(!$item->isEnabled());
            $entityManager->flush();

            $status = $item->isEnabled() ? 'aktiviert' : 'deaktiviert';
            $this->addFlash('success', "Inhalt wurde erfolgreich {$status}.");
        }

        return $this->redirectToRoute('app_management_user');
    }
}
