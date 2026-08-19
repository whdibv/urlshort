# 🔗 URL Shortener · 自建短链接服务

一个**开箱即用**的短链接服务，PHP 单文件 + JSON 存储，无需数据库、无需框架、无需 Composer，传到任何 PHP 虚拟主机就能跑。

在线演示：<https://url.wldwz.icu>

## ✨ 特性

| 功能 | 说明 |
|---|---|
| ⚡ **极简部署** | 单文件核心 + JSON 存储，零依赖，虚拟主机即传即用 |
| 🔀 **301 跳转** | 访问 `/短码` 直接 301 跳转到目标链接，SEO 友好 |
| 🔒 **链接加密** | 创建时可选访问密码，验证通过才跳转（session 免密） |
| 📱 **二维码** | 创建结果页直接显示二维码，后台可放大查看/保存 |
| 🔌 **完整 API** | `POST /api/create`，支持 Token 认证，可程序化创建 |
| 🛡 **访客管理** | 开关 + 每日限额（按 IP），防滥用 |
| ⏰ **自动过期** | 访客链接到期自动失效并删除，不占空间 |
| 📍 **IP 记录** | 自动记录访问者 IP（IPv4/IPv6），后台查看归属地（netart.cn） |
| 🔑 **API Token** | 独立 Token 管理（生成/吊销），不用暴露管理密码 |
| 🎨 **现代 UI** | 统一设计语言，桌面/移动端自适应 |

## 🚀 快速开始

### 1. 上传文件

把以下文件上传到虚拟主机任意目录（如 `/url/`）：

```
index.php              核心程序（唯一必传文件）
qrcode.min.js          二维码库（前端生成二维码用）
admin_config.php       配置（密码 + 站点链接）※ 首次部署请复制示例创建
```

### 2. 创建配置

```bash
# 复制示例配置并修改
cp admin_config.example.php admin_config.php
```

```php
<?php
return array(
    'password'   => '改成你自己的强密码',   // 管理后台密码（必改！）
    'site_links' => array(                 // 底部站点链接（可选，可留空数组）
        '🏠 主站' => 'https://example.com',
        '📝 博客' => 'https://blog.example.com',
    ),
);
```

### 3. 完成 🎉

- 首页（创建短链接）：`https://你的域名/`
- 管理后台：`https://你的域名/admin`

> 💡 支持子目录部署（如 `https://域名/url/`），程序自动适配。
> 想用独立二级域名（如 `s.example.com`），在域名管理里把它指向该目录即可。

## 📖 功能详解

### 创建短链接

首页粘贴长链接 → 可选自定义短码 / 访问密码 → 生成即自动复制 + 二维码。

```
https://你的域名/Ab3xYz   → 301 跳转 → 原始长链接
https://你的域名/blog     → 301 跳转（自定义短码）
```

### 访客使用管理（后台）

| 设置 | 说明 |
|---|---|
| 允许访客创建 | 关闭后仅管理员可创建（管理员 = 登录后台的浏览器） |
| 访客每日上限 | 按 IP 统计，每日最多 N 个（1-100） |
| 访客链接有效期 | 到期自动失效删除（0 = 永久） |

### IP 访问记录（后台）

每个短链接的 **IP 按钮** → 弹层显示访问明细：

```
IP                       次数  最后访问    归属地
223.73.229.8              12   8-19 18:22  中国 广东 佛山 移动
```

- 自动记录 IPv4 / IPv6，去重计数 + 首次/最后访问时间
- 归属地通过 [netart.cn](https://ip.netart.cn) 免费查询，**本地缓存**（同一 IP 只查一次）
- 单链接最多记录 100 个 IP，超出自动淘汰最久未访（防数据膨胀）

## 🔌 API 使用

### 创建短链接

```
POST /api/create
Content-Type: application/x-www-form-urlencoded
```

| 参数 | 必填 | 说明 |
|---|---|---|
| `url` | ✅ | 目标长链接（http/https 开头） |
| `code` |  | 自定义短码（2-20 位，字母数字 `-_`；留空自动生成） |
| `password` |  | 访问密码（可选，加密链接） |
| `token` |  | API Token 或管理密码。带有效 Token = 管理员身份（无限额、永久）；不带 = 访客身份（受限额 + 过期策略约束） |

```bash
curl -X POST "https://你的域名/api/create" \
  -d "url=https://example.com/very/long/path" \
  -d "code=blog" \
  -d "token=你的API-Token"
```

```json
{
  "code": 200,
  "msg": "创建成功",
  "short_url": "https://你的域名/blog",
  "code": "blog",
  "url": "https://example.com/very/long/path",
  "password": false,
  "expires": null
}
```

### 错误返回

```json
{ "code": 0, "msg": "短码「blog」已被占用" }
```

常见 `msg`：`url 无效` / `访客暂不可创建短链接` / `今日创建已达上限（N 个）` / `短码已被占用` / `Token 无效`。

### 管理 API Token（后台）

「API Token 管理」卡片：**生成** / **复制** / **吊销**，支持多个并存——给不同程序分配不同 Token，哪个泄露单独吊销哪个。

## 📁 目录结构

```
├── index.php              核心程序（创建/跳转/后台/API 全在这里）
├── admin_config.php       配置（密码、站点链接）※ 不入库，自行创建
├── admin_config.example.php  配置模板
├── short.json             数据文件（自动生成：links/settings/guest_usage/api_tokens/ip_cache）
├── short.example.json     数据结构示例
├── qrcode.min.js          二维码生成库（davidshimjs/qrcodejs，MIT）
└── README.md
```

## 🛡 安全说明

- ✅ 管理后台密码**不硬编码**在代码里，存于独立配置文件
- ✅ API Token 与密码分离，可单独吊销
- ✅ 链接密码以 **MD5 哈希**存储，不存明文
- ✅ IP 归属地查询接口校验 `FILTER_VALIDATE_IP`，拒绝非法输入
- ✅ 短码白名单正则（字母数字 `-_`），防注入
- ⚠️ 管理后台未做暴力破解防护，**请务必使用强密码**（虚拟主机无防火墙插件时可自加 IP 白名单）
- ⚠️ 数据存 JSON 文件，单机个人使用完全够；如遇高并发写，建议加锁或换 SQLite

## ❓ FAQ

**Q：需要数据库吗？**
A：不需要。全部数据存 `short.json`（JSON 格式），备份 = 下载这个文件。

**Q：支持 IPv6 访问记录吗？**
A：支持。访问 IP 原生记录（REMOTE_ADDR），归属地查询接口也能识别 IPv6。

**Q：访客创建的链接会一直占用空间吗？**
A：不会。默认 7 天自动过期，过期后首次访问即删除；管理员链接永久保留。

**Q：可以改首页/后台的文案吗？**
A：可以，所有页面文字都在 `index.php` 里，直接改。

**Q：管理密码忘了怎么办？**
A：直接修改 `admin_config.php` 里的 `password` 即可。

## 📄 License

[MIT](LICENSE) © 鱼鱼

---

*由 [🐟 鱼鱼](https://blog.wldwz.icu) 维护 · 用 ❤️ 和 PHP 写成*
