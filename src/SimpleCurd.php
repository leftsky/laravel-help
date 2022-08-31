<?php

namespace Leftsky\LaravelHelp;

use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Illuminate\Http\Request;

trait SimpleCurd
{
    private mixed $dbModel;
    private array $columnName = [];
    private array $noUpdate = ["id", "create_at", "updated_at", "deleted_at"];
    private array $likeOpColumns = ["name", "title"];

    /**
     * @throws \Exception
     */
    public function __construct()
    {

        if (!isset($this->model) || !class_exists($this->model)) {
            throw new \Exception();
        }
        $this->dbModel = app($this->model);
//        $this->dbModel = new YourModal();
        $con = $this->dbModel->getConnection();
        $con->registerDoctrineType(EnumType::class, "enum", "enum");
        $table = $con->getDoctrineSchemaManager()
            ->listTableDetails($this->dbModel->getTable());
        $columns = $table->getColumns();
        foreach ($columns as $key => $column) {
            $this->columns [] = [
                "name" => $key,
                "required" => $column->getNotnull(),
                "label" => $column->getComment() ?? $key,
                "type" => match ($column->getType()::class) {
                    IntegerType::class, BigIntType::class => "integer",
                    BooleanType::class => "boolean",
                    TextType::class => "text",
                    StringType::class => "string",
                    DateTimeType::class => "datetime",
                    DateType::class => "date",
                    EnumType::class => "enum",
                    default => null
                },
                "cc" => [
                    $column,
                    $column->getPrecision(),
                    $column->getPrecision(),
                    $column->getPrecision(),
                ],
                "length" => $column->getLength() ?? 0,
                "valueList" => match ($column->getType()::class) {
                    EnumType::class => $this->dbModel->enums[$key] ?? [],
                    default => []
                }
            ];
        }
//        dd($this->columns);
        $this->columnName = array_column($this->columns, "name");
    }

    protected function columns()
    {
        return rsps(ERR_SUCCESS, $this->columns);
    }

    protected function get(Request $request)
    {
        $argvValidates = [
            // 指定ID
            "id" => "nullable|integer",
            // 关联模型
//            "with" => "array",
            // 分页部分
            "page" => "integer",
            "current" => "integer",
            "pageSize" => "integer",
            // 额外筛选
            "filter" => "array",
            // 时间段筛选
            "startTime" => "string",
            "endTime" => "string",
            // 排序字段
            "sort" => "array",
            "orderBy" => "string",
            "orderByDesc" => "string",
        ];
        // 循环载入列名
        foreach ($this->columnName as $item) {
            if (!in_array($item, $this->noUpdate))
                $argvValidates[$item] = "nullable";
        }
        // 从 request 中拉取需要的参数
        $argvs = $request->validate($argvValidates);
        // 渲染分页字段
        $page = $argvs["page"] ?? ($argvs["current"] ?? 1);
        $pageSize = $argvs["pageSize"] ?? 10;

        // 初始化模型
        $list = $this->dbModel::with([]);
        // 根据列名字段筛选匹配
        foreach ($this->columnName as $column) {
            $this->getWhere($argvs[$column] ?? null, $column, $list);
            $this->getWhere($argvs["filter"][$column] ?? null, $column, $list);
        }

        // 如果设置了排序字段
        if (isset($argvs["orderBy"])) {
            $list->orderBy($argvs["orderBy"]);
        } else if (isset($argvs["orderByDesc"])) {
            $list->orderByDesc($argvs["orderByDesc"]);
        } else if (isset($argvs["sort"])) {
            if (sizeof($argvs["sort"]) === 1) {
                foreach ($argvs["sort"] as $key => $value) {
                    if ($value === 'ascend') {
                        $list->orderBy($key);
                    } else if ($value === 'descend') {
                        $list->orderByDesc($key);
                    }
                }
            }
        }

        return rsps(ERR_SUCCESS, [
            "total" => $list->count(),
            "data" => $list->forPage($page, $pageSize)->get(),
            "current" => $page
        ]);
    }

    private function getWhere(mixed $cValue, $column, $list)
    {
        switch (gettype($cValue)) {
            case "NULL":
                break;
            case "string":
                if (in_array($column, $this->likeOpColumns))
                    $list->where($column, "like", "%$cValue%");
                else $list->where($column, $cValue);
                break;
            case "array":
                $list->whereIn($column, $cValue);
                break;
        }
    }

}
