<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\UserDevicePinType;
use App\Form\UserType;
use App\Repository\ContentRepository;
use App\Repository\UserRepository;
use App\Service\AudienceSynchronizer;
use App\Service\DevicePinAssigner;
use App\Service\UserPasswordUpdater;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/management')]
final class AdminDashboardController extends ManagementController
{
    private const USERNAME_TAKEN = 'Ein Benutzer mit diesem oder einem sehr ähnlichen Namen existiert bereits. Wählen Sie bitte einen anderen.';

    #[Route('/admin', name: 'app_management_admin')]
    #[IsGranted('ROLE_ADMIN')]
    public function adminAction(Request $request, UserPasswordUpdater $passwordUpdater, EntityManagerInterface $entityManager, UserRepository $userRepository, ContentRepository $contentRepository, AudienceSynchronizer $audienceSynchronizer, LoggerInterface $logger): Response
    {
        $passwordForm = $this->createForm(ChangePasswordType::class);
        $passwordForm->handleRequest($request);

        if ($passwordChanged = $this->changeOwnPassword($passwordForm, $passwordUpdater, $entityManager)) {
            return $passwordChanged;
        }

        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $passwordUpdater->update($user, $form->get('plainPassword')->getData());
                $user->setRoles(['ROLE_USER']);

                $entityManager->persist($user);
                $entityManager->flush();

                // Deliver existing "publish to all" content from other creators
                // to the freshly created consumer.
                $audienceSynchronizer->onUserCreated($user);
                $entityManager->flush();

                $this->addFlash('success', 'Benutzer wurde erfolgreich hinzugefügt.');

                return $this->redirectToRoute('app_management_admin');

            } catch (UniqueConstraintViolationException $e) {
                $form->get('username')->addError(new FormError(self::USERNAME_TAKEN));
            } catch (\Exception $e) {
                $logger->error('Creating a user failed.', ['exception' => $e]);
                $this->addFlash('danger', 'Beim Anlegen des Benutzers gab es einen unerwarteten Fehler.');
            }
        }

        /** @var User $admin */
        $admin = $this->getUser();

        return $this->render('management/admin.html.twig', [
            'users' => $userRepository->getUsersByRole('ROLE_USER'),
            'contents' => $contentRepository->findByCreator($admin),
            'form' => $form->createView(),
            'passwordForm' => $passwordForm->createView(),
        ], new Response(null, $this->statusFor($form, $passwordForm)));
    }

    #[Route('/admin/edit-user/{id}', name: 'app_management_edit_user')]
    #[IsGranted('ROLE_ADMIN')]
    public function editUser(Request $request, User $user, UserPasswordUpdater $passwordUpdater, EntityManagerInterface $entityManager, AudienceSynchronizer $audienceSynchronizer, LoggerInterface $logger): Response
    {
        // Capture the allowed targets before the form changes them, so we can
        // retract content for targets that get removed.
        $oldAllowedIds = $audienceSynchronizer->resolveAllowedTargetIds($user);

        $devicePinForm = $this->createForm(UserDevicePinType::class, $user);
        $devicePinForm->handleRequest($request);

        if ($devicePinForm->isSubmitted() && $devicePinForm->isValid()) {
            try {
                $entityManager->flush();
                $this->addFlash('success', $user->getDevicePin()
                    ? 'Die Fernseher-PIN wurde gespeichert. Geben Sie sie jetzt auf dem Fernseher ein.'
                    : 'Die Fernseher-PIN wurde entfernt. Verbundene Fernseher verlieren ihre Zuordnung.');
            } catch (UniqueConstraintViolationException $e) {
                $this->addFlash('danger', 'Diese PIN ist bereits vergeben – bitte wählen Sie eine andere.');
            }

            return $this->redirectToRoute('app_management_edit_user', ['id' => $user->getId()]);
        }

        $form = $this->createForm(UserType::class, $user, ['is_new' => false, 'current_user_id' => $user->getId()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $passwordUpdater->update($user, $form->get('plainPassword')->getData());

                $entityManager->flush();

                $audienceSynchronizer->syncCreatorTargets($user, $oldAllowedIds);
                $entityManager->flush();

                $this->addFlash('success', 'Der Benutzer wurde erfolgreich aktualisiert.');

                return $this->redirectToRoute('app_management_admin');

            } catch (UniqueConstraintViolationException $e) {
                $form->get('username')->addError(new FormError(self::USERNAME_TAKEN));
            } catch (\Exception $e) {
                $logger->error('Updating a user failed.', ['exception' => $e]);
                $this->addFlash('danger', 'Beim Aktualisieren des Benutzers gab es einen unerwarteten Fehler.');
            }
        }

        return $this->render('management/edit_user.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'devicePinForm' => $devicePinForm->createView(),
        ], new Response(null, $this->statusFor($form, $devicePinForm)));
    }

    #[Route('/admin/delete-user/{id}', name: 'app_management_delete_user', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteUser(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        if ($user->isAdmin()) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            $entityManager->remove($user);
            $entityManager->flush();
            $this->addFlash('success', 'Benutzer wurde erfolgreich entfernt.');
        } else {
            $this->addFlash('danger', 'Ungültiger CSRF-Token.');
        }

        return $this->redirectToRoute('app_management_admin');
    }

    #[Route('/admin/reset-device-pins', name: 'app_management_reset_device_pins', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function resetDevicePins(Request $request, UserRepository $userRepository, DevicePinAssigner $pinAssigner, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('reset-device-pins', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Ungültiger CSRF-Token.');

            return $this->redirectToRoute('app_management_admin');
        }

        $kitas = $this->selectedKitas($request, $userRepository);
        if ([] === $kitas) {
            $this->addFlash('danger', 'Es wurden keine Kitas ausgewählt.');

            return $this->redirectToRoute('app_management_admin');
        }

        $pinAssigner->assignNewPins($kitas);
        $entityManager->flush();
        $this->addFlash('success', sprintf(
            '%d Kita(s) haben eine neue Fernseher-PIN erhalten. Bereits verbundene Fernseher zeigen wieder die PIN-Eingabe.',
            count($kitas),
        ));

        return $this->redirectToRoute('app_management_admin');
    }

    #[Route('/admin/delete-users', name: 'app_management_delete_users', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteUsers(Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete-users', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Ungültiger CSRF-Token.');

            return $this->redirectToRoute('app_management_admin');
        }

        $kitas = $this->selectedKitas($request, $userRepository);
        if ([] === $kitas) {
            $this->addFlash('danger', 'Es wurden keine Kitas ausgewählt.');

            return $this->redirectToRoute('app_management_admin');
        }

        foreach ($kitas as $kita) {
            $entityManager->remove($kita);
        }
        $entityManager->flush();
        $this->addFlash('success', sprintf('%d Kita(s) wurden mitsamt all ihrer Beiträge gelöscht.', count($kitas)));

        return $this->redirectToRoute('app_management_admin');
    }

    /**
     * The Kitas ticked in the admin's list; admin accounts are never part of a selection.
     *
     * @return list<User>
     */
    private function selectedKitas(Request $request, UserRepository $userRepository): array
    {
        $ids = $request->request->all('ids');
        if ([] === $ids) {
            return [];
        }

        return array_values(array_filter(
            $userRepository->findBy(['id' => $ids]),
            fn (User $user) => !$user->isAdmin(),
        ));
    }
}
