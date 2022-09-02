<?php

namespace Leftsky\LaravelHelp\SimpleCurd;

use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Illuminate\Http\Request;

trait SimpleCurd
{
    private mixed $dbModel;
    private array $columns = [];
    private array $columnName = [];
    private array $noUpdate = ["id", "create_at", "updated_at", "deleted_at"];
    private array $likeOpColumns = ["name", "title"];

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        if (!isset($this->model) || !class_exists($this->model))
            throw new \Exception();
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
                "label" => $column->getComment() ?? match ($key) {
                        "created_at" => "创建时间",
                        "updated_at" => "更新时间",
                        "deleted_at" => "删除时间",
                        default => $key
                    },
                "type" => match ($column->getType()::class) {
                    IntegerType::class, BigIntType::class => "integer",
                    BooleanType::class => "boolean",
                    JsonType::class => "json",
                    TextType::class => "text",
                    StringType::class => "string",
                    DateTimeType::class => "datetime",
                    DateType::class => "date",
                    EnumType::class => "enum",
                    default => null
                },
                "length" => $column->getLength() ?? 0,
                "valueList" => match ($column->getType()::class) {
                    EnumType::class => $this->dbModel->enums[$key] ?? [],
                    default => []
                }
            ];
        }
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
        foreach ($this->columns as $column) {
            $this->getWhere($argvs[$column["name"]] ?? null, $column, $list);
            $this->getWhere($argvs["filter"][$column["name"]] ?? null, $column, $list);
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
        if ($cValue === null) return;
        $cValue = match ($column["type"]) {
            "boolean" => in_array(strtolower($cValue), [1, "true"]),
            "integer" => intval($cValue),
            default => $cValue
        };
        switch (gettype($cValue)) {
            case "NULL":
                break;
            case "boolean":
            case "integer":
                $list->where($column["name"], $cValue);
                break;
            case "string":
                if (in_array($column["name"], $this->likeOpColumns))
                    $list->where($column["name"], "like", "%$cValue%");
                else $list->where($column["name"], $cValue);
                break;
            case "array":
                $list->whereIn($column["name"], $cValue);
                break;
        }
    }

    public function modify(Request $request)
    {
        $argvValidates = [
            "id" => "required|integer"
        ];
        // 循环载入列名
        foreach ($this->columnName as $item) {
            if (!in_array($item, $this->noUpdate))
                $argvValidates[$item] = "nullable";
        }
        $argvs = $request->validate($argvValidates);
        if (!$item = $this->dbModel::find($argvs["id"])) {
            return rsps(ERR_FAILED, null, "查询不到记录");
        }
        foreach ($argvs as $key => $value) {
            $item->{$key} = $value;
        }
        $item->save();

        return rsps(ERR_SUCCESS, $item);
    }

    public function info(Request $request)
    {
        $argvs = $request->validate([
            "id" => "required|integer"
        ]);
        return rsps(ERR_SUCCESS, $this->dbModel::find($argvs["id"]));
    }

    public function del(Request $request)
    {
        $argvs = $request->validate([
            "id" => "required|integer"
        ]);
        $this->dbModel::where("id", $argvs["id"])->delete();
        return rsps(ERR_SUCCESS);
    }

    public function withSelect(Request $request)
    {
        $argvs = $request->validate([
            "column" => "required|string",
            "selectEd" => "array",
            "searchStr" => "string"
        ]);

        return rsps(ERR_SUCCESS);
    }

}
