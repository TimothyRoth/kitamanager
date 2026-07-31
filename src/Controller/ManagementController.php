<?php

namespace App\Controller;

use App\Entity\Content;
use App\Entity\SliderItem;
use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\ContentType as ContentFormType;
use App\Enum\ContentType as eContentType;
use App\Form\UserDevicePinType;
use App\Form\UserDurationType;
use App\Form\UserType;
use App\Repository\ContentRepository;
use App\Repository\SliderItemRepository;
use App\Repository\UserRepository;
use App\Service\AudienceSynchronizer;
use App\Service\ImageDownscaleException;
use App\Service\ImageDownscaler;
use App\Service\ImageUploader;
use App\Service\UserPasswordUpdater;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/management')]
final class ManagementController extends AbstractController
{
    #[Route('/admin', name: 'app_management_admin')]
    #[IsGranted('ROLE_ADMIN')]
    public function adminAction(Request $request, UserPasswordUpdater $passwordUpdater, EntityManagerInterface $entityManager, UserRepository $userRepository, ContentRepository $contentRepository, AudienceSynchronizer $audienceSynchronizer): Response
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

                // Deliver existing "publish to all" content from other creators
                // to the freshly created consumer.
                $audienceSynchronizer->onUserCreated($user);
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

        /** @var User $admin */
        $admin = $this->getUser();

        return $this->render('management/admin.html.twig', [
            'users' => $userRepository->getUsersByRole('ROLE_USER'),
            'contents' => $contentRepository->findByCreator($admin),
            'form' => $form->createView(),
        ], new Response(null, $status));
    }

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

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $passwordUpdater->update($user, $passwordForm->get('newPassword')->getData());
            $entityManager->flush();
            $this->addFlash('success', 'Ihr Passwort wurde erfolgreich geändert.');

            return $this->redirectToRoute('app_management_user');
        }

        // 422 when a submitted form is invalid, otherwise Turbo would not
        // render the response and validation errors would never show up.
        $formInvalid = ($durationForm->isSubmitted() && !$durationForm->isValid())
            || ($passwordForm->isSubmitted() && !$passwordForm->isValid());

        return $this->render('management/user.html.twig', [
            'contents' => $contentRepository->findByCreator($user),
            'sliderItems' => $sliderItemRepository->findAllForConsumer($user),
            'durationForm' => $durationForm->createView(),
            'passwordForm' => $passwordForm->createView(),
        ], new Response(null, $formInvalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/admin/edit-user/{id}', name: 'app_management_edit_user')]
    #[IsGranted('ROLE_ADMIN')]
    public function editUser(Request $request, User $user, UserPasswordUpdater $passwordUpdater, EntityManagerInterface $entityManager, AudienceSynchronizer $audienceSynchronizer): Response
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
                $form->get('username')->addError(new FormError('Ein Benutzer mit diesem Namen existiert bereits. Wählen Sie bitte einen anderen.'));
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Beim Aktualisieren des Benutzers gab es einen unerwarteten Fehler.');
            }
        }

        $formInvalid = ($form->isSubmitted() && !$form->isValid())
            || ($devicePinForm->isSubmitted() && !$devicePinForm->isValid());

        return $this->render('management/edit_user.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'devicePinForm' => $devicePinForm->createView(),
        ], new Response(null, $formInvalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
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
    public function createImage(Request $request, ImageUploader $imageUploader, ImageDownscaler $imageDownscaler, EntityManagerInterface $entityManager, AudienceSynchronizer $audienceSynchronizer): Response
    {
        if ($this->isTruncatedUpload($request)) {
            $this->addFlash('danger', 'Der Upload war zu groß. Erlaubt sind max. 25 MB pro Bild – bitte laden Sie weniger Bilder gleichzeitig hoch.');

            return $this->redirectToRoute('app_management_create_image');
        }

        /** @var User $creator */
        $creator = $this->getUser();
        $allowed = $audienceSynchronizer->allowedTargetUsers($creator);

        $content = new Content();
        $form = $this->createForm(ContentFormType::class, $content, [
            'is_article' => false,
            'multiple' => true,
            'audience_choices' => $allowed,
            'audience_all_default' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile[] $imageFiles */
            $imageFiles = $form->get('imageFile')->getData();
            ['ids' => $audienceIds, 'all' => $audienceAll] = $this->extractAudience($form);

            $created = [];
            $failed = [];
            foreach ($imageFiles as $imageFile) {
                // No-JS fallback path: downscale oversized images here as well.
                if ($imageDownscaler->needsDownscale($imageFile->getPathname())) {
                    try {
                        $imageDownscaler->downscale($imageFile->getPathname());
                    } catch (ImageDownscaleException $e) {
                        $failed[] = $imageFile->getClientOriginalName() . ': ' . $e->getMessage();
                        continue;
                    }
                }

                $image = new Content();
                $image->setImageUrl($imageUploader->upload($imageFile, $creator->getId()));
                $image->setType(eContentType::IMAGE);
                $image->setCreator($creator);
                $entityManager->persist($image);
                $created[] = $image;
            }

            $entityManager->flush();

            foreach ($created as $image) {
                $audienceSynchronizer->syncContentAudience($image, $audienceIds, $audienceAll);
            }
            $entityManager->flush();

            foreach ($failed as $failure) {
                $this->addFlash('danger', $failure);
            }

            if ($created) {
                $this->addFlash('success', sprintf('%d Bild(er) erfolgreich hochgeladen!', count($created)));

                return $this->redirectToRoute('app_management_user', ['_fragment' => 'mein-slider']);
            }

            return $this->redirectToRoute('app_management_create_image');
        }

        return $this->render('management/create_content.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Neue Fotos hinzufügen',
        ]);
    }

    /**
     * AJAX endpoint of the bulk upload: receives exactly ONE image per request.
     * Processing images one at a time (instead of the whole batch in a single
     * POST) is what keeps memory usage flat and prevents server OOM. Oversized
     * images are downscaled to the TV limit instead of being rejected.
     */
    #[Route('/user/upload-image', name: 'app_management_upload_image', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadImage(Request $request, ImageUploader $imageUploader, ImageDownscaler $imageDownscaler, EntityManagerInterface $entityManager, AudienceSynchronizer $audienceSynchronizer): JsonResponse
    {
        // Body exceeded post_max_size: PHP dropped the whole request payload.
        if ($this->isTruncatedUpload($request)) {
            return $this->uploadError('Das Bild ist zu groß. Erlaubt sind maximal 25 MB pro Bild.');
        }

        if (!$this->isCsrfTokenValid('upload-image', $request->request->getString('_token'))) {
            return $this->uploadError('Ungültiger CSRF-Token. Bitte laden Sie die Seite neu.');
        }

        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) {
            return $this->uploadError('Das Bild konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.');
        }

        if (!$file->isValid()) {
            // PHP rejected the file before our app code ran (e.g. upload_max_filesize).
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return $this->uploadError('Das Bild ist zu groß. Erlaubt sind maximal 25 MB pro Bild.');
            }

            return $this->uploadError('Das Bild konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.');
        }

        if ($file->getSize() > ImageDownscaler::MAX_ORIGINAL_BYTES) {
            return $this->uploadError(sprintf(
                'Das Bild ist zu groß (%.1f MB). Erlaubt sind maximal 25 MB pro Bild.',
                $file->getSize() / 1_000_000
            ));
        }

        if (!in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/gif'], true)) {
            return $this->uploadError('Bitte laden Sie ein gültiges Bild hoch (JPEG, PNG, GIF).');
        }

        $downscaled = false;
        if ($imageDownscaler->needsDownscale($file->getPathname())) {
            try {
                $imageDownscaler->downscale($file->getPathname());
                $downscaled = true;
            } catch (ImageDownscaleException $e) {
                return $this->uploadError($e->getMessage());
            }
        }

        /** @var User $creator */
        $creator = $this->getUser();

        $audienceAll = $request->request->getBoolean('audienceAll');
        $audienceIds = array_map('intval', $request->request->all('audience'));

        try {
            $image = new Content();
            $image->setImageUrl($imageUploader->upload($file, $creator->getId()));
            $image->setType(eContentType::IMAGE);
            $image->setCreator($creator);
            $entityManager->persist($image);
            $entityManager->flush();

            // Invalid/forbidden audience ids are filtered inside the synchronizer.
            $audienceSynchronizer->syncContentAudience($image, $audienceIds, $audienceAll);
            $entityManager->flush();
        } catch (\Exception $e) {
            return $this->uploadError('Beim Speichern des Bildes gab es einen unerwarteten Fehler.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['ok' => true, 'downscaled' => $downscaled]);
    }

    private function uploadError(string $message, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return $this->json(['ok' => false, 'error' => $message], $status);
    }

    /**
     * Downscale an oversized upload in place before it gets stored. On failure
     * the user-facing message is attached to the form's imageFile field.
     * Returns true when the file is ready to be stored.
     */
    private function downscaleOrAddError(ImageDownscaler $imageDownscaler, UploadedFile $imageFile, FormInterface $form): bool
    {
        if (!$imageDownscaler->needsDownscale($imageFile->getPathname())) {
            return true;
        }

        try {
            $imageDownscaler->downscale($imageFile->getPathname());

            return true;
        } catch (ImageDownscaleException $e) {
            $form->get('imageFile')->addError(new FormError($e->getMessage()));

            return false;
        }
    }

    #[Route('/user/create-article', name: 'app_management_create_article')]
    public function createArticle(Request $request, ImageUploader $imageUploader, ImageDownscaler $imageDownscaler, EntityManagerInterface $entityManager, AudienceSynchronizer $audienceSynchronizer): Response
    {
        if ($this->isTruncatedUpload($request)) {
            $this->addFlash('danger', 'Der Upload war zu groß. Erlaubt sind max. 25 MB für das Bild.');

            return $this->redirectToRoute('app_management_create_article');
        }

        /** @var User $creator */
        $creator = $this->getUser();
        $allowed = $audienceSynchronizer->allowedTargetUsers($creator);

        $content = new Content();
        $form = $this->createForm(ContentFormType::class, $content, [
            'is_article' => true,
            'audience_choices' => $allowed,
            'audience_all_default' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $imageFile */
            $imageFile = $form->get('imageFile')->getData();

            if ($this->downscaleOrAddError($imageDownscaler, $imageFile, $form)) {
                $imageUrl = $imageUploader->upload($imageFile, $creator->getId());

                $content->setImageUrl($imageUrl);
                $content->setType(eContentType::ARTICLE);
                $content->setCreator($creator);

                $entityManager->persist($content);
                $entityManager->flush();

                ['ids' => $audienceIds, 'all' => $audienceAll] = $this->extractAudience($form);
                $audienceSynchronizer->syncContentAudience($content, $audienceIds, $audienceAll);
                $entityManager->flush();

                $this->addFlash('success', 'Artikel erfolgreich erstellt!');

                return $this->redirectToRoute('app_management_user', ['_fragment' => 'mein-slider']);
            }
        }

        return $this->render('management/create_content.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Neuen Artikel erstellen',
        ]);
    }

    #[Route('/user/edit-content/{id}', name: 'app_management_edit_content')]
    public function editContent(Request $request, Content $content, ImageUploader $imageUploader, ImageDownscaler $imageDownscaler, EntityManagerInterface $entityManager, AudienceSynchronizer $audienceSynchronizer, SliderItemRepository $sliderItemRepository): Response
    {
        $this->denyAccessUnlessGranted('EDIT', $content);

        if ($this->isTruncatedUpload($request)) {
            $this->addFlash('danger', 'Der Upload war zu groß und konnte nicht verarbeitet werden. Bitte laden Sie ein kleineres Bild hoch (max. 25 MB).');

            return $this->redirectToRoute('app_management_edit_content', ['id' => $content->getId()]);
        }

        $creator = $content->getCreator();
        $allowed = $audienceSynchronizer->allowedTargetUsers($creator);
        $allowedIds = array_map(static fn (User $u) => $u->getId(), $allowed);

        // Pre-select consumers that currently receive this content (excluding the creator).
        $selected = [];
        foreach ($sliderItemRepository->findByContent($content) as $item) {
            $consumer = $item->getConsumer();
            if ($consumer->getId() !== $creator->getId() && in_array($consumer->getId(), $allowedIds, true)) {
                $selected[] = $consumer;
            }
        }

        $isArticle = $content->getType() === eContentType::ARTICLE;
        $form = $this->createForm(ContentFormType::class, $content, [
            'is_article' => $isArticle,
            'is_new' => false,
            'audience_choices' => $allowed,
            'audience_selected' => $selected,
            'audience_all_default' => $content->isAudienceAll(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('imageFile')->getData();

            if (null === $imageFile || $this->downscaleOrAddError($imageDownscaler, $imageFile, $form)) {
                if ($imageFile) {
                    $oldImageUrl = $content->getImageUrl();
                    $newImageUrl = $imageUploader->upload($imageFile, $creator->getId());
                    $content->setImageUrl($newImageUrl);
                    $imageUploader->delete($oldImageUrl);
                }

                $entityManager->flush();

                if ($form->has('audience')) {
                    ['ids' => $audienceIds, 'all' => $audienceAll] = $this->extractAudience($form);
                    $audienceSynchronizer->syncContentAudience($content, $audienceIds, $audienceAll);
                    $entityManager->flush();
                }

                $this->addFlash('success', 'Inhalt erfolgreich aktualisiert!');

                return $this->redirectToRoute('app_management_user', ['_fragment' => 'mein-slider']);
            }
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

        return $this->redirectToRoute($this->dashboardRoute());
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

    #[Route('/slider-item/{id}/move-up', name: 'app_management_slider_move_up', methods: ['POST'])]
    public function moveSliderItemUp(Request $request, SliderItem $item, EntityManagerInterface $entityManager, SliderItemRepository $sliderItemRepository): Response
    {
        $this->denyAccessUnlessGranted('MANAGE', $item);

        if ($this->isCsrfTokenValid('move-up' . $item->getId(), $request->request->get('_token'))) {
            $previous = $this->adjacentSliderItem($sliderItemRepository, $item, 'up');
            if ($previous) {
                $this->swapOrder($item, $previous);
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute('app_management_user');
    }

    #[Route('/slider-item/{id}/move-down', name: 'app_management_slider_move_down', methods: ['POST'])]
    public function moveSliderItemDown(Request $request, SliderItem $item, EntityManagerInterface $entityManager, SliderItemRepository $sliderItemRepository): Response
    {
        $this->denyAccessUnlessGranted('MANAGE', $item);

        if ($this->isCsrfTokenValid('move-down' . $item->getId(), $request->request->get('_token'))) {
            $next = $this->adjacentSliderItem($sliderItemRepository, $item, 'down');
            if ($next) {
                $this->swapOrder($item, $next);
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute('app_management_user');
    }

    #[Route('/slider-item/{id}/toggle-status', name: 'app_management_slider_toggle_status', methods: ['POST'])]
    public function toggleSliderItemStatus(Request $request, SliderItem $item, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('MANAGE', $item);

        if ($this->isCsrfTokenValid('toggle-status' . $item->getId(), $request->request->get('_token'))) {
            $item->setIsEnabled(!$item->isEnabled());
            $entityManager->flush();

            $status = $item->isEnabled() ? 'aktiviert' : 'deaktiviert';
            $this->addFlash('success', "Inhalt wurde erfolgreich {$status}.");
        }

        return $this->redirectToRoute('app_management_user');
    }

    private function adjacentSliderItem(SliderItemRepository $repository, SliderItem $item, string $direction): ?SliderItem
    {
        $qb = $repository->createQueryBuilder('si')
            ->andWhere('si.consumer = :consumer')
            ->setParameter('consumer', $item->getConsumer())
            ->setMaxResults(1);

        if ('up' === $direction) {
            $qb->andWhere('si.displayOrder < :order')
                ->orderBy('si.displayOrder', 'DESC');
        } else {
            $qb->andWhere('si.displayOrder > :order')
                ->orderBy('si.displayOrder', 'ASC');
        }

        return $qb->setParameter('order', $item->getDisplayOrder())
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function swapOrder(SliderItem $a, SliderItem $b): void
    {
        $orderA = $a->getDisplayOrder();
        $a->setDisplayOrder($b->getDisplayOrder());
        $b->setDisplayOrder($orderA);
    }

    /**
     * @return array{ids: int[], all: bool}
     */
    private function extractAudience(FormInterface $form): array
    {
        if (!$form->has('audience')) {
            return ['ids' => [], 'all' => false];
        }

        $ids = [];
        foreach ($form->get('audience')->getData() as $user) {
            if ($user instanceof User) {
                $ids[] = $user->getId();
            }
        }

        return ['ids' => $ids, 'all' => (bool) $form->get('audienceAll')->getData()];
    }

    private function dashboardRoute(): string
    {
        return $this->isGranted('ROLE_ADMIN') ? 'app_management_admin' : 'app_management_user';
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
