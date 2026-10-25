<!--
 * MD.Viewer — Documentation
 * Version: 2.11.3
-->

# MD.Viewer

<!-- md-i18n:switcher -->
**其他语言：** [English](README.md) · [Deutsch](README.de.md) · 中文 · [हिन्दी](README.hi.md) · [Español](README.es.md) · [Русский](README.ru.md)

> 一个安全的自托管 PHP Markdown 查看器与浏览器，把普通的 `.md` 文件变成精美的文档页面，具备可搜索的文件浏览器、自动生成的目录、标题编号、Mermaid 图表、术语提示气泡、响应式阅读控制、深色模式、内置自我更新器、文件上传、剪贴板预览和服务端配置。无构建步骤。无数据库。无框架。

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![md.php](https://img.shields.io/badge/md.php-v2.18.0-success)](https://github.com/paulmann/MD.Viewer)
[![updater.php](https://img.shields.io/badge/updater.php-v3.14.0-4f46e5)](https://github.com/paulmann/MD.Viewer)

---

## 概述

MD.Viewer 围绕 **`md.php`** 构建：这是单一的 PHP 入口，负责渲染同名的 Markdown 文件；当没有同名文件时，则切换到递归的 Markdown 文件浏览器。项目为简单部署而设计：把文件复制到 PHP 主机上，把 `.md` 文档放在旁边，然后在浏览器中打开 `md.php`。

查看器专注的是无需工具链的自托管文档。它支持易读的排版、标题编号、自动生成目录、带自动修复与英文控制台诊断的 Mermaid 渲染、代码块与引用的统一复制控件、术语提示气泡、深色模式、面向移动端的控件，以及在本机保存查看偏好的设置面板。

**`updater.php`** 是配套的自我更新与维护引擎。它用 ETag 与 SHA-256 校验 GitHub 上的原始文件，在替换前创建带版本号的备份，可从设置面板或直接通过 URL 还原旧版本，管理可选的 `index.php` 硬链接，处理 `.md` 文件上传，并支持在浏览器中直接更新与回滚。

服务端行为由放在 `md.php` 旁边的 **`.md.ini`** 配置文件控制。该文件硬性锁定功能开关，浏览器或设置面板都无法覆盖。

---

## 快速开始

```bash
# Clone into your web root or any subdirectory
git clone https://github.com/paulmann/MD.Viewer.git /var/www/html/docs

# Create a Markdown file that matches the script name
echo "# Hello, World\n\nThis is my documentation." > /var/www/html/docs/md.md

# Open in a browser
# https://your-domain.com/docs/md.php
```

最小配置：

- 把 **`md.php`**（连同 `js/md/` 和 `css/md/` 目录）放在 **`md.md`** 旁边。
- 在浏览器中打开 **`md.php`**。
- 脚本会自动寻找同名的 Markdown 文件。

可选：

- 加入 **`updater.php`**，以启用应用内更新检查、应用更新、备份、还原、文件上传和 `index.php` 链接管理。
- 在 `.md.ini` 中设置 **`ALLOW_UPDATE = true`** 以启用更新系统（默认关闭）。

### 单文件引导安装

最快的安装方式是只把 **`updater.php`** 上传到服务器，然后在浏览器中用 `?update=true` 打开它。它会：

1. 自动创建带安全默认值的 `.md.ini`。
2. 从 GitHub 下载所有受管理的文件（`md.php`、`js/md/*`、`css/md/*`、`README.md`、`LICENSE`）。
3. 若所需子目录（`js/md/`、`css/md/`）不存在，则以 `0755` 权限创建。
4. 把自己放进最终清单，并显示完整的结果页面。

**要求：** 浏览器模式需要在运行前于 `.md.ini` 中设置 `ALLOW_UPDATE = true`（或者手动把它加入自动创建的 `.md.ini` 并重新加载）。机器 API 则改用 `.md.ini` 中的 `API_KEY` 作为凭据 —— 参见 [机器 API](#4-machine-apijson供外部客户端调用)。

```bash
# Upload only updater.php, then:
curl -o updater.php https://raw.githubusercontent.com/paulmann/MD.Viewer/main/updater.php
# Edit .md.ini (auto-created on first hit) and set ALLOW_UPDATE = true
# Then open: https://your-domain.com/updater.php?update=true
#
# Or drive it over the machine API (no ALLOW_UPDATE needed, key from .md.ini):
curl "https://your-domain.com/updater.php?api_key=<key>&action=conflicts&format=json"
curl -X POST "https://your-domain.com/updater.php?api_key=<key>&action=install&format=json&dry_run=1"
```

---

## 环境要求

- PHP **8.3+**。
- PHP 扩展：`mbstring` 与 `curl`（更新器和剪贴板功能需要）。
- Web 服务器，例如 Apache、Nginx、Caddy，或 PHP 内置服务器。
- 访问 CDN 资源（Mermaid、语法高亮、Tailwind）需要浏览器端能上网，除非替换为本地副本。

---

## 安装

### 通过 Git 克隆

```bash
git clone https://github.com/paulmann/MD.Viewer.git
cd MD.Viewer
```

### 手动安装

1. 下载仓库或发布归档。
2. 把 `md.php`、`updater.php`、`js/`、`css/`，以及可选的 `README.md` 复制到目标目录。
3. 在同一目录树中加入你的 Markdown 文件。
4. 在浏览器中打开 `md.php`。首次加载时会自动创建 `.md.ini`。

### 通过更新器单文件安装

1. 只把 `updater.php` 上传到服务器目录。
2. 在浏览器中打开一次 `updater.php` —— 它会以 `ALLOW_UPDATE = false` 自动创建 `.md.ini`。
3. 在服务器上编辑 `.md.ini`：设置 `ALLOW_UPDATE = true`。
4. 打开 `https://your-domain.com/updater.php?update=true`。
5. 更新器会下载所有文件，按需创建 `js/md/` 与 `css/md/` 目录，并显示结果页面。
6. 打开 `md.php` —— MD.Viewer 已就绪。
7. 可选：不修改 `ALLOW_UPDATE` 也能安装，只需用 `.md.ini` 中的 `API_KEY`
   调用机器 API（参见 [机器 API](#4-machine-apijson供外部客户端调用)）。

### PHP 内置服务器

```bash
cd /path/to/MD.Viewer
php -S localhost:8080
# then open http://localhost:8080/md.php
```

### Nginx 示例

```nginx
server {
    listen 80;
    server_name docs.example.com;
    root /var/www/html/docs;
    index index.php md.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## 文件结构

```text
MD.Viewer/
├── md.php                     # Main viewer / browser script       v2.18.0
├── updater.php                # Self-updater, backup, upload        v3.14.0
├── .md.ini                    # Server-side config (auto-created)
├── md.md                      # Default Markdown file for md.php
├── uploads.md/                # Created automatically for uploaded/saved files
├── md.backup/                 # Created automatically when updates/restores run
│   ├── 2.8.2/
│   ├── 2.8.2-pre-restore/     # Auto-created before each rollback
│   └── .state/                # ETag + SHA-256 per-file cache
├── css/
│   └── md/
│       ├── md.css             # v2.2.2
│       ├── settings.css       # v2.9.0
│       └── tooltips.css       # v2.4.5
├── js/
│   └── md/
│       ├── md.js              # v2.7.0
│       ├── settings.js        # v2.11.0
│       ├── tooltips.js        # v2.4.5
│       └── upload.js          # v2.9.1
├── LICENSE                    # v1.0.0
└── README.md                  # v2.11.3
```

关键命名规则：`md.php` 会寻找 `md.md`，`docs.php` 会寻找 `docs.md`。多个彼此独立的查看器实例可以共用一个目录树，无需额外的路由配置。

---

## 服务端配置 —— `.md.ini`

首次请求时，`md.php`（或 `updater.php`）会在同一目录创建 `.md.ini`。该文件硬性锁定功能开关，使其**无法**从浏览器或设置面板修改。每次加载都会自动追加缺失的键及其安全默认值。

```ini
; MD.Viewer server-side configuration

; Disable the "Upload .md" button in the file browser (recommended: true)
DISABLE_UPLOAD    = true

; Disable the "Preview Clipboard" button in the file browser
DISABLE_CLIPBOARD = false

; Disable the "Save to File" button in clipboard preview
DISABLE_SAVE_CLIPBOARD_TO_FILE = true

; Allow updating files via updater.php?update=true or the Settings panel
ALLOW_UPDATE = false

; Allow restoring a backup via updater.php?restore=latest or ?restore=[version]
ALLOW_RESTORE = false

; Allow creating/removing the index.php hard link from the Settings panel
ALLOW_CREATE_INDEX_PHP_LINK = true

; Machine API key used by external clients (a control panel, an installer)
; Generated automatically on first run. Send it as ?api_key=<value>.
API_KEY = mdv_your_generated_key_here
```

| 键 | 默认值 | 作用 |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | 隐藏并禁用上传按钮 |
| `DISABLE_CLIPBOARD` | `false` | 隐藏并禁用剪贴板预览按钮 |
| `API_KEY` | 自动生成 | 机器 API 的密钥（`?api_key=`）；JSON 安装时必需 |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | 隐藏剪贴板预览中的“保存到文件” |
| `ALLOW_UPDATE` | `false` | 启用更新系统（设置面板 + `?update=true`） |
| `ALLOW_RESTORE` | `false` | 启用备份与还原分区以及 `?restore=` URL 模式 |
| `ALLOW_CREATE_INDEX_PHP_LINK` | `true` | 启用设置面板中的索引文件分区 |

**所有键都会被自动追加**（连同其默认值）到已有的 `.md.ini` 中，在下一次加载时生效 —— 升级永远不需要手动编辑 `.md.ini`。

### 按目录生效的 `.md.ini`（v2.12.0 / v3.12.0）

与 `md.php` 相邻的 `.md.ini` 保持其安装级文件的角色
（`BROWSE_DIR`、`API_KEY`、`ALLOW_*`，以及 `DISABLE_*` 系列标志的默认值）。
在此之上，**浏览根目录下的任意目录都可以带有自己的
`.md.ini`**。适用于某个文档的设置，是基础文件再叠加从浏览根目录
到该文档所在目录路径上找到的每一个 `.md.ini` 的结果 —— 越深的文件
总是覆盖越浅的文件：

```text
ext/mdviewer/.md.ini     base file: installation-level keys, API key
share/.md.ini            applies to everything below share/
share/sub/.md.ini        applies to share/sub/ and below, and wins
```

在目录文件中只有这三个键会被采纳：

| 键 | 默认值 | 在该目录中的作用 |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | 是否允许 **上传 .md**，以及上传的文件落在哪里 |
| `DISABLE_CLIPBOARD` | `false` | 剪贴板预览按钮是否可用 |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | 是否允许 **保存到文件**，以及文件落在哪里 |

规则：

- `BROWSE_DIR`、`API_KEY`、`ALLOW_UPDATE`、`ALLOW_RESTORE` 和
  `ALLOW_CREATE_INDEX_PHP_LINK` 这些键属于安装级。它们在目录文件中被忽略，
  并会向 PHP 错误日志写入一条说明。
- 值的解析与基础文件同样严格（去除首尾空白，剥掉可选的成对引号，
  含空字节、换行或超过 255 字节的值一律丢弃）。无法读取的文件或不可用的值
  会被跳过，并在错误日志中留下一条记录 —— 它绝不会破坏页面，
  也绝不会回退到某个特权默认值。
- 只有当目录 `.md.ini` 的真实路径位于浏览根目录之内时才会被读取，
  因此试图用 `..` 爬出去的路径会被拒绝。
- 在阅读模式（`?file=sub/doc.md`）下，由文档所在目录决定；在
  文件浏览器模式下，由浏览根目录决定。
- **上传 .md** 与 **保存到文件** 写入正在查看的目录。请求通过
  `dir` 参数携带该目录（相对于浏览根目录）；含穿越片段的 `dir` 值、
  绝对路径或空字节会得到 `400` 应答，目录也不可能以其他方式被逃离。
- 目录级 `.md.ini` 是可选的。没有它们时行为与从前完全一致。

---

## 使用模式

### 阅读模式

当存在同名 Markdown 文件时，MD.Viewer 将其渲染为带样式的文档页面。它从第一个顶级标题推导页面标题，从第一段构建描述，渲染目录，并应用本地的查看偏好。

### 文件浏览器模式

当不存在同名 Markdown 文件时，MD.Viewer 切换到递归的 Markdown 浏览器。浏览器扫描当前目录树，以可搜索的表格列出 Markdown 文件，并用 `?file=relative/path.md` 打开选中的文件。

### 剪贴板预览模式

文件浏览器包含一个 **剪贴板预览** 按钮。把任意 Markdown 文本粘贴到文本区并点击预览 —— 内容会以阅读模式渲染。若 `DISABLE_SAVE_CLIPBOARD_TO_FILE = false`，会出现一条 **保存到文件** 栏，含文件名输入框和保存按钮，写入 `BROWSE_DIR`（当该键未设置时回退到 `md.php` 旁边的 `uploads.md/`）。

### URL 参数

| 参数 | 含义 | 示例 |
|---|---|---|
| `?file=path/to/doc.md` | 渲染指定的 Markdown 文件 | `md.php?file=docs/api.md` |

---

## 功能

### Markdown 渲染

- 标题、段落、斜体、粗体、行内代码、引用、水平分隔线、围栏代码块。
- Mermaid 自动修复会转换 PlantUML 风格的类注释、字面 `\n`、未闭合的 `<br>`、PlantUML 的 `return`，以及未加引号的圆括号；诊断信息只有英文。
- 有序与无序列表，包括嵌套列表。
- 带样式的表格输出。
- 任务列表、脚注、引用式链接。
- 图片与标准链接。
- 支持 emoji 短代码。
- 用于精修文档排版的统一行内模式。

### 导航与结构

- 自动生成的目录。
- 可选的标题自动编号。
- 按冒号拆分标题，以获得更清爽的主标题。
- 可搜索的文件浏览器模式。
- 复制并下载正在阅读文档的源 `.md` 文件，以及
  浏览器中每个文件的源 `.md`（两个开关默认开启）。

### 代码、图表与富内容

- 带复制按钮的语法高亮围栏代码块。
- Mermaid 图表。
- 上标/下标支持。

### 术语提示气泡

通过 `js/md/tooltips.js` 与 `css/md/tooltips.css` 实现的术语提示气泡。术语在悬停时显示提示；引擎会把不同拼写变体解析到同一定义。包含触摸支持、2 秒宽限期，以及感知视口的智能定位。

### 设置面板 —— 功能提示

设置面板 **功能** 分区中的每个开关都会显示详细的悬停提示，说明该功能的作用以及它如何影响渲染。提示使用与术语提示气泡相同的 `.g-tooltip` 气泡和定位引擎 —— 同样的视觉样式、同样的深色模式支持、同样的触摸/键盘行为。无需额外的 CSS。

### 外观与阅读控制

- 深色模式。
- 可调节的页面宽度、字号和行高。
- 页头工具栏的可见性设置（桌面端与移动端可分别设置）。
- 偏好持久保存在浏览器 cookie 中。

### 文件上传

若 `.md.ini` 中 `DISABLE_UPLOAD = false`，文件浏览器会显示 **上传 .md** 按钮。上传的文件保存到 `BROWSE_DIR`；当该键未设置时，保存到 `md.php` 旁边的 `uploads.md/`。使用按目录设置时，文件改为落在正在查看的目录，并由该目录自己的 `.md.ini` 决定是否允许该操作。上传防护分为三层：

1. 当 `DISABLE_UPLOAD = true` 时，界面按钮被隐藏/禁用。
2. 若 `MDV_CONFIG.disableUpload` 为 true，JS 点击处理器会提前返回。
3. PHP 侧 `updater.php`：独立读取 `.md.ini`，若被禁用则返回 `403`。

### 复制与下载源 `.md` 文件

三个可选控件把文件本身交给读者，而不是渲染后的页面。
三者默认都开启，都是 PHP 侧的功能开关（参见设置面板和
`.md.ini` 键表），且两者都是**界面级的闸门而非访问控制**：对同一文件的
请求无论开关如何都仍然可被应答，因为查看器没有身份验证，
也从来没有过。

| 开关 | 出现位置 | 默认值 |
|---|---|---|
| `FEATURE_DOWNLOAD_ICON` | 文件浏览器每一行上的下载图标 | 开启 |
| `FEATURE_DOWNLOAD_BUTTON` | 文档工具栏中的下载按钮，位于宽度与字体控件左侧 | 开启 |
| `FEATURE_COPY_BUTTON` | 文档工具栏中的复制按钮，位于下载按钮左侧 | 开启 |

三者都指向同一个请求 —— `md.php?file=<relative path>&download=1` —— 而该
请求由与查看页面完全相同的函数校验，因此显示时被拒绝的路径在这里同样被拒绝。
响应以 `application/octet-stream` 返回文件字节，并带
`X-Content-Type-Options: nosniff` 与
`Content-Disposition: attachment`：`.md` 是文本，而按文档作者自己选定的
文件名把它声明为文本，正是它在浏览器眼中变成脚本的方式。复制按钮在点击时
抓取该请求，而不是把文档第二次嵌入页面，然后把结果放进剪贴板。剪贴板
预览背后没有文件，因此它不会得到这两个工具栏控件中的任何一个。

### 剪贴板预览与保存到文件
粘贴 Markdown 文本并即时渲染，无需保存文件。若 `DISABLE_SAVE_CLIPBOARD_TO_FILE = false`，会出现一条保存到文件栏，含文件名输入框、保存按钮和行内状态反馈。预览打开时，源文本存入 `sessionStorage`。文件写入正在查看的目录，而该目录下的 `.md.ini` 可以在此禁用或重新启用保存。

### 设置面板

设置面板（从页头的齿轮图标打开）包含：

- 查看偏好开关（主题、宽度、字体、行高、目录、编号）。
- 按设备类型控制的页头工具栏可见性。
- PHP 侧功能开关，带说明性悬停提示（在下次页面加载时通过 cookie 生效）。
- 更新检查与应用控件（仅当 `ALLOW_UPDATE = true` 时可见）。
- 备份列表与还原控件（仅当 `ALLOW_RESTORE = true` 时可见）。
- `index.php` 硬链接管理（仅当 `ALLOW_CREATE_INDEX_PHP_LINK = true` 时可见）。

---

## 内置更新器

`updater.php` 提供四种更新与管理文件的方式。

### 更新器起始页

不带任何参数打开 `updater.php` 会显示一个**信息页**，包含：

- 每个受管理文件的存在/缺失状态及版本号。
- 每个文件名都链接到 GitHub 上的原始文件（在新标签页打开）。
- `.md.ini` 状态：若 `ALLOW_UPDATE = false` 则显示警告及编辑说明。
- 操作按钮（**检查并应用更新**、**↺ 强制重装全部**、**⟲ 还原最新备份**）—— 仅当 `.md.ini` 中相应标志启用时才出现。

### 1. 设置面板（AJAX）

- 检查所有受管理文件的更新，并逐文件显示版本。
- 应用更新 —— 备份当前文件，下载新版本。
- 无视版本强制重装所有文件（绕过 ETag/SHA-256 缓存）。
- 查看并还原备份版本（仅当 `ALLOW_RESTORE = true` 时可见）。

### 2. 直接更新（`?update=true`）

需要 `.md.ini` 中 `ALLOW_UPDATE = true`。

```
https://your-domain.com/updater.php?update=true
https://your-domain.com/updater.php?update=true&force=true   ← force reinstall
```

**两阶段引导：**

- **阶段 1** —— 先更新 `updater.php` 自身。若文件有变化，则重定向到 `?_phase=2`（保留已设置的 `&force=true`），以便由**新的**更新器处理其余部分。
- **阶段 2** —— 更新其余所有受管理文件，并显示版本差异。

**`&force=true` 标志：**

- 完全跳过 ETag 与 SHA-256 缓存 —— 总是从 GitHub 下载每个文件。
- 在部分更新失败后，或本地文件已损坏时很有用。
- 通过重定向 URL 从阶段 1 自动传递到阶段 2。
- 结果页底部会出现一个琥珀色的 **↺ 强制重装全部** 按钮。

**目录自动创建：**

更新器在写文件之前，会以 `0755` 权限创建所有缺失的子目录（`js/md/`、`css/md/`）。这使得 `?update=true` 和机器 API 能在单文件安装的基础上工作。

**结果页：**

- 逐文件状态徽章：`updated` / `force-updated` / `created` / `current` / `error`
- 版本差异：`2.7.0 → 2.8.2`
- 每个文件名都是指向 GitHub 上原始文件的可点击链接（在新标签页打开）
- 页脚：**↺ 强制重装全部** 按钮 + **← 返回** 按钮

### 3. 直接回滚（`?restore=`）

需要 `.md.ini` 中 `ALLOW_RESTORE = true`。

```
https://your-domain.com/updater.php?restore=latest
https://your-domain.com/updater.php?restore=2.7.0
```

| URL | 行为 |
|---|---|
| `?restore=latest` | 扫描 `md.backup/`，自动挑出语义化版本号最高的那个 |
| `?restore=X.Y.Z` | 还原指定的具名备份版本 |

**回滚过程：**

1. 校验 `ALLOW_RESTORE = true` —— 若为 false 则显示错误页。
2. `?restore=latest` 扫描 `md.backup/`，按语义化版本排序，挑出最新的。
3. 校验版本字符串（只允许字母数字、点和短横线）。
4. 覆盖前把当前文件备份到 `md.backup/[version]-pre-restore/` —— 回滚的回滚始终可行。
5. 使逐文件的 ETag 缓存（`.state/`）失效，以便下次更新检查做完整抓取。
6. 输出 HTML 结果页，带 `restored` / `skipped` / `error` 徽章、版本差异和可点击的文件链接。

### 4. Machine API（JSON，供外部客户端调用）

受密钥保护的 JSON API 让另一个程序（一个控制面板、一个安装器）驱动安装，
并逐步渲染其进度。它从不返回 HTML，PHP 的通知或警告也从不
泄漏进响应体。

`API_KEY` 在首次请求时生成并存入 `.md.ini`。若希望在部署后不必读取该文件
就能自动化引导，请在部署前在那里设置你自己的值。

```
GET  updater.php?api_key=<key>&action=status&format=json
GET  updater.php?api_key=<key>&action=conflicts&format=json
POST updater.php?api_key=<key>&action=install&format=json[&dry_run=1][&force=1]
```

| 动作 | 方法 | 用途 |
|---|---|---|
| `status` | GET | 逐文件的本地安装状态（存在 / 缺失 / 版本）。无网络 I/O。 |
| `conflicts` | GET | 试运行的冲突报告。绝不写入任何内容。 |
| `install` | POST | 经写入前冲突守卫抓取并写入文件。 |

响应外壳：

```json
{
  "ok": true,
  "action": "install",
  "version": "2.16.0",
  "steps":     [{"step": "install:css/md/md.css", "status": "ok", "ms": 143, "detail": "2.2.2 → 2.2.2"}],
  "files":     [{"path": "css/md/md.css", "status": "current", "version": "2.2.2"}],
  "conflicts": [{"path": "css/md/settings.css", "status": "same_name_elsewhere",
                 "existing_path": "legacy/components/settings.css", "size_bytes": 1932}],
  "errors":    []
}
```

- `conflicts[].status`：`ok` | `exists`（该确切路径已存在） | `same_name_elsewhere`
- `files[].status`：`present` | `missing` | `updated` | `created` | `force-updated` | `current` | `skipped` | `would_write` | `error`
- `steps[].status`：`ok` | `dry-run` | `skip` | `warn` | `error`
- HTTP 状态码：`200` 已完成（逐文件失败时响应体中仍保留 `"ok": false`），`400` 未知动作，`403` `unauthorized` 或 `api_key_unavailable`，`405` 以非 POST 方式调用 `install`

`dry_run=1` 在冲突报告后即停止：不抓取任何内容，也不写入任何内容。
`force=1` 还会替换位于受管理路径上、但看起来不像 MD.Viewer 文件的文件
（否则它会被跳过并记录在 `conflicts` 中），并绕过 ETag/SHA-256 缓存。
上面的浏览器模式仍然遵循 `ALLOW_UPDATE` / `ALLOW_RESTORE`；API 密钥则是
JSON 动作自己的凭据。
---

## 受管理的文件

由更新器管理的全部文件（检查、下载、备份）：

```
md.php                    v2.18.0
updater.php               v3.14.0
js/md/md.js               v2.7.0
js/md/settings.js         v2.11.0
js/md/tooltips.js         v2.4.5
js/md/upload.js           v2.9.1
css/md/md.css             v2.2.2
css/md/settings.css       v2.9.0
css/md/tooltips.css       v2.4.5
README.md                 v2.11.3
LICENSE                   v1.0.0
```

---

## 备份与还原

在每次替换文件（更新或强制重装）之前，当前本地文件会被复制到 `md.backup/[version]/`。设置面板列出备份版本、日期和文件数量（仅当 `ALLOW_RESTORE = true` 时可见）。还原时会先把当前版本备份（作为 `[version]-pre-restore`），然后把选中的文件写回。`README.md` 与 `LICENSE` 会被下载和更新，但**不会**备份（它们是文档，不是代码）。

---

## 安全

所有安全敏感的操作都在**三个彼此独立的层级**上受到保护：服务端 `.md.ini` 标志、PHP API 守卫，以及客户端界面抑制。

| 功能 | `.md.ini` 标志 | PHP 守卫 | 界面抑制 |
|---|---|---|---|
| 更新（应用/检查） | `ALLOW_UPDATE` | `requireAllowUpdate()` | 为 false 时隐藏 |
| 还原备份 | `ALLOW_RESTORE` | `requireAllowRestore()` | 为 false 时隐藏 |
| 列出备份 | `ALLOW_RESTORE` | `requireAllowRestore()` | 为 false 时不调用 |
| 创建索引链接 | `ALLOW_CREATE_INDEX_PHP_LINK` | `requireAllowIndexLink()` | 为 false 时隐藏 |
| 移除索引链接 | `ALLOW_CREATE_INDEX_PHP_LINK` | `requireAllowIndexLink()` | 为 false 时隐藏 |
| 索引状态 | `ALLOW_CREATE_INDEX_PHP_LINK` | 返回 `{disabled:true}` | 为 false 时不调用 |
| 文件上传 | `DISABLE_UPLOAD` | 为 true 时返回 403 | 按钮隐藏 |
| 保存剪贴板 | `DISABLE_SAVE_CLIPBOARD_TO_FILE` | 为 true 时返回 403 | 按钮隐藏 |
| 机器 API（`status`、`conflicts`、`install`） | `API_KEY` | 403 `unauthorized` | 无密钥时无法调用 |

其他防护：

- **`.md.ini`** 从不发送给浏览器 —— 它只在服务端读取。
- **目录级 `.md.ini`** 只从浏览根目录之内的目录读取，且只从中取 `DISABLE_*` 键；安装级键会被忽略并记录日志。
- 所有文件服务、上传和保存操作都**阻止路径穿越**。
- **上传 / 保存到文件**在服务端校验文件名（只允许 `.md` 扩展名，不含斜杠，不含空字节）。
- `updater.php` 中的 **CORS 守卫**拒绝跨源请求。
- **所有变更性动作**都要求 `POST`（直接 URL 模式除外，它们要求各自的 `ALLOW_*` 标志）。
- `?restore=` 中的**版本字符串**仅按 `[a-zA-Z0-9.\-]` 校验。
- **机器 API** 只应答 JSON（`format=json`），要求 `API_KEY`，并且会报告每一个
  被跳过的外来文件，而不是覆盖它。

---

## 许可证

在 PolyForm Noncommercial License 1.0.0 下发布。参见 [LICENSE](https://github.com/paulmann/MD.Viewer/blob/main/LICENSE)。
