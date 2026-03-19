# Doctrine utility library

Doctrine custom types and functions.

**You may fork and modify it as you wish.**

Any suggestions are welcomed.

## Usage for `\PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository` and `\PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository`

The purposes for these classes are:

- easier constructor injection for the repositories; the quotes are because these repositories are actual **read services** in CRUD methodology
- code reuse by using custom filters and join filters
- better find usages for methods because you are forced to implement only what you need

**Product.php**

```php
namespace Acme\Domain\Product\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Utility\Entity\CreatedTrait;
use PrecisionSoft\Doctrine\Utility\Entity\ModifiedTrait;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;

#[ORM\Entity(repositoryClass: DoctrineRepository::class)]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(options: ['collate' => 'utf8mb4_general_ci'])]
class Product
{
    use CreatedTrait;
    use ModifiedTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64, nullable: false, unique: true)]
    private string $barcode;

    #[ORM\ManyToOne(targetEntity: ProductType::class, fetch: 'EXTRA_LAZY')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ProductType $productType;
}
```

**ProductRepository.php**

```php
namespace Acme\Domain\Product\Repository;

use Acme\Domain\Product\Entity\Product;
use Acme\Domain\Product\Exception\Exception;
use Acme\Domain\Product\Exception\NotFoundException;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;

class ProductRepository extends AbstractRepository
{
    public const JOIN_PRODUCT_TYPE = 'joinProductType';

    public static function getEntityClass(): string
    {
        return Product::class;
    }

    public function find(int $productId): Product
    {
        /** @var Product|null $product */
        $product = $this->getDoctrineRepository()->find($productId);

        if (null === $product) {
            throw new NotFoundException('the product was not found');
        }

        return $product;
    }

    protected function attachCustomFilters(QueryBuilder $queryBuilder, array $filters): JoinCollection
    {
        $joins = new JoinCollection();

        foreach ($filters as $key => $value) {
            switch ($key) {
                case 'barcodeLike':
                    $baseKey = \substr($key, 0, -4);

                    $queryBuilder
                        ->andWhere(static::getAlias() . ".{$baseKey} LIKE :{$key}")
                        ->setParameter($key, $value);

                    break;
                case static::JOIN_PRODUCT_TYPE:
                    $joins->addJoin(
                        new Join(
                            $value,
                            static::getAlias() . '.productType',
                            ProductTypeRepository::getAlias(),
                        ),
                    );

                    break;
                default:
                    throw new Exception(\sprintf('invalid filter `%s` for `%s::%s`', $key, static::class, __FUNCTION__));
            }
        }

        return $joins;
    }
}
```

## Todo

- unit tests

## Dev

```shell
git clone git@github.com:precision-soft/doctrine-utility.git
cd utility
./dc build && ./dc up -d
```
