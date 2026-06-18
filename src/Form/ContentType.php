<?php

namespace App\Form;

use App\Entity\Content;
use Symfony\Component\Form\AbstractType;
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
        $imageConstraint = new Image([
            'maxSize' => '10M',
            'maxSizeMessage' => 'Die Datei ist zu groß ({{ size }} {{ suffix }}). Erlaubt sind maximal {{ limit }} {{ suffix }}.',
            'mimeTypes' => [
                'image/jpeg',
                'image/png',
                'image/gif',
            ],
            'mimeTypesMessage' => 'Bitte laden Sie ein gültiges Bild hoch (JPEG, PNG, GIF).',
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Content::class,
            'is_article' => false,
            'is_new' => true,
            'multiple' => false,
        ]);
    }
}
