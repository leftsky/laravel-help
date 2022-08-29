<?php

use OSS\Core\OssException;
use OSS\OssClient;

class Uploader
{
    /**
     * 请在 app.php 中添加如下配置
     * 'alioss' => [
     *      // 文件夹
     *      "folder" => env("ALIOSS_FOLDER"),
     *      // 超时时间
     *      'expire' => env('ALIOSS_EXPIRE'),
     *      // OSS 域名
     *      'domain' => env('ALIOSS_DOMAIN'),
     *      // 最大大小
     *      'maxsize' => env('ALIOSS_MAXSIZE'),
     *      // 密钥 Id
     *      'accessKeyId' => env('ALIOSS_AK_ID'),
     *      // 密钥 Secret
     *      'accessKeySecret' => env('ALIOSS_AK_SECRET'),
     *      // bucket 地域
     *      'region' => env('ALIOSS_REGION'),
     *      // bucket 名
     *      'bucket' => env('ALIOSS_BUCKET'),
     * ],
     * @return OssClient|null
     */
    private function getOssHandle(): ?OssClient
    {
        $oss = config('app.alioss');
        try {
            return new OssClient($oss['accessKeyId'],
                $oss['accessKeySecret'], $oss['region']);
        } catch (OssException $e) {
            return null;
        }
    }

    /**
     * 自动检测 request 中单个file文件，上传至oss并返回路径
     * @return string
     */
    public function ossUpload(): string
    {
        $file = request()->file("file");
        $oss = config('app.alioss');
        $extension = $file->getClientOriginalExtension();
        $path = $file->path();
        $rand = rand(100000, 999999);
        $now = date("Y-m-d_H_i_s_", time());
        $uri = $oss["folder"] . $now . $rand . "." . $extension;
        $ossClient = $this->getOssHandle();
        $ossClient->putObject($oss['bucket'], $uri, file_get_contents($path));
        return "{$oss["domain"]}/$uri";
    }
}