<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\DoctrineORMAdminBundle\Filter;

use Sonata\AdminBundle\Form\Type\Filter\ChoiceType;
use Sonata\AdminBundle\Form\Type\Operator\StringOperatorType;
use Sonata\DoctrineORMAdminBundle\Datagrid\ProxyQueryInterface;

final class StringFilter extends Filter
{
    public const TRIM_NONE = 0;
    public const TRIM_LEFT = 1;
    public const TRIM_RIGHT = 2;
    public const TRIM_BOTH = self::TRIM_LEFT | self::TRIM_RIGHT;

    public const CHOICES = [
        StringOperatorType::TYPE_CONTAINS => 'LIKE',
        StringOperatorType::TYPE_STARTS_WITH => 'LIKE',
        StringOperatorType::TYPE_ENDS_WITH => 'LIKE',
        StringOperatorType::TYPE_NOT_CONTAINS => 'NOT LIKE',
        StringOperatorType::TYPE_EQUAL => '=',
        StringOperatorType::TYPE_NOT_EQUAL => '<>',
    ];

    /**
     * Filtering types do not make sense for searching by empty value.
     */
    private const MEANINGLESS_TYPES = [
        StringOperatorType::TYPE_CONTAINS,
        StringOperatorType::TYPE_STARTS_WITH,
        StringOperatorType::TYPE_ENDS_WITH,
        StringOperatorType::TYPE_NOT_CONTAINS,
    ];

    public function filter(ProxyQueryInterface $query, string $alias, string $field, array $data): void
    {
        if (!\array_key_exists('value', $data)) {
            return;
        }

        $data['value'] = $this->trim((string) ($data['value'] ?? ''));
        $type = $data['type'] ?? StringOperatorType::TYPE_CONTAINS;

        // ignore empty value if it doesn't make sense
        if ('' === $data['value'] &&
            (!$this->getOption('allow_empty') || \in_array($type, self::MEANINGLESS_TYPES, true))
        ) {
            return;
        }

        $operator = $this->getOperator($type);

        // c.name > '1' => c.name OPERATOR :FIELDNAME
        $parameterName = $this->getNewParameterName($query);

        $forceCaseInsensitivity = true === $this->getOption('force_case_insensitivity', false);

        // NEXT_MAJOR: Remove the following condition and its body.
        if (null !== $this->getOption('case_sensitive')) {
            @trigger_error(
                'Option "case_sensitive" is deprecated since sonata-project/doctrine-orm-admin-bundle 3.x'
                .' and will be removed in version 4.x. Use the "force_case_insensitivity" option instead.',
                \E_USER_DEPRECATED
            );

            if (null === $this->getOption('force_case_insensitivity')) {
                $forceCaseInsensitivity = false === $this->getOption('case_sensitive', true);
            }
        }

        if ($forceCaseInsensitivity && '' !== $data['value']) {
            $clause = 'LOWER(%s.%s) %s :%s';
        } else {
            $clause = '%s.%s %s :%s';
        }

        $or = $query->getQueryBuilder()->expr()->orX(
            sprintf($clause, $alias, $field, $operator, $parameterName)
        );

        if (StringOperatorType::TYPE_NOT_CONTAINS === $type || StringOperatorType::TYPE_NOT_EQUAL === $type) {
            $or->add($query->getQueryBuilder()->expr()->isNull(sprintf('%s.%s', $alias, $field)));
        }

        $this->applyWhere($query, $or);

        switch ($type) {
            case StringOperatorType::TYPE_EQUAL:
            case StringOperatorType::TYPE_NOT_EQUAL:
                $format = '%s';
                break;
            case StringOperatorType::TYPE_STARTS_WITH:
                $format = '%s%%';
                break;
            case StringOperatorType::TYPE_ENDS_WITH:
                $format = '%%%s';
                break;
            default:
                $format = '%%%s%%';
        }

        $query->getQueryBuilder()->setParameter(
            $parameterName,
            sprintf(
                $format,
                true !== $forceCaseInsensitivity || '' === $data['value'] ? $data['value'] : mb_strtolower($data['value'])
            )
        );
    }

    public function getDefaultOptions(): array
    {
        return [
            // NEXT_MAJOR: Remove the "format" option.
            'format' => '%%%s%%',
            // NEXT_MAJOR: Remove the "case_sensitive" option.
            'case_sensitive' => null,
            // NEXT_MAJOR: Use `false` as default value for the "force_case_insensitivity" option.
            'force_case_insensitivity' => null,
            'trim' => self::TRIM_BOTH,
            'allow_empty' => false,
        ];
    }

    public function getRenderSettings(): array
    {
        return [ChoiceType::class, [
            'field_type' => $this->getFieldType(),
            'field_options' => $this->getFieldOptions(),
            'label' => $this->getLabel(),
        ]];
    }

    private function getOperator(int $type): string
    {
        if (!isset(self::CHOICES[$type])) {
            throw new \OutOfRangeException(sprintf(
                'The type "%s" is not supported, allowed one are "%s".',
                $type,
                implode('", "', array_keys(self::CHOICES))
            ));
        }

        return self::CHOICES[$type];
    }

    private function trim(string $string): string
    {
        $trimMode = $this->getOption('trim');

        if ($trimMode & self::TRIM_LEFT) {
            $string = ltrim($string);
        }

        if ($trimMode & self::TRIM_RIGHT) {
            $string = rtrim($string);
        }

        return $string;
    }
}
