<?php

namespace App\Http\Controllers\Util;

use Illuminate\Http\Request;

trait SimpleCurd
{
    private mixed $dbModel;
    private array $columnName = [];
    private array $noUpdate = ["id", "create_at", "updated_at", "deleted_at"];

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        if (!isset($this->model) || !class_exists($this->model)) {
            throw new \Exception();
        }
        $this->dbModel = app($this->model);
        $table = $this->dbModel
            ->getConnection()
            ->getDoctrineSchemaManager()
            ->listTableDetails($this->dbModel->getTable());
        foreach ($table->getColumns() as $key => $column) {
            $names [] = $key;
        }
        $this->columnName = $names ?? [];
    }

    protected function get(Request $request)
    {
        $argvs = $request->validate([
            "page" => "integer",
            "current" => "integer",
            "pageSize" => "integer",
            "sort" => "array",
            "filter" => "array"
        ]);
        $page = $argvs["page"] ?? ($argvs["current"] ?? 1);
        $pageSize = $argvs["pageSize"] ?? 10;

        $list = $this->dbModel::with([]);
        return rsps(ERR_SUCCESS, [
            "total" => $list->count(),
            "data" => $list->forPage($page, $pageSize)->get(),
            "current" => $page
        ]);
    }
}
