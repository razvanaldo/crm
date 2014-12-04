<?php

namespace OroCRM\Bundle\AnalyticsBundle\Form\Extension;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\PersistentCollection;

//use OroCRM\Bundle\AnalyticsBundle\Validator\CategoriesConstraint;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use OroCRM\Bundle\AnalyticsBundle\Entity\RFMMetricCategory;
use OroCRM\Bundle\AnalyticsBundle\Form\Type\RFMCategorySettingsType;
use OroCRM\Bundle\ChannelBundle\Entity\Channel;
use OroCRM\Bundle\ChannelBundle\Form\Type\ChannelType;

class ChannelTypeExtension extends AbstractTypeExtension
{
    /**
     * @var DoctrineHelper
     */
    protected $doctrineHelper;

    /**
     * @var string
     */
    protected $interface;

    /**
     * @param DoctrineHelper $doctrineHelper
     * @param string $interface
     * @param string $rfmCategoryClass
     */
    public function __construct(DoctrineHelper $doctrineHelper, $interface, $rfmCategoryClass)
    {
        $this->doctrineHelper = $doctrineHelper;
        $this->interface = $interface;
        $this->rfmCategoryClass = $rfmCategoryClass;
    }

    /**
     * {@inheritdoc}
     */
    public function getExtendedType()
    {
        return ChannelType::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'preSetData']);
        $builder->addEventListener(FormEvents::POST_SUBMIT, [$this, 'postSubmit']);
    }

    /**
     * @param FormEvent $event
     */
    public function postSubmit(FormEvent $event)
    {
        /** @var Channel $channel */
        $channel = $event->getData();

        if (!$this->isApplicable($channel)) {
            return;
        }

        $em = $this->doctrineHelper->getEntityManager($this->rfmCategoryClass);
        $form = $event->getForm();

        $rfmEnabled = $form->get('rfm_enabled');
        $channelData = (array)$channel->getData();
        $channelData['rfm_enabled'] = (bool)$rfmEnabled->getData();
        $channel->setData($channelData);

        foreach (RFMMetricCategory::$types as $type) {
            if (!$form->has($type)) {
                continue;
            }

            /** @var PersistentCollection $categories */
            $child = $form->get($type);
            $categories = $child->getData();

            if (!$categories->isDirty()) {
                continue;
            }

            /** @var RFMMetricCategory $category */
            foreach ($categories->getInsertDiff() as $category) {
                $category
                    ->setCategoryType($type)
                    ->setChannel($channel);
                $em->persist($category);
            }

            foreach ($categories->getDeleteDiff() as $category) {
                $em->remove($category);
            }
        }
    }

    /**
     * @param FormEvent $event
     */
    public function preSetData(FormEvent $event)
    {
        /** @var Channel $channel */
        $channel = $event->getData();

        if (!$this->isApplicable($channel)) {
            return;
        }

        $categories = $this->doctrineHelper
            ->getEntityRepository($this->rfmCategoryClass)
            ->findBy(
                ['channel' => $channel],
                ['categoryIndex' => Criteria::ASC]
            );

        $channelData = (array)$channel->getData();
        $rfmEnabled = !empty($channelData['rfm_enabled']);
        $form = $event->getForm();
        $form->add(
            'rfm_enabled',
            'checkbox',
            [
                'label' => 'orocrm.analytics.form.rfm_enable.label',
                'mapped' => false,
                'required' => false,
                'data' => $rfmEnabled
            ]
        );
        $this->addRFMTypes($form, $categories);
    }

    /**
     * @param FormInterface $form
     * @param array $categories
     */
    protected function addRFMTypes(FormInterface $form, array $categories)
    {
        foreach (RFMMetricCategory::$types as $type) {
            $typeCategories = array_filter(
                $categories,
                function (RFMMetricCategory $category) use ($type) {
                    return $category->getCategoryType() === $type;
                }
            );

            $collection = new PersistentCollection(
                $this->doctrineHelper->getEntityManager($this->rfmCategoryClass),
                $this->doctrineHelper->getEntityMetadata($this->rfmCategoryClass),
                new ArrayCollection($typeCategories)
            );

            $collection->takeSnapshot();

//            $constraint = new CategoriesConstraint();
//            $constraint->setType($type);

            $form->add(
                $type,
                RFMCategorySettingsType::NAME,
                [
                    RFMCategorySettingsType::TYPE_OPTION => $type,
                    'label' => sprintf('orocrm.analytics.form.%s.label', $type),
                    'mapped' => false,
                    'required' => false,
                    'is_increasing' => $type === RFMMetricCategory::TYPE_RECENCY,
//                    'constraints' => [$constraint],
                    'data' => $collection,
                ]
            );
        }
    }

    /**
     * @param Channel $channel
     *
     * @return bool
     */
    protected function isApplicable(Channel $channel = null)
    {
        if (!$channel) {
            return false;
        }

        $customerIdentity = $channel->getCustomerIdentity();
        if (!$customerIdentity) {
            return false;
        }

        return in_array($this->interface, class_implements($customerIdentity));
    }
}
