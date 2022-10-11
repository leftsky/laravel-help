<?php

namespace Leftsky\LaravelHelp\SimpleCurd;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait SimpleCurd
{
    // 【视情况可修改】模型命名空间
    private string $modelNamespace = "App\\Models\\";
    // 【视情况可修改】修改时忽略的字段
    private array $noUpdate = ["id", "create_at", "updated_at", "deleted_at"];
    // 【视情况可修改】自动采用like模糊匹配的字段
    private array $likeOpColumns = ["name", "title"];
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
            ) = ParseTable::decodeTableColumns($this->model);
        $this->inited = true;
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
        switch (gettype($cValue)) {
            case "NULL":
                break;
            case "boolean":
            case "integer":
                $cValue = match ($column["type"]) {
                    "boolean" => in_array(strtolower($cValue), [1, "true"]),
                    "integer" => intval($cValue),
                    default => $cValue
                };
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
                $item->{$key} = Auth::check() ? Auth::id() : null;
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
        return rsps(ERR_SUCCESS,
            $this->dbModel::with(array_merge($this->withs, $argvs["with"] ?? []))
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
        list ($withName, $withClass) = ParseTable::getWithName($argvs["columnName"]);
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
