<?php

namespace App\CoreBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class ProjectBaseImageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('docker_base_image', 'choice', [
                'label' => false,
                'choices' => [
                    'ubuntu:precise' => 'Ubuntu Precise (12.04)',
                    'symfony2:latest' => 'Symfony 2',
                ]
            ])
            ->add('save', 'submit', ['label' => 'Save project\'s base image']);
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults([
            'data_class' => 'App\\CoreBundle\\Entity\\Project',
        ]);
    }

    public function getName()
    {
        return 'project_base_image';
    }
}