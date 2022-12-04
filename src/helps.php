<?php

// 成功
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Route;

if (!function_exists('rsps')) {
    /**
     * laravel 封装回执
     * @param int $code
     * @param null $data
     * @param string|null $msg
     * @return mixed
     */
    function rsps(int $code, $data = null, string $msg = null): mixed
    {
        return response([
            "code" => $code,
            "msg" => $msg ?? define_to_message($code),
            "data" => $data
        ], define_to_status_code($code));
    }
}

if (!function_exists("get_cur_domain")) {
    /**
     * 获得当前的域名，【app.imgDomain】-【$_SERVER】-【domain】
     * @return string
     */
    function get_cur_domain(): string
    {
        $domain = config("app.imgDomain");
        if (!$domain) {
            if (isset($_SERVER['SERVER_PORT']) && isset($_SERVER['HTTP_HOST']))
                $domain = ((int)$_SERVER['SERVER_PORT'] === 80
                        ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'];
            else $domain = config("domain");
        }
        return $domain;
    }
}

if (!function_exists("fix_img")) {
    /**
     * 使用域名拼接图片路径
     * @param string $value
     * @return string
     */
    function fix_img(string $value): string
    {
        if (strstr($value, "http")) return $value;
        if ($value == "") return config("app.default.image");
        $domain = get_cur_domain();
        return "$domain$value";
    }
}

if (!function_exists("undo_fix_img")) {
    /**
     * 消除图片路径中的本域名信息
     * @param string $value
     * @return string
     */
    function undo_fix_img(string $value): string
    {
        if ($value == "" || !strstr($value, "http")) return $value;
        $domain = get_cur_domain();
        return str_replace($domain, "", $value);
    }
}

if (!function_exists("fix_article")) {
    /**
     * 使用域名拼接替换文章内资源路径
     * @param string $value
     * @param string $search
     * @return string
     */
    function fix_article(string $value, string $search): string
    {
        if (strstr($value, "http")) return $value;
        if ($value == "") return config("app.default.image");
        $domain = get_cur_domain();
        return str_replace($search, $domain . $search, $value);
    }
}

if (!function_exists("undo_fix_article")) {
    /**
     * 消除文章内的路径信息
     * @param string $value
     * @param string $search
     * @return string
     */
    function undo_fix_article(string $value, string $search): string
    {
        if ($value == "" || !strstr($value, "http")) return $value;
        $domain = get_cur_domain();
        return str_replace($domain . $search, $search, $value);
    }
}

if (!function_exists('try_url')) {
    function try_url(string $url): bool
    {
        $client = new \GuzzleHttp\Client([
            "timeout" => 1
        ]);
        try {
            return $client->get($url)->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            return false;
        }
    }
}

if (!function_exists('route_simple_curd')) {
    /**
     * 为一键CURD创建路由
     * @param $class
     */
    function route_simple_curd($class)
    {
        Route::post("add", $class . "@add");
        Route::post("get", $class . "@get");
        Route::post("info", $class . "@info");
        Route::post("modify", $class . "@modify");
        Route::post("del", $class . "@del");
        Route::post("withSelect", $class . "@withSelect");
        Route::post("enums", $class . "@enums");
        Route::post("columns", $class . "@columns");
    }
}

