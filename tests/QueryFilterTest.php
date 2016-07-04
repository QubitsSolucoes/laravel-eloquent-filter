<?php

namespace Mnabialek\LaravelEloquentFilter\Tests;

use Mnabialek\LaravelEloquentFilter\Filters\QueryFilter;
use Mnabialek\LaravelEloquentFilter\Objects\Filter;
use Mnabialek\LaravelEloquentFilter\Objects\Sort;
use Mockery as m;

class QueryFilterTest extends UnitTestCase
{
    protected $parser;
    protected $query;
    protected $app;

    /** @test */
    public function it_applies_filters_and_sorts_when_apply()
    {
        $this->setMocks();

        $this->parser->shouldReceive('getFilters')->once();
        $this->parser->shouldReceive('getSorts')->once();

        $filter = $this->createFilterMock('EmptyQueryFilter');

        $filter->shouldReceive('applyFilters')->once()->with($this->query)
            ->andReturn('foo');
        $filter->shouldReceive('applySorts')->once()->with('foo')
            ->andReturn('bar');

        $this->assertEquals('bar', $filter->apply($this->query));
    }

    /** @test */
    public function it_applies_default_filters_when_no_filters_given()
    {
        $this->setMocks();

        $this->parser->shouldReceive('getFilters')->once()
            ->andReturn(collect());
        $this->parser->shouldReceive('getSorts')->once();

        $filter = $this->createFilterMock('EmptyQueryFilter');

        $filter->shouldReceive('getBaseQuery')->once()->with($this->query)
            ->andReturn($this->query);

        $filter->shouldReceive('applyDefaultFilters')->once()->withNoArgs()
            ->passthru();
        $filter->shouldNotReceive('applySimpleFilter');

        $filter->applyFilters($this->query);

        $this->assertEquals(collect(), $filter->getAppliedFilters());
    }

    /** @test */
    public function it_applies_valid_filters_when_filters_configured()
    {
        $this->setMocks();

        $idFilter = new Filter('id', 2);
        $emailFilter =
            new Filter('email', ['foo@example.com', 'bar@example.com']);
        $roleFilter = new Filter('role', 'admin');
        $statusFilter = new Filter('status', ['pending']);
        $otherFilter = new Filter('something', 'else');
        $likeFilter = new Filter('likefield', 'anothervalue', 'LiKe');
        $multiLikeFilter = new Filter('multilikefield', ['1st', '2nd'], 'LiKe');
        $multiOperatorFilter = new Filter('multiopfield', ['1st', '2nd'], '!=');

        $this->parser->shouldReceive('getFilters')->once()
            ->andReturn(collect([
                $idFilter,
                $roleFilter,
                $emailFilter,
                $otherFilter,
                $statusFilter,
                $likeFilter,
                $multiLikeFilter,
                $multiOperatorFilter,
            ]));
        $this->parser->shouldReceive('getSorts')->once();

        $filter = $this->createFilterMock('NotEmptyQueryFilter');

        $filter->shouldReceive('applyId')->with($idFilter->getValue());
        $filter->shouldReceive('applySimpleFilter')->once()->with($emailFilter)
            ->passthru();
        $filter->shouldReceive('applySimpleFilter')->once()->with($roleFilter)
            ->passthru();
        $filter->shouldReceive('applySimpleFilter')->once()->with($statusFilter)
            ->passthru();
        $filter->shouldReceive('applySimpleFilter')->once()->with($likeFilter)
            ->passthru();
        $filter->shouldReceive('applySimpleFilter')->once()
            ->with($multiLikeFilter)->passthru();
        $filter->shouldReceive('applySimpleFilter')->once()
            ->with($multiOperatorFilter)->passthru();

        $filter->shouldNotReceive('applySomething');
        $filter->shouldNotReceive('applySimpleFilter')->with($otherFilter);

        $this->query->shouldReceive('whereIn')->once()
            ->with($emailFilter->getField(), $emailFilter->getValue());
        $this->query->shouldReceive('where')->once()
            ->with($roleFilter->getField(), $roleFilter->getOperator(),
                $roleFilter->getValue());
        $this->query->shouldReceive('where')->once()
            ->with($statusFilter->getField(), $statusFilter->getOperator(),
                $statusFilter->getValue()[0]);
        $this->query->shouldReceive('where')->once()
            ->with($likeFilter->getField(),
                strtoupper($likeFilter->getOperator()),
                '%' . $likeFilter->getValue() . '%');

        $qMock = m::mock('stdClass');

        // this is tricky - we mark this as matched expectation only for 1st
        // call and only for 1st call we call user function
        $executed = false;
        $nestedMultiLike = m::on(function ($callback) use ($qMock, &$executed) {
            $result = ($executed === false);
            if ($result) {
                call_user_func($callback, $qMock);
                $executed = true;
            }

            return $result;
        });

        $qMock->shouldReceive('orWhere')->with($multiLikeFilter->getField(),
            strtoupper($multiLikeFilter->getOperator()),
            '%' . $multiLikeFilter->getValue()[0] . '%');
        $qMock->shouldReceive('orWhere')->with($multiLikeFilter->getField(),
            strtoupper($multiLikeFilter->getOperator()),
            '%' . $multiLikeFilter->getValue()[1] . '%');

        $this->query->shouldReceive('where')->once()->with($nestedMultiLike);

        // here we can make it simpler than in 1st case because we have no more
        // closures
        $qMock2 = m::mock('stdClass2');
        $nestedMultiOperator = m::on(function ($callback) use ($qMock2) {
            call_user_func($callback, $qMock2);

            return true;
        });

        $qMock2->shouldReceive('where')->with($multiOperatorFilter->getField(),
            $multiOperatorFilter->getOperator(),
            $multiOperatorFilter->getValue()[0]);
        $qMock2->shouldReceive('where')->with($multiOperatorFilter->getField(),
            strtoupper($multiOperatorFilter->getOperator()),
            $multiOperatorFilter->getValue()[1]);

        $this->query->shouldReceive('where')->once()
            ->with($nestedMultiOperator);

        $filter->shouldReceive('applyDefaultFilters')->once()
            ->withNoArgs()->passthru();

        $filter->applyFilters($this->query);

        $this->assertEquals(collect([
            'id',
            'role',
            'email',
            'status',
            'likefield',
            'multilikefield',
            'multiopfield',
        ]),
            $filter->getAppliedFilters());
    }

    /** @test */
    public function it_applies_default_sorts_when_no_sorts_given()
    {
        $this->setMocks();

        $this->parser->shouldReceive('getFilters')->once();
        $this->parser->shouldReceive('getSorts')->once()->andReturn(collect());

        $filter = $this->createFilterMock('EmptyQueryFilter');

        $filter->shouldReceive('applyDefaultSorts')->once()->withNoArgs()
            ->passthru();
        $filter->shouldNotReceive('applySimpleSort');

        $filter->applySorts($this->query);

        $this->assertEquals(collect(), $filter->getAppliedSorts());
    }

    /** @test */
    public function it_applies_valid_sorts_when_sorts_configured()
    {
        $this->setMocks();

        $createdAtSort = new Sort('created_at', 'ASC');
        $nameSort = new Sort('name', 'DESC');
        $surnameSort = new Sort('surname', 'DESC');
        $otherSort = new Sort('field', 'ASC');
        $this->parser->shouldReceive('getFilters')->once();

        $this->parser->shouldReceive('getSorts')->once()->andReturn(collect([
            $createdAtSort,
            $nameSort,
            $surnameSort,
            $otherSort,
        ]));

        $filter = $this->createFilterMock('NotEmptyQueryFilter');

        $filter->shouldReceive('applySortCreatedAt')
            ->with($createdAtSort->getOrder());
        $filter->shouldReceive('applySimpleSort')->once()->with($nameSort)
            ->passthru();
        $filter->shouldReceive('applySimpleSort')->once()->with($surnameSort)
            ->passthru();

        $filter->shouldNotReceive('applySortField');
        $filter->shouldNotReceive('applySimpleFilter')->with($otherSort);

        $this->query->shouldReceive('orderBy')->once()
            ->with($nameSort->getField(), $nameSort->getOrder());
        $this->query->shouldReceive('orderBy')->once()
            ->with($surnameSort->getField(), $surnameSort->getOrder());

        $filter->shouldReceive('applyDefaultSorts')->once()->withNoArgs()
            ->passthru();

        $filter->applySorts($this->query);

        $this->assertEquals(collect(['created_at', 'name', 'surname']),
            $filter->getAppliedSorts());
    }

    protected function setMocks()
    {
        $this->parser =
            m::mock('Mnabialek\LaravelEloquentFilter\Contracts\InputParser');
        $this->app = m::mock('Illuminate\Contracts\Container\Container');
        $this->query = m::mock('Illuminate\Database\Eloquent\Builder');
    }

    protected function createFilterMock($className)
    {
        $filter = m::mock(__NAMESPACE__ . '\\' . $className)->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $filter->__construct($this->parser, collect(), $this->app);

        return $filter;
    }
}

// stubs

class EmptyQueryFilter extends QueryFilter
{
    public function getAppliedSorts()
    {
        return $this->appliedSorts;
    }

    public function getAppliedFilters()
    {
        return $this->appliedFilters;
    }
}

class NotEmptyQueryFilter extends QueryFilter
{
    protected $simpleFilters = [
        'id',
        'email',
        'role',
        'status',
        'likefield',
        'multilikefield',
        'multiopfield',
    ];

    protected $simpleSorts = ['created_at', 'name', 'surname'];

    public function getAppliedSorts()
    {
        return $this->appliedSorts;
    }

    public function getAppliedFilters()
    {
        return $this->appliedFilters;
    }

    protected function applyId($value)
    {
    }

    protected function applySortCreatedAt($order)
    {
    }
}
