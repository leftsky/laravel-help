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
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

trait SimpleCurd
{
    // 【视情况可修改】模型命名空间
    private string $modelNamespace = "App\\Models\\";
    // 【视情况可修改】修改时忽略的字段
    private array $noUpdate = ["id", "create_at", "updated_at", "deleted_at"];
    // 【视情况可修改】自动采用like模糊匹配的字段
    private array $likeOpColumns = ["name", "title"];
    // 【视情况可修改】关联时显示对方的字段，顺序优先。字段均不存在则显示ID
    private array $withShowColumns = ["name", "nickname", "username", "title",
        "serial", "serial_number", "code", "id"];
    // searchStr 的模糊搜索字段
    private array $searchColumns = ["title", "btitle", "content", "description"];

    // 【内部使用】已校验的模型类
    private mixed $dbModel;
    // 【内部使用】是否已初始化
    private bool $inited = false;
    // 【内部使用】需要关联的数组
    private array $withs = [];
    // 【内部使用】渲染的关联字段，可用于筛选
    private array $withFields = [];
    // 【内部使用】扫描列信息
    private array $columns = [];
    // 【内部使用】扫描列名列表
    private array $columnName = [];

    /**
     * 初始化；检测表类是否存在，扫描拉取列信息
     * @throws Exception
     */
    private function init()
    {
        if (!isset($this->model) || !class_exists($this->model))
            throw new Exception();
        $this->dbModel = app($this->model);
        list(
            $this->columns,
            $this->columnName,
            $this->withs,
            $this->withFields
            ) = $this->decodeTableColumns($this->model, true, true);
        $this->inited = true;
    }

    /**
     * 扫描列信息
     * @param string $model // 模型类
     * @param bool $fullInfo // 是否是完整信息；组合columns描述。如果无需全部信息则只取name
     * @param bool|null $takeWith // 是否扫描with信息
     * @param bool $useCache // 是否可以使用缓存
     * @return array
     * @throws Exception
     */
    private function decodeTableColumns(
        string $model,
        bool   $fullInfo = false,
        bool   $takeWith = null,
        bool   $useCache = true
    ): array
    {
        // 获得 migrations 中id最高的记录值，用此做列信息缓存的 key 值
        $id = DB::selectOne("SELECT MAX(id) as maxid FROM migrations");
        $maxId = $id->maxid ?? 0;
        $cacheKey = "decodeTableColumns:$maxId:" . $model;
        // 如果存在缓存则标志可以使用缓存，则从缓存中拉取列信息
        if (Cache::has($cacheKey) && $useCache) {
            return json_decode(Cache::get($cacheKey), true);
        }
        if (!isset($model) || !class_exists($model)) throw new Exception();
        $curOrm = app($model);
        $con = $curOrm->getConnection();
        $con->registerDoctrineType(EnumType::class, "enum", "enum");
        $table = $con->getDoctrineSchemaManager()
            ->listTableDetails($curOrm->getTable());
        foreach ($table->getColumns() as $key => $column) {
            // 检索【belongsTo】关联字段，并加入with
            if ($takeWith) {
                list($withName, $withClass) = $this->getWithName($key);
                if (isset($withName)) {
                    if (class_exists($withClass) &&
                        method_exists($this->dbModel, $withName)) {
                        $withs [] = $withName;
                        list($no1, $ccs, $no3) = $this->decodeTableColumns($withClass);
                        unset($showWithColumn);
                        foreach ($this->withShowColumns as $name) {
                            if (in_array($name, $ccs)) {
                                $showWithColumn = $name;
                                break;
                            }
                        }
                        if (isset($showWithColumn)) $withFileds [] = "$withName.$showWithColumn";
                    } else {
                        unset($withName);
                    }
                }
            }
            // 组合columns描述。如果无需全部信息则只取name
            $columns [] = $fullInfo ? [
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
                    EnumType::class => $curOrm->enums[$key] ?? [],
                    default => []
                },
                "withName" => $withName ?? null,
                "showWithColumn" => $showWithColumn ?? "id"
            ] : ["name" => $key];
        }
        $data = [
            $columns ?? [],
            array_column($columns ?? [], "name"),
            $withs ?? [],
            $withFileds ?? []
        ];
        Cache::put($cacheKey, json_encode($data, JSON_UNESCAPED_UNICODE), 24 * 60 * 60);
        return $data;
    }

    /**
     * 从 column 获得 with 的类
     * @param $key
     * @return array
     */
    private function getWithName($key): array
    {
        $words = explode("_", $key);
        if ($key === "uid") {
            $withName = "user";
        } else if (sizeof($words) > 1 && $words[sizeof($words) - 1] === "id") {
            // 检测后缀为 _id 的字段，去除并规则匹配 with 和 class
            $withName = substr($key, 0, strlen($key) - 3);
        }
        return [
            $withName ?? null,
            isset($withName) ? $this->modelNamespace . camelize($withName) : null
        ];
    }

    /**
     * 获得模型的 enums 信息
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function enums(Request $request)
    {
        !$this->inited && $this->init();
        return rsps(ERR_SUCCESS, $this->dbModel->enums);
    }

    /**
     * 获得扫描后的列信息
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function columns(Request $request)
    {
        !$this->inited && $this->init();
        return rsps(ERR_SUCCESS, $this->columns);
    }

    /**
     * 分页查询记录
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function get(Request $request)
    {
        !$this->inited && $this->init();
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
            "appends" => "array",
            "with" => "array"
        ];
        // 循环载入列名
        foreach (array_merge($this->columnName, $this->withFields) as $item) {
            if (!in_array($item, $this->noUpdate))
                $argvValidates[str_replace(".", "\.", $item)] = "nullable";
        }
        // 从 request 中拉取需要的参数
        $argvs = $request->validate($argvValidates);
        // 渲染分页字段
        $page = $argvs["page"] ?? ($argvs["current"] ?? 1);
        $pageSize = $argvs["pageSize"] ?? 10;

        // 初始化模型
        $list = $this->dbModel::with(array_merge($this->withs, $argvs["with"] ?? []));
        // 根据列名字段筛选匹配
        foreach ($this->columns as $column) {
            $this->getWhere($argvs[$column["name"]] ?? null, $column, $list);
            $this->getWhere($argvs["filter"][$column["name"]] ?? null, $column, $list);
        }
        // 关联模糊匹配
        foreach ($this->withFields as $field) {
            if ($argvs[$field] ?? null) {
                $cValue = $argvs[$field];
                list ($w, $c) = explode(".", $field);
                $list->whereHas($w, function ($query) use ($c, $cValue) {
                    $query->where($c, "like", "%$cValue%");
                });
            }
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
            "data" => $list->forPage($page, $pageSize)->get()->append($argvs["appends"] ?? []),
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

    /**
     * 增加记录
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function add(Request $request)
    {
        !$this->inited && $this->init();
        $argvValidates = [];
        // 循环载入列名
        foreach ($this->columnName as $item) {
            if (!in_array($item, $this->noUpdate))
                $argvValidates[$item] = "nullable";
        }
        $argvs = $request->validate($argvValidates);
        $item = new $this->dbModel();
        foreach ($argvs as $key => $value) {
            // 安全措施，UID拒绝从参数中拉取赋值
            $uidKey = $this->dbModel->uidKey ?? "uid";
            if ($key !== $uidKey) {         // 如果与用户ID列名不相等，则可以赋值
                $item->{$key} = $value;
            } else if ($value == true) {    // 如果列名相等且值为true，则赋值当前登录的用户
                $item->{$key} = Auth::check() ? null : Auth::id();
            }
        }
        $item->save();
        return rsps(ERR_SUCCESS, $item);
    }

    /**
     * 修改记录
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function modify(Request $request)
    {
        !$this->inited && $this->init();
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

    /**
     * 根据 id 拉取记录信息
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function info(Request $request)
    {
        !$this->inited && $this->init();
        $argvs = $request->validate([
            "id" => "required|integer",
            "appends" => "array",
            "with" => "array"
        ]);
        return rsps(ERR_SUCCESS, $this->dbModel::with($argvs["with"] ?? [])
            ->find($argvs["id"])
            ->append($argvs["appends"] ?? []));
    }

    /**
     * 删除记录值
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function del(Request $request)
    {
        !$this->inited && $this->init();
        $argvs = $request->validate([
            "id" => "required|integer"
        ]);
        $this->dbModel::where("id", $argvs["id"])->delete();
        return rsps(ERR_SUCCESS);
    }

    /**
     * 关联字段的查询
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function withSelect(Request $request)
    {
        !$this->inited && $this->init();
        $argvs = $request->validate([
            "columnName" => "required|string",
            "withName" => "required|string",
            "searchStr" => "nullable|string"
        ]);
        $column = $argvs["withName"];
        list ($withName, $withClass) = $this->getWithName($argvs["columnName"]);
        if ($withName && $withClass && class_exists($withClass)) {
            $db = app($withClass);
            $list = $db::when($argvs["searchStr"] ?? null,
                function ($query) use ($column, $argvs) {
                    $query->where($column, "like", "%{$argvs["searchStr"]}%");
                })
                ->select(["$column as text", "id"])
                // 防止记录过多引起内存超限，这里仅检索前100条记录
                ->limit(100)
                ->get();
        }

        return rsps(ERR_SUCCESS, $list ?? []);
    }

}
