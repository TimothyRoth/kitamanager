<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\ContentType as ContentFormType;
use App\Enum\ContentType as eContentType;
use App\Form\UserDurationType;
use App\Form\UserType;
use App\Repository\ContentRepository;
use App\Repository\UserRepository;
use App\Service\ImageUploader;
use App\Service\UserPasswordUpdater;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/management')]
final class ManagementController extends AbstractController
{
    #[Route('/admin', name: 'app_management_admin')]
    #[IsGranted('ROLE_ADMIN')]
    public function adminAction(Request $request, UserPasswordUpdater $passwordUpdater, EntityManagerInterface $entityManager, UserRepository $userRepository, ContentRepository $contentRepository): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $passwordUpdater->update($user, $form->get('plainPassword')->getData());
                $user->setRoles(['ROLE_USER']);

                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Benutzer wurde erfolgreich hinzugefügt.');

                return $this->redirectToRoute('app_management_admin');

            } catch (UniqueConstraintViolationException $e) {
                $form->get('username')->addError(new FormError('Ein Benutzer mit diesem Namen existiert bereits. Wählen Sie bitte einen anderen.'));
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Beim Anlegen des Benutzers gab es einen unerwarteten Fehler.');
            }
        }

        $status = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('management/admin.html.twig', [
            'users' => $userRepository->getUsersByRole('ROLE_USER'),
            'globalContents' => $contentRepository->findBy(['user' => null], ['displayOrder' => 'ASC']),
            'form' => $form->createView(),
        ], new Response(null, $status));
    }

    #[Route('/user', name: 'app_management_user')]
    public function userAction(Request $request, EntityManagerInterface $entityManager, UserPasswordUpdater $passwordUpdater): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $contents = $user->getContent();

        $durationForm = $this->createForm(UserDurationType::class, $user);
        $durationForm->handleRequest($request);

        if ($durationForm->isSubmitted() && $durationForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Die Anzeigedauer wurde erfolgreich aktualisiert.');

            return $this->redirectToRoute('app_management_user');
        }

        $passwordForm = $this->createForm(ChangePasswordType::class);
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $passwordUpdater->update($user, $passwordForm->get('newPassword')->getData());
            $entityManager->flush();
            $this->addFlash('success', 'Ihr Passwort wurde erfolgreich geändert.');

            return $this->redirectToRoute('app_management_user');
        }

        return $this->render('management/user.html.twig', [
            'contents' => $contents,
            'durationForm' => $durationForm->createView(),
            'passwordForm' => $passwordForm->createView(),
        ]);
    }

    #[Route('/admin/edit-user/{id}', name: 'app_management_edit_user')]
    #[IsGranted('ROLE_ADMIN')]
    public function editUser(Request $request, User $user, UserPasswordUpdater $passwordUpdater, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UserType::class, $user, ['is_new' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $passwordUpdater->update($user, $form->get('plainPassword')->getData());

                $entityManager->flush();

                $this->addFlash('success', 'Der Benutzer wurde erfolgreich aktualisiert.');

                return $this->redirectToRoute('app_management_admin');

            } catch (UniqueConstraintViolationException $e) {
                $form->get('username')->addError(new FormError('Ein Benutzer mit diesem Namen existiert bereits. Wählen Sie bitte einen anderen.'));
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Beim Aktualisieren des Benutzers gab es einen unerwarteten Fehler.');
            }
        }

        $status = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('management/edit_user.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ], new Response(null, $status));
    }

    #[Route('/admin/delete-user/{id}', name: 'app_management_delete_user', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteUser(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            $entityManager->remove($user);
            $entityManager->flush();
            $this->addFlash('success', 'Benutzer wurde erfolgreich entfernt.');
        } else {
            $this->addFlash('danger', 'Ungültiger CSRF-Token.');
        }

        return $this->redirectToRoute('app_management_admin');
    }

    #[Route('/user/create-image', name: 'app_management_create_image')]
    public function createImage(Request $request, ImageUploader $imageUploader, EntityManagerInterface $entityManager): Response
    {
        if ($this->isTruncatedUpload($request)) {
            $this->addFlash('danger', 'Der Upload war zu groß. Erlaubt sind max. 10MB pro Bild – bitte laden Sie ggf. weniger Bilder gleichzeitig hoch.');

            return $this->redirectToRoute('app_management_create_image');
        }

        $content = new Content();
        $form = $this->createForm(ContentFormType::class, $content, ['is_article' => false, 'multiple' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile[] $imageFiles */
            $imageFiles = $form->get('imageFile')->getData();

            foreach ($imageFiles as $imageFile) {
                $image = new Content();
                $image->setImageUrl($imageUploader->upload($imageFile, $this->getUser()->getId()));
                $image->setType(eContentType::IMAGE);
                $image->setUser($this->getUser());
                $entityManager->persist($image);
            }

            $entityManager->flush();

            $this->addFlash('success', sprintf('%d Bild(er) erfolgreich hochgeladen!', count($imageFiles)));

            return $this->redirectToRoute('app_management_user');
        }

        return $this->render('management/create_content.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Neue Bilder hinzufügen',
        ]);
    }

    #[Route('/user/create-article', name: 'app_management_create_article')]
    public function createArticle(Request $request, ImageUploader $imageUploader, EntityManagerInterface $entityManager): Response
    {
        if ($this->isTruncatedUpload($request)) {
            $this->addFlash('danger', 'Der Upload war zu groß. Erlaubt sind max. 10MB pro Bild – bitte laden Sie ggf. weniger Bilder gleichzeitig hoch.');

            return $this->redirectToRoute('app_management_create_article');
        }

        $content = new Content();
        $form = $this->createForm(ContentFormType::class, $content, ['is_article' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $imageFile */
            $imageFile = $form->get('imageFile')->getData();
            $imageUrl = $imageUploader->upload($imageFile, $this->getUser()->getId());

            $content->setImageUrl($imageUrl);
            $content->setType(eContentType::ARTICLE);
            $content->setUser($this->getUser());

            $entityManager->persist($content);
            $entityManager->flush();

            $this->addFlash('success', 'Artikel erfolgreich erstellt!');

            return $this->redirectToRoute('app_management_user');
        }

        return $this->render('management/create_content.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Neuen Artikel erstellen',
        ]);
    }

    #[Route('/user/edit-content/{id}', name: 'app_management_edit_content')]
    public function editContent(Request $request, Content $content, ImageUploader $imageUploader, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('EDIT', $content);

        if ($this->isTruncatedUpload($request)) {
            $this->addFlash('danger', 'Der Upload war zu groß und konnte nicht verarbeitet werden. Bitte laden Sie ein kleineres Bild hoch (max. 10MB).');

            return $this->redirectToRoute('app_management_edit_content', ['id' => $content->getId()]);
        }

        $isArticle = $content->getType() === eContentType::ARTICLE;
        $form = $this->createForm(ContentFormType::class, $content, [
            'is_article' => $isArticle,
            'is_new' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('imageFile')->getData();
            if ($imageFile) {
                $oldImageUrl = $content->getImageUrl();
                $newImageUrl = $imageUploader->upload($imageFile, $this->getUser()->getId());
                $content->setImageUrl($newImageUrl);
                $imageUploader->delete($oldImageUrl);
            }

            $entityManager->flush();
            $this->addFlash('success', 'Inhalt erfolgreich aktualisiert!');

            return $this->redirectToRoute('app_management_user');
        }

        return $this->render('management/edit_content.html.twig', [
            'form' => $form->createView(),
            'page_title' => $isArticle ? 'Artikel bearbeiten' : 'Bild bearbeiten',
            'content' => $content,
        ]);
    }

    #[Route('/user/delete-content/{id}', name: 'app_management_delete_content', methods: ['POST'])]
    public function deleteContent(Request $request, Content $content, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('DELETE', $content);

        if ($this->isCsrfTokenValid('delete' . $content->getId(), $request->request->get('_token'))) {
            $entityManager->remove($content);
            $entityManager->flush();
            $this->addFlash('success', 'Inhalt erfolgreich gelöscht!');
        } else {
            $this->addFlash('danger', 'Ungültiger CSRF-Token.');
        }

        return $this->redirectToRoute('app_management_user');
    }

    #[Route('/content/bulk-delete', name: 'app_management_bulk_delete_content', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkDeleteContent(Request $request, EntityManagerInterface $entityManager, ContentRepository $contentRepository): Response
    {
        $redirect = $request->request->get('_redirect');
        $redirectRoute = in_array($redirect, ['app_management_user', 'app_management_admin'], true)
            ? $redirect
            : 'app_management_user';

        if (!$this->isCsrfTokenValid('bulk-delete', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Ungültiger CSRF-Token.');

            return $this->redirectToRoute($redirectRoute);
        }

        $ids = $request->request->all('ids');
        $deleted = 0;

        foreach ($ids as $id) {
            $content = $contentRepository->find($id);
            if (null === $content || !$this->isGranted('DELETE', $content)) {
                continue;
            }

            $entityManager->remove($content);
            $deleted++;
        }

        if ($deleted > 0) {
            $entityManager->flush();
            $this->addFlash('success', sprintf('%d Inhalt(e) erfolgreich gelöscht.', $deleted));
        } else {
            $this->addFlash('danger', 'Es wurden keine Inhalte gelöscht.');
        }

        return $this->redirectToRoute($redirectRoute);
    }

    #[Route('/user/content/{id}/move-up', name: 'app_management_content_move_up', methods: ['POST'])]
    public function moveContentUp(Request $request, Content $content, EntityManagerInterface $entityManager, ContentRepository $contentRepository): Response
    {
        $this->denyAccessUnlessGranted('EDIT', $content);

        if ($this->isCsrfTokenValid('move-up' . $content->getId(), $request->request->get('_token'))) {
            $qb = $contentRepository->createQueryBuilder('c');
            if ($content->getUser()) {
                $qb->where('c.user = :user')->setParameter('user', $this->getUser());
            } else {
                $qb->where('c.user IS NULL');
            }
            $qb->andWhere('c.displayOrder < :order')->setParameter('order', $content->getDisplayOrder())->orderBy('c.displayOrder', 'DESC');
            $previousContent = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();

            if ($previousContent) {
                $currentOrder = $content->getDisplayOrder();
                $content->setDisplayOrder($previousContent->getDisplayOrder());
                $previousContent->setDisplayOrder($currentOrder);
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute($content->getUser() ? 'app_management_user' : 'app_management_admin');
    }

    #[Route('/user/content/{id}/move-down', name: 'app_management_content_move_down', methods: ['POST'])]
    public function moveContentDown(Request $request, Content $content, EntityManagerInterface $entityManager, ContentRepository $contentRepository): Response
    {
        $this->denyAccessUnlessGranted('EDIT', $content);

        if ($this->isCsrfTokenValid('move-down' . $content->getId(), $request->request->get('_token'))) {
            $qb = $contentRepository->createQueryBuilder('c');
            if ($content->getUser()) {
                $qb->where('c.user = :user')->setParameter('user', $this->getUser());
            } else {
                $qb->where('c.user IS NULL');
            }
            $qb->andWhere('c.displayOrder > :order')->setParameter('order', $content->getDisplayOrder())->orderBy('c.displayOrder', 'ASC');
            $nextContent = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();

            if ($nextContent) {
                $currentOrder = $content->getDisplayOrder();
                $content->setDisplayOrder($nextContent->getDisplayOrder());
                $nextContent->setDisplayOrder($currentOrder);
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute($content->getUser() ? 'app_management_user' : 'app_management_admin');
    }

    #[Route('/user/content/{id}/toggle-status', name: 'app_management_content_toggle_status', methods: ['POST'])]
    public function toggleContentStatus(Request $request, Content $content, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('EDIT', $content);

        if ($this->isCsrfTokenValid('toggle-status' . $content->getId(), $request->request->get('_token'))) {
            $content->setIsEnabled(!$content->isEnabled());
            $entityManager->flush();

            $status = $content->isEnabled() ? 'aktiviert' : 'deaktiviert';
            $this->addFlash('success', "Inhalt wurde erfolgreich {$status}.");
        }

        return $this->redirectToRoute($content->getUser() ? 'app_management_user' : 'app_management_admin');
    }

    #[Route('/admin/create-global-content', name: 'app_management_create_global_content')]
    #[IsGranted('ROLE_ADMIN')]
    public function adminCreateContent(Request $request, ImageUploader $imageUploader, EntityManagerInterface $entityManager): Response
    {
        // Determine if creating an article or image based on a query parameter, for example
        $isArticle = $request->query->getBoolean('is_article', false);

        if ($this->isTruncatedUpload($request)) {
            $this->addFlash('danger', 'Der Upload war zu groß. Erlaubt sind max. 10MB pro Bild – bitte laden Sie ggf. weniger Bilder gleichzeitig hoch.');

            return $this->redirectToRoute('app_management_create_global_content', ['is_article' => $isArticle ? 1 : 0]);
        }

        $content = new Content();

        $form = $this->createForm(ContentFormType::class, $content, [
            'is_article' => $isArticle,
            'multiple' => !$isArticle,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isArticle) {
                /** @var UploadedFile $imageFile */
                $imageFile = $form->get('imageFile')->getData();
                $content->setImageUrl($imageUploader->upload($imageFile, null));
                $content->setType(eContentType::ARTICLE);
                $content->setUser(null); // Explicitly set user to null for global content

                $entityManager->persist($content);
                $entityManager->flush();

                $this->addFlash('success', 'Globaler Inhalt erfolgreich erstellt!');
            } else {
                /** @var UploadedFile[] $imageFiles */
                $imageFiles = $form->get('imageFile')->getData();

                foreach ($imageFiles as $imageFile) {
                    $image = new Content();
                    $image->setImageUrl($imageUploader->upload($imageFile, null)); // null for global content
                    $image->setType(eContentType::IMAGE);
                    $image->setUser(null);
                    $entityManager->persist($image);
                }

                $entityManager->flush();

                $this->addFlash('success', sprintf('%d globale(s) Bild(er) erfolgreich erstellt!', count($imageFiles)));
            }

            return $this->redirectToRoute('app_management_admin');
        }

        return $this->render('management/create_content.html.twig', [
            'form' => $form->createView(),
            'page_title' => $isArticle ? 'Neuen Artikel erstellen' : 'Neue Bilder hinzufügen',
        ]);
    }

    /**
     * Detects a POST whose body exceeded PHP's post_max_size: PHP discards $_POST/$_FILES,
     * so the form never submits and the user would otherwise get no feedback at all.
     */
    private function isTruncatedUpload(Request $request): bool
    {
        if (!$request->isMethod('POST')) {
            return false;
        }

        $contentLength = (int) $request->server->get('CONTENT_LENGTH', 0);

        return $contentLength > 0
            && 0 === $request->request->count()
            && 0 === $request->files->count();
    }
}
