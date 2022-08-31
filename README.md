# laravel-help
Larrvel help function、trait、abstract。For fast and simple curd、upload、wechat help、alipay、wechat pay、meida controller


### 一键curd协助trait

### 在路由中增加本类get function路由。在需要的 Controller 中添加
```PHP
    // 引入一键curd 代理get方法
    use SimpleCurd;
    // 设置当前模型
    private string $model = YourModal::class;
```
### 前端请求body
```
{
    // 当前页面，二选一。page优先级高
    page: 1,
    current: 1,
    // 分页大小
    pageSize: 10,
    // 排序方式 1；ascend 正序 descend 倒序
    sort: {
        columnName: ascend
    },
    // 排序方式2、3 正序与倒序 排序参数三选1
    orderBy: columnName,
    orderByDesc: columnName,
    // 所有筛选都可以同时出现，代理逻辑用where相关联
    // 筛选方式1 相等筛选
    columnName: 100,
    // 筛选方式2 whereIn 筛选
    columnName2: [123, 200],
    // 筛选方式3 
    filter: {
        columnName3: [50, "真好"]
    }
}
// 回执
{
    // 总数量
    total: 1000,
    // 查询出的数据
    data: [...],
    // 当前页码
    current: 1
}
```
