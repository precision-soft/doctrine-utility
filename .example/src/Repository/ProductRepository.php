<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Repository;

use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Product;
use PrecisionSoft\Doctrine\Utility\Example\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Repository\EmptyArrayFilterBehavior;
use PrecisionSoft\Doctrine\Utility\Walker\MySqlWalker;
use UnitEnum;

/**
 * The catalogue's read service: generic filters come from the mapping, the custom ones and the join are declared here.
 */
class ProductRepository extends AbstractRepository
{
    public const JOIN_CATEGORY = 'joinCategory';
    public const FILTER_NAME_LIKE = 'nameLike';
    public const FILTER_CATEGORY_NAME = 'categoryName';
    public const BARCODE_INDEX = 'idx_product_barcode';

    protected const CATEGORY_ALIAS = 'category';

    protected ?EmptyArrayFilterBehavior $emptyArrayFilterBehavior = null;

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<Product>
     * @throws Exception if a custom filter is unknown or the category filter comes without its join
     */
    public function findByFilters(array $filters, bool $selectJoins = false): array
    {
        $queryBuilder = $this->createQueryBuilderFromFilters($filters, $selectJoins);
        $queryBuilder->orderBy(static::getAlias() . '.id', 'ASC');

        /** @var list<Product> $products */
        $products = $queryBuilder->getQuery()->getResult();

        return $products;
    }

    /**
     * @return list<Product>
     * @throws Exception if the criteria names an unmapped field, binds a null, or sorts a keyset on a nullable column
     */
    public function findPage(Criteria $criteria): array
    {
        /** @var list<Product> $products */
        $products = $this->createQueryBuilderFromCriteria($criteria)->getQuery()->getResult();

        return $products;
    }

    /**
     * The MySQL family only: the JSON functions are registered on the entity manager the tests build.
     *
     * @return list<string>
     */
    public function findNamesWithTag(string $tag): array
    {
        return $this->fetchNames(
            $this->createQueryBuilder()
                ->where(\sprintf("JSON_CONTAINS(%s.attributes, :candidate, '$.tags') = 1", static::getAlias()))
                ->setParameter('candidate', \json_encode($tag, \JSON_THROW_ON_ERROR)),
        );
    }

    /** @return list<string> */
    public function findNamesByAttribute(string $path, string $value): array
    {
        return $this->fetchNames(
            $this->createQueryBuilder()
                ->where(\sprintf('JSON_UNQUOTE(JSON_EXTRACT(%s.attributes, :path)) = :value', static::getAlias()))
                ->setParameter('path', $path)
                ->setParameter('value', $value),
        );
    }

    /** @return list<string> */
    public function findNamesWithAttributePath(string $path): array
    {
        return $this->fetchNames(
            $this->createQueryBuilder()
                ->where(\sprintf("JSON_CONTAINS_PATH(%s.attributes, 'one', :path) = 1", static::getAlias()))
                ->setParameter('path', $path),
        );
    }

    /**
     * Where a tag sits inside the document, as `JSON_SEARCH` reports it once `JSON_UNQUOTE` strips the quotes.
     *
     * @return array<string, string> product name => json path
     */
    public function findTagPaths(string $tag): array
    {
        $queryBuilder = $this->createQueryBuilder()
            ->select(static::getAlias() . '.name AS name')
            ->addSelect(\sprintf("JSON_UNQUOTE(JSON_SEARCH(%s.attributes, 'one', :tag)) AS path", static::getAlias()))
            ->where(\sprintf("JSON_SEARCH(%s.attributes, 'one', :tag) IS NOT NULL", static::getAlias()))
            ->orderBy(static::getAlias() . '.id', 'ASC')
            ->setParameter('tag', $tag);

        /** @var list<array{name: string, path: string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return \array_column($rows, 'path', 'name');
    }

    /** @return array<string, int> `Y-m` => products created in that month */
    public function countByCreatedMonth(): array
    {
        $queryBuilder = $this->createQueryBuilder()
            ->select(\sprintf("DATE_FORMAT(%s.created, '%%Y-%%m') AS period", static::getAlias()))
            ->addSelect(\sprintf('COUNT(%s.id) AS total', static::getAlias()))
            ->groupBy('period')
            ->orderBy('period', 'ASC');

        /** @var list<array{period: string, total: int|string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['period']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * @param string $indexHint one of the `MySqlWalker::HINT_*_INDEX` constants
     */
    public function findByBarcode(string $barcode, string $indexHint): ?Product
    {
        /** @var Product|null $product */
        $product = $this->createBarcodeQuery($barcode, $indexHint)->getOneOrNullResult();

        return $product;
    }

    /**
     * The server's plan for the barcode lookup, so a hint is proved by what the optimizer chose rather than by the SQL text.
     *
     * @return list<array<string, mixed>>
     */
    public function explainBarcodeLookup(string $barcode, string $indexHint): array
    {
        $query = $this->createBarcodeQuery($barcode, $indexHint);
        $sql = $query->getSQL();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getConnection()
            ->executeQuery('EXPLAIN ' . (true === \is_array($sql) ? \implode('; ', $sql) : $sql), [$barcode])
            ->fetchAllAssociative();

        return $rows;
    }

    public function setEmptyArrayFilterBehavior(?EmptyArrayFilterBehavior $emptyArrayFilterBehavior): static
    {
        $this->emptyArrayFilterBehavior = $emptyArrayFilterBehavior;

        return $this;
    }

    /** @return class-string<Product> */
    protected function getEntityClass(): string
    {
        return Product::class;
    }

    /** @return array<class-string<UnitEnum>, UnitEnum> */
    protected function getFlags(): array
    {
        if (null === $this->emptyArrayFilterBehavior) {
            return parent::getFlags();
        }

        return [EmptyArrayFilterBehavior::class => $this->emptyArrayFilterBehavior] + parent::getFlags();
    }

    /**
     * @param array<string, mixed> $filters
     * @throws Exception if a filter is not one of the three declared here, or the category filter has no join to stand on
     */
    protected function attachCustomFilters(QueryBuilder $queryBuilder, array $filters): JoinCollection
    {
        $joinCollection = new JoinCollection();

        if (true === \array_key_exists(static::FILTER_CATEGORY_NAME, $filters) && false === \array_key_exists(static::JOIN_CATEGORY, $filters)) {
            throw new Exception(\sprintf('filter `%s` needs the `%s` join', static::FILTER_CATEGORY_NAME, static::JOIN_CATEGORY));
        }

        foreach ($filters as $filterName => $filterValue) {
            switch ($filterName) {
                case static::FILTER_NAME_LIKE:
                    $queryBuilder
                        ->andWhere(\sprintf('%s.name LIKE :%s', static::getAlias(), $filterName))
                        ->setParameter($filterName, $filterValue);

                    break;
                case static::JOIN_CATEGORY:
                    if (static::JOIN_INNER !== $filterValue && static::JOIN_LEFT !== $filterValue) {
                        throw new Exception(\sprintf('join `%s` takes `JOIN_INNER` or `JOIN_LEFT`', $filterName));
                    }

                    $joinCollection->addJoin(new Join($filterValue, static::getAlias() . '.category', static::CATEGORY_ALIAS));

                    break;
                case static::FILTER_CATEGORY_NAME:
                    $queryBuilder
                        ->andWhere(\sprintf('%s.name = :%s', static::CATEGORY_ALIAS, $filterName))
                        ->setParameter($filterName, $filterValue);

                    break;
                default:
                    throw new Exception(\sprintf('invalid filter `%s` for `%s`', $filterName, static::class));
            }
        }

        return $joinCollection;
    }

    protected function createBarcodeQuery(string $barcode, string $indexHint): Query
    {
        $query = $this->createQueryBuilder()
            ->where(static::getAlias() . '.barcode = :barcode')
            ->setParameter('barcode', $barcode)
            ->getQuery();
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, MySqlWalker::class);
        $query->setHint($indexHint, static::BARCODE_INDEX);

        return $query;
    }

    /** @return list<string> */
    protected function fetchNames(QueryBuilder $queryBuilder): array
    {
        $queryBuilder
            ->select(static::getAlias() . '.name AS name')
            ->orderBy(static::getAlias() . '.id', 'ASC');

        /** @var list<array{name: string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return \array_column($rows, 'name');
    }
}
