<?php

namespace App\Form;

use App\Entity\Content;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\NotBlank;

class ContentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // TVs cannot reliably render larger files, so each individual image is
        // capped at 3MB. The total size of a bulk upload is not limited here.
        $imageConstraint = new Image([
            'maxSize' => '3M',
            'maxSizeMessage' => 'Die Datei ist zu groß ({{ size }} {{ suffix }}). Erlaubt sind maximal {{ limit }} {{ suffix }}.',
            'mimeTypes' => [
                'image/jpeg',
                'image/png',
                'image/gif',
            ],
            'mimeTypesMessage' => 'Bitte laden Sie ein gültiges Bild hoch (JPEG, PNG, GIF).',
            // Messages for PHP-level upload errors (e.g. a single file exceeding upload_max_filesize).
            'uploadIniSizeErrorMessage' => 'Die Datei ist zu groß. Erlaubt sind maximal {{ limit }} {{ suffix }}.',
            'uploadFormSizeErrorMessage' => 'Die Datei ist zu groß und konnte nicht hochgeladen werden.',
            'uploadPartialErrorMessage' => 'Die Datei wurde nur teilweise hochgeladen. Bitte versuchen Sie es erneut.',
            'uploadNoTmpDirErrorMessage' => 'Hochladen nicht möglich: Auf dem Server fehlt ein temporäres Verzeichnis.',
            'uploadCantWriteErrorMessage' => 'Die Datei konnte auf dem Server nicht gespeichert werden.',
            'uploadErrorMessage' => 'Beim Hochladen ist ein unerwarteter Fehler aufgetreten. Bitte versuchen Sie es erneut.',
        ]);

        $imageOptions = [
            'label' => $options['multiple'] ? 'Bilder hochladen' : 'Bild hochladen',
            'mapped' => false,
            'multiple' => $options['multiple'],
            'required' => $options['is_new'],
            'constraints' => $options['multiple']
                ? [new All(['constraints' => [$imageConstraint]])]
                : [$imageConstraint],
        ];

        if ($options['multiple']) {
            $imageOptions['attr'] = [
                'accept' => 'image/*',
                'class' => 'image-multi-upload',
            ];
        }

        $builder->add('imageFile', FileType::class, $imageOptions);

        if ($options['is_article']) {
            $builder
                ->add('title', TextType::class, [
                    'label' => 'Titel',
                    'constraints' => [new NotBlank(['message' => 'Bitte geben Sie einen Titel an.'])],
                ])
                ->add('content', TextareaType::class, [
                    'label' => 'Inhalt',
                    'attr' => ['class' => 'wysiwyg'],
                    'constraints' => [new NotBlank(['message' => 'Bitte geben Sie einen Inhalt an.'])],
                ]);
        }

        // Audience selection is only shown when the creator may publish to others.
        if (!empty($options['audience_choices'])) {
            $builder->add('audienceAll', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'An alle zugewiesenen Benutzer ausspielen',
                'data' => $options['audience_all_default'],
                'attr' => ['data-audience-all' => ''],
            ]);

            $builder->add('audience', EntityType::class, [
                'class' => User::class,
                'mapped' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'Sichtbar für',
                'choice_label' => 'username',
                'choices' => $options['audience_choices'],
                'data' => $options['audience_selected'],
                'attr' => ['data-audience-list' => ''],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Content::class,
            'is_article' => false,
            'is_new' => true,
            'multiple' => false,
            'audience_choices' => [],
            'audience_selected' => [],
            'audience_all_default' => false,
        ]);
    }
}
