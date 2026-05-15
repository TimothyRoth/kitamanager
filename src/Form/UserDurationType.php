<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class UserDurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('durationBetweenSlides', IntegerType::class, [
                'label' => 'Dauer zwischen den Slides (in Sekunden)',
                'constraints' => [
                    new NotBlank(),
                    new Range([
                        'min' => 1,
                        'max' => 60,
                        'notInRangeMessage' => 'Die Dauer muss zwischen {{ min }} und {{ max }} Sekunden liegen.',
                    ]),
                ],
                'attr' => [
                    'min' => 1,
                    'max' => 60,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
