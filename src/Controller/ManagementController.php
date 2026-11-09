<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\Dashboard;
use App\Service\UserPasswordUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;

abstract class ManagementController extends AbstractController
{
    public function __construct(protected readonly Dashboard $dashboard)
    {
    }

    protected function changeOwnPassword(FormInterface $passwordForm, UserPasswordUpdater $passwordUpdater, EntityManagerInterface $entityManager): ?Response
    {
        if (!$passwordForm->isSubmitted() || !$passwordForm->isValid()) {
            return null;
        }

        /** @var User $user */
        $user = $this->getUser();
        $passwordUpdater->update($user, $passwordForm->get('newPassword')->getData());
        $entityManager->flush();
        $this->addFlash('success', 'Ihr Passwort wurde erfolgreich geändert.');

        return $this->redirectToRoute($this->dashboard->routeOfCurrentUser());
    }

    protected function statusFor(FormInterface ...$forms): int
    {
        foreach ($forms as $form) {
            if ($form->isSubmitted() && !$form->isValid()) {
                return Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return Response::HTTP_OK;
    }
}
