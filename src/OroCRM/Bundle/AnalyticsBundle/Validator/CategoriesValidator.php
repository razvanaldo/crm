<?php

namespace OroCRM\Bundle\AnalyticsBundle\Validator;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\PersistentCollection;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

use OroCRM\Bundle\AnalyticsBundle\Entity\RFMMetricCategory;

class CategoriesValidator extends ConstraintValidator
{
    const MIN_CATEGORIES_COUNT = 2;

    /**
     * @param PersistentCollection|RFMMetricCategory[] $value
     * @param CategoriesConstraint $constraint
     *
     * {@inheritdoc}
     */
    public function validate($value, Constraint $constraint)
    {
        if (!$value instanceof PersistentCollection) {
            return;
        }

        $this->validateBlank($value, $constraint);
        $this->validateNumber($value, $constraint);
        $this->validateCount($value, $constraint);
        $this->validateOrder($value, $constraint);
    }

    /**
     * @param PersistentCollection $value
     * @param CategoriesConstraint $constraint
     */
    protected function validateBlank(PersistentCollection $value, CategoriesConstraint $constraint)
    {
        $values = $value->matching(
            new Criteria(null, ['minValue' => Criteria::ASC])
        );
        if (!$this->filterNotEmptyFields($values)->isEmpty()) {
            $this->context->addViolationAt($constraint->getType(), $constraint->blankMessage);
        }
    }

    /**
     * @param PersistentCollection $value
     * @param CategoriesConstraint $constraint
     */
    protected function validateNumber(PersistentCollection $value, CategoriesConstraint $constraint)
    {
        $values = $value->matching(
            new Criteria(null, ['minValue' => Criteria::ASC])
        );
        $invalidItems = $values->filter(
            function (RFMMetricCategory $category) {
                if (!(is_numeric($category->getMaxValue()) || is_real($category->getMaxValue()))
                   && !is_null($category->getMaxValue())
                ) {
                    return true;
                }
            }
        );
        if (!$invalidItems->isEmpty()) {
            $this->context->addViolationAt($constraint->getType(), $constraint->numberMessage);
        }
    }

    /**
     * @param PersistentCollection $value
     * @param CategoriesConstraint $constraint
     */
    protected function validateCount(PersistentCollection $value, CategoriesConstraint $constraint)
    {
        if ($value->count() >= self::MIN_CATEGORIES_COUNT) {
            return;
        }

        $this->context->addViolationAt(
            $constraint->getType(),
            $constraint->countMessage,
            ['%count%' => self::MIN_CATEGORIES_COUNT]
        );
    }

    /**
     * @param PersistentCollection $value
     * @param CategoriesConstraint $constraint
     */
    protected function validateOrder(PersistentCollection $value, CategoriesConstraint $constraint)
    {
        if ($value->isEmpty() || count($this->filterNotEmptyFields($value)) > 0) {
            return;
        }

        $orderedByIndex = $value->matching(new Criteria(null, ['categoryIndex' => Criteria::ASC]));

        $isIncreasing = is_null($orderedByIndex->first()->getMinValue())
            && is_null($orderedByIndex->last()->getMaxValue());

        if ($isIncreasing) {
            $criteria = Criteria::ASC;
        } else {
            $criteria = Criteria::DESC;
        }

        $orderedByValue = $value->matching(
            new Criteria(null, ['minValue' => $criteria])
        );

        if ($orderedByValue->toArray() !== $orderedByIndex->toArray()) {
            $this->context->addViolationAt($constraint->getType(), $constraint->message, ['%order%' => $criteria]);

            return;
        }

        if (!$isIncreasing) {
            return;
        }

        $invalidItems = $orderedByValue->filter(
            function (RFMMetricCategory $category) {
                $maxValue = $category->getMaxValue();
                if (!$maxValue) {
                    return false;
                }

                return $category->getMinValue() >= $maxValue;
            }
        );

        if (!$invalidItems->isEmpty()) {
            $this->context->addViolationAt($constraint->getType(), $constraint->message, ['%order%' => $criteria]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validatedBy()
    {
        return 'orocrm_analytics.validator.categories';
    }

    /**
     * @param $values
     * @return mixed
     */
    protected function filterNotEmptyFields($values)
    {
        return $values->filter(
            function (RFMMetricCategory $category) {
                if ($category->getMaxValue() == $category->getMinValue()
                    && is_null($category->getMaxValue())
                ) {
                    return true;
                }
            }
        );
    }
}
