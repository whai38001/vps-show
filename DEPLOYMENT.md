# 部署指南 / Deployment Guide

## 环境要求

### 系统要求
- Linux 系统（推荐 Ubuntu 20.04+ / CentOS 8+）
- PHP 7.4+ 及扩展：`pdo_mysql`, `json`, `curl`, `openssl`
- MySQL 5.7+ / MariaDB 10.3+
- Nginx 1.18+ / OpenResty

### 硬件要求
- **最小配置**：1 CPU，512MB RAM，10GB 存储
- **推荐配置**：2 CPU，2GB RAM，20GB 存储
- **高并发**：4+ CPU，4GB+ RAM，SSD 存储

## 部署步骤

### 1. 代码部署
```bash
# 克隆或上传代码到服务器
cd /var/www/
git clone <repository> vps-site
cd vps-site

# 设置文件权限
chown -R www-data:www-data .
chmod -R 755 .
chmod -R 775 log tmp
```

### 2. 环境变量配置
创建环境配置文件：
```bash
# 生产环境变量 (推荐使用 systemd 或容器环境变量)
export DB_HOSTS="127.0.0.1"
export DB_PORT="3306"
export DB_NAME="vps_production"
export DB_USER="vps_user"
export DB_PASS="your_secure_password"
export ADMIN_USERNAME="admin"
export ADMIN_PASSWORD_HASH="$(php -r 'echo password_hash("your_admin_password", PASSWORD_DEFAULT);')"
export ADMIN_PASSWORD=""  # 生产环境必须为空
export SITE_NAME="Your VPS Deals Site"
export CORS_ALLOW_ORIGIN="https://yourdomain.com"
```

### 3. 数据库设置
```sql
-- 创建数据库和用户
CREATE DATABASE vps_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'vps_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON vps_production.* TO 'vps_user'@'localhost';
FLUSH PRIVILEGES;
```

### 4. Nginx 配置
```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/vps-site;
    index index.php index.html;

    # 安全配置
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;
    add_header X-XSS-Protection "1; mode=block";

    # 主路由
    location / {
        try_files $uri /index.php?$query_string;
    }

    # 保护敏感目录
    location ~ ^/(log|tmp|lib|scripts)/ {
        deny all;
        return 404;
    }

    # PHP 处理
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_read_timeout 60s;
    }

    # 静态文件缓存
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}

# HTTPS 配置 (使用 Let's Encrypt)
server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    
    # SSL 安全配置
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    
    # 其他配置同 HTTP
    root /var/www/vps-site;
    # ... 其他配置项
}
```

### 5. 初始化数据
```bash
# 访问网站自动创建数据表结构
curl -I http://yourdomain.com/

# 导入示例数据（可选）
curl http://yourdomain.com/scripts/seed.php
```

## Docker 部署

### docker-compose.yml
```yaml
version: '3.8'
services:
  web:
    image: php:8.1-fpm-alpine
    volumes:
      - ./:/var/www/html
    environment:
      - DB_HOSTS=mysql
      - DB_NAME=vps
      - DB_USER=vps
      - DB_PASS=secure_password
      - ADMIN_PASSWORD_HASH=${ADMIN_PASSWORD_HASH}
    depends_on:
      - mysql

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./:/var/www/html:ro
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - web

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_DATABASE=vps
      - MYSQL_USER=vps
      - MYSQL_PASSWORD=secure_password
      - MYSQL_ROOT_PASSWORD=root_password
    volumes:
      - mysql_data:/var/lib/mysql

volumes:
  mysql_data:
```

## 监控和维护

### 日志监控
```bash
# PHP 错误日志
tail -f /var/www/vps-site/log/php-error.log

# Nginx 访问日志
tail -f /var/log/nginx/access.log

# 系统资源监控
htop
iostat -x 1
```

### 性能优化
1. **PHP-FPM 调优**：
   ```ini
   ; /etc/php/8.1/fpm/pool.d/www.conf
   pm = dynamic
   pm.max_children = 20
   pm.start_servers = 4
   pm.min_spare_servers = 2
   pm.max_spare_servers = 6
   ```

2. **MySQL 调优**：
   ```ini
   # /etc/mysql/mysql.conf.d/mysqld.cnf
   innodb_buffer_pool_size = 1G
   innodb_log_file_size = 256M
   query_cache_size = 64M
   ```

3. **缓存策略**：
   - 启用 Nginx 静态文件缓存
   - 配置 CDN（如 CloudFlare）
   - 考虑 Redis 缓存热点数据

### 安全加固
1. **防火墙配置**：
   ```bash
   ufw allow 22/tcp
   ufw allow 80/tcp
   ufw allow 443/tcp
   ufw enable
   ```

2. **定期更新**：
   ```bash
   # 系统更新
   apt update && apt upgrade -y
   
   # PHP 依赖更新
   composer update --no-dev
   ```

3. **备份策略**：
   ```bash
   # 数据库备份
   mysqldump -u vps -p vps_production > backup_$(date +%Y%m%d).sql
   
   # 文件备份
   tar -czf site_backup_$(date +%Y%m%d).tar.gz /var/www/vps-site
   ```

## 故障排除

### 常见问题
1. **数据库连接失败**：检查 `lib/config.php` 中的数据库配置
2. **权限错误**：确保 `log/` 和 `tmp/` 目录可写
3. **500 错误**：查看 PHP 错误日志
4. **API 429 错误**：速率限制触发，检查 `tmp/ratelimit/` 文件

### 健康检查
```bash
# 检查服务状态
systemctl status nginx php8.1-fpm mysql

# 检查端口监听
netstat -tlnp | grep -E ':(80|443|3306|9000)'

# 检查磁盘空间
df -h

# 检查内存使用
free -m
```