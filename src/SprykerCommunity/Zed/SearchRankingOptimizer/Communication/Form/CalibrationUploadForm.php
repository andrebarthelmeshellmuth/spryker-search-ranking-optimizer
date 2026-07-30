<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form;

use Spryker\Zed\Kernel\Communication\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class CalibrationUploadForm extends AbstractType
{
    /**
     * @var string
     */
    public const FIELD_RELEVANT_PRODUCT_COUNT = 'relevantProductCount';

    /**
     * @var string
     */
    public const FIELD_STORE_NAME = 'storeName';

    /**
     * @var string
     */
    public const FIELD_LOCALE_NAME = 'localeName';

    /**
     * @var string
     */
    public const FIELD_FILE = 'file';

    /**
     * @var string
     */
    public const FIELD_USE_CSV_UPLOAD = 'useCsvUpload';

    /**
     * @var string
     */
    public const OPTION_STORE_CHOICES = 'store_choices';

    /**
     * @var string
     */
    public const OPTION_LOCALE_CHOICES = 'locale_choices';

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed> $options
     *
     * @return void
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(static::FIELD_RELEVANT_PRODUCT_COUNT, IntegerType::class, [
            'label' => 'Relevant products per search (X)',
            'help' => 'How many of the top-ranked products per search term are assumed relevant — e.g. 6 for '
                . 'the first two SRP rows. Used as both the sample size and the query limit for every '
                . 'uploaded search term.',
            'constraints' => [
                new NotBlank(),
                new GreaterThan(0),
            ],
        ]);

        $builder->add(static::FIELD_STORE_NAME, ChoiceType::class, [
            'label' => 'Store',
            'help' => 'Zed has no "current store" of its own — pick which store\'s catalog the calibration query runs against.',
            'choices' => $options[static::OPTION_STORE_CHOICES],
            'constraints' => [new NotBlank()],
        ]);

        $builder->add(static::FIELD_LOCALE_NAME, ChoiceType::class, [
            'label' => 'Locale',
            'help' => 'Locale the uploaded search terms are written in.',
            'choices' => $options[static::OPTION_LOCALE_CHOICES],
            'constraints' => [new NotBlank()],
        ]);

        $builder->add(static::FIELD_USE_CSV_UPLOAD, CheckboxType::class, [
            'label' => 'Bootstrap from CSV upload instead',
            'help' => 'Unchecked (default): sources search terms from the distinct queries organically '
                . 'rated via the SRP widget. Check this to bypass those and upload a CSV instead — use '
                . 'this to bootstrap calibration before real ratings exist, or for testing.',
            'required' => false,
        ]);

        $builder->add(static::FIELD_FILE, FileType::class, [
            'label' => 'Search terms (CSV, one term per line)',
            'mapped' => false,
            'required' => false,
            'constraints' => [
                new File([
                    'mimeTypes' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
                    'mimeTypesMessage' => 'Please upload a CSV file.',
                ]),
            ],
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();

            if (!$form->get(static::FIELD_USE_CSV_UPLOAD)->getData()) {
                return;
            }

            $file = $form->get(static::FIELD_FILE)->getData();

            if ($file !== null) {
                return;
            }

            $form->get(static::FIELD_FILE)->addError(new FormError('Please select a CSV file to upload.'));
        });
    }

    /**
     * @param \Symfony\Component\OptionsResolver\OptionsResolver $resolver
     *
     * @return void
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            static::OPTION_STORE_CHOICES => [],
            static::OPTION_LOCALE_CHOICES => [],
        ]);
    }

    /**
     * @return string
     */
    public function getBlockPrefix(): string
    {
        return 'search_ranking_calibration_upload';
    }
}
