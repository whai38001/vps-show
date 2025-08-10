# VPS Deals Website / VPS 套餐展示站点

This project is a simple website to display and manage VPS (Virtual Private Server) deals. It allows users to browse and filter VPS plans from various vendors. An admin panel is included for managing the vendors and their plans.

本项目用于展示与管理各厂商的 VPS 套餐，支持搜索、筛选与排序，并带有管理后台可对厂商与套餐进行增删改查。

## Features

*   **Public-facing:**
    *   List and search for VPS plans.
    *   Filter plans by vendor, billing cycle, price range, and location.
*   **Admin Panel:**
    *   Secure login/logout.
    *   CRUD (Create, Read, Update, Delete) functionality for vendors.
    *   CRUD functionality for VPS plans.
    *   Scripts to seed the database with initial data and import from specific vendors.

**特性（中文）**

- **前台功能**：
  - 套餐列表、搜索
  - 根据厂商、付费周期、价格区间、地区筛选
  - 价格排序、最新发布
- **后台管理**：
  - 登录/登出
  - 厂商与套餐的增删改查
  - 数据初始化脚本与部分厂商导入脚本

## Installation and Setup

For detailed deployment instructions, please see [DEPLOYMENT.md](./DEPLOYMENT.md).

1.  **Database Configuration:**
    *   Environment variables are supported (recommended in production).
    *   You can still open `lib/config.php` to view defaults.
    *   ENV variables:
        - `DB_HOSTS` (comma-separated, default: `mysql,127.0.0.1`)
        - `DB_PORT` (default: `3306`)
        - `DB_NAME` (default: `vps`)
        - `DB_USER` (default: `vps`)
        - `DB_PASS` (no default; set in production)
        - `DB_CHARSET` (default: `utf8mb4`)
        - `SITE_NAME` (default: `VPS Deals`)

2.  **Admin Credentials:**
    *   Configure via environment variables, or fallback to defaults in `lib/config.php`.
    *   ENV variables:
        - `ADMIN_USERNAME` (default: `admin`)
        - `ADMIN_PASSWORD_HASH` (recommended in production)
        - `ADMIN_PASSWORD` (plaintext fallback for dev; leave empty in production)
    *   Generate secure hash:
        ```bash
        php -r "echo password_hash('StrongPass123', PASSWORD_DEFAULT), \"\n\";"
        ```

3.  **Initialize Database and Data:**
    *   The database tables are created automatically when you first visit the site.
    *   To populate the database with sample data, navigate to `/scripts/seed.php` in your browser. This will add a sample vendor (RackNerd) and several of their VPS plans.
    
4.  **File Permissions:**
    *   Ensure the web server/PHP user can write to:
        - `log/` (PHP error log file)
        - `tmp/ratelimit/` (rate limiting state)
    *   These directories are auto-created at runtime if missing, but may need correct ownership (e.g., `chown -R www-data:www-data log tmp`).
    
5.  **Requirements:**
    *   PHP 7.4+ with extensions: `pdo_mysql`, `json`, `curl` (optional but recommended), `openssl`.
    *   MySQL/MariaDB 10.x+
    *   Nginx/OpenResty + PHP-FPM

**安装与初始化（中文）**

1. 数据库配置：编辑 `lib/config.php`，填写 `DB_HOSTS`、`DB_PORT`、`DB_NAME`、`DB_USER`、`DB_PASS` 等参数。
2. 管理员账号：建议在 `lib/config.php` 中设置安全的密码哈希（`ADMIN_PASSWORD_HASH`），并留空 `ADMIN_PASSWORD`。
3. 初始化：首次访问会自动建表。访问 `/scripts/seed.php` 可导入示例数据。

4. 本地开发常用脚本：
   - PHP 语法检查：
     ```bash
     ./scripts/lint.sh
     ```
     需要本机已安装 `php` 可执行文件。

## Usage

*   **Main Site:** Access the project's root URL in your browser to see the list of VPS deals.
*   **Admin Panel:**
    *   Access the admin panel by navigating to `/admin`.
    *   The default credentials are:
        *   **Username:** `admin`
        *   **Password:** `admin123`

**使用（中文）**

- 前台：直接访问站点根路径。
- 后台：访问 `/admin`，默认账号 `admin` / `admin123`（生产环境请修改）。

### 语言与中文显示

- 语言切换：右上角按钮或 URL 参数 `?lang=zh` / `?lang=en`，语言会写入 Cookie 记住偏好。
- 中文文案来源：
  - 固定 UI 文案来自 `lib/i18n.php` 的消息表。
  - 套餐卡片中的“副标题、配置特性、时长”等自由文本，运行时通过 `lib/i18n.php` 的 `i18n_text()` 做“术语表/正则”级别的最佳努力翻译。
- 为什么有的套餐卡片仍显示英文？
  - 套餐标题、角标、特性等字段的原始数据可能是英文，且不一定全部被术语表覆盖。
  - 本项目已在前端渲染时对“标题、角标、子标题、特性、价格周期”等字段调用 `i18n_text()`/`i18n_duration_label()`，但不在词汇表内的词仍会以原文显示。
  - 解决办法：
    1) 在后台录入/编辑时直接使用中文；
    2) 扩充 `lib/i18n.php` 中 `i18n_text()` 的正则与映射表；
    3) 如需更强翻译能力，可对接外部翻译服务（需自行实现缓存与速率控制）。

#### 后台保存时中文规范化（可选）

- 在 `admin` 的“新增/编辑 套餐”表单勾选“保存前规范化为中文（基于术语表）”，将对 `标题 / 副标题 / 角标 / 地区 / 功能规格` 做一次基于 `lib/i18n.php` 术语表与规则的中文规范化再入库。
- 该操作是幂等的（尽量避免重复替换），但建议仍保留原始英文数据在外部来源处，便于回溯。

## Scripts Usage & Maintenance

This project includes several scripts in the `/scripts` directory for maintenance, data import, and diagnostics.

### Data Seeding & Import

-   **`seed.php`**: Populates the database with initial sample data (default: RackNerd plans). This is useful for a fresh setup.
    -   **Usage**: Access `/scripts/seed.php` in your browser. It will clear old data for the sample vendor and insert the new plans.

-   **`import_url.php`**: The most powerful import script. It can fetch and parse plan information from a given URL.
    -   **Web UI Usage**: Access `/scripts/import_url.php` in your browser. You will see a form where you can paste one or more URLs (one per line) to import them in a batch.
    -   **CLI Usage**:
        ```bash
        php scripts/import_url.php "https://example.com/vps-deals"
        ```
    -   **Supported Vendors**: It has special parsers for CloudCone, RackNerd, BuyVM, and OrangeVPS, and will attempt a generic parse for other URLs.

-   **`import_cloudcone.php` / `import_orangevps.php`**: Vendor-specific importers. `import_url.php` is generally recommended, but these can be used for specific cases.
    -   **Usage**: Access `/scripts/import_cloudcone.php?url=<campaign_url>` in your browser (requires admin login).

### Diagnostics & Development

-   **`lint.sh`**: Checks all `.php` files in the project for syntax errors.
    -   **Usage**: Run from the command line:
        ```bash
        bash scripts/lint.sh
        ```

-   **`db_diag.php`**: A diagnostic tool to check the database connection.
    -   **Usage**: Access `/scripts/db_diag.php` in your browser to see the connection status for hosts defined in your config.

## File Structure

```
/
├── 404.html              # 404 Not Found page
├── index.php             # Main public-facing page to display VPS plans
├── admin/                # Admin panel files
│   ├── index.php         # Main admin page for managing vendors and plans
│   ├── login.php         # Admin login page
│   └── logout.php        # Admin logout script
├── assets/               # CSS, JS, and other static assets
│   └── style.css         # Main stylesheet
├── lib/                  # Core library files
│   ├── auth.php          # Authentication and session management
│   ├── config.php        # Database and site configuration
│   ├── i18n.php          # i18n utilities, glossary-based translation for plan texts
│   └── db.php            # Database connection and schema initialization
└── scripts/              # Utility and import scripts
    ├── import_cloudcone.php # Script to import plans from CloudCone
    └── seed.php          # Script to populate the database with sample data
```

## Deployment (Nginx/OpenResty example)

Use a typical PHP-FPM setup. Example server block (adjust paths/socket):

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/vps-site;  # project root

    index index.php index.html;

    location / {
        try_files $uri /index.php?$query_string;
    }

    # Protect internal dirs from direct access
    location ~ ^/(log|tmp)/ { deny all; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;  # or 127.0.0.1:9000
        fastcgi_read_timeout 60s;
    }
}
```

For container/compose deployments, point `DB_HOSTS` to the database service name, e.g. `DB_HOSTS=mysql`.

## API

- Public API endpoints are documented in `api/README.md`.
- Health check endpoint at `GET /api/health.php` for monitoring.
- Caching and rate-limiting:
  - `GET /api/plans.php` returns `ETag` and `Last-Modified`; supports conditional requests (HTTP 304).
  - Simple IP-based rate limit is enabled (default 120 req/min for read APIs).

## Dev & QA

- Lint PHP files:
  ```bash
  ./scripts/lint.sh
  ```

## 安全与上线清单（Checklist）

- 修改默认管理员密码：设置 `ADMIN_PASSWORD_HASH`（并清空 `ADMIN_PASSWORD`）。
- 数据库只允许内网访问：使用 `DB_HOSTS` 指向内网/容器网络。
- 检查写权限：`log/` 与 `tmp/ratelimit/` 可写。
- 生产环境关闭错误显示：已默认 `display_errors=0`，注意收集 `log/php-error.log`。
- 开启 HTTPS 与安全 Cookie：在 HTTPS 下会自动设定 `secure` 与 `httponly`。

## 常见问题（FAQ）

- 首次访问空白/报错：确认数据库连接与权限；访问 `/scripts/seed.php` 试填充数据。
- 中文未完全生效：扩充 `lib/i18n.php` 术语映射，或后台录入时勾选“规范化为中文”。
- API 429 Too Many Requests：触发了速率限制，稍后再试或放宽限额（参见 `lib/ratelimit.php`）。
