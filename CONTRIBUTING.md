# Contributing Guide / 贡献指南

感谢你为项目贡献！请在提交前阅读以下规范，以保持代码质量与一致性。

## 开发环境
- PHP 7.4+（推荐 8.x），扩展：pdo_mysql、json、curl、openssl。
- MySQL/MariaDB。
- 本地或容器均可，推荐使用 Nginx + PHP-FPM。

## 安全基线（必须遵守）
- 严格遵守应用层 CSP：
  - style-src 仅允许 `'self'`，禁止任何内联样式与内联 `<style>`。
  - script-src 仅允许 `'self'`，禁止内联脚本、`eval`、`new Function()` 等。
  - 事件绑定使用 `addEventListener`，不要写 `onclick` 等内联事件。
- 不要引入外域 JS/CSS（除图片 `img-src * data:` 外）。
- 表单、SQL、输出均需做好转义与参数化（本项目已有相应封装，保持一致）。

## 代码风格与结构
- PHP：
  - 显式处理边界与错误，优先早返回，避免深层嵌套。
  - 避免无意义的 try/catch；捕获后要有价值的处理。
  - 输出到 HTML 前使用 `htmlspecialchars()`。
  - 数据库使用预处理语句；禁止字符串拼接 SQL 参数。
- JS：
  - 不写内联 JS；用模块文件（置于 `assets/`）。
  - 通过 `classList` 切换类名控制 UI 状态（如 `.hidden`）。
  - 避免使用 `eval`、`innerHTML +=` 拼接不可信内容。
- CSS：
  - 不写内联样式；全部集中到 `assets/style.css`。
  - 能用工具类解决的样式不再新增重复选择器。

## CSS 工具类约定（节选）
源：`assets/style.css`（Utilities 区域）。新增工具类时遵循以下命名与语义：
- 布局对齐：`row`（flex 行）、`wrap`、`items-center`、`items-end`、`self-center`、`justify-center`、`justify-end`
- 间距：`gap6`、`gap8`、`gap12`、`mt6`、`mt8`、`mt10`、`mt12`、`mb6`、`mb8`、`mb12`、`mb16`、`mb24`、`ml8`、`p18`
- 宽度：`w84`、`w90`、`w120`、`w140`、`w160`、`w220`、`minw420`、`maxw420`、`maxw520`、`maxw640`、`maxw720`、`maxw740`、`flex1`
- 文本：`small`、`text-light`、`text-lg`、`fw700`、`muted`
- 其它：`hidden`（display:none）、`pre-log`（日志块样式）

迁移示例：
```html
<!-- 原： -->
<div style="padding:18px; max-width:740px; margin:0 auto;"></div>
<!-- 现： -->
<div class="p18 maxw740 mx-auto"></div>
```

## 提交与评审
- 尽量将功能、修复、重构分成独立小提交。
- 提交说明聚焦“为什么”，而不是“改了什么”。
- 若修改安全策略（如 CSP）、公共组件（如分页、样式工具类），请在 `README.md` 或本文件同步补充说明。

## 回归与测试清单
- 前台：搜索、筛选、分页、比较栏（含复制/导出 CSV/TSV）。
- 后台：厂商/套餐 CRUD；库存同步（手动/自动配置）；Webhook 配置；导出日志；分页与筛选。
- 安全：随机抽查页面元素不含内联 `style`；浏览器控制台无 CSP 相关报错。

谢谢你的贡献！
