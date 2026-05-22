# SkyQQBot - 光之遇见签到系统

基于 PHP + MySQL 的签到积分管理平台，支持每日签到、积分兑换卡密、排行榜、管理后台等功能。

## 功能特性

- 每日签到获取随机积分
- 积分兑换卡密
- 积分排行榜（JSON 接口 + 图片生成）
- 积分流水查询
- 管理后台（仪表盘、系统配置、卡密管理、用户管理）
- 一键安装向导

## 环境要求

- PHP >= 7.2（需开启 GD、PDO、cURL 扩展）
- MySQL >= 5.7
- Apache / Nginx

## 快速部署

1. 将项目文件上传至 Web 目录
2. 浏览器访问 `install.php` 填写数据库连接信息，完成安装
3. 安装完成后**务必删除** `install.php`
4. 默认管理员账号：`admin`，密码：`admin123`，请登录后立即修改

## 目录结构

```
├── admin.php              # 管理后台
├── sign.php               # 签到接口
├── get_points.php         # 积分查询接口
├── get_points_log.php     # 积分流水接口
├── redeem.php             # 卡密兑换接口
├── rank.php               # 排行榜接口
├── sc.php                 # 排行榜图片生成接口
├── install.php            # 安装向导
├── db_config.example.php  # 数据库配置模板
├── db_config.php          # 数据库配置（安装完后自动生成请忽略）
├── fonts/
│   └── msyh.ttc           # 中文字体（排行榜图片用）
├── uploads/rankings/      # 排行榜图片存储目录
│   └── s.php              # 图片清理脚本
├── .htaccess              # Apache 配置
└── .gitignore
```

## API 接口文档

所有接口统一返回 JSON 格式，结构如下：

```json
{
    "code": 200,
    "msg": "success",
    "data": {}
}
```

- `code`：状态码，`200` 表示成功，`400` 表示请求错误，`404` 表示未找到，`500` 表示服务器错误
- `msg`：提示信息
- `data`：返回数据（仅在成功时存在）

---

### 1. 签到

每日签到获取随机积分，同一用户每天只能签到一次。

**请求**

```
POST /sign.php
GET  /sign.php?user_identifier=xxx
```

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| user_identifier | string | 是 | 用户唯一标识 |

**成功响应**

```json
{
    "code": 200,
    "msg": "签到成功",
    "data": {
        "earned_points": 3,
        "total_points": 15
    }
}
```

**失败响应**

```json
{
    "code": 400,
    "msg": "今日已签到，请明天再来"
}
```

```json
{
    "code": 400,
    "msg": "缺少 user_identifier"
}
```

---

### 2. 查询积分

查询指定用户的当前积分。

**请求**

```
GET /get_points.php?user_identifier=xxx
```

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| user_identifier | string | 是 | 用户唯一标识 |

**成功响应**

```json
{
    "code": 200,
    "msg": "success",
    "data": {
        "points": 15
    }
}
```

**失败响应**

```json
{
    "code": 404,
    "msg": "用户不存在"
}
```

```json
{
    "code": 400,
    "msg": "缺少 user_identifier"
}
```

---

### 3. 查询积分流水

分页查询指定用户的积分变动记录。

**请求**

```
GET /get_points_log.php?user_identifier=xxx&page=1&page_size=10
```

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| user_identifier | string | 是 | 用户唯一标识 |
| page | int | 否 | 页码，默认 1 |
| page_size | int | 否 | 每页条数，默认 10，最大 50 |

**成功响应**

```json
{
    "code": 200,
    "msg": "获取成功",
    "data": {
        "total": 25,
        "page": 1,
        "page_size": 10,
        "list": [
            {
                "id": "3",
                "change_type": "sign",
                "points": 2,
                "balance_after": 15,
                "created_at": "2026-05-23 08:30:00",
                "extra_info": "2026-05-23"
            },
            {
                "id": "2",
                "change_type": "redeem",
                "points": -100,
                "balance_after": 13,
                "created_at": "2026-05-22 14:20:00",
                "extra_info": "HM-20260522-A1B2C3D4"
            },
            {
                "id": "1",
                "change_type": "admin",
                "points": 50,
                "balance_after": 113,
                "created_at": "2026-05-21 10:00:00",
                "extra_info": ""
            }
        ]
    }
}
```

> `change_type` 取值：`sign`（签到）、`redeem`（兑换）、`admin`（管理员调整）
>
> `extra_info` 含义：签到时为签到日期，兑换时为卡密编号，管理员调整时为空

**失败响应**

```json
{
    "code": 404,
    "msg": "用户不存在"
}
```

```json
{
    "code": 400,
    "msg": "缺少 user_identifier"
}
```

---

### 4. 兑换卡密

使用积分兑换卡密，系统自动生成唯一卡密并扣除积分。

**请求**

```
POST /redeem.php
GET  /redeem.php?user_identifier=xxx
```

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| user_identifier | string | 是 | 用户唯一标识 |

**成功响应**

```json
{
    "code": 200,
    "msg": "兑换成功",
    "data": {
        "code": "HM-20260523-A1B2C3D4",
        "remaining_points": 0
    }
}
```

**失败响应**

```json
{
    "code": 404,
    "msg": "用户不存在，请先签到"
}
```

```json
{
    "code": 400,
    "msg": "积分不足，需要 100 积分，当前 50"
}
```

```json
{
    "code": 400,
    "msg": "缺少 user_identifier"
}
```

---

### 5. 排行榜

获取积分排行榜 Top 6。

**请求**

```
GET /rank.php
```

无需参数。

**成功响应**

```json
{
    "code": 200,
    "msg": "success",
    "data": [
        {
            "user_identifier": "ABC123DEF",
            "points": 520
        },
        {
            "user_identifier": "GHI456JKL",
            "points": 380
        },
        {
            "user_identifier": "MNO789PQR",
            "points": 215
        }
    ]
}
```

---

### 6. 排行榜图片生成

生成排行榜图片并保存到服务器，返回图片 URL。
此处需要把sc.php中的sky.qmkjcm.cn换成自己的域名

**请求**

```
GET /sc.php
```

无需参数。

**成功响应**

```json
{
    "code": 200,
    "msg": "success",
    "data": {
        "image_url": "https://sky.qmkjcm.cn/uploads/rankings/rank_20260523_164326_6a10172eb774e.png"
    }
}
```

**失败响应**

```json
{
    "code": 500,
    "msg": "未找到 TTF/TTC 中文字体，请将字体文件放入 /fonts/ 目录",
    "data": null
}
```

```json
{
    "code": 500,
    "msg": "获取排行榜数据失败",
    "data": null
}
```

---

## 管理后台

访问 `admin.php` 进入管理后台，包含以下功能模块：

| 模块 | 功能 |
|------|------|
| 仪表盘 | 总用户数、今日签到、总积分池、未使用卡密 |
| 系统配置 | 积分名称、签到积分范围、兑换所需积分 |
| 卡密管理 | 生成/导入/导出/删除卡密 |
| 用户管理 | 查看用户列表、调整积分、查看积分流水 |
| 修改密码 | 修改管理员登录密码 |

## 数据库表结构

| 表名 | 说明 |
|------|------|
| users | 用户表（user_identifier, points） |
| sign_log | 签到记录（user_id, sign_date） |
| points_log | 积分流水（user_id, change_type, points, balance_after） |
| redeem_code | 卡密表（code, user_id, status） |
| system_config | 系统配置（config_key, config_value） |
| admin_users | 管理员表（username, password_hash） |

## 注意事项

- `db_config.php` 包含数据库密码，已被 `.gitignore` 忽略，不会上传至仓库
- 安装完成后请删除 `install.php`
- 请及时修改默认管理员密码
- 排行榜图片生成依赖 `fonts/` 目录下的中文字体文件
