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
use App\Security\Dashboard;
use App\Service\AudienceSynchronizer;
use App\Service\ContentPublisher;
use App\Service\DevicePinAssigner;
use App\Service\ImageDownscaleException;
use App\Service\ImageDownscaler;
use App\Service\ImageUploader;
use App\Service\SliderOrder;
use App\Service\UserPasswordUpdater;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/management')]
final class ManagementController extends AbstractController
{
    private const USERNAME_TAKEN = 'Ein Benutzer mit diesem oder einem sehr ähnlichen Namen existiert bereits. Wählen Sie bitte einen anderen.';
    private const IMAGE_NOT_STORED = 'Beim Speichern des Bildes gab es einen unerwarteten Fehler.';

    public function __construct(private readonly Dashboard $dashboard)
    {
    }

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

    #[Route('/user/create-image', name: 'app_management_create_image')]
    public function createImage(Request $request, ImageDownscaler $imageDownscaler, ContentPublisher $contentPublisher, AudienceSynchronizer $audienceSynchronizer, LoggerInterface $logger): Response
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

            $storedCount = 0;
            $failed = [];
            foreach ($imageFiles as $imageFile) {
                // No-JS fallback path: downscale oversized images here as well.
                try {
                    $imageDownscaler->prepareForTvIfNeeded($imageFile->getPathname());
                } catch (ImageDownscaleException $e) {
                    $failed[] = $imageFile->getClientOriginalName() . ': ' . $e->getMessage();
                    continue;
                }

                // Each photo is stored completely before the next one starts,
                // so a failure leaves nothing half-stored behind it.
                try {
                    $image = (new Content())->setType(eContentType::IMAGE)->setCreator($creator);
                    $contentPublisher->publish($image, $imageFile, $audienceIds, $audienceAll);
                } catch (\Exception $e) {
                    $logger->error('Storing an uploaded image failed.', ['exception' => $e]);
                    $failed[] = self::IMAGE_NOT_STORED;
                    break;
                }

                $storedCount++;
                $this->rememberUploadedImage();
            }

            foreach ($failed as $failure) {
                $this->addFlash('danger', $failure);
            }

            if ($storedCount > 0) {
                return $this->redirectToRoute('app_management_user', ['_fragment' => 'mein-slider']);
            }

            return $this->redirectToRoute('app_management_create_image');
        }

        return $this->render('management/create_content.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Neue Fotos hinzufügen',
        ], new Response(null, $this->statusFor($form)));
    }

    /**
     * AJAX endpoint of the bulk upload: receives exactly ONE image per request.
     * Processing images one at a time (instead of the whole batch in a single
     * POST) is what keeps memory usage flat and prevents server OOM. Oversized
     * images are downscaled to the TV limit instead of being rejected.
     */
    #[Route('/user/upload-image', name: 'app_management_upload_image', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadImage(Request $request, ImageDownscaler $imageDownscaler, ContentPublisher $contentPublisher, LoggerInterface $logger): JsonResponse
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

        try {
            $downscaled = $imageDownscaler->prepareForTvIfNeeded($file->getPathname());
        } catch (ImageDownscaleException $e) {
            return $this->uploadError($e->getMessage());
        }

        /** @var User $creator */
        $creator = $this->getUser();

        $audienceAll = $request->request->getBoolean('audienceAll');
        $audienceIds = array_map('intval', $request->request->all('audience'));

        try {
            $image = (new Content())->setType(eContentType::IMAGE)->setCreator($creator);
            $contentPublisher->publish($image, $file, $audienceIds, $audienceAll);
        } catch (\Exception $e) {
            $logger->error('Storing an uploaded image failed.', ['exception' => $e]);

            return $this->uploadError(self::IMAGE_NOT_STORED, Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->rememberUploadedImage();

        return $this->json(['ok' => true, 'downscaled' => $downscaled]);
    }

    /**
     * One flash entry per stored picture: the next page counts them and shows a
     * single message, also when the pictures arrived in separate requests.
     */
    private function rememberUploadedImage(): void
    {
        $this->addFlash('uploaded-image', true);
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
        try {
            $imageDownscaler->prepareForTvIfNeeded($imageFile->getPathname());

            return true;
        } catch (ImageDownscaleException $e) {
            $form->get('imageFile')->addError(new FormError($e->getMessage()));

            return false;
        }
    }

    #[Route('/user/create-article', name: 'app_management_create_article')]
    public function createArticle(Request $request, ImageDownscaler $imageDownscaler, ContentPublisher $contentPublisher, AudienceSynchronizer $audienceSynchronizer, LoggerInterface $logger): Response
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
                try {
                    $content->setType(eContentType::ARTICLE);
                    $content->setCreator($creator);
                    ['ids' => $audienceIds, 'all' => $audienceAll] = $this->extractAudience($form);
                    $contentPublisher->publish($content, $imageFile, $audienceIds, $audienceAll);

                    $this->addFlash('success', 'Artikel erfolgreich erstellt!');

                    return $this->redirectToRoute('app_management_user', ['_fragment' => 'mein-slider']);
                } catch (\Exception $e) {
                    $logger->error('Storing an uploaded image failed.', ['exception' => $e]);
                    $this->addFlash('danger', self::IMAGE_NOT_STORED);
                }
            }
        }

        return $this->render('management/create_content.html.twig', [
            'form' => $form->createView(),
            'page_title' => 'Neuen Artikel erstellen',
        ], new Response(null, $this->statusFor($form)));
    }

    #[Route('/user/edit-content/{id}', name: 'app_management_edit_content')]
    public function editContent(Request $request, Content $content, ImageUploader $imageUploader, ImageDownscaler $imageDownscaler, EntityManagerInterface $entityManager, AudienceSynchronizer $audienceSynchronizer, SliderItemRepository $sliderItemRepository, LoggerInterface $logger): Response
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
                try {
                    $replacedImageUrl = null;
                    if ($imageFile) {
                        $replacedImageUrl = $content->getImageUrl();
                        $content->setImageUrl($imageUploader->upload($imageFile, $creator->getId()));
                    }

                    $entityManager->flush();
                    $imageUploader->delete($replacedImageUrl);

                    if ($form->has('audience')) {
                        ['ids' => $audienceIds, 'all' => $audienceAll] = $this->extractAudience($form);
                        $audienceSynchronizer->syncContentAudience($content, $audienceIds, $audienceAll);
                        $entityManager->flush();
                    }

                    $this->addFlash('success', 'Inhalt erfolgreich aktualisiert!');

                    return $this->redirectToRoute('app_management_user', ['_fragment' => 'mein-slider']);
                } catch (\Exception $e) {
                    $logger->error('Storing an uploaded image failed.', ['exception' => $e]);
                    $this->addFlash('danger', self::IMAGE_NOT_STORED);
                }
            }
        }

        return $this->render('management/edit_content.html.twig', [
            'form' => $form->createView(),
            'page_title' => $isArticle ? 'Artikel bearbeiten' : 'Bild bearbeiten',
            'content' => $content,
        ], new Response(null, $this->statusFor($form)));
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

        return $this->redirectToRoute($this->dashboard->routeOfCurrentUser());
    }

    #[Route('/content/bulk-delete', name: 'app_management_bulk_delete_content', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkDeleteContent(Request $request, EntityManagerInterface $entityManager, ContentRepository $contentRepository): Response
    {
        if (!$this->isCsrfTokenValid('bulk-delete', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Ungültiger CSRF-Token.');

            return $this->redirectToRoute($this->dashboard->routeOfCurrentUser());
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

        return $this->redirectToRoute($this->dashboard->routeOfCurrentUser());
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

    private function changeOwnPassword(FormInterface $passwordForm, UserPasswordUpdater $passwordUpdater, EntityManagerInterface $entityManager): ?Response
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

    private function statusFor(FormInterface ...$forms): int
    {
        foreach ($forms as $form) {
            if ($form->isSubmitted() && !$form->isValid()) {
                return Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return Response::HTTP_OK;
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
