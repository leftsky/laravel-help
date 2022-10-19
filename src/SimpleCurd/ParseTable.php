<?php

namespace Leftsky\LaravelHelp\SimpleCurd;

use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Exception;

class ParseTable
{
    // 【视情况可修改】关联时显示对方的字段，顺序优先。字段均不存在则显示ID
    private static array $withShowColumns = ["name", "nickname", "username", "title",
        "serial", "serial_number", "code", "id"];
    // 缓存生存时间
    private static int $cacheAliveTime = 24 * 60 * 60;

    /**
     * 获得 当前表 的缓存key值
     */
    private static function getCacheKey(string $model): string
    {
        $id = DB::selectOne("SELECT MAX(id) as maxid FROM migrations");
        $maxId = $id->maxid ?? 0;
        return "leftsky_SimpleCurdTableColumnsCache:$maxId:" . $model;
    }

    /**
     * 扫描列信息
     * @param string $model // 模型类
     * @param string|null $baseModel
     * @param array $options // 配置选项
     *              fullInfo    // 是否是完整信息；组合columns描述。如果无需全部信息则只取name
     *              takeWith    // 是否扫描with信息
     *              useCache    // 是否可以使用缓存
     * @return array
     * @throws Exception
     */
    public static function decodeTableColumns(
        string $model,
        string $baseModel = null,
        array  $options = [
            "fullInfo" => true,
            "takeWith" => true,
            "useCache" => true
        ]
    ): array
    {
        $cacheKey = self::getCacheKey($model);
        // 如果存在缓存则标志可以使用缓存，则从缓存中拉取列信息
        if ($options["useCache"] && Cache::has($cacheKey)) {
            return json_decode(Cache::get($cacheKey), true);
        }
        if (!isset($model) || !class_exists($model)) throw new Exception();
        $curOrm = app($model);
        $con = $curOrm->getConnection();
        $con->registerDoctrineType(EnumType::class, "enum", "enum");
        $con->registerDoctrineType(SetType::class, "set", "set");
        $table = $con->getDoctrineSchemaManager()
            ->listTableDetails($curOrm->getTable());
        foreach ($table->getColumns() as $key => $column) {
            // 检索【belongsTo】关联字段，并加入with
            if ($options["takeWith"]) {
                list($withName, $withClass) = ParseTable::getWithName($key);
                if (isset($withName)) {
                    if (class_exists($withClass) &&
                        method_exists($baseModel ?? $curOrm, $withName)) {
                        $withs [] = $withName;
                        list($no1, $ccs, $no3) = self::decodeTableColumns($withClass,
                            $baseModel ?? $curOrm,
                            ["fullInfo" => false, "takeWith" => false, "useCache" => true]);
                        unset($showWithColumn);
                        foreach (self::$withShowColumns as $name) {
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
            $columns [] = $options["fullInfo"] ? [
                "name" => $key,
                "required" => $column->getNotnull(),
                "label" => $column->getComment() ?? match ($key) {
                        "created_at" => "创建时间",
                        "updated_at" => "更新时间",
                        "deleted_at" => "删除时间",
                        default => $key
                    },
                "type" => match ($column->getType()::class) {
                    IntegerType::class, SmallIntType::class, BigIntType::class => "integer",
                    BooleanType::class => "boolean",
                    JsonType::class => "json",
                    TextType::class => "text",
                    StringType::class => "string",
                    DateTimeType::class => "datetime",
                    DateType::class => "date",
                    EnumType::class => "enum",
                    SetType::class => "set",
                    default => null
                },
                "length" => $column->getLength() ?? 0,
                "valueList" => match ($column->getType()::class) {
                    EnumType::class, SetType::class => $curOrm->enums[$key] ?? [],
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
        if ($options["fullInfo"] && $options["takeWith"] && $options["useCache"]) {
            Cache::put($cacheKey, json_encode($data, JSON_UNESCAPED_UNICODE),
                self::$cacheAliveTime);
        }
        return $data;
    }

    /**
     * 从 column 获得 with 的类
     * @param $key // 列名
     * @param string $modelNamespace // 模型ROM所处的命名空间
     * @return array
     */
    public static function getWithName($key, $modelNamespace = "App\\Models\\"): array
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
            isset($withName) ? $modelNamespace . camelize($withName) : null
        ];
    }
}