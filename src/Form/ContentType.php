<?php

namespace App\Form;

use App\Entity\Content;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\NotBlank;

class ContentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('imageFile', FileType::class, [
                'label' => 'Bild hochladen',
                'mapped' => false,
                'required' => $options['is_new'],
                'constraints' => [
                    new Image([
                        'maxSize' => '5M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                        ],
                        'mimeTypesMessage' => 'Bitte laden Sie ein gültiges Bild hoch (JPEG, PNG, GIF).',
                    ]),
                ],
            ]);

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
        ]);
    }
}
